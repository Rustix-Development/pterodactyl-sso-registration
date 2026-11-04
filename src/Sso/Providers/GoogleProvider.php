<?php

namespace RustixDev\Registration\Sso\Providers;

use RustixDev\Registration\Sso\Flow;
use RustixDev\Registration\Sso\Identity;

final class GoogleProvider extends OAuth2Provider
{
    public const string ID = 'google';

    public const string NAME = 'Google';

    protected const string CONSOLE = 'https://console.cloud.google.com/apis/credentials (OAuth client ID, Web application > Authorized redirect URIs)';

    protected function authorizeEndpoint(): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth';
    }

    protected function tokenEndpoint(): string
    {
        return 'https://oauth2.googleapis.com/token';
    }

    protected function scopes(): array
    {
        return ['openid', 'email', 'profile'];
    }

    protected function authorizationParameters(Flow $flow): array
    {
        return ['prompt' => 'select_account', 'nonce' => $flow->nonce];
    }

    protected function profile(string $token): Identity
    {
        $user = $this->fetch($token, 'https://openidconnect.googleapis.com/v1/userinfo');

        return $this->identity($user, 'sub', self::text($user, 'name'), self::text($user, 'email'), ($user['email_verified'] ?? false) === true, self::text($user, 'picture'));
    }
}
