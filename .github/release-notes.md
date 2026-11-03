Public sign-up for Pterodactyl Panel v2: a "Create an account" page on the login screen.

- Off until you turn it on under **Admin → Extensions → Registration → Settings**.
- Email verification (on by default): new accounts can't use the panel until they open the emailed link, and can ask for a new one.
- Allowed and blocked email domains, an optional terms of service checkbox, the panel's own username and password rules.
- Rate limits, a honeypot field and answers that don't tell whether an address already has an account.
- With the Single Sign-On extension: "Sign up with Discord, GitHub, ..." buttons, under the same rules.

## Install

Download `registration-${VERSION}.pteroext` below, upload it under **Admin → Extensions** with **Install**, enable it, then open its **Settings** and turn on **Allow sign-ups**.

Requires Pterodactyl Panel v2.0.0-beta.1 or later and PHP 8.3+. The panel must be able to send mail for email verification.

This is a community extension and is not affiliated with or endorsed by Pterodactyl.
