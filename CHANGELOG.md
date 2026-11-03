# Changelog

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
