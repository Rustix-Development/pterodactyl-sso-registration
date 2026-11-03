<?php

namespace RustixDev\Registration\Services;

use Illuminate\Support\Facades\URL;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Models\User;
use RustixDev\Registration\Models\Signup;
use RustixDev\Registration\Notifications\VerifyEmail;
use RustixDev\Registration\RegistrationSettings;

/**
 * Email verification of accounts people create themselves. Accounts an admin creates, and
 * root admins, are never held back.
 */
class Verification
{
    public const string ROUTE = 'extensions.registration.web.verify';

    public const int LIFETIME_HOURS = 24;

    public function __construct(private readonly RegistrationSettings $settings) {}

    /**
     * Whether the user still has to verify their address before they can use the panel.
     */
    public function pending(User $user): bool
    {
        if ($user->root_admin || ! $this->settings->requiresVerification()) {
            return false;
        }

        return Signup::query()->whereKey($user->id)->whereNull('verified_at')->exists();
    }

    public function send(User $user): void
    {
        $user->notify(new VerifyEmail);
    }

    /**
     * Marks the address verified when the hash belongs to the user's current address.
     */
    public function verify(User $user, string $hash): bool
    {
        if (! hash_equals(self::hash($user), $hash)) {
            return false;
        }

        $signup = Signup::query()->find($user->id);
        if (! $signup instanceof Signup) {
            return false;
        }

        if ($signup->verified_at === null) {
            $signup->forceFill(['verified_at' => now()])->save();

            Activity::event('registration:verified')
                ->actor($user)
                ->subject($user)
                ->property(['email' => $user->email])
                ->withRequestMetadata()
                ->log();
        }

        return true;
    }

    public static function url(User $user): string
    {
        return URL::temporarySignedRoute(self::ROUTE, now()->addHours(self::LIFETIME_HOURS), [
            'user' => $user->id,
            'hash' => self::hash($user),
        ]);
    }

    /**
     * Ties a link to the address it was sent to, so it stops working when the address changes.
     */
    public static function hash(User $user): string
    {
        return sha1(mb_strtolower($user->email));
    }
}
