<?php

namespace RustixDev\Registration;

use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Pterodactyl\Extensions\ExtensionProvider;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Extensions\ExtensionSettingsDefinition;
use RustixDev\Registration\Captcha\NoCaptcha;
use RustixDev\Registration\Contracts\VerifiesHumans;
use RustixDev\Registration\Fields\VerificationFields;
use RustixDev\Registration\Http\Middleware\RequireVerifiedEmail;
use RustixDev\Registration\Listeners\SkipAccountCreatedMail;
use RustixDev\Registration\Sso\SsoProviders;
use RustixDev\Registration\Sso\SsoProvisioner;

class RegistrationProvider extends ExtensionProvider
{
    public function register(): void
    {
        $this->app->bind(RegistrationSettings::class, fn (): RegistrationSettings => new RegistrationSettings($this->settings()));

        // A captcha extension binds its own; see Contracts\VerifiesHumans.
        $this->app->bindIf(VerifiesHumans::class, NoCaptcha::class);

        // Single Sign-On asks for this when an external account has no panel account yet. The
        // class is only loaded when Single Sign-On resolves it, so it is safe without it.
        $this->app->bind(SsoProviders::CONTRACT, SsoProvisioner::class);
    }

    public function boot(): void
    {
        $this->registerWebRoutes($this->extensionPath('routes', 'web.php'));
        $this->registerRootRoutes($this->extensionPath('routes', 'root.php'));
        $this->registerAdminApiRoutes($this->extensionPath('routes', 'admin.php'));

        $this->registerSettings(new ExtensionSettingsDefinition($this->settings(), RegistrationSettings::definitions()));

        // The verification status and its buttons on the admin user page.
        $this->registerFields(User::class, VerificationFields::class);

        // The panel has no extension API for these yet: the middleware and the listener are
        // added directly, and both check that the extension is available on every call.
        $router = $this->app->make(Router::class);
        $router->pushMiddlewareToGroup('web', RequireVerifiedEmail::class);
        $router->pushMiddlewareToGroup('api', RequireVerifiedEmail::class);

        Event::listen(NotificationSending::class, [SkipAccountCreatedMail::class, 'handle']);
    }
}
