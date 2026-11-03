<?php

namespace RustixDev\Registration\Sso;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Pterodactyl\Models\User;
use RustixDev\Registration\RegistrationSettings;
use RustixDev\Registration\Services\SignupService;
use Sso\Contracts\ProvisionsAccounts;
use Sso\Data\ExternalIdentity;
use Sso\Exceptions\SsoException;
use Sso\Services\Placeholders;
use Throwable;

/**
 * Creates the account of someone who used "Sign up with ..." on the sign-up page. Single
 * Sign-On calls it for an external account that is not linked to a panel account; this class
 * is only loaded by Single Sign-On, so its classes always exist here.
 */
class SsoProvisioner implements ProvisionsAccounts
{
    public function __construct(
        private readonly RegistrationSettings $settings,
        private readonly SignupService $signups,
        private readonly Request $request,
    ) {}

    public function provision(ExternalIdentity $identity): ?User
    {
        $intent = $this->request->hasSession() ? SsoIntent::pull($this->request->session(), $identity->provider) : null;

        // Not started from the sign-up page, or sign-ups are off: Single Sign-On keeps its
        // own behaviour and reports the account as not linked.
        if ($intent === null || ! $this->settings->enabled() || ! $this->settings->ssoEnabled()) {
            return null;
        }

        if ($this->settings->termsUrl() !== null && ! $intent['terms']) {
            throw new SsoException(SsoException::REFUSED);
        }

        $email = $identity->email === null ? '' : trim($identity->email);
        if ($email === '' || Placeholders::isReservedEmail($email) || ! $this->settings->allowsEmail($email)) {
            throw new SsoException(SsoException::REFUSED);
        }

        // The address has an account already: sign in to it and connect the provider instead.
        if (User::query()->where('email', $email)->exists()) {
            throw new SsoException(SsoException::UNLINKED);
        }

        $key = 'registration:signup:'.$this->request->ip();
        if (RateLimiter::tooManyAttempts($key, $this->settings->signupsPerHour())) {
            throw new SsoException(SsoException::REFUSED);
        }
        RateLimiter::hit($key, 3600);

        try {
            return $this->signups->registerExternal($identity->provider, $identity->name, $email, $identity->emailVerified, $intent['terms']);
        } catch (ValidationException) {
            throw new SsoException(SsoException::REFUSED);
        } catch (SsoException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            throw new SsoException(SsoException::PROVIDER);
        }
    }
}
