<?php

namespace RustixDev\Registration\Services;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\ValidationException;
use Pterodactyl\Actions\Users\CreateUser;
use Pterodactyl\Contracts\Users\CreatesUsers;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Models\User;
use Pterodactyl\Rules\Username;
use RustixDev\Registration\Models\Signup;
use RustixDev\Registration\Notifications\SignupAttempted;
use RustixDev\Registration\RegistrationSettings;

/**
 * Creates accounts through the panel's own CreatesUsers action, so its validation, extension
 * fields, activity entry and any extension wrapping that action all apply.
 */
class SignupService
{
    public const string DOMAIN_MESSAGE = 'Sign-ups with this email address are not allowed.';

    public function __construct(
        private readonly RegistrationSettings $settings,
        private readonly CreatesUsers $users,
        private readonly Verification $verification,
    ) {}

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $core = CreateUser::rules();
        $rules = [
            'username' => $core['username'],
            // Whether the address is taken is checked apart, so the answer can stay the same.
            'email' => [...array_values(array_filter($core['email'], fn (mixed $rule): bool => ! $rule instanceof Unique)), $this->allowedDomain(...)],
            'name_first' => $core['name_first'],
            'name_last' => $core['name_last'],
            'password' => ['required', 'confirmed', ...CreateUser::passwordRules()],
        ];

        if ($this->settings->termsUrl() !== null) {
            $rules['terms'] = ['accepted'];
        }

        return $rules;
    }

    /**
     * Signs someone up with a password. With verification on, the result is "verify" whether
     * or not the address already has an account: its owner gets an email saying so instead.
     *
     * @param  array<string, mixed>  $input
     * @return array{status: 'verify'|'created', user: User|null}
     *
     * @throws ValidationException
     */
    public function register(array $input): array
    {
        $validated = Validator::make($input, $this->rules(), [
            'terms.accepted' => 'You must agree to the terms of service.',
        ])->validate();

        $existing = User::query()->where('email', $validated['email'])->first();
        if ($existing instanceof User) {
            if (! $this->settings->requiresVerification()) {
                throw ValidationException::withMessages(['email' => 'This email address cannot be used to sign up.']);
            }

            // At most one of these an hour, so the form cannot be used to flood someone's inbox.
            if (RateLimiter::attempt('registration:attempted:'.$existing->id, 1, fn () => true, 3600) !== false) {
                $existing->notify(new SignupAttempted);
            }

            return ['status' => 'verify', 'user' => null];
        }

        $user = $this->create([
            'username' => $validated['username'],
            'email' => $validated['email'],
            'name_first' => $validated['name_first'],
            'name_last' => $validated['name_last'],
            'password' => $validated['password'],
        ], Signup::SOURCE_PASSWORD, isset($validated['terms']), false);

        return ['status' => $this->verification->pending($user) ? 'verify' : 'created', 'user' => $user];
    }

    /**
     * Creates the account of someone signing up with an external account. The caller checks
     * that the address is free and allowed.
     */
    public function registerExternal(string $provider, string $name, string $email, bool $emailVerified, bool $termsAccepted): User
    {
        [$first, $last] = self::splitName($name);

        return $this->create([
            'username' => $this->uniqueUsername($name, $email),
            'email' => $email,
            'name_first' => $first,
            'name_last' => $last,
            // Signing in goes through the provider; a password can be set with "Forgot password?".
            'password' => Str::password(64),
        ], Signup::sso($provider), $termsAccepted, $emailVerified);
    }

    /**
     * @param  array<string, string>  $attributes
     */
    private function create(array $attributes, string $source, bool $termsAccepted, bool $verified): User
    {
        $verified = $verified || ! $this->settings->requiresVerification();

        $user = DB::transaction(function () use ($attributes, $source, $termsAccepted, $verified): User {
            $user = $this->users->create($attributes);

            Signup::query()->create([
                'user_id' => $user->id,
                'source' => $source,
                'terms_accepted_at' => $termsAccepted ? now() : null,
                'verified_at' => $verified ? now() : null,
            ]);

            Activity::event('registration:signup')
                ->actor($user)
                ->subject($user)
                ->property(['email' => $user->email, 'username' => $user->username, 'source' => $source])
                ->withRequestMetadata()
                ->log();

            return $user;
        });

        if (! $verified) {
            $this->verification->send($user);
        }

        return $user;
    }

    private function allowedDomain(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && ! $this->settings->allowsEmail($value)) {
            $fail(self::DOMAIN_MESSAGE);
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    public static function splitName(string $name): array
    {
        $parts = preg_split('/\s+/u', trim($name), 2, PREG_SPLIT_NO_EMPTY) ?: [];
        $first = mb_substr($parts[0] ?? 'New', 0, 191);
        $last = mb_substr($parts[1] ?? 'User', 0, 191);

        return [$first, $last];
    }

    /**
     * A username the panel accepts, from the external account's name or else the address.
     */
    public function uniqueUsername(string $name, string $email): string
    {
        $base = self::usernameFrom($name) ?? self::usernameFrom(Str::before($email, '@')) ?? 'user';
        $username = $base;
        for ($n = 2; User::query()->where('username', $username)->exists(); $n++) {
            $username = $base.$n;
        }

        return $username;
    }

    private static function usernameFrom(string $value): ?string
    {
        $slug = mb_strtolower(Str::ascii($value));
        $slug = preg_replace('/[^a-z0-9_.-]+/', '', $slug) ?? '';
        $slug = trim(mb_substr(trim($slug, '_.-'), 0, 32), '_.-');

        return preg_match(Username::VALIDATION_REGEX, $slug) === 1 ? $slug : null;
    }
}
