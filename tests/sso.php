<?php

// Sign-in, sign-up and connections with external accounts. Required by registration.php,
// which sets up the panel, the in-memory database and $memory, $settings, $signups and check().

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Pterodactyl\Models\User;
use RustixDev\Registration\Http\Controllers\SignupController;
use RustixDev\Registration\Http\Controllers\SsoController;
use RustixDev\Registration\Models\Identity as IdentityModel;
use RustixDev\Registration\Models\Signup;
use RustixDev\Registration\Notifications\SignupAttempted;
use RustixDev\Registration\Notifications\VerifyEmail;
use RustixDev\Registration\RegistrationSettings;
use RustixDev\Registration\Services\Verification;
use RustixDev\Registration\Sso\Jwt;
use RustixDev\Registration\Sso\ProviderRegistry;
use RustixDev\Registration\Sso\Providers\SteamProvider;
use RustixDev\Registration\Sso\SsoService;

config(['app.url' => 'https://panel.test']);

/** Fakes provider HTTP with only these answers, dropping earlier ones. */
function fakeHttp(array $answers): void
{
    Http::swap(new Illuminate\Http\Client\Factory(app('events')));
    Http::preventStrayRequests();
    Http::fake($answers);
}
$issuer = 'https://idp.test/realms/main';
$memory->values = [
    'enabled' => true,
    'blocked_domains' => ['mailinator.com'],
    'terms_url' => 'https://example.com/terms',
    'sso_discord_enabled' => true, 'sso_discord_client_id' => 'discord-id', 'sso_discord_client_secret' => 'discord-secret',
    'sso_github_enabled' => true, 'sso_github_client_id' => 'gh-id', 'sso_github_client_secret' => 'gh-secret',
    'sso_google_enabled' => false, 'sso_google_client_id' => 'g-id', 'sso_google_client_secret' => 'g-secret',
    'sso_oidc_enabled' => true, 'sso_oidc_client_id' => 'panel', 'sso_oidc_client_secret' => 'oidc-secret', 'sso_oidc_issuer' => $issuer, 'sso_oidc_name' => 'Cloudflare', 'sso_oidc_icon' => 'cloud',
    'sso_steam_enabled' => true,
];
$registry = new ProviderRegistry($memory);
app()->instance(ProviderRegistry::class, $registry);
$sso = app(SsoService::class);
foreach (['registration:signup:10.1.0.1', 'registration:sso:start:10.1.0.1', 'registration:sso:callback:10.1.0.1'] as $key) {
    RateLimiter::clear($key);
}
Cache::flush();

check('providers: only enabled and configured ones are offered, with the OIDC name and icon', array_column($registry->describe(), 'id') === ['discord', 'github', 'steam', 'oidc']
    && $registry->describe()[3] === ['id' => 'oidc', 'name' => 'Cloudflare', 'icon' => 'cloud']);
check('providers: the callback is an exact address on the panel', ProviderRegistry::callback('discord') === 'https://panel.test/extensions/registration/sso/discord/callback');
$definitions = collect(RegistrationSettings::definitions())->keyBy(fn ($definition) => $definition->key);
check('settings: client secrets are stored encrypted', $definitions['sso_discord_client_secret']->isSecret() && $definitions['sso_oidc_client_secret']->isSecret() && $definitions['sso_steam_api_key']->isSecret());
check('settings: the help shows the callback to copy', str_contains($definitions['sso_github_client_id']->describe(null)['help'] ?? '', 'https://panel.test/extensions/registration/sso/github/callback'));

