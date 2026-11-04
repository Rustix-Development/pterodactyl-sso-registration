<?php

namespace RustixDev\Registration\Sso;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\ValidationException;
use Pterodactyl\Actions\Users\CreateUser;
use Pterodactyl\Contracts\Users\CompletesLogins;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Models\User;
use RustixDev\Registration\Models\Identity as IdentityModel;
use RustixDev\Registration\Models\Signup;
use RustixDev\Registration\Notifications\SignupAttempted;
use RustixDev\Registration\RegistrationSettings;
use RustixDev\Registration\Services\SignupService;
use RustixDev\Registration\Services\Verification;
use Throwable;

/**
 * Signing in, signing up and connecting accounts with an external provider.
 */
class SsoService
{
    /** Set while a sign-in waits for the two-factor checkpoint. */
    public const string CHECKPOINT = 'registration.sso.checkpoint';

    /** An external account that is signing up and still needs an email address. */
    public const string PENDING = 'registration.sso.pending';

    public const string LOGIN_PATH = '/auth/login';

    public const string CONNECTIONS_PATH = '/account/connected-accounts';

    public const string FINISH_PATH = '/auth/login?signup=1&rxrg_finish=1';

    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly RegistrationSettings $settings,
        private readonly SignupService $signups,
        private readonly CompletesLogins $logins,
        private readonly Verification $verification,
    ) {}

    /**
     * Starts a trip to the provider and returns where to send the browser.
     *
     * @throws SsoException
     */
    public function start(Request $request, string $id, string $intent, bool $terms = false): string
    {
        $provider = $this->providers->find($id);
        if ($provider === null) {
            throw new SsoException(SsoException::UNAVAILABLE);
        }

        if ($intent === Flow::SIGNUP && ! $this->signupsOpen()) {
            throw new SsoException(SsoException::REFUSED);
        }

        $user = $request->user();
        if ($intent === Flow::LINK && ! $user instanceof User) {
            throw new SsoException(SsoException::STATE);
        }

        $flow = Flow::start($id, $intent, $terms, $intent === Flow::LINK ? $user->id : null);

        try {
            $url = $provider->authorizationUrl($flow, ProviderRegistry::callback($id));
        } catch (SsoException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            throw new SsoException(SsoException::PROVIDER);
        }

        $flow->save($request->session());

        return $url;
    }

    /**
     * The provider sent the browser back: finish what the trip was for. Returns the path to
     * send the browser to.
     */
    public function callback(Request $request, string $id): string
    {
        $flow = Flow::pull($request->session());
        $intent = $flow?->intent ?? Flow::LOGIN;

        try {
            $state = $request->query('state');
            if ($flow === null || $flow->provider !== $id || ! is_string($state) || ! hash_equals($flow->state, $state)) {
                throw new SsoException(SsoException::STATE);
            }

            $provider = $this->providers->find($id);
            if ($provider === null) {
                throw new SsoException(SsoException::UNAVAILABLE);
            }

            $identity = $provider->identify($request, $flow, ProviderRegistry::callback($id));

            return match ($intent) {
                Flow::LINK => $this->link($request, $identity, $flow),
                Flow::SIGNUP => $this->signup($request, $identity, $flow),
                default => $this->login($request, $identity),
            };
        } catch (SsoException $exception) {
            return self::failed($intent, $exception->reason, $id);
        } catch (Throwable $exception) {
            report($exception);

            return self::failed($intent, SsoException::PROVIDER, $id);
        }
    }

    public static function failed(string $intent, string $reason, string $provider): string
    {
        $query = http_build_query(['rxrg_sso' => $reason, 'rxrg_provider' => $provider]);

        return match ($intent) {
            Flow::LINK => self::CONNECTIONS_PATH.'?'.$query,
            Flow::SIGNUP => self::LOGIN_PATH.'?signup=1&'.$query,
            default => self::LOGIN_PATH.'?'.$query,
        };
    }

    /**
     * Signs in the account the external account is connected to. An external account that is
     * connected to none gets the same answer whether or not its address has an account here:
     * it is never connected on its own.
     */
    private function login(Request $request, Identity $identity): string
    {
        $linked = $this->find($identity);
        if (! $linked instanceof IdentityModel || ! $linked->user instanceof User) {
            throw new SsoException(SsoException::UNLINKED);
        }

        return $this->complete($request, $linked);
    }

    private function complete(Request $request, IdentityModel $linked): string
    {
        $linked->forceFill(['last_used_at' => now()])->save();
        $result = $this->logins->complete($linked->user);
        if ($result->complete) {
            return $result->intended;
        }

        // Two-factor accounts go through the panel's checkpoint, as with a password.
        $request->session()->put(self::CHECKPOINT, true);

        return self::LOGIN_PATH.'?rxrg_sso=checkpoint';
    }

    private function signup(Request $request, Identity $identity, Flow $flow): string
    {
        // Already connected: just sign in.
        $linked = $this->find($identity);
        if ($linked instanceof IdentityModel && $linked->user instanceof User) {
            return $this->complete($request, $linked);
        }

        if (! $this->signupsOpen() || ($this->settings->termsUrl() !== null && ! $flow->terms)) {
            throw new SsoException(SsoException::REFUSED);
        }

        $email = $identity->email === null ? null : trim($identity->email);

        // No address, or one the provider did not confirm: ask for one, and verify it here.
        if ($email === null || $email === '' || ! $identity->emailVerified) {
            $request->session()->put(self::PENDING, [
                'provider' => $identity->provider,
                'subject' => $identity->subject,
                'name' => $identity->name,
                'email' => $email,
                'terms' => $flow->terms,
                'expires' => time() + Flow::LIFETIME_SECONDS * 3,
            ]);

            return self::FINISH_PATH;
        }

        if (! $this->settings->allowsEmail($email)) {
            throw new SsoException(SsoException::REFUSED);
        }

        if (User::query()->where('email', $email)->exists()) {
            throw new SsoException(SsoException::EXISTS);
        }

        $this->hitSignupLimit($request);

        try {
            $user = $this->signups->registerExternal($identity, $email, true, $flow->terms);
        } catch (ValidationException) {
            throw new SsoException(SsoException::REFUSED);
        }

        $linked = IdentityModel::query()->where('user_id', $user->id)->where('provider', $identity->provider)->firstOrFail();

        return $this->complete($request, $linked);
    }

    /**
     * What the "Finish your account" step shows: the provider and any address it gave.
     *
     * @return array{provider: string, name: string, email: string|null}|null
     */
    public function pending(Request $request): ?array
    {
        $pending = $request->session()->get(self::PENDING);
        if (! is_array($pending) || ! is_int($pending['expires'] ?? null) || $pending['expires'] < time()) {
            return null;
        }

        $provider = $this->providers->all()[$pending['provider']] ?? null;

        return [
            'provider' => (string) $pending['provider'],
            'name' => $provider?->name() ?? (string) $pending['provider'],
            'email' => is_string($pending['email'] ?? null) ? $pending['email'] : null,
        ];
    }

    /**
     * Creates the account of a pending external sign-up with the address the person typed.
     * The address is then verified like any other. An address that has an account gets the
     * same answer, and its owner is emailed instead.
     *
     * @return array{status: 'verify'|'created', email: string, redirect?: string}
     *
     * @throws ValidationException|SsoException
     */
    public function finish(Request $request): array
    {
        $pending = $request->session()->get(self::PENDING);
        if ($this->pending($request) === null || ! $this->signupsOpen()) {
            throw new SsoException(SsoException::STATE);
        }

        $core = CreateUser::rules();
        $email = Validator::make($request->only('email'), [
            'email' => [...array_values(array_filter($core['email'], fn (mixed $rule): bool => ! $rule instanceof Unique)), function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && ! $this->settings->allowsEmail($value)) {
                    $fail(SignupService::DOMAIN_MESSAGE);
                }
            }],
        ])->validate()['email'];

        $this->hitSignupLimit($request);

        $identity = new Identity((string) $pending['provider'], (string) $pending['subject'], (string) $pending['name'], $email, false);
        if ($this->find($identity) !== null) {
            $request->session()->forget(self::PENDING);

            throw new SsoException(SsoException::STATE);
        }

        $existing = User::query()->where('email', $email)->first();
        if ($existing instanceof User) {
            if (! $this->settings->requiresVerification()) {
                throw ValidationException::withMessages(['email' => 'This email address cannot be used to sign up.']);
            }

            if (RateLimiter::attempt('registration:attempted:'.$existing->id, 1, fn () => true, 3600) !== false) {
                $existing->notify(new SignupAttempted);
            }
            $request->session()->forget(self::PENDING);

            return ['status' => 'verify', 'email' => $email];
        }

        $user = $this->signups->registerExternal($identity, $email, false, ($pending['terms'] ?? false) === true);
        $request->session()->forget(self::PENDING);

        if ($this->verification->pending($user)) {
            return ['status' => 'verify', 'email' => $email];
        }

        $linked = IdentityModel::query()->where('user_id', $user->id)->where('provider', $identity->provider)->firstOrFail();

        return ['status' => 'created', 'email' => $email, 'redirect' => $this->complete($request, $linked)];
    }

    private function link(Request $request, Identity $identity, Flow $flow): string
    {
        $user = $request->user();
        if (! $user instanceof User || $user->id !== $flow->userId) {
            throw new SsoException(SsoException::STATE);
        }

        $linked = $this->find($identity);
        if ($linked instanceof IdentityModel) {
            if ($linked->user_id !== $user->id) {
                throw new SsoException(SsoException::TAKEN);
            }

            return self::CONNECTIONS_PATH.'?'.http_build_query(['rxrg_sso' => 'linked', 'rxrg_provider' => $identity->provider]);
        }

        if (IdentityModel::query()->where('user_id', $user->id)->where('provider', $identity->provider)->exists()) {
            throw new SsoException(SsoException::STATE);
        }

        IdentityModel::query()->create([
            'user_id' => $user->id,
            'provider' => $identity->provider,
            'subject' => $identity->subject,
            'name' => mb_substr($identity->name, 0, 191),
            'email' => $identity->email === null ? null : mb_substr($identity->email, 0, 191),
        ]);

        Activity::event('registration:sso.link')->actor($user)->subject($user)->property(['provider' => $identity->provider, 'name' => $identity->name])->withRequestMetadata()->log();

        return self::CONNECTIONS_PATH.'?'.http_build_query(['rxrg_sso' => 'linked', 'rxrg_provider' => $identity->provider]);
    }

    /**
     * Disconnects a provider, unless it is the account's only way in.
     *
     * @throws ValidationException
     */
    public function unlink(User $user, string $provider): void
    {
        DB::transaction(function () use ($user, $provider): void {
            $identities = IdentityModel::query()->where('user_id', $user->id)->lockForUpdate()->get();
            $identity = $identities->firstWhere('provider', $provider);
            if (! $identity instanceof IdentityModel) {
                return;
            }

            if ($identities->count() === 1 && ! $this->hasPassword($user)) {
                throw ValidationException::withMessages(['provider' => 'This is the only way into your account. Set a password first with "Forgot password?" on the login page, or connect another account.']);
            }

            $identity->delete();

            Activity::event('registration:sso.unlink')->actor($user)->subject($user)->property(['provider' => $provider])->withRequestMetadata()->log();
        });
    }

    /**
     * Whether the person knows a password for the account: false only for an account created
     * with an external account that has not set one since.
     */
    public function hasPassword(User $user): bool
    {
        return ! Signup::query()->whereKey($user->id)->where('random_password', true)->exists();
    }

    /**
     * The providers the account can connect, and those it has connected.
     *
     * @return array{providers: list<array{id: string, name: string, icon: string, connected: bool, account: string|null, connected_at: string|null}>, has_password: bool}
     */
    public function connections(User $user): array
    {
        $linked = IdentityModel::query()->where('user_id', $user->id)->get()->keyBy('provider');
        $providers = [];
        foreach ($this->providers->all() as $id => $provider) {
            $identity = $linked->get($id);
            if (! $provider->enabled() && $identity === null) {
                continue;
            }

            $providers[] = [
                'id' => $id,
                'name' => $provider->name(),
                'icon' => $provider->icon(),
                'enabled' => $provider->enabled(),
                'connected' => $identity !== null,
                'account' => $identity?->name ?? $identity?->email,
                'connected_at' => $identity?->created_at?->toAtomString(),
            ];
        }

        return ['providers' => $providers, 'has_password' => $this->hasPassword($user)];
    }

    private function find(Identity $identity): ?IdentityModel
    {
        return IdentityModel::query()->with('user')->where('provider', $identity->provider)->where('subject', $identity->subject)->first();
    }

    private function signupsOpen(): bool
    {
        return $this->settings->enabled() && $this->settings->ssoEnabled();
    }

    private function hitSignupLimit(Request $request): void
    {
        $key = 'registration:signup:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, $this->settings->signupsPerHour())) {
            throw new SsoException(SsoException::LIMITED);
        }
        RateLimiter::hit($key, 3600);
    }
}
