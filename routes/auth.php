<?php

use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\TwoFactorAuthenticationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Authentication Routes
|--------------------------------------------------------------------------
|
| Modern authentication routes with enhanced security features including
| rate limiting, comprehensive logging, and secure password reset flow.
|
*/

Route::middleware('guest')->group(function () {
    // Registration Routes
    Route::get('register', [RegisterController::class, 'create'])->name('register');
    Route::post('register', [RegisterController::class, 'store']);

    // Login Routes
    Route::get('login', [LoginController::class, 'create'])->name('login');
    Route::post('login', [LoginController::class, 'store']);

    // Password Reset Routes
    Route::get('forgot-password', [PasswordResetController::class, 'create'])->name('password.request');
    Route::post('forgot-password', [PasswordResetController::class, 'store'])->name('password.email');
    Route::get('reset-password/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('reset-password', [PasswordResetController::class, 'update'])->name('password.update');
});

Route::middleware('auth')->group(function () {
    // Email Verification Routes
    Route::get('verify-email', [EmailVerificationController::class, 'prompt'])->name('verification.notice');
    Route::get('verify-email/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');
    Route::post('email/verification-notification', [EmailVerificationController::class, 'send'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    // Two-Factor Authentication Routes
    Route::prefix('two-factor')->name('two-factor.')->group(function () {
        Route::get('setup', [TwoFactorAuthenticationController::class, 'showSetup'])->name('setup');
        Route::post('generate-qr', [TwoFactorAuthenticationController::class, 'generateQrCode'])->name('generate-qr');
        Route::post('enable', [TwoFactorAuthenticationController::class, 'enable'])->name('enable');
        Route::get('manage', [TwoFactorAuthenticationController::class, 'showManage'])->name('manage');
        Route::post('disable', [TwoFactorAuthenticationController::class, 'disable'])->name('disable');
        Route::get('recovery-codes', [TwoFactorAuthenticationController::class, 'showRecoveryCodes'])->name('recovery-codes');
        Route::post('regenerate-recovery-codes', [TwoFactorAuthenticationController::class, 'regenerateRecoveryCodes'])->name('regenerate-recovery-codes');
        Route::post('verify', [TwoFactorAuthenticationController::class, 'verify'])->name('verify');
        Route::get('status', [TwoFactorAuthenticationController::class, 'status'])->name('status');
    });

    // Logout Route
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');
});
