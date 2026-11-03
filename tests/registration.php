<?php

// Checks for sign-ups, email verification, the gate and Single Sign-On sign-ups. Runs against a
// Pterodactyl v2 panel checkout for Laravel, on an in-memory SQLite database, without touching
// the panel's own:
//   PANEL=/var/www/pterodactyl php tests/registration.php
// Set SSO to a Single Sign-On checkout (with ProvisionsAccounts) to check sign-ups through it too.

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Pterodactyl\Facades\Extensions;
use Pterodactyl\Models\User;
use Pterodactyl\Notifications\AccountCreated;
use Pterodactyl\Services\Extensions\ExtensionSettings;
use RustixDev\Registration\Http\Controllers\SignupController;
use RustixDev\Registration\Http\Controllers\VerificationController;
use RustixDev\Registration\Http\Middleware\RequireVerifiedEmail;
use RustixDev\Registration\Listeners\SkipAccountCreatedMail;
use RustixDev\Registration\Models\Signup;
use RustixDev\Registration\Notifications\SignupAttempted;
use RustixDev\Registration\Notifications\VerifyEmail;
use RustixDev\Registration\RegistrationSettings;
use RustixDev\Registration\Services\SignupService;
use RustixDev\Registration\Services\Verification;
use RustixDev\Registration\Sso\SsoIntent;

$panel = getenv('PANEL') ?: '/var/www/pterodactyl';
if (! is_file($panel.'/vendor/autoload.php')) {
    fwrite(STDERR, "Set PANEL to a Pterodactyl v2 panel folder.\n");
    exit(2);
}
$sso = getenv('SSO') ?: $panel.'/extensions-dev/sso';

// This checkout's classes win over an installed copy of the extension.
$autoload = function (string $class) use ($sso): void {
    foreach (['RustixDev\\Registration\\' => __DIR__.'/../src/', 'Sso\\' => $sso.'/src/'] as $prefix => $dir) {
        if (str_starts_with($class, $prefix) && is_file($file = $dir.str_replace('\\', '/', substr($class, strlen($prefix))).'.php')) {
            require $file;

            return;
        }
    }
};
spl_autoload_register($autoload, true, true);
require $panel.'/vendor/autoload.php';
$app = require $panel.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
spl_autoload_unregister($autoload);
spl_autoload_register($autoload, true, true);

$failures = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    echo ($ok ? 'ok   ' : 'FAIL ').$label.($ok || $detail === '' ? '' : "\n     ".$detail)."\n";
    $failures += $ok ? 0 : 1;
}
/** The validation message for $field when $fn is refused, or null when it is not. */
function refused(callable $fn, string $field): ?string
{
    try {
        $fn();
    } catch (ValidationException $exception) {
        return $exception->errors()[$field][0] ?? null;
    }

    return null;
}

/** Extension settings kept in memory in place of the extension_settings table. */
final class MemorySettings extends ExtensionSettings
{
    /** @var array<string, mixed> */
    public array $values = [];

    public function __construct()
    {
        parent::__construct('registration');
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->values[$key] = $value;
    }
}

