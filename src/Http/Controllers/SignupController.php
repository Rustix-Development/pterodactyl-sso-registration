<?php

namespace RustixDev\Registration\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Pterodactyl\Contracts\Users\CompletesLogins;
use Pterodactyl\Models\User;
use RustixDev\Registration\Contracts\VerifiesHumans;
use RustixDev\Registration\RegistrationSettings;
use RustixDev\Registration\Services\SignupService;
use RustixDev\Registration\Services\Verification;
use RustixDev\Registration\Sso\ProviderRegistry;

class SignupController
{
    /** The hidden field people never see; bots that fill every field fill it too. */
    public const string HONEYPOT = 'website';

    public const int RESENDS_PER_WINDOW = 3;

    public const int RESEND_WINDOW_SECONDS = 600;

    public function __construct(
        private readonly RegistrationSettings $settings,
        private readonly SignupService $signups,
        private readonly Verification $verification,
        private readonly ProviderRegistry $providers,
    ) {}

    /**
     * What the login and sign-up pages need: everything here is safe for anyone to read.
     */
    public function options(): JsonResponse
    {
        $enabled = $this->settings->enabled();
        $providers = $this->providers->describe();

        return new JsonResponse(['data' => [
            'enabled' => $enabled,
            'terms_url' => $enabled ? $this->settings->termsUrl() : null,
            'verification' => $this->settings->requiresVerification(),
            // "Sign up with ..." on the sign-up page.
            'providers' => $enabled && $this->settings->ssoEnabled() ? $providers : [],
            // "Continue with ..." under the login form, unless the Single Sign-On extension draws its own.
            'login_providers' => $this->settings->leavesLoginToSsoExtension() ? [] : $providers,
        ]]);
    }

    public function store(Request $request, VerifiesHumans $captcha, CompletesLogins $logins): JsonResponse
    {
        if (! $this->settings->enabled()) {
            return self::closed();
        }

        $key = 'registration:signup:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, $this->settings->signupsPerHour())) {
            return self::tooMany(RateLimiter::availableIn($key));
        }
        RateLimiter::hit($key, 3600);

        $email = is_string($request->input('email')) ? trim($request->input('email')) : '';

        // Bots get the same answer as people, and nothing is created or sent.
        if (filled($request->input(self::HONEYPOT))) {
            return self::verify($email);
        }

        if (! $captcha->verify($request)) {
            throw ValidationException::withMessages(['captcha' => $captcha->message()]);
        }

        $result = $this->signups->register($request->only(['username', 'email', 'name_first', 'name_last', 'password', 'password_confirmation', 'terms']));
        if ($result['status'] === 'verify' || ! $result['user'] instanceof User) {
            return self::verify($email);
        }

        $login = $logins->complete($result['user']);

        return new JsonResponse(['data' => ['status' => 'created', 'redirect' => $login->intended]], 201);
    }

    /**
     * Sends a new link to an address that signed up and has not verified it. The answer is
     * the same whatever the address.
     */
    public function resend(Request $request): JsonResponse
    {
        $email = is_string($request->input('email')) ? trim($request->input('email')) : '';
        $hash = sha1(mb_strtolower($email));
        if ($email === '' || ! $this->settings->enabled()) {
            return new JsonResponse(['data' => ['status' => 'sent']], 202);
        }

        foreach (['registration:resend:ip:'.$request->ip(), 'registration:resend:email:'.$hash] as $key) {
            if (RateLimiter::tooManyAttempts($key, self::RESENDS_PER_WINDOW)) {
                return self::tooMany(RateLimiter::availableIn($key));
            }
        }
        RateLimiter::hit('registration:resend:ip:'.$request->ip(), self::RESEND_WINDOW_SECONDS);
        RateLimiter::hit('registration:resend:email:'.$hash, self::RESEND_WINDOW_SECONDS);

        $user = User::query()->where('email', $email)->first();
        if ($user instanceof User && $this->verification->pending($user)) {
            $this->verification->send($user);
        }

        return new JsonResponse(['data' => ['status' => 'sent']], 202);
    }

    private static function verify(string $email): JsonResponse
    {
        return new JsonResponse(['data' => ['status' => 'verify', 'email' => $email]], 201);
    }

    public static function closed(): JsonResponse
    {
        return new JsonResponse(['errors' => [['code' => 'RegistrationClosed', 'status' => '403', 'detail' => 'Sign-ups are closed on this panel.']]], 403);
    }

    public static function tooMany(int $seconds): JsonResponse
    {
        $minutes = max(1, (int) ceil($seconds / 60));

        return new JsonResponse(['errors' => [[
            'code' => 'TooManyRequests',
            'status' => '429',
            'detail' => 'Too many attempts. Try again in '.$minutes.' minute'.($minutes === 1 ? '' : 's').'.',
        ]]], 429, ['Retry-After' => (string) $seconds]);
    }
}
