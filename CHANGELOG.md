# Changelog

## 1.1.0

- Built-in sign-up and sign-in with external accounts, no other extension needed: Discord, GitHub, Google, Microsoft (common tenant), GitLab (with a custom address for self-hosted), Twitch, Steam and any OpenID Connect provider (Cloudflare Access, Authentik, Keycloak, Okta and so on) with its own button name and icon. Each provider has its own settings section with the callback URL to copy; client secrets are stored encrypted.
- **Sign up with ...** on the sign-up page and **Continue with ...** under the login form. Two-factor accounts still go through the panel's checkpoint.
- Providers that share no confirmed email address (Steam always) get a short "Finish your account" step for an address, which is then verified.
- An external account is never connected to an existing account on its own: people are told to sign in and connect it under the new **Account → Connected accounts** page, which also disconnects them. The last way into an account without a password cannot be removed.
- OpenID Connect ID tokens are checked against the provider's published keys (RSA and EC, cached for an hour) with issuer, audience, expiry and nonce. State, nonce and PKCE on every trip, Steam answers checked with Steam, and rate limits on starting and finishing.
- With the Single Sign-On extension also enabled, a new setting (on by default) leaves the login page's buttons to it so there is one set; sign-up still uses these providers. The 1.0 hook into Single Sign-On is replaced by the built-in providers.
- The admin user page shows whether the account verified its email, with **Mark as verified** and **Resend verification email**.
- New setting **Delete unverified accounts after (days)** (0, never, by default): the panel's scheduler deletes sign-ups that never verified, unless they own a server or are an admin, and logs `registration:pruned`. `php artisan registration:prune-unverified --dry-run` lists them.

## 1.0.0

- First release: a "Create an account" page reached from the login screen ("Don't have an account? Create one"), or at `/register`. It uses the panel's sign-in look, the active theme and works on phones.
- Settings under Admin → Extensions → Registration: allow sign-ups (off by default), require email verification (on by default), allowed and blocked email domains, a terms of service link with a checkbox, sign-ups per hour from one address, and Single Sign-On sign-ups.
- Accounts are created by the panel's own user creation, so its username, email and password rules, its activity entry and any extension that extends it all apply. New accounts are normal users and show in Admin → Users.
- Email verification: a signed link valid for 24 hours. Until it is opened the account only sees a "Verify your email" screen with a button to send the link again. The panel's "account created" email is skipped for these accounts so the link is the only email.
- Rate limits on sign-ups and on sending links again, a hidden honeypot field, and the same answer whether or not an address already has an account (its owner is emailed instead).
- The panel's two-factor requirement applies to new accounts as it does to every other.
- `registration:signup` and `registration:verified` activity entries.
- A `VerifiesHumans` contract a captcha extension can implement; none is included.
- With a Single Sign-On extension version that has the `ProvisionsAccounts` contract: "Sign up with ..." buttons for each of its enabled providers. The domain rules and terms apply, and a provider that confirms the email address skips the verification email.
