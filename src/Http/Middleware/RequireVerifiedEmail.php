<?php

namespace RustixDev\Registration\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pterodactyl\Facades\Extensions;
use Pterodactyl\Http\Middleware\RequireTwoFactorAuthentication;
use Pterodactyl\Models\User;
use RustixDev\Registration\RegistrationSettings;
use RustixDev\Registration\Services\Verification;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps an account that has not verified its address on the "Verify your email" screen:
 * pages redirect to it and everything else is refused, apart from signing in and out,
 * loading the page itself, and the verification routes.
 */
class RequireVerifiedEmail
{
    public const string CODE = 'RegistrationVerificationRequired';

    public const string SCREEN = '/account/verify-email';

    private const array WEB = [
        'auth/*',
        'locales/*',
        'sanctum/*',
        'extension-files/*',
        'extensions/registration/*',
        'extensions/sso/*',
        'account/verify-email',
    ];

    public function __construct(private readonly Verification $verification) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): mixed
    {
        $user = $request->user();
        if (! $user instanceof User || ! Extensions::isAvailable(RegistrationSettings::EXTENSION) || $this->allowed($request) || $this->needsTwoFactor($user) || ! $this->verification->pending($user)) {
            return $next($request);
        }

        if ($request->is('api/*') || $request->expectsJson() || ! $request->isMethodSafe()) {
            return new JsonResponse(['errors' => [[
                'code' => self::CODE,
                'status' => '409',
                'detail' => 'Verify your email address before you continue.',
            ]]], 409);
        }

        return redirect()->to(self::SCREEN);
    }

    private function allowed(Request $request): bool
    {
        if ($request->is(...self::WEB)) {
            return true;
        }

        // Stylesheets and images other extensions serve to the page, such as a theme's colours.
        return $request->isMethodSafe() && ! $request->expectsJson() && $request->is('extensions/*');
    }

    /**
     * The panel's two-factor requirement comes first, as it does for every other account: it
     * sends the user to their account page to turn it on and refuses everything else itself.
     */
    private function needsTwoFactor(User $user): bool
    {
        $level = config('pterodactyl.auth.2fa_required');

        return ! $user->use_totp && ($level === RequireTwoFactorAuthentication::LEVEL_ALL || ($level === RequireTwoFactorAuthentication::LEVEL_ADMIN && $user->root_admin));
    }
}
