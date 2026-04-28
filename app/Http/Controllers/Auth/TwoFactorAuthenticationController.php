<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use App\Services\ModernAuthenticationService;

class TwoFactorAuthenticationController extends Controller
{
    protected Google2FA $google2fa;
    protected ModernAuthenticationService $authService;

    public function __construct(Google2FA $google2fa, ModernAuthenticationService $authService)
    {
        $this->google2fa = $google2fa;
        $this->authService = $authService;
        $this->middleware('auth');
    }

    /**
     * Show 2FA setup page
     */
    public function showSetup(Request $request)
    {
        $user = $request->user();

        // If already enabled, redirect to manage page
        if ($user->two_factor_secret) {
            return redirect()->route('two-factor.manage');
        }

        return inertia('Auth/TwoFactorSetup');
    }

    /**
     * Generate QR code for 2FA setup
     */
    public function generateQrCode(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->two_factor_secret) {
            return response()->json([
                'success' => false,
                'message' => 'Two-factor authentication is already enabled.',
            ], 400);
        }

        // Generate secret key
        $secret = $this->google2fa->generateSecretKey();

        // Store temporarily in session
        session(['two_factor_setup_secret' => $secret]);

        // Generate QR code
        $companyName = config('app.name');
        $companyEmail = $user->email;
        $qrCodeUrl = $this->google2fa->getQRCodeUrl(
            $companyName,
            $companyEmail,
            $secret
        );

        $qrCodeSvg = QrCode::format('svg')->size(300)->generate($qrCodeUrl);

        return response()->json([
            'success' => true,
            'secret' => $secret,
            'qr_code' => $qrCodeSvg,
            'company_name' => $companyName,
            'company_email' => $companyEmail,
        ]);
    }

    /**
     * Enable 2FA
     */
    public function enable(Request $request): JsonResponse
    {
        $request->validate([
            'code' => 'required|string|digits:6',
        ]);

        $user = $request->user();

        if ($user->two_factor_secret) {
            return response()->json([
                'success' => false,
                'message' => 'Two-factor authentication is already enabled.',
            ], 400);
        }

        $secret = session('two_factor_setup_secret');

        if (!$secret) {
            return response()->json([
                'success' => false,
                'message' => 'Setup session expired. Please start over.',
            ], 400);
        }

        // Verify the code
        $valid = $this->google2fa->verifyKey($secret, $request->code);

        if (!$valid) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid verification code.',
            ], 422);
        }

        // Generate recovery codes
        $recoveryCodes = $this->generateRecoveryCodes();

        // Enable 2FA for user
        $user->update([
            'two_factor_secret' => encrypt($secret),
            'two_factor_recovery_codes' => encrypt(json_encode($recoveryCodes)),
            'two_factor_confirmed_at' => now(),
        ]);

        // Clear session
        session()->forget('two_factor_setup_secret');

        // Log the event
        $this->authService->logAuthenticationEvent(
            $user,
            '2fa_enabled',
            'success',
            $request,
            ['method' => 'totp']
        );

        return response()->json([
            'success' => true,
            'message' => 'Two-factor authentication enabled successfully.',
            'recovery_codes' => $recoveryCodes,
        ]);
    }

    /**
     * Show 2FA management page
     */
    public function showManage(Request $request)
    {
        $user = $request->user();

        if (!$user->two_factor_secret) {
            return redirect()->route('two-factor.setup');
        }

        return inertia('Auth/TwoFactorManage', [
            'enabled' => true,
            'confirmed' => !is_null($user->two_factor_confirmed_at),
        ]);
    }

    /**
     * Disable 2FA
     */
    public function disable(Request $request): JsonResponse
    {
        $request->validate([
            'password' => 'required|string',
        ]);

        $user = $request->user();

        // Verify password
        if (!password_verify($request->password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid password.',
            ], 422);
        }

        // Disable 2FA
        $user->update([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ]);

        // Log the event
        $this->authService->logAuthenticationEvent(
            $user,
            '2fa_disabled',
            'success',
            $request,
            ['method' => 'user_request']
        );

        return response()->json([
            'success' => true,
            'message' => 'Two-factor authentication disabled successfully.',
        ]);
    }

    /**
     * Show recovery codes
     */
    public function showRecoveryCodes(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->two_factor_recovery_codes) {
            return response()->json([
                'success' => false,
                'message' => 'Two-factor authentication is not enabled.',
            ], 400);
        }

        $recoveryCodes = json_decode(decrypt($user->two_factor_recovery_codes), true);

        return response()->json([
            'success' => true,
            'recovery_codes' => $recoveryCodes,
        ]);
    }

    /**
     * Regenerate recovery codes
     */
    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $request->validate([
            'password' => 'required|string',
        ]);

        $user = $request->user();

        // Verify password
        if (!password_verify($request->password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid password.',
            ], 422);
        }

        // Generate new recovery codes
        $recoveryCodes = $this->generateRecoveryCodes();

        // Update user
        $user->update([
            'two_factor_recovery_codes' => encrypt(json_encode($recoveryCodes)),
        ]);

        // Log the event
        $this->authService->logAuthenticationEvent(
            $user,
            'recovery_codes_regenerated',
            'success',
            $request,
        );

        return response()->json([
            'success' => true,
            'message' => 'Recovery codes regenerated successfully.',
            'recovery_codes' => $recoveryCodes,
        ]);
    }

    /**
     * Verify 2FA code during login
     */
    public function verify(Request $request): JsonResponse
    {
        $request->validate([
            'code' => 'required|string',
        ]);

        $user = $request->user();

        if (!$user->two_factor_secret) {
            return response()->json([
                'success' => false,
                'message' => 'Two-factor authentication is not enabled.',
            ], 400);
        }

        $secret = decrypt($user->two_factor_secret);

        // Check if it's a recovery code
        $recoveryCodes = json_decode(decrypt($user->two_factor_recovery_codes), true);
        if (in_array($request->code, $recoveryCodes)) {
            // Remove used recovery code
            $recoveryCodes = array_diff($recoveryCodes, [$request->code]);
            $user->update([
                'two_factor_recovery_codes' => encrypt(json_encode(array_values($recoveryCodes))),
            ]);

            // Log the event
            $this->authService->logAuthenticationEvent(
                $user,
                '2fa_verified',
                'success',
                $request,
                ['method' => 'recovery_code']
            );

            return response()->json([
                'success' => true,
                'message' => 'Authentication successful using recovery code.',
            ]);
        }

        // Verify TOTP code
        $valid = $this->google2fa->verifyKey($secret, $request->code);

        if (!$valid) {
            // Log failed attempt
            $this->authService->logAuthenticationEvent(
                $user,
                '2fa_verification_failed',
                'failure',
                $request,
                ['reason' => 'invalid_code']
            );

            return response()->json([
                'success' => false,
                'message' => 'Invalid verification code.',
            ], 422);
        }

        // Log successful verification
        $this->authService->logAuthenticationEvent(
            $user,
            '2fa_verified',
            'success',
            $request,
            ['method' => 'totp']
        );

        return response()->json([
            'success' => true,
            'message' => 'Authentication successful.',
        ]);
    }

    /**
     * Generate recovery codes
     */
    protected function generateRecoveryCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < 8; $i++) {
            $codes[] = Str::random(8) . '-' . Str::random(4);
        }
        return $codes;
    }

    /**
     * Check if 2FA is enabled for user
     */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'enabled' => !is_null($user->two_factor_secret),
            'confirmed' => !is_null($user->two_factor_confirmed_at),
        ]);
    }
}
