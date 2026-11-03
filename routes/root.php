<?php

use Illuminate\Support\Facades\Route;
use RustixDev\Registration\Http\Controllers\RegisterRedirectController;

// /register, a short link to the sign-up page.
Route::get('/', RegisterRedirectController::class)->name('index');
