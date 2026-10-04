<?php

namespace App\Http\Controllers\Auth\Concerns;

use App\Mail\Auth\PasswordChangedNotificationMail;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

trait SendsPasswordChangedMail
{
    /**
     * Tell the account owner their password changed. The password is already changed by now, so a mail
     * failure is logged (no address, no secrets) and never turned into a failed request.
     */
    protected function sendPasswordChangedMail(User $user, Request $request): void
    {
        try {
            Mail::to($user)->send(new PasswordChangedNotificationMail(
                $user,
                (string) $request->ip(),
                null,
                $request->userAgent(),
            ));
        } catch (\Throwable $e) {
            Log::error('Password-changed notice failed', [
                'user_id' => $user->employee_id,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
