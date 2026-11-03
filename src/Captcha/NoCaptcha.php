<?php

namespace RustixDev\Registration\Captcha;

use Illuminate\Http\Request;
use RustixDev\Registration\Contracts\VerifiesHumans;

/**
 * Used until another extension binds a captcha. The honeypot field and rate limits still apply.
 */
class NoCaptcha implements VerifiesHumans
{
    public function verify(Request $request): bool
    {
        return true;
    }

    public function message(): string
    {
        return 'The captcha could not be verified. Please try again.';
    }
}
