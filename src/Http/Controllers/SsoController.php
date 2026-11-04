<?php

namespace RustixDev\Registration\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Pterodactyl\Contracts\Users\CompletesLogins;
use Pterodactyl\Data\LoginCheckpoint;
use RustixDev\Registration\RegistrationSettings;
use RustixDev\Registration\Sso\Flow;
use RustixDev\Registration\Sso\SsoException;
use RustixDev\Registration\Sso\SsoService;

/**
 * Signing in and signing up with an external account.
 */
class SsoController
{
    public const int STARTS_PER_WINDOW = 20;

    public const int START_WINDOW_SECONDS = 600;

    public function __construct(
        private readonly SsoService $sso,
        private readonly RegistrationSettings $settings,
    ) {}

    /**
     * Starts signing in (`intent=login`) or signing up (`intent=signup`, with `terms`) and
     * answers with the provider's address.
     */
    public function start(Request $request, string $provider): JsonResponse
    {
        $intent = $request->input('intent') === Flow::SIGNUP ? Flow::SIGNUP : Flow::LOGIN;
        $terms = $request->boolean('terms');
        if ($intent === Flow::SIGNUP && $this->settings->termsUrl() !== null && ! $terms) {
            throw ValidationException::withMessages(['terms' => 'You must agree to the terms of service.']);
        }

        if ($limited = self::limit('registration:sso:start:'.$request->ip())) {
            return $limited;
        }

        try {
            return new JsonResponse(['data' => ['redirect' => $this->sso->start($request, $provider, $intent, $terms)]]);
        } catch (SsoException $exception) {
            return self::refused($exception);
        }
    }

    /**
     * Where every provider sends people back.
     */
    public function callback(Request $request, string $provider): RedirectResponse
    {
        if (RateLimiter::tooManyAttempts($key = 'registration:sso:callback:'.$request->ip(), self::STARTS_PER_WINDOW)) {
            return redirect()->to(SsoService::failed(Flow::LOGIN, SsoException::LIMITED, $provider));
        }
        RateLimiter::hit($key, self::START_WINDOW_SECONDS);

        return redirect()->to($this->sso->callback($request, $provider));
    }

    /**
     * The confirmation token of a sign-in waiting at the two-factor checkpoint, for the
     * login page to open the panel's checkpoint with.
     */
    public function checkpoint(Request $request, CompletesLogins $logins): JsonResponse
    {
        $checkpoint = $request->session()->get(SsoService::CHECKPOINT) === true ? $logins->pendingCheckpoint() : null;
        if (! $checkpoint instanceof LoginCheckpoint) {
            return new JsonResponse(['errors' => [['code' => 'NotFound', 'status' => '404', 'detail' => 'There is no sign-in waiting for two-factor authentication.']]], 404);
        }

        return new JsonResponse(['data' => ['confirmation_token' => $checkpoint->token]]);
    }

    /**
     * The external sign-up waiting for an email address, if any.
     */
    public function pending(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->sso->pending($request)]);
    }

    public function finish(Request $request): JsonResponse
    {
        try {
            return new JsonResponse(['data' => $this->sso->finish($request)], 201);
        } catch (SsoException $exception) {
            if ($exception->reason === SsoException::LIMITED) {
                return SignupController::tooMany(3600);
            }

            return self::refused($exception);
        }
    }

    public static function refused(SsoException $exception): JsonResponse
    {
        if ($exception->reason === SsoException::LIMITED) {
            return SignupController::tooMany(SsoController::START_WINDOW_SECONDS);
        }

        $detail = match ($exception->reason) {
            SsoException::UNAVAILABLE => 'That sign-in method is not available.',
            SsoException::REFUSED => 'Sign-ups are closed on this panel.',
            default => 'That sign-in has expired. Start again.',
        };

        return new JsonResponse(['errors' => [['code' => 'RegistrationSso', 'status' => '422', 'detail' => $detail, 'meta' => ['reason' => $exception->reason]]]], 422);
    }

    public static function limit(string $key): ?JsonResponse
    {
        if (RateLimiter::tooManyAttempts($key, self::STARTS_PER_WINDOW)) {
            return SignupController::tooMany(RateLimiter::availableIn($key));
        }
        RateLimiter::hit($key, self::START_WINDOW_SECONDS);

        return null;
    }
}