// One browser: a session that lives across the start and the callback.
$session = app('session')->driver();
$session->flush();
$visit = function (string $uri, string $method = 'GET', array $data = [], ?User $user = null) use ($session): Request {
    $request = Request::create($uri, $method, $data, server: ['REMOTE_ADDR' => '10.1.0.1']);
    $request->setLaravelSession($session);
    app()->instance('request', $request);
    $request->setUserResolver(fn () => $user ?? Auth::guard()->user());

    return $request;
};
$state = function (string $url): string {
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return $query['state'] ?? (isset($query['openid_return_to']) ? (function () use ($query) {
        parse_str((string) parse_url($query['openid_return_to'], PHP_URL_QUERY), $inner);

        return $inner['state'];
    })() : '');
};
$signOut = function () use ($session): void {
    Auth::guard()->logout();
    $session->flush();
};
// Starts a trip and comes back with the given query; returns where the browser goes.
$trip = function (string $provider, string $intent, array $query = [], bool $terms = true, ?User $user = null) use ($sso, $visit, $state): string {
    $url = $sso->start($visit('/extensions/registration/sso/'.$provider.'/start', 'POST', user: $user), $provider, $intent, $terms);

    return $sso->callback($visit('/extensions/registration/sso/'.$provider.'/callback?'.http_build_query(['code' => 'the-code', 'state' => $state($url), ...$query]), user: $user), $provider);
};
$discord = fn (array $user) => fakeHttp([
    'https://discord.com/api/oauth2/token' => Http::response(['access_token' => 'discord-token', 'token_type' => 'Bearer']),
    'https://discord.com/api/users/@me' => Http::response(['id' => '80351110224678912', 'username' => 'nelly', 'global_name' => 'Nelly Smith', 'avatar' => null, ...$user]),
]);

// --- OAuth 2.0 with a verified email -------------------------------------------------------
$url = $sso->start($visit('/x', 'POST'), 'discord', 'signup', true);
parse_str((string) parse_url($url, PHP_URL_QUERY), $authorize);
check('oauth: the authorize address has the exact callback, state and a PKCE challenge', str_starts_with($url, 'https://discord.com/oauth2/authorize?') && $authorize['redirect_uri'] === ProviderRegistry::callback('discord')
    && strlen($authorize['state']) === 40 && $authorize['code_challenge_method'] === 'S256' && strlen($authorize['code_challenge']) === 43);
check('oauth: a callback with the wrong state is refused', str_contains($sso->callback($visit('/cb?code=x&state=nope'), 'discord'), 'rxrg_sso=state'));
check('oauth: a callback with no trip started is refused', str_contains($sso->callback($visit('/cb?code=x&state='.$authorize['state']), 'discord'), 'rxrg_sso=state'));

$discord(['email' => 'nelly@mailinator.com', 'verified' => true]);
check('signup: the blocked domains apply', str_contains($trip('discord', 'signup'), 'signup=1&rxrg_sso=refused'));
check('signup: the terms must be accepted', str_contains($trip('discord', 'signup', terms: false), 'rxrg_sso=refused'));
$discord(['email' => 'new@example.com', 'verified' => true]);
$before = User::query()->count();
check('signup: an address with an account is told to sign in and connect instead', str_contains($trip('discord', 'signup'), 'rxrg_sso=exists') && User::query()->count() === $before);
check('login: an unconnected external account is never connected on its own, even when its address has an account', str_contains($trip('discord', 'login'), 'rxrg_sso=unlinked') && IdentityModel::query()->count() === 0);
$discord(['email' => 'nelly@discord.test', 'verified' => true]);
$path = $trip('discord', 'signup');
$nelly = User::query()->where('email', 'nelly@discord.test')->first();
$row = Signup::query()->find($nelly?->id);
check('signup: a verified email creates a verified, connected account and signs it in', $path === '/' && $nelly instanceof User && Auth::guard()->id() === $nelly->id && $row?->source === 'sso:discord'
    && $row->verified_at !== null && $row->random_password && IdentityModel::query()->where('user_id', $nelly->id)->where('provider', 'discord')->where('subject', '80351110224678912')->exists());
check('oauth: the code is traded with the PKCE verifier and the exact callback', Http::recorded(fn ($request) => $request->url() === 'https://discord.com/api/oauth2/token' && is_string($request['code_verifier']) && $request['redirect_uri'] === ProviderRegistry::callback('discord'))->isNotEmpty());
$signOut();
check('login: a connected account signs in', $trip('discord', 'login') === '/' && Auth::guard()->id() === $nelly->id);
$signOut();
check('login: cancelling on the provider is reported', str_contains($trip('discord', 'login', ['error' => 'access_denied']), 'rxrg_sso=denied'));

// --- Two-factor --------------------------------------------------------------------------
$nelly->forceFill(['use_totp' => true, 'totp_secret' => 'x'])->save();
$path = $trip('discord', 'login');
$token = json_decode(app(SsoController::class)->checkpoint($visit('/extensions/registration/sso/checkpoint'), app(Pterodactyl\Contracts\Users\CompletesLogins::class))->getContent(), true)['data']['confirmation_token'] ?? null;
check('2fa: a two-factor account goes through the panel\'s checkpoint and is not signed in yet', $path === '/auth/login?rxrg_sso=checkpoint' && Auth::guard()->guest() && is_string($token) && $token !== '');
$nelly->forceFill(['use_totp' => false, 'totp_secret' => null])->save();
$signOut();
check('2fa: without a pending checkpoint there is no token', app(SsoController::class)->checkpoint($visit('/x'), app(Pterodactyl\Contracts\Users\CompletesLogins::class))->getStatusCode() === 404);

