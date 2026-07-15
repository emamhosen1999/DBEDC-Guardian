<?php

/**
 * ZKTeco ADMS Push Protocol routes.
 *
 * Registered WITHOUT any route prefix so the device can reach them at the
 * exact paths it hardcodes internally:
 *   GET /iclock/cdata   — handshake + options
 *   POST /iclock/cdata  — attendance logs, command acks, template uploads
 *   GET /iclock/getrequest — device polls for pending commands
 *   POST /iclock/devicecmd — device sends command acknowledgment
 *   GET /iclock/test    — connectivity probe
 *
 * The MB460 "Server Domain Name" field accepts only a bare domain/IP.
 * The device appends /iclock/cdata automatically — putting this in api.php
 * would make the path /api/iclock/cdata which the device cannot reach.
 *
 * No CSRF middleware is applied because ZKTeco devices are not browsers
 * and cannot send CSRF tokens. The api middleware group (which includes
 * Sanctum and auth:sanctum) is also not applied here — these are public
 * device-to-server endpoints.
 *
 * Serial-only trust is NOT sufficient (serials are printed on the device and
 * are not secret), so every route below is guarded by EnsureAdmsDeviceAuthorized,
 * which enforces a registered+active allowlist plus an optional, staged
 * per-device shared secret. The middleware is referenced by FQCN so it needs no
 * alias registration in bootstrap/app.php.
 */

use App\Http\Controllers\Api\BiometricWebhookController;
use App\Http\Middleware\EnsureAdmsDeviceAuthorized;
use Illuminate\Support\Facades\Route;

Route::middleware(['throttle:300,1', EnsureAdmsDeviceAuthorized::class])->group(function () {
    Route::get('/iclock/cdata', [BiometricWebhookController::class, 'admsHandshake'])
        ->name('biometric.adms.handshake');
    Route::post('/iclock/cdata', [BiometricWebhookController::class, 'admsPush'])
        ->name('biometric.adms.push');
    Route::get('/iclock/getrequest', [BiometricWebhookController::class, 'admsGetRequest'])
        ->name('biometric.adms.getrequest');
    Route::post('/iclock/devicecmd', [BiometricWebhookController::class, 'admsDeviceCmd'])
        ->name('biometric.adms.devicecmd');
    Route::get('/iclock/test', [BiometricWebhookController::class, 'admsTest'])
        ->name('biometric.adms.test');
});
