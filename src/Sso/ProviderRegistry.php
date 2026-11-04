<?php

namespace RustixDev\Registration\Sso;

use Pterodactyl\Services\Extensions\ExtensionSettingDefinition;
use Pterodactyl\Services\Extensions\ExtensionSettings;
use RustixDev\Registration\Sso\Providers\DiscordProvider;
use RustixDev\Registration\Sso\Providers\GitHubProvider;
use RustixDev\Registration\Sso\Providers\GitLabProvider;
use RustixDev\Registration\Sso\Providers\GoogleProvider;
use RustixDev\Registration\Sso\Providers\MicrosoftProvider;
use RustixDev\Registration\Sso\Providers\OidcProvider;
use RustixDev\Registration\Sso\Providers\Provider;
use RustixDev\Registration\Sso\Providers\SteamProvider;
use RustixDev\Registration\Sso\Providers\TwitchProvider;

/**
 * The providers people can sign up and sign in with.
 */
class ProviderRegistry
{
    /** @var list<class-string<Provider>> */
    public const array PROVIDERS = [
        DiscordProvider::class,
        GitHubProvider::class,
        GoogleProvider::class,
        MicrosoftProvider::class,
        GitLabProvider::class,
        TwitchProvider::class,
        SteamProvider::class,
        OidcProvider::class,
    ];

    /** @var array<string, Provider>|null */
    private ?array $providers = null;

    public function __construct(private readonly ExtensionSettings $config) {}

    /**
     * @return array<string, Provider>
     */
    public function all(): array
    {
        if ($this->providers === null) {
            $this->providers = [];
            foreach (self::PROVIDERS as $class) {
                $this->providers[$class::ID] = new $class($this->config);
            }
        }

        return $this->providers;
    }

    /**
     * @return array<string, Provider>
     */
    public function enabled(): array
    {
        return array_filter($this->all(), fn (Provider $provider): bool => $provider->enabled());
    }

    public function find(string $id): ?Provider
    {
        $provider = $this->all()[$id] ?? null;

        return $provider?->enabled() ? $provider : null;
    }

    /**
     * What the pages show of each enabled provider.
     *
     * @return list<array{id: string, name: string, icon: string}>
     */
    public function describe(): array
    {
        return array_values(array_map(fn (Provider $provider): array => ['id' => $provider->id(), 'name' => $provider->name(), 'icon' => $provider->icon()], $this->enabled()));
    }

    /**
     * The exact address each provider sends people back to.
     */
    public static function callback(string $id): string
    {
        return rtrim((string) config('app.url'), '/').'/extensions/registration/sso/'.$id.'/callback';
    }

    /**
     * @return list<ExtensionSettingDefinition>
     */
    public static function definitions(): array
    {
        $definitions = [];
        foreach (self::PROVIDERS as $class) {
            array_push($definitions, ...$class::definitions(self::callback($class::ID)));
        }

        return $definitions;
    }
}