// --- OAuth 2.0 without a verified email ----------------------------------------------------
fakeHttp([
    'https://github.com/login/oauth/access_token' => Http::response(['access_token' => 'gh-token']),
    'https://api.github.com/user/emails' => Http::response([['email' => 'octo@example.net', 'primary' => true, 'verified' => false]]),
    'https://api.github.com/user' => Http::response(['id' => 583231, 'login' => 'octocat', 'name' => 'The Octocat']),
]);
Notification::fake();
$path = $trip('github', 'signup');
$pending = $sso->pending($visit('/x'));
check('signup: without a verified email the person finishes with an address', $path === SsoService::FINISH_PATH && $pending === ['provider' => 'github', 'name' => 'GitHub', 'email' => null] && User::query()->where('username', 'theoctocat')->doesntExist());
check('finish: the domain rules apply', (function () use ($sso, $visit) {
    try {
        $sso->finish($visit('/x', 'POST', ['email' => 'o@mailinator.com']));
    } catch (ValidationException $exception) {
        return ($exception->errors()['email'][0] ?? null) === RustixDev\Registration\Services\SignupService::DOMAIN_MESSAGE;
    }

    return false;
})());
$result = $sso->finish($visit('/x', 'POST', ['email' => 'octo@example.net']));
$octo = User::query()->where('email', 'octo@example.net')->first();
check('finish: creates the connected account, which verifies the address', $result === ['status' => 'verify', 'email' => 'octo@example.net'] && $octo instanceof User && $verification->pending($octo)
    && Notification::sent($octo, VerifyEmail::class)->isNotEmpty() && IdentityModel::query()->where('user_id', $octo->id)->where('provider', 'github')->where('subject', '583231')->exists() && Auth::guard()->guest());
check('finish: works once', $sso->pending($visit('/x')) === null);

// --- Steam (OpenID 2.0, no email) ----------------------------------------------------------
// Starts a Steam sign-up and comes back with Steam's answer, changed by $over.
$steamTrip = function (array $over = []) use ($sso, $visit, $state): string {
    $url = $sso->start($visit('/x', 'POST'), 'steam', 'signup', true);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $id = 'https://steamcommunity.com/openid/id/76561197960287930';
    $assertion = [
        'openid.ns' => 'http://specs.openid.net/auth/2.0', 'openid.mode' => 'id_res', 'openid.op_endpoint' => SteamProvider::ENDPOINT,
        'openid.claimed_id' => $id, 'openid.identity' => $id, 'openid.return_to' => $query['openid_return_to'],
        'openid.response_nonce' => gmdate('Y-m-d\TH:i:s\Z').'abc', 'openid.assoc_handle' => '1234567890',
        'openid.signed' => 'signed,op_endpoint,claimed_id,identity,return_to,response_nonce,assoc_handle', 'openid.sig' => 'c2ln', ...$over,
    ];

    return $sso->callback($visit('/extensions/registration/sso/steam/callback?state='.$state($url).'&'.http_build_query($assertion, '', '&', PHP_QUERY_RFC3986)), 'steam');
};
fakeHttp([SteamProvider::ENDPOINT => Http::response("ns:http://specs.openid.net/auth/2.0\nis_valid:false\n")]);
check('steam: an answer Steam did not sign is refused', str_contains($steamTrip(), 'rxrg_sso=provider'));
fakeHttp([SteamProvider::ENDPOINT => Http::response("ns:http://specs.openid.net/auth/2.0\nis_valid:true\n")]);
check('steam: an answer for another return address is refused', str_contains($steamTrip(['openid.return_to' => 'https://evil.test/cb']), 'rxrg_sso=provider'));
check('steam: an answer for another Steam endpoint is refused', str_contains($steamTrip(['openid.op_endpoint' => 'https://evil.test/openid/login']), 'rxrg_sso=provider'));
check('steam: a checked answer with no email asks for one', $steamTrip() === SsoService::FINISH_PATH && $sso->pending($visit('/x'))['provider'] === 'steam');
check('steam: the answer is checked with Steam', Http::recorded(fn ($request) => $request->url() === SteamProvider::ENDPOINT && str_contains($request->body(), 'openid.mode=check_authentication'))->isNotEmpty());
RateLimiter::clear('registration:attempted:'.$nelly->id);
$taken = $sso->finish($visit('/x', 'POST', ['email' => 'nelly@discord.test']));
check('finish: an address with an account gets the usual answer, nothing is created and its owner is told', $taken['status'] === 'verify' && IdentityModel::query()->where('provider', 'steam')->doesntExist() && Notification::sent($nelly, SignupAttempted::class)->isNotEmpty());

