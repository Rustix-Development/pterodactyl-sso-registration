<?php

namespace RustixDev\Registration\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Contracts\Users\DeletesUsers;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Models\User;
use RustixDev\Registration\Models\Signup;
use RustixDev\Registration\RegistrationSettings;
use Throwable;

/**
 * Deletes accounts created on the sign-up page that never verified their email, once the
 * number of days in the settings has passed. Admins and accounts that own a server are kept,
 * and nothing is deleted while verification is turned off or the setting is 0.
 */
class UnverifiedCleanup
{
    public function __construct(
        private readonly RegistrationSettings $settings,
        private readonly DeletesUsers $users,
    ) {}

    /**
     * The sign-ups that would be deleted now.
     *
     * @return Builder<Signup>
     */
    public function candidates(?CarbonImmutable $now = null): Builder
    {
        $days = $this->settings->deleteUnverifiedAfterDays();
        $query = Signup::query()->whereNull('verified_at');
        if ($days === 0 || ! $this->settings->requiresVerification()) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->where('created_at', '<=', ($now ?? CarbonImmutable::now())->subDays($days))
            ->whereHas('user', fn (Builder $user) => $user->where('root_admin', false)->whereDoesntHave('servers'));
    }

    /**
     * @return int the number of accounts deleted
     */
    public function run(?CarbonImmutable $now = null): int
    {
        $days = $this->settings->deleteUnverifiedAfterDays();
        $deleted = 0;

        foreach ($this->candidates($now)->with('user')->lazyById(100, 'user_id') as $signup) {
            $user = $signup->user;
            if (! $user instanceof User) {
                continue;
            }

            try {
                DB::transaction(function () use ($user, $signup, $days): void {
                    // Checked again in the transaction: the account may have changed since.
                    $user = User::query()->lockForUpdate()->find($user->id);
                    if (! $user instanceof User || $user->root_admin || $user->servers()->exists() || Signup::query()->whereKey($user->id)->whereNotNull('verified_at')->exists()) {
                        return;
                    }

                    Activity::event('registration:pruned')
                        ->property([
                            'user_id' => $user->id,
                            'username' => $user->username,
                            'email' => $user->email,
                            'source' => $signup->source,
                            'created_at' => $signup->created_at?->toAtomString(),
                            'days' => $days,
                        ])
                        ->log();

                    $this->users->delete($user);
                });
            } catch (Throwable $exception) {
                report($exception);

                continue;
            }

            if (! User::query()->whereKey($user->id)->exists()) {
                $deleted++;
            }
        }

        return $deleted;
    }
}
