# Registration for Pterodactyl

Let people create their own panel account from the login page, with email verification, allowed and blocked email domains, an optional terms of service checkbox, and sign-up with the Single Sign-On extension.

## Features

- **A sign-up page** linked under the login form ("Don't have an account? Create one"), also at `/register`. It uses the panel's sign-in look and the active theme, Theme Studio included, and fits phone screens.
- **Off by default.** Nothing changes on the login page until you turn on **Allow sign-ups**.
- **Email verification** (on by default). The new account gets a link valid for 24 hours. Until it is opened, signing in only shows a "Verify your email" screen with **Send the link again** and **Sign out**. Root admins and accounts an admin creates are never held back.
- **Email domains**: only allow some domains (your company's, say), and block others such as disposable mail services. A domain covers its subdomains.
- **Terms of service**: set a link and people must tick "I agree to the terms of service" first.
- **The panel's own rules**: accounts are created by the panel's user creation, with its username, email and password rules. They are normal users in **Admin → Users**, and the panel's two-factor requirement applies to them like everyone else.
- **Abuse protection**: sign-ups per hour from one IP address (5 by default), limits on sending links again, a hidden honeypot field, and the same answer whether or not an address already has an account. When it does, its owner gets an email saying someone tried to sign up with it.
- **Activity log**: `registration:signup` and `registration:verified`, beside the panel's own `user:user.create`.
- **Single Sign-On**: with the [Single Sign-On](https://github.com/pterodactyl/SSO-Extension) extension enabled, the page offers **Sign up with Discord**, **GitHub**, **Google** and every other provider it has turned on. See below.

## Install

1. Download `registration-1.0.0.pteroext` from the [latest release](https://github.com/Rustix-Development/pterodactyl-sso-registration/releases/latest).
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
| Sign up with Single Sign-On | On | Offers the Single Sign-On providers on the sign-up page. |

## Single Sign-On

When the Single Sign-On extension (id `sso`) is enabled and has providers turned on, the sign-up page shows a **Sign up with ...** button for each. The button goes through Single Sign-On's own sign-in, and when the external account is not linked to any panel account yet, Single Sign-On asks this extension to create one:

- Only sign-ups started from the sign-up page create accounts. **Continue with ...** on the login page keeps signing in only, as before.
- The terms checkbox and the email domain rules apply. Providers that share no email address (Steam) cannot be used to sign up.
- An address that already has an account is not taken over: the person is asked to sign in and connect the provider from **Account → Connections**.
- If the provider confirms the email address, the account skips the verification email. Otherwise it is verified like any other.
- The username comes from the external account's name, made unique. The account has a random password; the person keeps signing in with the provider, or sets a password with **Forgot password?**.

This needs a Single Sign-On version with the `Sso\Contracts\ProvisionsAccounts` contract. With an older one, or without it installed, the buttons are not shown and everything else works the same.

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
| POST | `/extensions/registration/signup`, `/resend`, `/sso/{provider}` | Visitors who are not signed in |
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

`npm run test:php` runs against a panel checkout where the extension is installed and enabled; set `SSO` to a Single Sign-On checkout to include its checks. Package the build with `php artisan p:extension:pack`, or let the release workflow build the `.pteroext`.

## Uninstall

Under **Admin → Extensions**, disable Registration and click **Remove**. Accounts people created stay as normal users.

## License

MIT. See [LICENSE](LICENSE).

This is a community extension and is not affiliated with or endorsed by Pterodactyl.
