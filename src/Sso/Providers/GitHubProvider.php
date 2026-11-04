<?php

namespace RustixDev\Registration\Sso\Providers;

use RustixDev\Registration\Sso\Identity;

final class GitHubProvider extends OAuth2Provider
{
    public const string ID = 'github';

    public const string NAME = 'GitHub';

    protected const string CONSOLE = 'https://github.com/settings/developers (OAuth Apps > Authorization callback URL)';

    protected function authorizeEndpoint(): string
    {
        return 'https://github.com/login/oauth/authorize';
    }

    protected function tokenEndpoint(): string
    {
        return 'https://github.com/login/oauth/access_token';
    }

    protected function scopes(): array
    {
        return ['read:user', 'user:email'];
    }

    protected function profile(string $token): Identity
    {
        $user = $this->fetch($token, 'https://api.github.com/user');

        // The profile's email is whatever the person made public; the primary verified
        // address comes from the emails endpoint.
        $email = null;
        foreach ($this->fetch($token, 'https://api.github.com/user/emails') as $entry) {
            if (is_array($entry) && ($entry['primary'] ?? false) === true && ($entry['verified'] ?? false) === true && is_string($entry['email'] ?? null)) {
                $email = $entry['email'];
            }
        }

        return $this->identity($user, 'id', self::text($user, 'name') ?? self::text($user, 'login'), $email, $email !== null, self::text($user, 'avatar_url'));
    }
}
