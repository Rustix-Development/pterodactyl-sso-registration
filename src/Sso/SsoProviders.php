<?php

namespace RustixDev\Registration\Sso;

use Illuminate\Contracts\Container\Container;
use Pterodactyl\Facades\Extensions;
use Throwable;

/**
 * Reads the providers of the Single Sign-On extension (id "sso") when it is enabled. Every
 * call is guarded, so nothing here needs that extension to be installed.
 */
class SsoProviders
{
    public const string EXTENSION = 'sso';

    public const string REGISTRY = 'Sso\\ProviderRegistry';

    /** Added to Single Sign-On for sign-ups; older versions cannot create accounts. */
    public const string CONTRACT = 'Sso\\Contracts\\ProvisionsAccounts';

    public function __construct(private readonly Container $container) {}

    /**
     * Whether Single Sign-On is running and able to hand new accounts to this extension.
     */
    public function available(): bool
    {
        try {
            return Extensions::isAvailable(self::EXTENSION) && class_exists(self::REGISTRY) && interface_exists(self::CONTRACT);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    public function enabled(): array
    {
        if (! $this->available()) {
            return [];
        }

        try {
            $providers = $this->container->make(self::REGISTRY)->enabled();
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }

        return array_values(array_map(fn (object $provider): array => ['id' => (string) $provider->id(), 'name' => (string) $provider->name()], $providers));
    }

    public function has(string $id): bool
    {
        foreach ($this->enabled() as $provider) {
            if ($provider['id'] === $id) {
                return true;
            }
        }

        return false;
    }

    /**
     * Where Single Sign-On starts a sign-in with the provider.
     */
    public static function redirectPath(string $id): string
    {
        return '/extensions/sso/'.rawurlencode($id).'/redirect';
    }
}
