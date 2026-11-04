<?php

namespace RustixDev\Registration\Sso\Providers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Pterodactyl\Services\Extensions\ExtensionSettingDefinition;
use RustixDev\Registration\Sso\Flow;
use RustixDev\Registration\Sso\Identity;
use RustixDev\Registration\Sso\SsoException;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Throwable;

/**
 * Steam's OpenID 2.0 sign-in. Steam shares no email address, so signing up with it asks for
 * one, which is then verified like any other.
 */
final class SteamProvider extends Provider
{
    public const string ID = 'steam';

    public const string NAME = 'Steam';

    public const string ENDPOINT = 'https://steamcommunity.com/openid/login';

    private const string NS = 'http://specs.openid.net/auth/2.0';

    private const string SELECT = 'http://specs.openid.net/auth/2.0/identifier_select';

    private const array SIGNED = ['op_endpoint', 'claimed_id', 'identity', 'return_to', 'response_nonce', 'assoc_handle'];

    public static function definitions(string $callback): array
    {
        return [
            static::enabledDefinition()->help('Steam needs no application. People who sign up with Steam are asked for an email address. Callback: '.$callback),
            ExtensionSettingDefinition::make(static::key('api_key'), static::key('api_key'), '', ['nullable', 'string', 'max:255'])
                ->field('password')
                ->label('Web API key')
                ->tab(static::NAME)
                ->help('Optional, for the Steam name on new accounts and Connections. Create one at https://steamcommunity.com/dev/apikey. Stored encrypted.'),
        ];
    }

    protected function configured(): bool
    {
        return true;
    }

    public function authorizationUrl(Flow $flow, string $redirectUri): string
    {
        $scheme = parse_url($redirectUri, PHP_URL_SCHEME);
        $host = parse_url($redirectUri, PHP_URL_HOST);
        $port = parse_url($redirectUri, PHP_URL_PORT);

        return self::ENDPOINT.'?'.http_build_query([
            'openid.ns' => self::NS,
            'openid.mode' => 'checkid_setup',
            'openid.return_to' => $redirectUri.'?'.http_build_query(['state' => $flow->state]),
            'openid.realm' => $scheme.'://'.$host.(is_int($port) ? ':'.$port : '').'/',
            'openid.identity' => self::SELECT,
            'openid.claimed_id' => self::SELECT,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function identify(Request $request, Flow $flow, string $redirectUri): Identity
    {
        // PHP turns the dots of openid.* into underscores, so read the raw query string.
        $query = $request->server->get('QUERY_STRING');
        $params = array_filter(
            HeaderUtils::parseQuery(is_string($query) ? $query : ''),
            fn (mixed $value, int|string $key): bool => is_string($key) && str_starts_with($key, 'openid.') && is_string($value),
            ARRAY_FILTER_USE_BOTH,
        );
        $param = fn (string $key): string => is_string($params[$key] ?? null) ? $params[$key] : '';

        if ($param('openid.mode') === 'cancel') {
            throw new SsoException(SsoException::DENIED);
        }

        $claimed = $param('openid.claimed_id');
        $signed = explode(',', $param('openid.signed'));
        if ($param('openid.mode') !== 'id_res'
            || $param('openid.ns') !== self::NS
            || $param('openid.op_endpoint') !== self::ENDPOINT
            || $param('openid.return_to') !== $redirectUri.'?'.http_build_query(['state' => $flow->state])
            || $param('openid.identity') !== $claimed
            || array_diff(self::SIGNED, $signed) !== []
            || preg_match('#^https://steamcommunity\.com/openid/id/(7656119\d{10})$#D', $claimed, $matches) !== 1) {
            throw new SsoException(SsoException::PROVIDER);
        }

        // Ask Steam whether it really signed this answer.
        try {
            $answer = Http::timeout(10)
                ->withBody(http_build_query([...$params, 'openid.mode' => 'check_authentication'], '', '&', PHP_QUERY_RFC3986), 'application/x-www-form-urlencoded')
                ->post(self::ENDPOINT)
                ->throw()
                ->body();
        } catch (Throwable $exception) {
            report($exception);

            throw new SsoException(SsoException::PROVIDER);
        }

        if (preg_match('/^is_valid:true$/m', $answer) !== 1) {
            throw new SsoException(SsoException::PROVIDER);
        }

        $steamId = $matches[1];
        $player = $this->player($steamId);

        return new Identity(self::ID, $steamId, self::text($player, 'personaname') ?? 'Steam '.$steamId, null, false, self::text($player, 'avatarfull'));
    }

    /**
     * @return array<string, mixed>
     */
    private function player(string $steamId): array
    {
        if ($this->string('api_key') === '') {
            return [];
        }

        try {
            $players = Http::acceptJson()->timeout(10)->get('https://api.steampowered.com/ISteamUser/GetPlayerSummaries/v2/', [
                'key' => $this->string('api_key'),
                'steamids' => $steamId,
            ])->throw()->json('response.players');
        } catch (Throwable) {
            return [];
        }

        foreach (is_array($players) ? $players : [] as $player) {
            if (is_array($player) && ($player['steamid'] ?? null) === $steamId) {
                return $player;
            }
        }

        return [];
    }
}
