<?php

use Illuminate\Support\Facades\Route;
use RustixDev\Registration\Http\Controllers\ConnectionController;
use RustixDev\Registration\Http\Controllers\SignupController;
use RustixDev\Registration\Http\Controllers\SsoController;
use RustixDev\Registration\Http\Controllers\VerificationController;

// Public: the login and sign-up pages read this before anyone signs in.
Route::get('/options', [SignupController::class, 'options'])->name('options');

Route::middleware('guest')->group(function () {
    Route::post('/signup', [SignupController::class, 'store'])->name('signup');
    Route::post('/resend', [SignupController::class, 'resend'])->name('resend');

    // Signing in and up with an external account.
    Route::post('/sso/{provider}/start', [SsoController::class, 'start'])->where('provider', '[a-z0-9-]+')->name('sso.start');
    Route::get('/sso/pending', [SsoController::class, 'pending'])->name('sso.pending');
    Route::post('/sso/finish', [SsoController::class, 'finish'])->name('sso.finish');
    Route::get('/sso/checkpoint', [SsoController::class, 'checkpoint'])->name('sso.checkpoint');
});

// Every provider sends people back here, signed in (connecting an account) or not. This
// exact address is the one to register with the provider.
Route::get('/sso/{provider}/callback', [SsoController::class, 'callback'])->where('provider', '[a-z0-9-]+')->name('sso.callback');

// The emailed link, signed and valid for a day. It works signed in or not.
Route::get('/verify/{user}/{hash}', [VerificationController::class, 'verify'])
    ->where(['user' => '[0-9]+', 'hash' => '[a-f0-9]{40}'])
    ->name('verify');

// The "Verify your email" screen. Only "auth": the panel's two-factor requirement would
// otherwise refuse these before the account can be used at all.
Route::middleware('auth')->group(function () {
    Route::get('/verification', [VerificationController::class, 'show'])->name('verification');
    Route::post('/verification', [VerificationController::class, 'resend'])->name('verification.resend');

    Route::get('/connections', [ConnectionController::class, 'index'])->name('connections');
    Route::post('/connections/{provider}', [ConnectionController::class, 'store'])->where('provider', '[a-z0-9-]+')->name('connections.store');
    Route::delete('/connections/{provider}', [ConnectionController::class, 'destroy'])->where('provider', '[a-z0-9-]+')->name('connections.destroy');
});
