<?php

namespace RustixDev\Registration\Sso\Providers;

use Illuminate\Http\Client\PendingRequest;
use RustixDev\Registration\Sso\Flow;
use RustixDev\Registration\Sso\Identity;

final class TwitchProvider extends OAuth2Provider
{
    public const string ID = 'twitch';

    public const string NAME = 'Twitch';

    protected const bool PKCE = false;

    protected const string CONSOLE = 'https://dev.twitch.tv/console/apps (OAuth Redirect URLs, category Website Integration, client type Confidential)';

    protected function authorizeEndpoint(): string
    {
        return 'https://id.twitch.tv/oauth2/authorize';
    }

    protected function tokenEndpoint(): string
    {
        return 'https://id.twitch.tv/oauth2/token';
    }

    protected function scopes(): array
    {
        return ['openid', 'user:read:email'];
    }

    protected function authorizationParameters(Flow $flow): array
    {
        return [
            'nonce' => $flow->nonce,
            'claims' => json_encode(['userinfo' => ['email' => null, 'email_verified' => null, 'preferred_username' => null, 'picture' => null]]) ?: '',
        ];
    }

    protected function profile(string $token): Identity
    {
        $user = $this->fetch($token, 'https://id.twitch.tv/oauth2/userinfo', fn (PendingRequest $request) => $request->withHeaders(['Client-Id' => $this->string('client_id')]));

        return $this->identity($user, 'sub', self::text($user, 'preferred_username'), self::text($user, 'email'), ($user['email_verified'] ?? false) === true, self::text($user, 'picture'));
    }
}
