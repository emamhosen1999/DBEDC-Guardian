<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\CaptchaService;
use App\Services\DeviceAuthService;
use App\Services\GeoLocationService;
use App\Services\ModernAuthenticationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LoginController extends Controller
{
    protected ModernAuthenticationService $authService;
    protected DeviceAuthService $deviceAuthService;
    protected CaptchaService $captchaService;
    protected GeoLocationService $geoLocationService;

    public function __construct(
        ModernAuthenticationService $authService,
        DeviceAuthService $deviceAuthService,
        CaptchaService $captchaService,
        GeoLocationService $geoLocationService
    ) {
        $this->authService = $authService;
        $this->deviceAuthService = $deviceAuthService;
        $this->captchaService = $captchaService;
        $this->geoLocationService = $geoLocationService;
    }

    /**
     * Display the login view.
     */
    public function create(): Response
    {
        $isRequired = $this->captchaService->shouldTrigger(request());
        
        $captchaConfig = [
            'enabled' => config('captcha.enabled') || $isRequired,
            'provider' => config('captcha.provider'),
            'site_key' => config('captcha.' . config('captcha.provider') . '.site_key'),
            'required' => $isRequired,
        ];

        return Inertia::render('Auth/Login', [
            'canResetPassword' => true,
            'status' => session('status'),
            'deviceBlocked' => session('device_blocked', false),
            'deviceMessage' => session('device_message'),
            'blockedDeviceInfo' => session('blocked_device_info'),
            'captcha' => $captchaConfig,
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(Request $request)
    {
        $request->validate([
            'email' => 'required|string|email',
            'password' => 'required|string',
            'remember' => 'boolean',
            'device_name' => 'nullable|string|max:120',
            'device_signature' => 'nullable|array',
            'captcha_token' => 'nullable|string',
        ]);

        // CAPTCHA verification if required
        if ($this->captchaService->shouldTrigger($request)) {
            $captchaToken = $request->input('captcha_token');
            
            if (! $captchaToken) {
                $this->captchaService->incrementFailedAttempts($request);
                
                throw ValidationException::withMessages([
                    'captcha' => 'CAPTCHA verification is required.',
                ]);
            }

            $captchaResult = $this->captchaService->verify($captchaToken, 'login');

            if (! $captchaResult['success']) {
                $this->captchaService->incrementFailedAttempts($request);

                throw ValidationException::withMessages([
                    'captcha' => 'CAPTCHA verification failed. Please try again.',
                ]);
            }
        }

        $email = $request->email;
        $password = $request->password;
        $remember = $request->boolean('remember');

        // Check rate limiting
        $key = 'login.'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);

            $this->authService->logAuthenticationEvent(
                null,
                'login_rate_limited',
                'failure',
                $request,
                ['email' => $email, 'retry_after' => $seconds]
            );

            throw ValidationException::withMessages([
                'email' => "Too many login attempts. Please try again in {$seconds} seconds.",
            ]);
        }

        // Check if account is locked
        if ($this->authService->isAccountLocked($email)) {
            $this->authService->logAuthenticationEvent(
                null,
                'login_account_locked',
                'failure',
                $request,
                ['email' => $email]
            );

            throw ValidationException::withMessages([
                'email' => 'This account has been temporarily locked due to multiple failed login attempts.',
            ]);
        }

        // Find user
        $user = User::where('email', $email)->first();

        // Validate credentials
        if (! $user || ! Hash::check($password, $user->password)) {
            RateLimiter::hit($key, 60); // 1 minute decay

            $this->authService->recordFailedAttempt(
                $email,
                $request,
                $user ? 'invalid_password' : 'invalid_email'
            );

            $this->authService->logAuthenticationEvent(
                $user,
                'login_failed',
                'failure',
                $request,
                ['email' => $email, 'reason' => 'invalid_credentials']
            );

            throw ValidationException::withMessages([
                'email' => 'The provided credentials are incorrect.',
            ]);
        }

        // Check if user account is active
        if (! $user->active) {
            $this->authService->logAuthenticationEvent(
                $user,
                'login_inactive_account',
                'failure',
                $request
            );

            throw ValidationException::withMessages([
                'email' => 'This account has been deactivated. Please contact your administrator.',
            ]);
        }

        // Geolocation verification
        $currentLocation = $this->geoLocationService->getLocation($request->ip());
        
        if ($currentLocation && ! $currentLocation['is_private']) {
            // Check for suspicious location
            if ($this->geoLocationService->isSuspiciousLocation($user->id, $currentLocation)) {
                $this->authService->logAuthenticationEvent(
                    $user,
                    'login_suspicious_location',
                    'failure',
                    $request,
                    ['location' => $currentLocation]
                );

                throw ValidationException::withMessages([
                    'email' => 'Login from an unusual location detected. Please contact administrator or use a known device.',
                ]);
            }

            // Check for impossible travel
            $knownLocations = $this->geoLocationService->getUserKnownLocations($user->id);
            $previousLocation = null;
            if (! empty($knownLocations)) {
                $lastKey = array_key_last($knownLocations);
                $previousLocation = $knownLocations[$lastKey];
            }
            if ($this->geoLocationService->isImpossibleTravel($user->id, $currentLocation, $previousLocation)) {
                $this->authService->logAuthenticationEvent(
                    $user,
                    'login_impossible_travel',
                    'failure',
                    $request,
                    [
                        'current_location' => $currentLocation,
                        'previous_location' => $previousLocation,
                    ]
                );

                throw ValidationException::withMessages([
                    'email' => 'Suspicious login pattern detected. Please verify your identity.',
                ]);
            }
        }

        // NEW SECURE DEVICE BINDING LOGIC
        // Get device_id from request (UUIDv4 from frontend)
        $deviceId = $request->input('device_id') ?? $request->header('X-Device-ID');
        $deviceName = trim((string) $request->input('device_name', ''));
        $deviceSignature = is_array($request->input('device_signature'))
            ? $request->input('device_signature')
            : null;

        if (! $deviceId) {
            throw ValidationException::withMessages([
                'device_id' => 'Device identification is required for security.',
            ]);
        }

        // Check if user can login from this device
        $deviceCheck = $this->deviceAuthService->canLoginFromDevice($user, $deviceId, $deviceSignature);

        if (! $deviceCheck['allowed']) {
            $blockedDevice = $deviceCheck['device'] ?? null;

            $this->authService->logAuthenticationEvent(
                $user,
                'login_device_blocked',
                'failure',
                $request,
                [
                    'device_id' => $deviceId,
                    'blocked_by_device' => $blockedDevice?->id,
                    'message' => $deviceCheck['message'],
                ]
            );

            $deviceBlockedData = [
                'blocked' => true,
                'message' => $deviceCheck['message'],
                'blocked_device_info' => $blockedDevice ? [
                    'device_name' => $blockedDevice->device_name,
                    'browser' => $blockedDevice->browser,
                    'platform' => $blockedDevice->platform,
                    'device_type' => $blockedDevice->device_type,
                    'ip_address' => $blockedDevice->ip_address,
                    'last_used_at' => $blockedDevice->last_used_at ?
                        $blockedDevice->last_used_at->format('M j, Y g:i A') : null,
                ] : null,
            ];

            throw ValidationException::withMessages([
                'device_blocking' => [$deviceCheck['message']],
                'device_blocking_data' => [json_encode($deviceBlockedData)],
            ]);
        }

        // Clear rate limiting on successful login
        RateLimiter::clear($key);

        // Reset CAPTCHA failed attempts
        $this->captchaService->resetFailedAttempts($request);

        // Register device as known
        $this->captchaService->registerDevice($request);

        // Record user location for geolocation tracking
        if ($currentLocation && ! $currentLocation['is_private']) {
            $this->geoLocationService->recordUserLocation($user->id, $currentLocation);
            $this->geoLocationService->recordLastLoginTime($user->id);
        }

        // Login user
        Auth::login($user, $remember);

        // Regenerate session for security
        $request->session()->regenerate();
        $request->session()->put('device_id', $deviceId);

        // Register/update device with secure token
        $device = $this->deviceAuthService->registerDevice(
            $user,
            $request,
            $deviceId,
            $deviceSignature,
            $deviceName !== '' ? $deviceName : null
        );

        if (! $device) {
            // If device registration failed, log out and throw error
            Auth::logout();

            throw ValidationException::withMessages([
                'device_id' => 'Failed to register device. Please try again.',
            ]);
        }

        if ($user->hasSingleDeviceLoginEnabled()) {
            $this->deviceAuthService->enforceSingleDeviceSession(
                $user,
                $device,
                $request->session()->getId(),
                null
            );
        }

        // Update login statistics
        $this->authService->updateLoginStats($user, $request);

        // Track session
        $this->authService->trackUserSession($user, $request);

        // Log successful login
        $this->authService->logAuthenticationEvent(
            $user,
            'login_success',
            'success',
            $request
        );

        return redirect()->intended(route('dashboard.redirect'));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request)
    {
        $user = Auth::user();

        if ($user) {
            $deviceId = $request->header('X-Device-ID')
                ?? $request->input('device_id')
                ?? $request->session()->get('device_id');

            if ($deviceId) {
                \App\Models\UserDevice::where('user_id', $user->id)
                    ->where('device_id', $deviceId)
                    ->update([
                        'last_used_at' => now(),
                    ]);
            }

            // Log logout event
            $this->authService->logAuthenticationEvent(
                $user,
                'logout',
                'success',
                $request
            );

            // Update session tracking if exists
            $sessionId = session()->getId();
            DB::table('user_sessions')
                ->where('session_id', $sessionId)
                ->update([
                    'is_current' => false,
                    'updated_at' => now(),
                ]);
        }

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
