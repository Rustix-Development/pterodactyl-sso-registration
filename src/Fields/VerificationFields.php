<?php

namespace RustixDev\Registration\Fields;

use Pterodactyl\Extensions\Fields;
use Pterodactyl\Models\User;
use RustixDev\Registration\Services\Verification;

/**
 * The verification status on the admin user page. Nothing here is edited with the form: the
 * buttons beside it call the extension's admin API.
 */
final class VerificationFields extends Fields
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * @return array<string, string|bool|null>
     */
    public function values(User $user, Verification $verification): array
    {
        return $verification->status($user);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function save(User $user, array $values): void {}
}
