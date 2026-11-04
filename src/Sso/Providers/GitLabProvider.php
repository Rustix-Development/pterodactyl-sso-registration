<?php

namespace RustixDev\Registration\Sso\Providers;

use Pterodactyl\Services\Extensions\ExtensionSettingDefinition;
use RustixDev\Registration\Sso\Identity;

final class GitLabProvider extends OAuth2Provider
{
    public const string ID = 'gitlab';

    public const string NAME = 'GitLab';

    public const string DEFAULT_URL = 'https://gitlab.com';

    protected const string CONSOLE = 'your GitLab under Preferences > Applications (scopes: read_user)';

    public static function definitions(string $callback): array
    {
        return [
            ...parent::definitions($callback),
            ExtensionSettingDefinition::make(static::key('url'), static::key('url'), self::DEFAULT_URL, ['nullable', 'string', 'max:255', 'url:https'])
                ->label('GitLab address')
                ->tab(static::NAME)
                ->help('https://gitlab.com, or the address of a self-hosted GitLab.'),
        ];
    }

    private function base(): string
    {
        $url = rtrim($this->string('url'), '/');

        return str_starts_with($url, 'https://') ? $url : self::DEFAULT_URL;
    }

    protected function authorizeEndpoint(): string
    {
        return $this->base().'/oauth/authorize';
    }

    protected function tokenEndpoint(): string
    {
        return $this->base().'/oauth/token';
    }

    protected function scopes(): array
    {
        return ['read_user'];
    }

    protected function profile(string $token): Identity
    {
        $user = $this->fetch($token, $this->base().'/api/v4/user');

        // GitLab only makes a confirmed address the primary one.
        return $this->identity($user, 'id', self::text($user, 'name') ?? self::text($user, 'username'), self::text($user, 'email'), self::text($user, 'confirmed_at') !== null, self::text($user, 'avatar_url'));
    }
}
