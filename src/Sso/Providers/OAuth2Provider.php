<?php

namespace RustixDev\Registration\Sso\Providers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Pterodactyl\Services\Extensions\ExtensionSettingDefinition;
use RustixDev\Registration\Sso\Flow;
use RustixDev\Registration\Sso\Identity;
use RustixDev\Registration\Sso\SsoException;
use Throwable;

/**
 * An OAuth 2.0 authorization code flow with a client ID and secret, PKCE where the provider
 * supports it, and a profile endpoint.
 */
abstract class OAuth2Provider extends Provider
{
    /** Whether to send a PKCE challenge. */
    protected const bool PKCE = true;

    /** Where to create the application, for the settings' help text. */
    protected const string CONSOLE = '';

    abstract protected function authorizeEndpoint(): string;

    abstract protected function tokenEndpoint(): string;

    /** @return list<string> */
    abstract protected function scopes(): array;

    /**
     * @throws SsoException
     */
    abstract protected function profile(string $token): Identity;

    public static function definitions(string $callback): array
    {
        return [
            static::enabledDefinition(),
            ExtensionSettingDefinition::make(static::key('client_id'), static::key('client_id'), '', ['nullable', 'string', 'max:255'])
                ->label('Client ID')
                ->tab(static::NAME)
                ->help('Create an application at '.static::CONSOLE.' and set its redirect (callback) URL to exactly: '.$callback),
            ExtensionSettingDefinition::make(static::key('client_secret'), static::key('client_secret'), '', ['nullable', 'string', 'max:500'])
                ->field('password')
                ->label('Client secret')
                ->tab(static::NAME)
                ->help('Stored encrypted.'),
        ];
    }

    protected function configured(): bool
    {
        return $this->string('client_id') !== '' && $this->string('client_secret') !== '';
    }

    /** @return array<string, string> */
    protected function authorizationParameters(Flow $flow): array
    {
        return [];
    }

    public function authorizationUrl(Flow $flow, string $redirectUri): string
    {
        return $this->authorizeEndpoint().'?'.http_build_query([
            ...$this->authorizationParameters($flow),
            'response_type' => 'code',
            'client_id' => $this->string('client_id'),
            'redirect_uri' => $redirectUri,
            'scope' => implode(' ', $this->scopes()),
            'state' => $flow->state,
            ...(static::PKCE ? ['code_challenge' => $flow->challenge(), 'code_challenge_method' => 'S256'] : []),
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function identify(Request $request, Flow $flow, string $redirectUri): Identity
    {
        $code = $request->query('code');
        if ($request->query('error') !== null || ! is_string($code) || $code === '') {
            throw new SsoException(SsoException::DENIED);
        }

        $tokens = $this->exchange($code, $flow, $redirectUri);
        $token = $tokens['access_token'] ?? null;
        if (! is_string($token) || $token === '') {
            throw new SsoException(SsoException::PROVIDER);
        }

        return $this->profile($token);
    }

    /**
     * Trades the code for tokens.
     *
     * @return array<string, mixed>
     */
    protected function exchange(string $code, Flow $flow, string $redirectUri): array
    {
        try {
            $tokens = Http::asForm()->acceptJson()->timeout(10)->post($this->tokenEndpoint(), [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $redirectUri,
                'client_id' => $this->string('client_id'),
                'client_secret' => $this->string('client_secret'),
                ...(static::PKCE ? ['code_verifier' => $flow->verifier] : []),
            ])->throw()->json();
        } catch (Throwable $exception) {
            report($exception);

            throw new SsoException(SsoException::PROVIDER);
        }

        return is_array($tokens) ? $tokens : [];
    }

    /**
     * GETs JSON with the access token.
     *
     * @return array<mixed>
     */
    protected function fetch(string $token, string $url, ?callable $configure = null): array
    {
        try {
            $request = Http::acceptJson()->timeout(10)->withToken($token);
            if ($configure !== null) {
                $request = $configure($request) ?? $request;
            }
            /** @var PendingRequest $request */
            $data = $request->get($url)->throw()->json();
        } catch (Throwable $exception) {
            report($exception);

            throw new SsoException(SsoException::PROVIDER);
        }

        if (! is_array($data)) {
            throw new SsoException(SsoException::PROVIDER);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $user
     */
    protected function identity(array $user, string $idKey, ?string $name, ?string $email, bool $verified, ?string $avatar = null): Identity
    {
        $subject = self::text($user, $idKey);
        if ($subject === null) {
            throw new SsoException(SsoException::PROVIDER);
        }

        return new Identity(static::ID, $subject, $name ?? $subject, $email, $email !== null && $verified, $avatar);
    }
}