// --- OpenID Connect ------------------------------------------------------------------------
$key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
$other = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
$details = openssl_pkey_get_details($key)['rsa'];
$b64 = fn (string $value) => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
$jwt = function (array $claims, $signer, string $kid = 'k1') use ($b64): string {
    $input = $b64(json_encode(['alg' => 'RS256', 'kid' => $kid, 'typ' => 'JWT'])).'.'.$b64(json_encode($claims));
    openssl_sign($input, $signature, $signer, OPENSSL_ALGO_SHA256);

    return $input.'.'.$b64($signature);
};
$oidc = function (callable $claims) use ($issuer, $details, $b64, $session): void {
    fakeHttp([
        $issuer.'/.well-known/openid-configuration' => Http::response(['issuer' => $issuer, 'authorization_endpoint' => $issuer.'/auth', 'token_endpoint' => $issuer.'/token', 'jwks_uri' => $issuer.'/certs', 'userinfo_endpoint' => $issuer.'/userinfo']),
        $issuer.'/certs' => Http::response(['keys' => [['kty' => 'RSA', 'kid' => 'k1', 'use' => 'sig', 'alg' => 'RS256', 'n' => $b64($details['n']), 'e' => $b64($details['e'])]]]),
        $issuer.'/token' => fn () => Http::response(['access_token' => 'oidc-token', 'id_token' => $claims($GLOBALS['oidcNonce'] ?? 'none')]),
    ]);
};
$claims = fn (string $nonce, array $over = []) => ['iss' => $issuer, 'aud' => 'panel', 'sub' => 'kc-42', 'exp' => time() + 300, 'iat' => time(), 'nonce' => $nonce, 'email' => 'kim@corp.test', 'email_verified' => true, 'name' => 'Kim Lee', ...$over];
$oidcTrip = function (string $intent) use ($sso, $visit, $state): string {
    $url = $sso->start($visit('/x', 'POST'), 'oidc', $intent, true);
    // The provider puts the nonce it was sent into the ID token.
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $GLOBALS['oidcNonce'] = $query['nonce'];

    return $sso->callback($visit('/cb?code=c&state='.$state($url)), 'oidc');
};
$oidc(fn ($nonce) => $jwt($claims($nonce), $other));
check('oidc: an ID token signed with another key is refused', str_contains($oidcTrip('signup'), 'rxrg_sso=provider') && User::query()->where('email', 'kim@corp.test')->doesntExist());
$oidc(fn ($nonce) => $jwt($claims('another-nonce'), $key));
check('oidc: an ID token for another sign-in (nonce) is refused', str_contains($oidcTrip('signup'), 'rxrg_sso=provider'));
$oidc(fn ($nonce) => $jwt($claims($nonce, ['aud' => 'someone-else']), $key));
check('oidc: an ID token for another client is refused', str_contains($oidcTrip('signup'), 'rxrg_sso=provider'));
$oidc(fn ($nonce) => $jwt($claims($nonce, ['exp' => time() - 3600]), $key));
check('oidc: an expired ID token is refused', str_contains($oidcTrip('signup'), 'rxrg_sso=provider'));
$oidc(fn ($nonce) => $jwt($claims($nonce, ['iss' => 'https://evil.test']), $key));
check('oidc: an ID token from another issuer is refused', str_contains($oidcTrip('signup'), 'rxrg_sso=provider'));
$unsigned = fn ($nonce) => $b64(json_encode(['alg' => 'none'])).'.'.$b64(json_encode($claims($nonce))).'.';
$oidc($unsigned);
check('oidc: an unsigned ID token is refused', str_contains($oidcTrip('signup'), 'rxrg_sso=provider'));
$oidc(fn ($nonce) => $jwt($claims($nonce), $key));
$path = $oidcTrip('signup');
$kim = User::query()->where('email', 'kim@corp.test')->first();
check('oidc: a signed, verified ID token signs up a verified account', $path === '/' && $kim instanceof User && ! $verification->pending($kim) && IdentityModel::query()->where('user_id', $kim->id)->where('provider', 'oidc')->where('subject', 'kc-42')->exists());
check('oidc: the signing keys are cached', Cache::has('registration:sso:jwks:'.sha1($issuer.'/certs')));
$signOut();
$memory->values['sso_oidc_trust_email'] = false;
$oidc(fn ($nonce) => $jwt($claims($nonce, ['sub' => 'kc-43', 'email' => 'lee@corp.test']), $key));
check('oidc: with "trust confirmed addresses" off the address is asked for and verified', $oidcTrip('signup') === SsoService::FINISH_PATH && $sso->pending($visit('/x'))['email'] === 'lee@corp.test');
unset($memory->values['sso_oidc_trust_email']);
$signOut();
check('jwt: EC keys convert too', is_string(Jwt::pem(['kty' => 'EC', 'crv' => 'P-256', 'x' => $b64(str_repeat("\x01", 32)), 'y' => $b64(str_repeat("\x02", 32))])));

