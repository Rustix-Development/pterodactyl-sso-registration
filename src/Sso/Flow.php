<?php

namespace RustixDev\Registration\Sso;

use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Str;

/**
 * One trip to a provider and back, kept in the session: the state and nonce the callback must
 * echo, the PKCE verifier, and what the trip is for.
 */
final readonly class Flow
{
    public const string SESSION = 'registration.sso.flow';

    public const string LOGIN = 'login';

    public const string SIGNUP = 'signup';

    public const string LINK = 'link';

    public const int LIFETIME_SECONDS = 600;

    public function __construct(
        public string $provider,
        public string $intent,
        public string $state,
        public string $nonce,
        public string $verifier,
        public bool $terms = false,
        public ?int $userId = null,
        public int $expires = 0,
    ) {}

    public static function start(string $provider, string $intent, bool $terms = false, ?int $userId = null): self
    {
        return new self($provider, $intent, Str::random(40), Str::random(40), self::base64Url(random_bytes(32)), $terms, $userId, time() + self::LIFETIME_SECONDS);
    }

    public function save(Session $session): void
    {
        $session->put(self::SESSION, get_object_vars($this));
    }

    /**
     * Takes the flow out of the session, once: a callback can only be used one time.
     */
    public static function pull(Session $session): ?self
    {
        $data = $session->pull(self::SESSION);
        if (! is_array($data) || ! is_int($data['expires'] ?? null) || $data['expires'] < time()) {
            return null;
        }

        foreach (['provider', 'intent', 'state', 'nonce', 'verifier'] as $key) {
            if (! is_string($data[$key] ?? null) || $data[$key] === '') {
                return null;
            }
        }

        if (! in_array($data['intent'], [self::LOGIN, self::SIGNUP, self::LINK], true)) {
            return null;
        }

        return new self($data['provider'], $data['intent'], $data['state'], $data['nonce'], $data['verifier'], ($data['terms'] ?? false) === true, is_int($data['userId'] ?? null) ? $data['userId'] : null, $data['expires']);
    }

    public function challenge(): string
    {
        return self::base64Url(hash('sha256', $this->verifier, true));
    }

    public static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
