<?php

namespace RustixDev\Registration\Contracts;

use Illuminate\Http\Request;

/**
 * A captcha check for the sign-up form. The extension ships none: another extension can
 * bind its own implementation in its provider's register():
 *
 *     $this->app->bind(VerifiesHumans::class, MyCaptcha::class);
 *
 * The sign-up page renders an empty element with the attribute data-registration-captcha
 * inside the form for a widget, and every field of the submitted form, including any the
 * widget adds, reaches verify() through the request.
 */
interface VerifiesHumans
{
    /**
     * Whether the request comes from a person. Returning false refuses the sign-up with
     * the message from message().
     */
    public function verify(Request $request): bool;

    public function message(): string;
}
