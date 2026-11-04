# Registration for Pterodactyl

Let people create their own panel account from the login page, with email verification, allowed and blocked email domains, an optional terms of service checkbox, and sign-up and sign-in with Discord, GitHub, Google, Microsoft, GitLab, Twitch, Steam or any OpenID Connect provider (Cloudflare Access, Authentik, Keycloak, Okta and so on).

## Features

- **A sign-up page** linked under the login form ("Don't have an account? Create one"), also at `/register`. It uses the panel's sign-in look and the active theme, Theme Studio included, and fits phone screens.
- **Off by default.** Nothing changes on the login page until you turn on **Allow sign-ups**.
- **Email verification** (on by default). The new account gets a link valid for 24 hours. Until it is opened, signing in only shows a "Verify your email" screen with **Send the link again** and **Sign out**. Root admins and accounts an admin creates are never held back.
- **Email domains**: only allow some domains (your company's, say), and block others such as disposable mail services. A domain covers its subdomains.
- **Terms of service**: set a link and people must tick "I agree to the terms of service" first.
- **The panel's own rules**: accounts are created by the panel's user creation, with its username, email and password rules. They are normal users in **Admin → Users**, and the panel's two-factor requirement applies to them like everyone else.
- **Abuse protection**: sign-ups per hour from one IP address (5 by default), limits on sending links again, a hidden honeypot field, and the same answer whether or not an address already has an account. When it does, its owner gets an email saying someone tried to sign up with it.
- **Sign up and sign in with external accounts**, built in: Discord, GitHub, Google, Microsoft, GitLab (self-hosted too), Twitch, Steam and any OpenID Connect provider with your own name and icon on its button. **Sign up with ...** on the sign-up page, **Continue with ...** under the login form, and **Account → Connected accounts** to connect and disconnect them. See [External accounts](#external-accounts).
- **Admin tools**: the admin user page shows whether an account verified its email, with **Mark as verified** and **Resend verification email**.
- **Cleanup**: optionally delete accounts that never verified their email after a number of days.
- **Activity log**: `registration:signup`, `registration:verified`, `registration:resent`, `registration:pruned`, `registration:sso.link` and `registration:sso.unlink`, beside the panel's own `user:user.create`.

## Install

1. Download `registration-1.1.0.pteroext` from the [latest release](https://github.com/Rustix-Development/pterodactyl-sso-registration/releases/latest).
2. In the panel, open **Admin → Extensions**, click **Install** and upload the file. Enable it.
3. Click **Settings** on the Registration card and turn on **Allow sign-ups**. Set the other options while you are there.
4. If you keep email verification on, check that the panel can send mail under **Admin → Settings → Mail**.

Requires Pterodactyl Panel v2.0.0-beta.1 or later and PHP 8.3+.

## Settings

| Setting | Default | |
|---|---|---|
| Allow sign-ups | Off | Shows the link on the login page and opens the sign-up page. |
| Require email verification | On | New accounts must open the emailed link before they can use the panel. Turning it off releases accounts that are still waiting. |
| Allowed email domains | Empty | Only these domains (and their subdomains) can sign up. Empty allows every domain. |
| Blocked email domains | Empty | These domains (and their subdomains) cannot sign up. |
| Terms of service link | Empty | Adds a required "I agree" checkbox linking here. |
| Sign-ups per hour from one address | 5 | Attempts from one IP address in an hour, failed ones included. |
| Sign up with external accounts | On | Offers **Sign up with ...** for the providers turned on in their tabs. |
| Leave sign-in buttons to the Single Sign-On extension | On | Only while the [Single Sign-On](https://github.com/pterodactyl/SSO-Extension) extension is enabled: the login page shows its buttons instead of these, so there is one set. |
| Delete unverified accounts after (days) | 0 | Deletes accounts created on the sign-up page that still have not verified their email after this many days, unless they own a server or are an admin. Checked hourly by the panel's scheduler while verification is required. 0 never deletes. |

Each provider has its own tab with **Enabled**, its client ID and secret, and the callback URL to copy into the provider. Client secrets and the Steam API key are stored encrypted.

## Admin user page

**Admin → Users → a user** has a **Registration** card: whether the email address is verified, how the account was created (sign-up page, an external account, or by an admin) and when it was verified. While it is waiting, **Mark as verified** lets the account in at once and **Resend verification email** sends a new link (it shares the user's own limit of 3 every 10 minutes). Both are logged with the admin who did it.

## Cleanup

Set **Delete unverified accounts after (days)** to remove sign-ups that never verified. The panel's scheduler runs `registration:prune-unverified` every hour (`php artisan registration:prune-unverified --dry-run` lists what it would delete). Only accounts created through sign-up that are still unverified, own no servers and are not admins are deleted, through the panel's own user deletion, and each one leaves a `registration:pruned` activity entry with its username and address. Nothing is deleted while the setting is 0 or email verification is off.

## External accounts

Turn providers on under **Settings**, one tab each. Every provider sends people back to one exact callback URL, shown in its tab:

```
https://<your panel>/extensions/registration/sso/<provider>/callback
```

| Provider | Where to create the application | Notes |
|---|---|---|
| Discord | [Developer Portal](https://discord.com/developers/applications) → your app → **OAuth2** → **Redirects** | Scopes `identify email`. Discord's verified address skips the verification email. |
| GitHub | [Settings → Developer settings → OAuth Apps](https://github.com/settings/developers) → **Authorization callback URL** | Uses the primary verified address. |
| Google | [Google Cloud Console → Credentials](https://console.cloud.google.com/apis/credentials) → **OAuth client ID** (Web application) → **Authorized redirect URIs** | |
| Microsoft | [Microsoft Entra → App registrations](https://entra.microsoft.com) → New registration, "Accounts in any organizational directory and personal Microsoft accounts", **Web** redirect URI → create a client secret | Common tenant. Microsoft does not say whether an address is confirmed, so new accounts verify it. |
| GitLab | Your GitLab → **Preferences → Applications** (scope `read_user`) | Set **GitLab address** for a self-hosted GitLab. |
| Twitch | [Twitch Developer Console](https://dev.twitch.tv/console/apps) → Register Your Application (Confidential) → **OAuth Redirect URLs** | |
| Steam | No application needed. Optionally a [Web API key](https://steamcommunity.com/dev/apikey) for the Steam name. | Steam shares no email address: signing up asks for one, which is then verified. |
| OpenID Connect | Your identity provider: Cloudflare Zero Trust (**Access → Applications → SaaS, OIDC**), Authentik (**OAuth2/OpenID Provider**), Keycloak (a confidential client), Okta, Auth0, Zitadel... | Set the **Issuer URL** (the address in front of `/.well-known/openid-configuration`), a **Button name** such as "Cloudflare" and an icon. |

How it behaves:

- **Sign up** with **Sign up with ...** on the sign-up page creates the account and connects the external account. Sign-ups must be open, the terms checkbox and the email domain rules apply. A provider-confirmed email address skips the verification email; with no address or an unconfirmed one (Steam, Microsoft, or OpenID Connect with **Trust confirmed email addresses** off) the person gets a short **Finish your account** step to enter an address, which is then verified. The username comes from the external account's name, made unique, and the account gets a random password until its owner sets one with **Forgot password?**.
- **Sign in** with **Continue with ...** under the login form works for external accounts already connected to an account. Two-factor accounts still go through the panel's two-factor checkpoint.
- **No take-overs.** An external account is never connected to an existing account on its own, even when the addresses match. Signing in with an unconnected one says to sign in another way and connect it under **Account → Connected accounts** (the same answer whether or not the address has an account); signing up with an address that has an account says to sign in and connect instead.
- **Account → Connected accounts** lists the providers with **Connect** and **Disconnect**. The last connection of an account that has no password of its own cannot be removed.
- **Security**: a random state and nonce per trip, PKCE where the provider supports it (all but Twitch), exact callback URLs, OpenID Connect ID tokens checked against the provider's published keys (RS256/384/512, ES256/384, cached for an hour) along with issuer, audience, expiry and nonce, Steam answers checked with Steam, rate limits on starting and finishing, and connecting only through a POST from the signed-in page.
- **With the Single Sign-On extension** also enabled, its **Continue with** buttons stay on the login page and this extension's are left out (turn off **Leave sign-in buttons to the Single Sign-On extension** to swap). Sign-up and Connected accounts keep using the providers configured here, and connections are stored separately in `ext_registration_identities`.

## How it works

Pterodactyl 2.0 has no sign-up and no public pages for extensions, so:

- The panel gives visitors who are not signed in extension slots but no pages. The sign-up form renders in the `auth.login.before` slot at `/auth/login?signup=1`, and a stylesheet rule hides the login form beside it. A panel update that changes the login page layout could show both; the rest of the extension is unaffected.
- The verification requirement is a middleware added to the panel's `web` and `api` middleware groups. It only affects accounts this extension created that have not verified their address, and checks that the extension is enabled on every request.

## Captcha

No captcha is included. A captcha extension can implement `RustixDev\Registration\Contracts\VerifiesHumans` and bind it in its provider:

```php
$this->app->bind(\RustixDev\Registration\Contracts\VerifiesHumans::class, MyCaptcha::class);
```

The sign-up form has an empty `<div data-registration-captcha>` for its widget, and every field of the form reaches `verify()` with the request. The panel's own reCAPTCHA is not used, because extensions cannot reach its widget.

## Routes

| Method | Path | Who |
|---|---|---|
| GET | `/register` | Anyone (redirects to the sign-up page) |
| GET | `/extensions/registration/options` | Anyone |
| POST | `/extensions/registration/signup`, `/resend`, `/sso/{provider}/start`, `/sso/finish` | Visitors who are not signed in |
| GET | `/extensions/registration/sso/pending`, `/sso/checkpoint` | Visitors who are not signed in |
| GET | `/extensions/registration/sso/{provider}/callback` | The provider, sending the browser back |
| GET, POST, DELETE | `/extensions/registration/connections`, `/connections/{provider}` | The signed-in user |
| GET, POST | `/api/admin/extensions/registration/users/{id}`, `/users/{id}/verify`, `/users/{id}/resend` | Root admins |
| GET | `/extensions/registration/verify/{user}/{hash}` | The signed link from the email |
| GET, POST | `/extensions/registration/verification` | The signed-in user |

## Build from source

```sh
npm ci
npm run typecheck
npm test
npm run build
PANEL=/path/to/panel npm run test:php
```

`npm run test:php` runs against a panel checkout where the extension is installed and enabled. It uses an in-memory database and fakes every provider's HTTP. Package the build with `php artisan p:extension:pack`, or let the release workflow build the `.pteroext`.

## Uninstall

Under **Admin → Extensions**, disable Registration and click **Remove**. Accounts people created stay as normal users.

## License

MIT. See [LICENSE](LICENSE).

This is a community extension and is not affiliated with or endorsed by Pterodactyl.
