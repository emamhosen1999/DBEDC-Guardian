<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Auth\Concerns\SendsPasswordChangedMail;
use App\Http\Controllers\Controller;
use App\Mail\Auth\SecurePasswordResetMail;
use App\Models\User;
use App\Services\ModernAuthenticationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PasswordResetController extends Controller
{
    use SendsPasswordChangedMail;

    protected ModernAuthenticationService $authService;

    public function __construct(ModernAuthenticationService $authService)
    {
        $this->authService = $authService;
    }

    /**
     * Display the password reset request form.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/ForgotPassword', [
            'status' => session('status'),
        ]);
    }

    /**
     * Handle password reset request.
     */
    public function store(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $email = $request->email;
        $user = User::where('email', $email)->first();

        // Always log the attempt, regardless of whether email exists
        $this->authService->logAuthenticationEvent(
            $user,
            'password_reset_requested',
            $user ? 'success' : 'failure',
            $request,
            ['email' => $email]
        );

        // One identical answer whether the address exists, the mail went out or sending failed:
        // anything else lets a caller enumerate accounts (OWASP Forgot Password guidance).
        $generic = 'If an account with that email exists, we have sent a password reset link and verification code.';

        if (! $user) {
            return back()->with('status', $generic);
        }

        try {
            // Generate secure token with OTP
            $resetData = $this->authService->generatePasswordResetToken($email, $request);

            // Send email with reset link and OTP
            $this->sendPasswordResetEmail($user, $resetData, $request);

            return back()->with('status', $generic);

        } catch (\Exception $e) {
            $this->authService->logAuthenticationEvent(
                $user,
                'password_reset_email_failed',
                'failure',
                $request,
                ['email' => $email, 'error' => $e->getMessage()]
            );

            report($e);

            return back()->with('status', $generic);
        }
    }

    /**
     * Display the password reset form.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('Auth/ResetPassword', [
            'email' => $request->email,
            'token' => $request->token,
        ]);
    }

    /**
     * Handle the password reset form submission.
     */
    public function update(Request $request)
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'verification_code' => 'required|string|size:6',
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $email = $request->email;
        $token = $request->token;
        $verificationCode = $request->verification_code;
        $password = $request->password;

        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->authService->logAuthenticationEvent(
                null,
                'password_reset_invalid_email',
                'failure',
                $request,
                ['email' => $email]
            );

            throw ValidationException::withMessages([
                'email' => 'No account found with this email address.',
            ]);
        }

        // Verify token and code
        if (! $this->authService->verifyPasswordResetToken($email, $token, $verificationCode)) {
            $this->authService->logAuthenticationEvent(
                $user,
                'password_reset_invalid_token',
                'failure',
                $request,
                ['email' => $email]
            );

            throw ValidationException::withMessages([
                'verification_code' => 'The verification code is invalid or has expired.',
            ]);
        }

        // Update password
        $user->update([
            'password' => Hash::make($password),
        ]);
        $user->forceFill(['must_change_password' => false])->save(); // proof of mailbox ownership replaces an admin-set password

        // Clean up reset tokens for this email
        DB::table('password_reset_tokens_secure')
            ->where('email', $email)
            ->delete();

        $this->sendPasswordChangedMail($user, $request);

        // Log successful password reset
        $this->authService->logAuthenticationEvent(
            $user,
            'password_reset_success',
            'success',
            $request
        );

        return redirect()->route('login')->with('status', 'Your password has been reset successfully.');
    }

    /**
     * Send password reset email with OTP. The OTP and address must never reach a log line.
     */
    protected function sendPasswordResetEmail(User $user, array $resetData, Request $request): void
    {
        // The `log` and `array` mailers write the whole message - the OTP and reset link included - into
        // storage. In production that is a credential leak, not a delivery, so nothing is sent until a
        // real transport is configured (the caller still answers with the generic message).
        if (app()->isProduction() && in_array(config('mail.default'), ['log', 'array'], true)) {
            Log::warning('Password reset mail withheld: no real mail transport is configured.', [
                'mailer' => config('mail.default'),
                'user' => $user->getKey(),
            ]);

            return;
        }

        $resetUrl = route('password.reset', [
            'token' => $resetData['token'],
            'email' => $user->email,
        ]);

        Mail::to($user)->send(new SecurePasswordResetMail(
            $user,
            $resetData['verification_code'],
            $resetUrl,
            (string) $request->ip(),
            null,
            $request->userAgent(),
        ));
    }
}
