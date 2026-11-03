<?php

namespace RustixDev\Registration\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RustixDev\Registration\RegistrationSettings;
use RustixDev\Registration\Sso\SsoIntent;
use RustixDev\Registration\Sso\SsoProviders;

/**
 * "Sign up with ..." on the sign-up page: checks the terms, remembers that this sign-in may
 * create an account, and hands over to Single Sign-On's own redirect.
 */
class SsoSignupController
{
    public function __construct(
        private readonly RegistrationSettings $settings,
        private readonly SsoProviders $providers,
    ) {}

    public function __invoke(Request $request, string $provider): JsonResponse
    {
        if (! $this->settings->enabled() || ! $this->settings->ssoEnabled() || ! $this->providers->has($provider)) {
            return SignupController::closed();
        }

        $terms = $request->boolean('terms');
        if ($this->settings->termsUrl() !== null && ! $terms) {
            throw ValidationException::withMessages(['terms' => 'You must agree to the terms of service.']);
        }

        SsoIntent::put($request->session(), $provider, $terms);

        return new JsonResponse(['data' => ['redirect' => SsoProviders::redirectPath($provider)]]);
    }
}