config(['cache.default' => 'array', 'session.driver' => 'array', 'pterodactyl.auth.2fa_required' => 0]);
config(['database.connections.registration_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
DB::setDefaultConnection('registration_test');
Schema::create('users', function (Blueprint $table) {
    $table->increments('id');
    $table->string('external_id')->nullable()->unique();
    $table->char('uuid', 36)->unique();
    $table->string('username')->unique();
    $table->string('email')->unique();
    $table->string('name_first')->nullable();
    $table->string('name_last')->nullable();
    $table->text('password');
    $table->string('remember_token')->nullable();
    $table->char('language', 5)->default('en');
    $table->unsignedTinyInteger('root_admin')->default(0);
    $table->unsignedTinyInteger('use_totp')->default(0);
    $table->text('totp_secret')->nullable();
    $table->timestamp('totp_authenticated_at')->nullable();
    $table->boolean('gravatar')->default(true);
    $table->timestamps();
});
Schema::create('password_resets', function (Blueprint $table) {
    $table->string('email');
    $table->string('token');
    $table->timestamp('created_at');
});
Schema::create('activity_logs', function (Blueprint $table) {
    $table->id();
    $table->char('batch', 36)->nullable();
    $table->string('event');
    $table->string('ip');
    $table->text('description')->nullable();
    $table->string('actor_type')->nullable();
    $table->unsignedBigInteger('actor_id')->nullable();
    $table->unsignedInteger('api_key_id')->nullable();
    $table->json('properties');
    $table->timestamp('timestamp')->useCurrent();
});
Schema::create('activity_log_subjects', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('activity_log_id');
    $table->string('subject_type');
    $table->unsignedBigInteger('subject_id');
});
Schema::create('servers', function (Blueprint $table) {
    $table->increments('id');
    $table->char('uuid', 36)->nullable();
    $table->unsignedInteger('owner_id');
    $table->unsignedInteger('node_id')->default(1);
    $table->string('name')->nullable();
});
// Deleting a user looks up the nodes of their servers and subusers.
Schema::create('nodes', fn (Blueprint $table) => $table->increments('id'));
Schema::create('subusers', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('user_id');
    $table->unsignedInteger('server_id');
});
(require __DIR__.'/../database/migrations/2026_11_03_000001_create_ext_registration_signups_table.php')->up();
Route::get('/extensions/registration/verify/{user}/{hash}', fn () => '')->name(Verification::ROUTE);
Route::getRoutes()->refreshNameLookups();
// Registration (and Single Sign-On, for its checks) must be enabled on the panel, as they are
// once installed; the panel reads that when it boots, before the in-memory database is used.
if (! Extensions::isAvailable('registration')) {
    fwrite(STDERR, "Install and enable the extension on the panel first.\n");
    exit(2);
}

// Rate limits live in the panel's cache; start from none.
foreach (['registration:signup:10.0.0.1', 'registration:signup:10.0.0.5', 'registration:signup:10.0.0.9', 'registration:signup:127.0.0.1', 'registration:attempted:1'] as $key) {
    RateLimiter::clear($key);
}

$memory = new MemorySettings;
$settings = new RegistrationSettings($memory);
app()->instance(RegistrationSettings::class, $settings);
$verification = new Verification($settings);
$signups = app(SignupService::class);
Notification::fake();
$input = fn (array $over = []): array => [
    'username' => 'newperson', 'email' => 'new@example.com', 'name_first' => 'New', 'name_last' => 'Person',
    'password' => 'Correct-horse-9', 'password_confirmation' => 'Correct-horse-9', ...$over,
];

// --- Settings ------------------------------------------------------------------------------
check('settings: sign-ups off and verification on by default', ! $settings->enabled() && $settings->requiresVerification() && $settings->termsUrl() === null);
$memory->values = ['allowed_domains' => ['Example.com', '@corp.test'], 'blocked_domains' => ['bad.example.com']];
check('domains: listed domains and their subdomains are allowed', $settings->allowsEmail('a@example.com') && $settings->allowsEmail('a@mail.example.com') && $settings->allowsEmail('a@CORP.test'));
check('domains: other domains are refused when a list is set', ! $settings->allowsEmail('a@example.org') && ! $settings->allowsEmail('a@notexample.com'));
check('domains: a blocked domain wins over an allowed one', ! $settings->allowsEmail('a@bad.example.com') && ! $settings->allowsEmail('a@x.bad.example.com'));
check('domains: an address without a domain is refused', ! $settings->allowsEmail('nobody'));
$memory->values = ['enabled' => true, 'terms_url' => 'javascript:alert(1)'];
check('terms: only http(s) links are used', $settings->termsUrl() === null);

