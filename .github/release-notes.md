Public sign-up for Pterodactyl Panel v2, now with built-in sign-in with external accounts.

**New in 1.1.0**

- **Sign up and sign in with** Discord, GitHub, Google, Microsoft, GitLab, Twitch, Steam or any OpenID Connect provider (Cloudflare Access, Authentik, Keycloak, Okta...), with no other extension needed. Each provider has its own settings tab with the callback URL to copy.
- **Account → Connected accounts** to connect and disconnect providers. Existing accounts are never taken over by a matching email address.
- Two-factor accounts still go through the panel's checkpoint after signing in with a provider.
- **Admin user page**: see whether an account verified its email, **Mark as verified** or **Resend verification email**.
- **Cleanup**: optionally delete accounts that never verified after a number of days (never by default).
- With the Single Sign-On extension also installed, the login page keeps its buttons only, so there is one set.

**Everything else**

- Off until you turn it on under **Admin → Extensions → Registration → Settings**.
- Email verification (on by default), allowed and blocked email domains, an optional terms of service checkbox, the panel's own username and password rules.
- Rate limits, a honeypot field and answers that don't tell whether an address already has an account.

## Install

Download `registration-${VERSION}.pteroext` below, upload it under **Admin → Extensions** with **Install**, enable it, then open its **Settings** and turn on **Allow sign-ups**. Updating from 1.0.0: install it over the old one; the new table is created when it is enabled.

Requires Pterodactyl Panel v2.0.0-beta.1 or later and PHP 8.3+. The panel must be able to send mail for email verification.

This is a community extension and is not affiliated with or endorsed by Pterodactyl.
