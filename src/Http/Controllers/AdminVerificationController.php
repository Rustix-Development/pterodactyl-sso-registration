<?php

namespace RustixDev\Registration\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Models\User;
use RustixDev\Registration\Services\Verification;

/**
 * The verification status and buttons on the admin user page.
 */
class AdminVerificationController
{
    public function __construct(private readonly Verification $verification) {}

    public function show(string $user): JsonResponse
    {
        return $this->status($this->find($user));
    }

    /**
     * Marks the address verified without the emailed link.
     */
    public function verify(Request $request, string $user): JsonResponse
    {
        $account = $this->find($user);
        $admin = $request->user();
        if (! $this->verification->markVerified($account, $admin instanceof User ? $admin : null)) {
            return self::conflict('This account has nothing to verify.');
        }

        return $this->status($account);
    }

    /**
     * Sends the verification email again. It shares its limit with the user's own button.
     */
    public function resend(Request $request, string $user): JsonResponse
    {
        $account = $this->find($user);
        if (! $this->verification->pending($account)) {
            return self::conflict('This account does not have to verify its email.');
        }

        $key = 'registration:resend:user:'.$account->id;
        if (RateLimiter::tooManyAttempts($key, SignupController::RESENDS_PER_WINDOW)) {
            return SignupController::tooMany(RateLimiter::availableIn($key));
        }
        RateLimiter::hit($key, SignupController::RESEND_WINDOW_SECONDS);

        $this->verification->send($account);

        $activity = Activity::event('registration:resent')->subject($account)->property(['email' => $account->email]);
        $admin = $request->user();
        if ($admin instanceof User) {
            $activity->actor($admin);
        }
        $activity->withRequestMetadata()->log();

        return $this->status($account, 202);
    }

    private function find(string $id): User
    {
        $user = ctype_digit($id) ? User::query()->find((int) $id) : null;
        abort_unless($user instanceof User, 404);

        return $user;
    }

    private function status(User $user, int $code = 200): JsonResponse
    {
        return new JsonResponse(['data' => $this->verification->status($user)], $code);
    }

    private static function conflict(string $detail): JsonResponse
    {
        return new JsonResponse(['errors' => [['code' => 'RegistrationNothingToDo', 'status' => '409', 'detail' => $detail]]], 409);
    }
}