// --- Sign-up -------------------------------------------------------------------------------
$memory->values = ['enabled' => true, 'blocked_domains' => ['mailinator.com'], 'terms_url' => 'https://example.com/terms'];
check('signup: the terms must be accepted', refused(fn () => $signups->register($input()), 'terms') === 'You must agree to the terms of service.');
check('signup: a blocked domain is refused', refused(fn () => $signups->register($input(['email' => 'x@mailinator.com', 'terms' => true])), 'email') === SignupService::DOMAIN_MESSAGE);
check('signup: the panel\'s username rules apply', refused(fn () => $signups->register($input(['username' => '-bad name-', 'terms' => true])), 'username') !== null);
check('signup: the passwords must match', refused(fn () => $signups->register($input(['password_confirmation' => 'nope', 'terms' => true])), 'password') !== null);
$result = $signups->register($input(['terms' => true]));
$user = $result['user'];
$signup = Signup::query()->find($user?->id);
check('signup: creates the account through the panel and waits for verification', $result['status'] === 'verify' && $user instanceof User && ! $user->root_admin && $signup?->source === 'password' && $signup->verified_at === null && $signup->terms_accepted_at !== null);
check('signup: the password works', $user !== null && password_verify('Correct-horse-9', $user->password));
check('signup: activity is logged', DB::table('activity_logs')->where('event', 'registration:signup')->where('actor_id', $user?->id)->exists() && DB::table('activity_logs')->where('event', 'user:user.create')->exists());
check('signup: a verification email is sent', Notification::sent($user, VerifyEmail::class)->isNotEmpty());
$listener = new SkipAccountCreatedMail($verification);
check('mail: the panel\'s "account created" email is skipped while verification is pending', $listener->handle(new Illuminate\Notifications\Events\NotificationSending($user, new AccountCreated($user), 'mail')) === false);

$before = User::query()->count();
$again = $signups->register($input(['username' => 'someoneelse', 'terms' => true]));
check('signup: an address that has an account gets the same answer and nothing is created', $again['status'] === 'verify' && $again['user'] === null && User::query()->count() === $before);
check('signup: the owner of that address is told instead', Notification::sent($user, SignupAttempted::class)->isNotEmpty());
$memory->values['require_verification'] = false;
check('signup: without verification a taken address is refused', refused(fn () => $signups->register($input(['username' => 'someoneelse', 'terms' => true])), 'email') !== null);
$memory->values['require_verification'] = true;

// --- Verification ------------------------------------------------------------------------
check('verify: pending until the link is opened', $verification->pending($user));
$gate = new RequireVerifiedEmail($verification);
$blocked = function (string $uri, bool $json = false) use ($gate, $user) {
    $request = Request::create($uri);
    if ($json) {
        $request->headers->set('Accept', 'application/json');
    }
    $request->setUserResolver(fn () => $user);

    return $gate->handle($request, fn () => new Illuminate\Http\Response('through'));
};
check('gate: pages redirect to the verify screen', $blocked('/server/abc')->headers->get('Location') === url(RequireVerifiedEmail::SCREEN));
$api = $blocked('/api/client/account', true);
check('gate: the API refuses with a code the page recognises', $api->getStatusCode() === 409 && str_contains($api->getContent(), RequireVerifiedEmail::CODE));
check('gate: the verify screen, sign out and the extension\'s routes stay open', $blocked('/account/verify-email')->getContent() === 'through' && $blocked('/auth/logout')->getContent() === 'through' && $blocked('/extensions/registration/verification', true)->getContent() === 'through');
config(['pterodactyl.auth.2fa_required' => 2]);
check('gate: the panel\'s two-factor requirement comes first', $blocked('/server/abc')->getContent() === 'through');
config(['pterodactyl.auth.2fa_required' => 0]);
$admin = User::query()->create(['uuid' => (string) Illuminate\Support\Str::uuid(), 'username' => 'admin', 'email' => 'admin@example.com', 'password' => 'x', 'root_admin' => true]);
Signup::query()->create(['user_id' => $admin->id, 'source' => 'password']);
check('gate: root admins and accounts an admin created are never held back', ! $verification->pending($admin) && ! $verification->pending(User::query()->create(['uuid' => (string) Illuminate\Support\Str::uuid(), 'username' => 'byadmin', 'email' => 'byadmin@example.com', 'password' => 'x'])));
check('verify: a link for another address does not work', ! $verification->verify($user, sha1('other@example.com')) && $verification->pending($user));
$url = Verification::url($user);
check('verify: the link is signed and expires', str_contains($url, 'signature=') && str_contains($url, 'expires='));
$controller = new VerificationController($verification);
$tampered = $controller->verify(Request::create($url.'x'), (string) $user->id, Verification::hash($user));
check('verify: a tampered link is refused', str_ends_with($tampered->headers->get('Location'), '/auth/login?registration=expired') && $verification->pending($user));
$ok = $controller->verify(Request::create($url), (string) $user->id, Verification::hash($user));
check('verify: the link verifies the address', str_ends_with($ok->headers->get('Location'), '/auth/login?registration=verified') && ! $verification->pending($user) && DB::table('activity_logs')->where('event', 'registration:verified')->exists());
check('verify: the gate lets a verified account through', $blocked('/server/abc')->getContent() === 'through');

