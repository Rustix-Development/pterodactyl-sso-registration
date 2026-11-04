<?php

namespace RustixDev\Registration\Sso\Providers;

use RustixDev\Registration\Sso\Flow;
use RustixDev\Registration\Sso\Identity;

/**
 * Microsoft Entra ID, common tenant: work, school and personal accounts. Microsoft does not
 * say whether an address is confirmed, so new accounts verify it here.
 */
final class MicrosoftProvider extends OAuth2Provider
{
    public const string ID = 'microsoft';

    public const string NAME = 'Microsoft';

    protected const string CONSOLE = 'https://entra.microsoft.com (App registrations > New registration, "Accounts in any organizational directory and personal Microsoft accounts", Web platform redirect URI)';

    protected function authorizeEndpoint(): string
    {
        return 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize';
    }

    protected function tokenEndpoint(): string
    {
        return 'https://login.microsoftonline.com/common/oauth2/v2.0/token';
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
        $user = $this->fetch($token, 'https://graph.microsoft.com/oidc/userinfo');

        return $this->identity($user, 'sub', self::text($user, 'name'), self::text($user, 'email'), false);
    }
}
