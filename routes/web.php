<?php

use Illuminate\Support\Facades\Route;
use RustixDev\Registration\Http\Controllers\SignupController;
use RustixDev\Registration\Http\Controllers\SsoSignupController;
use RustixDev\Registration\Http\Controllers\VerificationController;

// Public: the login and sign-up pages read this before anyone signs in.
Route::get('/options', [SignupController::class, 'options'])->name('options');

Route::middleware('guest')->group(function () {
    Route::post('/signup', [SignupController::class, 'store'])->name('signup');
    Route::post('/resend', [SignupController::class, 'resend'])->name('resend');
    Route::post('/sso/{provider}', SsoSignupController::class)->where('provider', '[a-z0-9-]+')->name('sso');
});

// The emailed link, signed and valid for a day. It works signed in or not.
Route::get('/verify/{user}/{hash}', [VerificationController::class, 'verify'])
    ->where(['user' => '[0-9]+', 'hash' => '[a-f0-9]{40}'])
    ->name('verify');

// The "Verify your email" screen. Only "auth": the panel's two-factor requirement would
// otherwise refuse these before the account can be used at all.
Route::middleware('auth')->group(function () {
    Route::get('/verification', [VerificationController::class, 'show'])->name('verification');
    Route::post('/verification', [VerificationController::class, 'resend'])->name('verification.resend');
});