// --- Controller --------------------------------------------------------------------------
$http = app(SignupController::class);
$post = function (array $data, string $ip = '10.0.0.1') {
    $request = Request::create('/extensions/registration/signup', 'POST', $data, server: ['REMOTE_ADDR' => $ip]);
    app()->instance('request', $request);

    return $request;
};
$before = User::query()->count();
$trap = $http->store($post($input(['username' => 'botty', 'email' => 'bot@example.com', 'terms' => true, 'website' => 'http://spam.test'])), app(RustixDev\Registration\Contracts\VerifiesHumans::class), app(Pterodactyl\Contracts\Users\CompletesLogins::class));
check('honeypot: a filled hidden field gets the usual answer and creates nothing', $trap->getStatusCode() === 201 && json_decode($trap->getContent(), true)['data']['status'] === 'verify' && User::query()->count() === $before);
$memory->values['signups_per_hour'] = 2;
$codes = [];
for ($i = 0; $i < 3; $i++) {
    $codes[] = $http->store($post(['website' => 'x'], '10.0.0.9'), app(RustixDev\Registration\Contracts\VerifiesHumans::class), app(Pterodactyl\Contracts\Users\CompletesLogins::class))->getStatusCode();
}
check('rate limit: sign-ups per hour from one address', $codes === [201, 201, 429], json_encode($codes));
$memory->values['enabled'] = false;
check('closed: sign-ups are refused while turned off', $http->store($post($input()), app(RustixDev\Registration\Contracts\VerifiesHumans::class), app(Pterodactyl\Contracts\Users\CompletesLogins::class))->getStatusCode() === 403);
check('options: nothing but "closed" is shared while turned off', json_decode($http->options()->getContent(), true)['data']['enabled'] === false);
$memory->values['enabled'] = true;

// --- Single Sign-On ------------------------------------------------------------------------
check('names: split into first and last', SignupService::splitName('Moe Beydoun Jr') === ['Moe', 'Beydoun Jr'] && SignupService::splitName('moe') === ['moe', 'User']);
check('usernames: made from the name, unique', $signups->uniqueUsername('New Person!', 'x@example.com') === 'newperson2' && $signups->uniqueUsername('?', 'Jane.Doe@example.com') === 'jane.doe');
if (! Extensions::isAvailable('sso') || ! interface_exists('Sso\\Contracts\\ProvisionsAccounts')) {
    echo "skip Single Sign-On checks: set SSO to a Single Sign-On checkout with ProvisionsAccounts\n";
} else {
    $session = app('session')->driver('array');
    $request = Request::create('/extensions/sso/discord/callback', server: ['REMOTE_ADDR' => '10.0.0.5']);
    $request->setLaravelSession($session);
    app()->instance('request', $request);
    $provisioner = new RustixDev\Registration\Sso\SsoProvisioner($settings, $signups, $request);
    $identity = fn (array $over = []) => new Sso\Data\ExternalIdentity(...['provider' => 'discord', 'id' => '1', 'name' => 'Nelly Smith', 'email' => 'nelly@discord.test', 'emailVerified' => true, 'avatarUrl' => null, ...$over]);
    $reason = function (callable $fn): ?string {
        try {
            $fn();
        } catch (Sso\Exceptions\SsoException $exception) {
            return $exception->reason;
        }

        return null;
    };
    check('sso: without the sign-up page, Single Sign-On keeps its own behaviour', $provisioner->provision($identity()) === null);
    SsoIntent::put($session, 'github', true);
    check('sso: an intent for another provider does not count', $provisioner->provision($identity()) === null);
    SsoIntent::put($session, 'discord', false);
    check('sso: the terms must be accepted', $reason(fn () => $provisioner->provision($identity())) === 'refused');
    SsoIntent::put($session, 'discord', true);
    check('sso: a provider without an email cannot sign up', $reason(fn () => $provisioner->provision($identity(['email' => null]))) === 'refused');
    SsoIntent::put($session, 'discord', true);
    check('sso: the blocked domains apply', $reason(fn () => $provisioner->provision($identity(['email' => 'n@mailinator.com']))) === 'refused');
    SsoIntent::put($session, 'discord', true);
    check('sso: an address with an account must sign in and connect instead', $reason(fn () => $provisioner->provision($identity(['email' => 'new@example.com']))) === 'unlinked');
    SsoIntent::put($session, 'discord', true);
    $nelly = $provisioner->provision($identity());
    $row = Signup::query()->find($nelly?->id);
    check('sso: a verified provider email creates a verified account', $nelly instanceof User && $nelly->username === 'nellysmith' && $nelly->name_first === 'Nelly' && $row?->source === 'sso:discord' && $row->verified_at !== null && $row->terms_accepted_at !== null && ! $verification->pending($nelly));
    SsoIntent::put($session, 'discord', true);
    $unverified = $provisioner->provision($identity(['id' => '2', 'name' => 'Sam', 'email' => 'sam@example.net', 'emailVerified' => false]));
    check('sso: an unverified provider email has to be verified here', $unverified instanceof User && $verification->pending($unverified));
    check('sso: and gets the verification email', Notification::sent($unverified, VerifyEmail::class)->isNotEmpty());
    check('sso: the intent is used once', $provisioner->provision($identity(['id' => '3', 'email' => 'three@example.net'])) === null);
    SsoIntent::put($session, 'discord', true);
    $memory->values['enabled'] = false;
    check('sso: with sign-ups off nothing is created', $provisioner->provision($identity(['id' => '4', 'email' => 'four@example.net'])) === null);
}

