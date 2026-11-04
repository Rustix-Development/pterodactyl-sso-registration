<?php

namespace RustixDev\Registration;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Pterodactyl\Extensions\ExtensionProvider;
use Pterodactyl\Facades\Extensions;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Extensions\ExtensionSettingsDefinition;
use RustixDev\Registration\Captcha\NoCaptcha;
use RustixDev\Registration\Console\PruneUnverifiedCommand;
use RustixDev\Registration\Contracts\VerifiesHumans;
use RustixDev\Registration\Fields\VerificationFields;
use RustixDev\Registration\Http\Middleware\RequireVerifiedEmail;
use RustixDev\Registration\Listeners\SkipAccountCreatedMail;
use RustixDev\Registration\Models\Signup;
use RustixDev\Registration\Sso\ProviderRegistry;

class RegistrationProvider extends ExtensionProvider
{
    public function register(): void
    {
        $this->app->bind(RegistrationSettings::class, fn (): RegistrationSettings => new RegistrationSettings($this->settings()));

        // A captcha extension binds its own; see Contracts\VerifiesHumans.
        $this->app->bindIf(VerifiesHumans::class, NoCaptcha::class);

        $this->app->bind(ProviderRegistry::class, fn (): ProviderRegistry => new ProviderRegistry($this->settings()));
    }

    public function boot(): void
    {
        $this->registerWebRoutes($this->extensionPath('routes', 'web.php'));
        $this->registerRootRoutes($this->extensionPath('routes', 'root.php'));
        $this->registerAdminApiRoutes($this->extensionPath('routes', 'admin.php'));

        $this->registerSettings(new ExtensionSettingsDefinition($this->settings(), RegistrationSettings::definitions()));

        // The verification status and its buttons on the admin user page.
        $this->registerFields(User::class, VerificationFields::class);

        // Deletes accounts that never verified, once "Delete unverified accounts after" is set.
        $this->registerCommands([PruneUnverifiedCommand::class]);
        $this->registerSchedule(function (Schedule $schedule): void {
            $schedule->command('registration:prune-unverified')->hourly()->withoutOverlapping(30);
        });

        // The panel has no extension API for these yet: the middleware and the listener are
        // added directly, and both check that the extension is available on every call.
        $router = $this->app->make(Router::class);
        $router->pushMiddlewareToGroup('web', RequireVerifiedEmail::class);
        $router->pushMiddlewareToGroup('api', RequireVerifiedEmail::class);

        Event::listen(NotificationSending::class, [SkipAccountCreatedMail::class, 'handle']);

        // An account created with an external account has a random password until its owner
        // sets one; until then its last connection cannot be removed.
        User::updated(function (User $user): void {
            if ($user->wasChanged('password') && Extensions::isAvailable(RegistrationSettings::EXTENSION)) {
                Signup::query()->whereKey($user->id)->where('random_password', true)->update(['random_password' => false]);
            }
        });
    }
}
