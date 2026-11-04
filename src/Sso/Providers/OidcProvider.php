<?php

namespace RustixDev\Registration\Sso\Providers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Pterodactyl\Services\Extensions\ExtensionSettingDefinition;
use RustixDev\Registration\Sso\Flow;
use RustixDev\Registration\Sso\Identity;
use RustixDev\Registration\Sso\Jwt;
use RustixDev\Registration\Sso\SsoException;
use Throwable;

/**
 * Any OpenID Connect provider, found from its issuer address: Cloudflare Access, Authentik,
 * Keycloak, Okta, Auth0, Zitadel and so on. The ID token's signature is checked against the
 * provider's published keys, along with its issuer, audience, expiry and nonce.
 */
final class OidcProvider extends OAuth2Provider
{
    public const string ID = 'oidc';

    public const string NAME = 'OpenID Connect';

    public const array ICONS = ['key', 'shield', 'cloud', 'lock', 'building', 'globe'];

    /** Allowed difference between the panel's clock and the provider's. */
    private const int LEEWAY_SECONDS = 120;

    public static function definitions(string $callback): array
    {
        $tab = static::NAME;

        return [
            static::enabledDefinition(),
            ExtensionSettingDefinition::make(static::key('name'), static::key('name'), 'Single Sign-On', ['nullable', 'string', 'max:40'])
                ->label('Button name')
                ->tab($tab)
                ->help('Shown as "Continue with ...", such as Cloudflare, Authentik or your company name.'),
            ExtensionSettingDefinition::make(static::key('icon'), static::key('icon'), 'key', ['nullable', 'string', 'in:'.implode(',', self::ICONS)])
                ->field('select', array_map(fn (string $icon): array => ['value' => $icon, 'label' => ucfirst($icon)], self::ICONS))
                ->label('Button icon')
                ->tab($tab),
            ExtensionSettingDefinition::make(static::key('issuer'), static::key('issuer'), '', ['nullable', 'string', 'max:255', 'url:https'])
                ->label('Issuer URL')
                ->tab($tab)
                ->help('The address that serves /.well-known/openid-configuration, such as https://<team>.cloudflareaccess.com/cdn-cgi/access/sso/oidc/<client id>, https://auth.example.com/application/o/panel/ (Authentik) or https://sso.example.com/realms/<realm> (Keycloak).'),
            ExtensionSettingDefinition::make(static::key('client_id'), static::key('client_id'), '', ['nullable', 'string', 'max:255'])
                ->label('Client ID')
                ->tab($tab)
                ->help('Set the redirect (callback) URL of the application to exactly: '.$callback),
            ExtensionSettingDefinition::make(static::key('client_secret'), static::key('client_secret'), '', ['nullable', 'string', 'max:500'])
                ->field('password')
                ->label('Client secret')
                ->tab($tab)
                ->help('Stored encrypted.'),
            ExtensionSettingDefinition::make(static::key('trust_email'), static::key('trust_email'), true, ['boolean'])
                ->field('toggle')
                ->label('Trust confirmed email addresses')
                ->tab($tab)
                ->help('When the provider marks an address as verified (email_verified), new accounts skip the verification email. Turn off if the provider lets people set any address.'),
        ];
    }

    public function name(): string
    {
        return $this->string('name') !== '' ? $this->string('name') : 'Single Sign-On';
    }

    public function icon(): string
    {
        return in_array($this->string('icon'), self::ICONS, true) ? $this->string('icon') : 'key';
    }

    protected function configured(): bool
    {
        return parent::configured() && str_starts_with($this->string('issuer'), 'https://');
    }

