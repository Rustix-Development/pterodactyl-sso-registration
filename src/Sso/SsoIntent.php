<?php

namespace RustixDev\Registration\Sso;

use Illuminate\Contracts\Session\Session;

/**
 * Remembers, for one sign-in, that the person started it from the sign-up page and whether
 * they agreed to the terms. Single Sign-On only creates an account when this is set, so its
 * "Continue with" buttons on the login page keep signing in only.
 */
class SsoIntent
{
    public const string SESSION = 'registration.sso';

    public const int LIFETIME_SECONDS = 900;

    public static function put(Session $session, string $provider, bool $termsAccepted): void
    {
        $session->put(self::SESSION, ['provider' => $provider, 'terms' => $termsAccepted, 'expires' => time() + self::LIFETIME_SECONDS]);
    }

    /**
     * @return array{terms: bool}|null
     */
    public static function pull(Session $session, string $provider): ?array
    {
        $intent = $session->pull(self::SESSION);
        if (! is_array($intent) || ($intent['provider'] ?? null) !== $provider || ! is_int($intent['expires'] ?? null) || $intent['expires'] < time()) {
            return null;
        }

        return ['terms' => ($intent['terms'] ?? false) === true];
    }
}