// --- Connections -------------------------------------------------------------------------
$discord(['id' => '99', 'email' => 'other@discord.test', 'verified' => true]);
$path = $trip('discord', 'link', user: $kim);
check('link: connects another provider to the signed-in account', str_contains($path, '/account/connected-accounts?rxrg_sso=linked') && IdentityModel::query()->where('user_id', $kim->id)->where('provider', 'discord')->where('subject', '99')->exists());
$discord(['email' => 'nelly@discord.test', 'verified' => true]);
check('link: an external account connected to someone else is refused', str_contains($trip('discord', 'link', user: $octo), 'rxrg_sso=taken') && IdentityModel::query()->where('user_id', $octo->id)->where('provider', 'discord')->doesntExist());
$url = $sso->start($visit('/x', 'POST', user: $kim), 'discord', 'link');
check('link: a trip started by one account cannot finish for another', str_contains($sso->callback($visit('/cb?code=c&state='.$state($url), user: $octo), 'discord'), 'rxrg_sso=state'));
$connections = $sso->connections($kim);
check('connections: lists the providers and what is connected', $connections['has_password'] === false && collect($connections['providers'])->firstWhere('id', 'discord')['connected'] === true && collect($connections['providers'])->firstWhere('id', 'github')['connected'] === false);
$sso->unlink($kim, 'discord');
check('unlink: removes one of several connections', IdentityModel::query()->where('user_id', $kim->id)->count() === 1 && DB::table('activity_logs')->where('event', 'registration:sso.unlink')->exists());
$lastWay = null;
try {
    $sso->unlink($kim, 'oidc');
} catch (ValidationException $exception) {
    $lastWay = $exception->errors()['provider'][0] ?? null;
}
check('unlink: the last way into an account without a password stays', is_string($lastWay) && IdentityModel::query()->where('user_id', $kim->id)->count() === 1);
$kim->forceFill(['password' => password_hash('A-real-password-1', PASSWORD_BCRYPT)])->save();
$sso->unlink($kim->fresh(), 'oidc');
check('unlink: once a password is set, the last connection can go', IdentityModel::query()->where('user_id', $kim->id)->count() === 0 && $sso->hasPassword($kim));

// --- Options and coexistence ---------------------------------------------------------------
$options = json_decode(app(SignupController::class)->options()->getContent(), true)['data'];
check('options: sign-up and login buttons for the enabled providers', array_column($options['providers'], 'id') === ['discord', 'github', 'steam', 'oidc'] && ($options['login_providers'] === [] || count($options['login_providers']) === 4));
$memory->values['sso_leave_login'] = false;
check('options: the login buttons show when not left to the Single Sign-On extension', count(json_decode(app(SignupController::class)->options()->getContent(), true)['data']['login_providers']) === 4);
$memory->values['sso_leave_login'] = true;
check('options: left to the Single Sign-On extension only while it is enabled', $settings->leavesLoginToSsoExtension() === Pterodactyl\Facades\Extensions::isAvailable('sso'));
$memory->values['sso'] = false;
check('options: no sign-up buttons with external sign-ups off', json_decode(app(SignupController::class)->options()->getContent(), true)['data']['providers'] === []);
$signOut();