    /**
     * The provider's discovery document, cached for an hour.
     *
     * @return array<string, mixed>
     */
    public function discovery(): array
    {
        $issuer = rtrim($this->string('issuer'), '/');

        try {
            $document = Cache::remember('registration:sso:oidc:'.sha1($issuer), Jwt::CACHE_SECONDS, function () use ($issuer): array {
                $document = Http::acceptJson()->timeout(10)->get($issuer.'/.well-known/openid-configuration')->throw()->json();

                return is_array($document) ? $document : [];
            });
        } catch (Throwable $exception) {
            report($exception);

            throw new SsoException(SsoException::PROVIDER);
        }

        foreach (['issuer', 'authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $key) {
            if (! is_string($document[$key] ?? null) || ! str_starts_with($document[$key], 'https://')) {
                throw new SsoException(SsoException::PROVIDER);
            }
        }

        // The document must describe the issuer that was configured, not some other one.
        if (rtrim($document['issuer'], '/') !== $issuer) {
            throw new SsoException(SsoException::PROVIDER);
        }

        return $document;
    }

    protected function authorizeEndpoint(): string
    {
        return $this->discovery()['authorization_endpoint'];
    }

    protected function tokenEndpoint(): string
    {
        return $this->discovery()['token_endpoint'];
    }

    protected function scopes(): array
    {
        return ['openid', 'email', 'profile'];
    }

    protected function authorizationParameters(Flow $flow): array
    {
        return ['nonce' => $flow->nonce];
    }

    public function identify(Request $request, Flow $flow, string $redirectUri): Identity
    {
        $code = $request->query('code');
        if ($request->query('error') !== null || ! is_string($code) || $code === '') {
            throw new SsoException(SsoException::DENIED);
        }

        $document = $this->discovery();
        $tokens = $this->exchange($code, $flow, $redirectUri);
        $idToken = $tokens['id_token'] ?? null;
        if (! is_string($idToken)) {
            throw new SsoException(SsoException::PROVIDER);
        }

        try {
            $claims = Jwt::verify($idToken, $document['jwks_uri']);
        } catch (SsoException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            throw new SsoException(SsoException::PROVIDER);
        }

        $this->check($claims, $document['issuer'], $flow);

        // Some providers only put the address in the userinfo answer.
        $accessToken = $tokens['access_token'] ?? null;
        if (self::text($claims, 'email') === null && is_string($accessToken) && is_string($document['userinfo_endpoint'] ?? null) && str_starts_with($document['userinfo_endpoint'], 'https://')) {
            $info = $this->fetch($accessToken, $document['userinfo_endpoint']);
            if (($info['sub'] ?? null) === $claims['sub']) {
                $claims = [...$info, ...array_filter($claims, fn (mixed $value): bool => $value !== null)];
            }
        }

        $verified = $this->bool('trust_email') && ($claims['email_verified'] ?? false) === true;

        return $this->identity($claims, 'sub', self::text($claims, 'name') ?? self::text($claims, 'preferred_username'), self::text($claims, 'email'), $verified, self::text($claims, 'picture'));
    }

    protected function profile(string $token): Identity
    {
        // Never used: identify() reads the signed ID token instead.
        throw new SsoException(SsoException::PROVIDER);
    }

    protected function bool(string $name): bool
    {
        // Trusting confirmed addresses is on until it is turned off.
        return $name === 'trust_email' ? $this->config->get(static::key($name), true) !== false : parent::bool($name);
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function check(array $claims, string $issuer, Flow $flow): void
    {
        $audience = $claims['aud'] ?? null;
        $audiences = is_array($audience) ? $audience : [$audience];
        $clientId = $this->string('client_id');
        $now = time();

        $valid = ($claims['iss'] ?? null) === $issuer
            && in_array($clientId, $audiences, true)
            && (count($audiences) === 1 || ($claims['azp'] ?? null) === $clientId)
            && is_int($claims['exp'] ?? null) && $claims['exp'] + self::LEEWAY_SECONDS >= $now
            && (! isset($claims['iat']) || (is_int($claims['iat']) && $claims['iat'] - self::LEEWAY_SECONDS <= $now))
            && is_string($claims['nonce'] ?? null) && hash_equals($flow->nonce, $claims['nonce'])
            && self::text($claims, 'sub') !== null;

        if (! $valid) {
            throw new SsoException(SsoException::PROVIDER);
        }
    }
}