// --- Admin user page -----------------------------------------------------------------------
$memory->values = ['enabled' => true];
$late = $signups->register($input(['username' => 'lateperson', 'email' => 'late@example.com']))['user'];
$admin2 = User::query()->create(['uuid' => (string) Illuminate\Support\Str::uuid(), 'username' => 'boss', 'email' => 'boss@example.com', 'password' => 'x', 'root_admin' => true]);
$status = $verification->status($late);
check('admin: the status of an unverified sign-up', $status['signup'] === true && $status['source'] === 'password' && $status['verified_at'] === null && $status['pending'] === true && $status['required'] === true);
$byAdmin = User::query()->where('username', 'byadmin')->first();
check('admin: an account an admin created has nothing to verify', $verification->status($byAdmin)['signup'] === false && $verification->status($byAdmin)['pending'] === false && ! $verification->markVerified($byAdmin, $admin2));
$fields = app(RustixDev\Registration\Fields\VerificationFields::class);
check('admin: the form fields carry the status and save nothing', app()->call([$fields, 'values'], ['user' => $late]) === $status && $fields->rules() === []);
$adminHttp = app(RustixDev\Registration\Http\Controllers\AdminVerificationController::class);
$adminRequest = function () use ($admin2) {
    $request = Request::create('/api/admin/extensions/registration/users/1/verify', 'POST', server: ['REMOTE_ADDR' => '10.0.0.7']);
    app()->instance('request', $request);
    // After binding it: binding the request resets its user resolver to the guard.
    $request->setUserResolver(fn () => $admin2);

    return $request;
};
RateLimiter::clear('registration:resend:user:'.$late->id);
Notification::fake();
$sent = $adminHttp->resend($adminRequest(), (string) $late->id);
check('admin: resend sends the verification email again', $sent->getStatusCode() === 202 && Notification::sent($late, VerifyEmail::class)->count() === 1 && DB::table('activity_logs')->where('event', 'registration:resent')->where('actor_id', $admin2->id)->exists());
$codes = [];
for ($i = 0; $i < 3; $i++) {
    $codes[] = $adminHttp->resend($adminRequest(), (string) $late->id)->getStatusCode();
}
check('admin: resend shares the user\'s rate limit', $codes === [202, 202, 429], json_encode($codes));
check('admin: an unknown user is a 404', (function () use ($adminHttp) {
    try {
        $adminHttp->show('999999');
    } catch (Symfony\Component\HttpKernel\Exception\HttpException $exception) {
        return $exception->getStatusCode() === 404;
    }

    return false;
})());
$marked = $adminHttp->verify($adminRequest(), (string) $late->id);
$data = json_decode($marked->getContent(), true)['data'];
check('admin: mark as verified lets the account in and logs who did it', $marked->getStatusCode() === 200 && $data['verified_at'] !== null && $data['pending'] === false && ! $verification->pending($late)
    && DB::table('activity_logs')->where('event', 'registration:verified')->where('actor_id', $admin2->id)->where('properties', 'like', '%by_admin%')->exists());
