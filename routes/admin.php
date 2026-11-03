<?php

use Illuminate\Support\Facades\Route;
use RustixDev\Registration\Http\Controllers\AdminVerificationController;

// Root admins only: the panel mounts this file at /api/admin/extensions/registration.
Route::get('/users/{user}', [AdminVerificationController::class, 'show'])->where('user', '[0-9]+')->name('users.show');
Route::post('/users/{user}/verify', [AdminVerificationController::class, 'verify'])->where('user', '[0-9]+')->name('users.verify');
Route::post('/users/{user}/resend', [AdminVerificationController::class, 'resend'])->where('user', '[0-9]+')->name('users.resend');
