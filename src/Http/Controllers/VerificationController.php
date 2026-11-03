<?php

namespace RustixDev\Registration\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Pterodactyl\Models\User;
use RustixDev\Registration\Services\Verification;

class VerificationController
{
    public function __construct(private readonly Verification $verification) {}

    /**
     * The link from the verification email. It works whether or not the person is signed in.
     */
    public function verify(Request $request, string $user, string $hash): RedirectResponse
    {
        $account = ctype_digit($user) ? User::query()->find((int) $user) : null;
        if (! $request->hasValidSignature() || ! $account instanceof User || ! $this->verification->verify($account, $hash)) {
            return redirect()->to('/auth/login?registration=expired');
        }

        $current = $request->user();
        if ($current instanceof User && $current->id === $account->id) {
            return redirect()->to('/');
        }

        return redirect()->to('/auth/login?registration=verified');
    }

    /**
     * The signed-in user's state, for the "Verify your email" screen.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $this->user($request);

        return new JsonResponse(['data' => [
            'pending' => $this->verification->pending($user),
            'email' => $user->email,
        ]]);
    }

    public function resend(Request $request): JsonResponse
    {
        $user = $this->user($request);
        if (! $this->verification->pending($user)) {
            return new JsonResponse(['data' => ['status' => 'verified']]);
        }

        $key = 'registration:resend:user:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, SignupController::RESENDS_PER_WINDOW)) {
            return SignupController::tooMany(RateLimiter::availableIn($key));
        }
        RateLimiter::hit($key, SignupController::RESEND_WINDOW_SECONDS);

        $this->verification->send($user);

        return new JsonResponse(['data' => ['status' => 'sent']], 202);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
