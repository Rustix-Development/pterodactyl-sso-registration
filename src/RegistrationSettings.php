<?php

namespace RustixDev\Registration;

use Pterodactyl\Services\Extensions\ExtensionSettingDefinition;
use Pterodactyl\Services\Extensions\ExtensionSettings;
use RustixDev\Registration\Sso\ProviderRegistry;

/**
 * The extension's settings under Admin > Extensions > Registration, read with their defaults.
 */
class RegistrationSettings
{
    public const string EXTENSION = 'registration';

    public const string ENABLED = 'enabled';

    public const string REQUIRE_VERIFICATION = 'require_verification';

    public const string ALLOWED_DOMAINS = 'allowed_domains';

    public const string BLOCKED_DOMAINS = 'blocked_domains';

    public const string TERMS_URL = 'terms_url';

    public const string SIGNUPS_PER_HOUR = 'signups_per_hour';

    public const string SSO = 'sso';

    public const string LEAVE_LOGIN_TO_SSO = 'sso_leave_login';

    public const string DELETE_UNVERIFIED_AFTER = 'delete_unverified_after_days';

    /** A domain such as example.com; it also covers its subdomains. */
    public const string DOMAIN_PATTERN = '/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$/';

    public function __construct(private readonly ExtensionSettings $settings) {}

    /**
     * @return list<ExtensionSettingDefinition>
     */
    public static function definitions(): array
    {
        $domains = fn (mixed $value): array => is_array($value) ? self::normalizeDomains($value) : [];

        return [
            ExtensionSettingDefinition::make(self::ENABLED, self::ENABLED, false, ['boolean'])
                ->field('toggle')
                ->label('Allow sign-ups')
                ->help('Show "Create an account" on the login page and let anyone create an account. Off until you turn it on.')
                ->frontend()
                ->frontendType('boolean')
                ->public(),
            ExtensionSettingDefinition::make(self::REQUIRE_VERIFICATION, self::REQUIRE_VERIFICATION, true, ['boolean'])
                ->field('toggle')
                ->label('Require email verification')
                ->help('New accounts must open the link emailed to them before they can use the panel. The panel has to be able to send mail (Admin > Settings > Mail).'),
            ExtensionSettingDefinition::make(self::ALLOWED_DOMAINS, self::ALLOWED_DOMAINS, [])
                ->list(['string', 'max:253', 'regex:'.self::DOMAIN_PATTERN.'i'], 100)
                ->normalizeUsing($domains)
                ->label('Allowed email domains')
                ->help('Only addresses at these domains can sign up, such as example.com (subdomains included). Leave empty to allow every domain.'),
            ExtensionSettingDefinition::make(self::BLOCKED_DOMAINS, self::BLOCKED_DOMAINS, [])
                ->list(['string', 'max:253', 'regex:'.self::DOMAIN_PATTERN.'i'], 200)
                ->normalizeUsing($domains)
                ->label('Blocked email domains')
                ->help('Addresses at these domains cannot sign up, such as a disposable mail service (subdomains included).'),
            ExtensionSettingDefinition::make(self::TERMS_URL, self::TERMS_URL, null, ['nullable', 'string', 'max:500', 'url:https,http'])
                ->label('Terms of service link')
                ->help('When set, people must tick "I agree to the terms of service" with a link to this page before they can sign up.')
                ->frontend()
                ->frontendType('string')
                ->public(),
            ExtensionSettingDefinition::make(self::SIGNUPS_PER_HOUR, self::SIGNUPS_PER_HOUR, 5, ['integer', 'min:1', 'max:1000'])
                ->field('number')
                ->label('Sign-ups per hour from one address')
                ->help('How many accounts one IP address can create in an hour. Failed attempts count too.'),
            ExtensionSettingDefinition::make(self::SSO, self::SSO, true, ['boolean'])
                ->field('toggle')
                ->label('Sign up with external accounts')
                ->help('Offer "Sign up with" buttons for the providers turned on in their tabs above (Discord, GitHub, Google and so on). The same domain rules and terms apply.'),
            ExtensionSettingDefinition::make(self::LEAVE_LOGIN_TO_SSO, self::LEAVE_LOGIN_TO_SSO, true, ['boolean'])
                ->field('toggle')
                ->label('Leave sign-in buttons to the Single Sign-On extension')
                ->help('Only while the Single Sign-On extension is enabled: the login page shows its "Continue with" buttons instead of these, so there is one set. Sign-up buttons and Account > Connected accounts still use these providers.'),
            ExtensionSettingDefinition::make(self::DELETE_UNVERIFIED_AFTER, self::DELETE_UNVERIFIED_AFTER, 0, ['integer', 'min:0', 'max:365'])
                ->field('number')
                ->label('Delete unverified accounts after (days)')
                ->help('Accounts created on the sign-up page that have still not verified their email after this many days are deleted, unless they own a server or are an admin. Checked every hour while email verification is required. 0 never deletes them.'),
            // Each provider in its own tab.
            ...ProviderRegistry::definitions(),
        ];
    }

