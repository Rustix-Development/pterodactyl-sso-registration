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
     * What the admin user page shows: whether the account was created on the sign-up page,
     * how, and whether its address is verified.
     *
     * @return array{signup: bool, source: string|null, verified_at: string|null, required: bool, pending: bool}
     */
    public function status(User $user): array
    {
        $signup = Signup::query()->find($user->id);

        return [
            'signup' => $signup instanceof Signup,
            'source' => $signup?->source,
            'verified_at' => $signup?->verified_at?->toAtomString(),
            'required' => $this->settings->requiresVerification(),
            'pending' => $signup instanceof Signup && $this->pending($user),
        ];
    }

    /**
     * Marks the address of an account created on the sign-up page verified without the link,
     * as an admin. False when there is nothing to verify.
     */
    public function markVerified(User $user, ?User $admin = null): bool
    {
        $signup = Signup::query()->find($user->id);
        if (! $signup instanceof Signup || $signup->verified_at !== null) {
            return false;
        }

        $signup->forceFill(['verified_at' => now()])->save();

        $activity = Activity::event('registration:verified')->subject($user)->property(['email' => $user->email, 'by_admin' => true]);
        if ($admin instanceof User) {
            $activity->actor($admin);
        }
        $activity->withRequestMetadata()->log();

        return true;
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
