<?php

namespace RustixDev\Registration\Sso\Providers;

use RustixDev\Registration\Sso\Identity;

final class DiscordProvider extends OAuth2Provider
{
    public const string ID = 'discord';

    public const string NAME = 'Discord';

    protected const string CONSOLE = 'https://discord.com/developers/applications (OAuth2 > Redirects)';

    protected function authorizeEndpoint(): string
    {
        return 'https://discord.com/oauth2/authorize';
    }

    protected function tokenEndpoint(): string
    {
        return 'https://discord.com/api/oauth2/token';
    }

    protected function scopes(): array
    {
        return ['identify', 'email'];
    }

    protected function profile(string $token): Identity
    {
        $user = $this->fetch($token, 'https://discord.com/api/users/@me');
        $id = self::text($user, 'id');
        $avatar = self::text($user, 'avatar');

        return $this->identity(
            $user,
            'id',
            self::text($user, 'global_name') ?? self::text($user, 'username'),
            self::text($user, 'email'),
            ($user['verified'] ?? false) === true,
            $id !== null && $avatar !== null ? 'https://cdn.discordapp.com/avatars/'.$id.'/'.$avatar.'.png' : null,
        );
    }
}