    public function enabled(): bool
    {
        return $this->settings->get(self::ENABLED, false) === true;
    }

    public function requiresVerification(): bool
    {
        return $this->settings->get(self::REQUIRE_VERIFICATION, true) !== false;
    }

    /**
     * Whether the Single Sign-On extension draws the login page's buttons instead.
     */
    public function leavesLoginToSsoExtension(): bool
    {
        if ($this->settings->get(self::LEAVE_LOGIN_TO_SSO, true) === false) {
            return false;
        }

        try {
            return \Pterodactyl\Facades\Extensions::isAvailable('sso');
        } catch (\Throwable) {
            return false;
        }
    }

    public function ssoEnabled(): bool
    {
        return $this->settings->get(self::SSO, true) !== false;
    }

    /**
     * Days an account created on the sign-up page may stay unverified before it is deleted;
     * 0 keeps it.
     */
    public function deleteUnverifiedAfterDays(): int
    {
        $value = $this->settings->get(self::DELETE_UNVERIFIED_AFTER, 0);

        return is_numeric($value) ? min(365, max(0, (int) $value)) : 0;
    }

    /** @return list<string> */
    public function allowedDomains(): array
    {
        return self::normalizeDomains($this->settings->get(self::ALLOWED_DOMAINS, []));
    }

    /** @return list<string> */
    public function blockedDomains(): array
    {
        return self::normalizeDomains($this->settings->get(self::BLOCKED_DOMAINS, []));
    }

    public function termsUrl(): ?string
    {
        $url = $this->settings->get(self::TERMS_URL);
        $url = is_string($url) ? trim($url) : '';

        return $url !== '' && preg_match('#^https?://#i', $url) === 1 ? $url : null;
    }

    public function signupsPerHour(): int
    {
        $value = $this->settings->get(self::SIGNUPS_PER_HOUR, 5);

        return is_numeric($value) ? max(1, (int) $value) : 5;
    }

    /**
     * Whether an address may sign up under the allowed and blocked domain lists. A listed
     * domain covers its subdomains: example.com matches mail.example.com too.
     */
    public function allowsEmail(string $email): bool
    {
        $domain = self::domainOf($email);
        if ($domain === null) {
            return false;
        }

        $allowed = $this->allowedDomains();
        if ($allowed !== [] && ! self::matchesAny($domain, $allowed)) {
            return false;
        }

        return ! self::matchesAny($domain, $this->blockedDomains());
    }

    public static function domainOf(string $email): ?string
    {
        $at = strrpos($email, '@');
        if ($at === false) {
            return null;
        }

        $domain = rtrim(mb_strtolower(trim(substr($email, $at + 1))), '.');

        return $domain === '' ? null : $domain;
    }

    /**
     * @param  list<string>  $domains
     */
    private static function matchesAny(string $domain, array $domains): bool
    {
        foreach ($domains as $entry) {
            if ($domain === $entry || str_ends_with($domain, '.'.$entry)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function normalizeDomains(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $domains = [];
        foreach ($value as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            $entry = rtrim(mb_strtolower(trim(preg_replace('/^(\*\.|@)/', '', trim($entry)) ?? '')), '.');
            if ($entry !== '' && ! in_array($entry, $domains, true)) {
                $domains[] = $entry;
            }
        }

        return $domains;
    }
}