check('admin: marking twice is refused', $adminHttp->verify($adminRequest(), (string) $late->id)->getStatusCode() === 409);
check('admin: resend to a verified account is refused', $adminHttp->resend($adminRequest(), (string) $late->id)->getStatusCode() === 409);

// --- Cleanup -------------------------------------------------------------------------------
$cleanup = app(RustixDev\Registration\Services\UnverifiedCleanup::class);
$old = function (string $username, int $days, bool $verified = false, bool $rootAdmin = false) use ($signups): User {
    $user = $signups->register([
        'username' => $username, 'email' => $username.'@example.com', 'name_first' => 'Old', 'name_last' => 'Account',
        'password' => 'Correct-horse-9', 'password_confirmation' => 'Correct-horse-9',
    ])['user'];
    Signup::query()->whereKey($user->id)->update(['created_at' => now()->subDays($days), 'verified_at' => $verified ? now() : null]);
    if ($rootAdmin) {
        $user->forceFill(['root_admin' => true])->save();
    }

    return $user;
};
check('cleanup: off by default', $settings->deleteUnverifiedAfterDays() === 0);
$stale = $old('stale', 10);
$fresh = $old('fresh', 3);
$kept = $old('verifiedold', 10, true);
$owner = $old('serverowner', 10);
DB::table('servers')->insert(['uuid' => (string) Illuminate\Support\Str::uuid(), 'owner_id' => $owner->id, 'name' => 'x']);
$root = $old('oldadmin', 10, rootAdmin: true);
$byAdminOld = User::query()->create(['uuid' => (string) Illuminate\Support\Str::uuid(), 'username' => 'adminmadeold', 'email' => 'adminmadeold@example.com', 'password' => 'x']);
$byAdminOld->forceFill(['created_at' => now()->subDays(30)])->save();
check('cleanup: with the setting at 0 nothing is deleted', $cleanup->run() === 0 && User::query()->whereKey($stale->id)->exists());
$memory->values['delete_unverified_after_days'] = 7;
check('cleanup: the setting is read as days', $settings->deleteUnverifiedAfterDays() === 7);
$memory->values['require_verification'] = false;
check('cleanup: nothing is deleted while verification is turned off', $cleanup->candidates()->count() === 0);
$memory->values['require_verification'] = true;
check('cleanup: only old, unverified sign-ups without servers that are not admins', $cleanup->candidates()->pluck('user_id')->all() === [$stale->id]);
$deleted = $cleanup->run();
check('cleanup: deletes them', $deleted === 1 && ! User::query()->whereKey($stale->id)->exists() && ! Signup::query()->whereKey($stale->id)->exists());
check('cleanup: keeps the rest', User::query()->whereKey([$fresh->id, $kept->id, $owner->id, $root->id, $byAdminOld->id])->count() === 5);
$entry = DB::table('activity_logs')->where('event', 'registration:pruned')->first();
check('cleanup: logs an activity entry', $entry !== null && str_contains($entry->properties, 'stale@example.com') && str_contains($entry->properties, '"days":7'));
$memory->values['delete_unverified_after_days'] = 2;
check('cleanup: the command runs it', Illuminate\Support\Facades\Artisan::call('registration:prune-unverified', ['--dry-run' => true]) === 0 && str_contains(Illuminate\Support\Facades\Artisan::output(), 'fresh') && User::query()->whereKey($fresh->id)->exists());

echo $failures === 0 ? "\nAll checks passed.\n" : "\n{$failures} check(s) failed.\n";
exit($failures === 0 ? 0 : 1);
