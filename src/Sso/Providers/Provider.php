<?php

namespace RustixDev\Registration\Sso\Providers;

use Illuminate\Http\Request;
use Pterodactyl\Services\Extensions\ExtensionSettingDefinition;
use Pterodactyl\Services\Extensions\ExtensionSettings;
use RustixDev\Registration\Sso\Flow;
use RustixDev\Registration\Sso\Identity;
use RustixDev\Registration\Sso\SsoException;

/**
 * A place people can sign in with. Each one reads its own settings, which live in the
 * extension's settings under its own tab.
 */
abstract class Provider
{
    public const string ID = '';

    public const string NAME = '';

    public function __construct(protected readonly ExtensionSettings $config) {}

    public function id(): string
    {
        return static::ID;
    }

    public function name(): string
    {
        return static::NAME;
    }

    /** The icon the buttons show, by name. */
    public function icon(): string
    {
        return static::ID;
    }

    /** Turned on and configured. */
    public function enabled(): bool
    {
        return $this->bool('enabled') && $this->configured();
    }

    abstract protected function configured(): bool;

    abstract public function authorizationUrl(Flow $flow, string $redirectUri): string;

    /**
     * @throws SsoException
     */
    abstract public function identify(Request $request, Flow $flow, string $redirectUri): Identity;

    /**
     * The provider's settings. $callback is the exact address to register with the provider.
     *
     * @return list<ExtensionSettingDefinition>
     */
    abstract public static function definitions(string $callback): array;

    public static function key(string $name): string
    {
        return 'sso_'.static::ID.'_'.$name;
    }

    protected static function enabledDefinition(): ExtensionSettingDefinition
    {
        return ExtensionSettingDefinition::make(static::key('enabled'), static::key('enabled'), false, ['boolean'])
            ->field('toggle')
            ->label('Enabled')
            ->tab(static::NAME);
    }

    protected function string(string $name): string
    {
        $value = $this->config->get(static::key($name));

        return is_string($value) ? trim($value) : '';
    }

    protected function bool(string $name): bool
    {
        return $this->config->get(static::key($name)) === true;
    }

    /**
     * The non-empty string at $key, else null.
     *
     * @param  array<string, mixed>  $data
     */
    protected static function text(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;
        if (is_int($value)) {
            $value = (string) $value;
        }

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
