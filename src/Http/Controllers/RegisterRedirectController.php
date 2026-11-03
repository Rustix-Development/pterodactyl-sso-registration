<?php

namespace RustixDev\Registration\Http\Controllers;

use Illuminate\Http\RedirectResponse;

/**
 * /register, a short address to share for the sign-up page.
 */
class RegisterRedirectController
{
    public function __invoke(): RedirectResponse
    {
        return redirect()->to('/auth/login?signup=1');
    }
}
