<?php

namespace RustixDev\Registration\Sso;

/**
 * Who an external account says it is, after the provider confirmed it.
 */
final readonly class Identity
{
    public function __construct(
        public string $provider,
        public string $subject,
        public string $name,
        public ?string $email,
        public bool $emailVerified,
        public ?string $avatarUrl = null,
    ) {}
}
