<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PasswordPolicyService
{
    /**
     * Validate password against policy requirements.
     */
    public function validatePassword(string $password, ?User $user = null): array
    {
        $errors = [];
        $config = config('password');

        // Check minimum length
        if (strlen($password) < $config['min_length']) {
            $errors[] = "Password must be at least {$config['min_length']} characters.";
        }

        // Check maximum length
        if (strlen($password) > $config['max_length']) {
            $errors[] = "Password must not exceed {$config['max_length']} characters.";
        }

        // Check uppercase requirement
        if ($config['require_uppercase'] && ! preg_match('/[A-Z]/', $password)) {
            $errors[] = 'Password must contain at least one uppercase letter.';
        }

        // Check lowercase requirement
        if ($config['require_lowercase'] && ! preg_match('/[a-z]/', $password)) {
            $errors[] = 'Password must contain at least one lowercase letter.';
        }

        // Check number requirement
        if ($config['require_numbers'] && ! preg_match('/[0-9]/', $password)) {
            $errors[] = 'Password must contain at least one number.';
        }

        // Check special character requirement
        if ($config['require_special_chars']) {
            $specialChars = preg_quote($config['special_chars'], '/');
            if (! preg_match("/[{$specialChars}]/", $password)) {
                $errors[] = 'Password must contain at least one special character.';
            }
        }

        // Check password history
        if ($user && $this->isPasswordInHistory($password, $user)) {
            $errors[] = 'You cannot reuse one of your last ' . $config['history_count'] . ' passwords.';
        }

        // Check against breached passwords
        if ($config['check_breached'] && $this->isPasswordBreached($password)) {
            $errors[] = 'This password has been found in a data breach. Please choose a different password.';
        }

        return $errors;
    }

    /**
     * Check if password is in user's password history.
     */
    protected function isPasswordInHistory(string $password, User $user): bool
    {
        $history = $user->password_history ?? [];
        $historyCount = config('password.history_count', 5);

        foreach (array_slice($history, -$historyCount) as $oldPassword) {
            if (Hash::check($password, $oldPassword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if password has been breached using Have I Been Pwned API.
     */
    protected function isPasswordBreached(string $password): bool
    {
        try {
            $sha1 = strtoupper(sha1($password));
            $prefix = substr($sha1, 0, 5);
            $suffix = substr($sha1, 5);

            $response = Http::timeout(5)->get(config('password.hibp_api_url') . $prefix);

            if (! $response->successful()) {
                Log::warning('Failed to check password breach status', [
                    'status' => $response->status(),
                ]);
                return false; // Allow password if API fails
            }

            $hashes = explode("\r\n", $response->body());

            foreach ($hashes as $hash) {
                [$hashSuffix, $count] = explode(':', $hash);
                if ($hashSuffix === $suffix) {
                    return (int) $count > 0;
                }
            }

            return false;
        } catch (\Exception $e) {
            Log::warning('Error checking password breach status', [
                'error' => $e->getMessage(),
            ]);
            return false; // Allow password if error occurs
        }
    }

    /**
     * Update user's password history.
     */
    public function updatePasswordHistory(User $user, string $hashedPassword): void
    {
        $history = $user->password_history ?? [];
        $historyCount = config('password.history_count', 5);

        // Add new password to history
        $history[] = $hashedPassword;

        // Keep only the last N passwords
        $history = array_slice($history, -$historyCount);

        $user->update([
            'password_history' => $history,
            'password_changed_at' => now(),
            'password_expires_at' => $this->calculatePasswordExpiration(),
        ]);
    }

    /**
     * Calculate password expiration date.
     */
    public function calculatePasswordExpiration(): ?\Carbon\Carbon
    {
        $expirationDays = config('password.expiration_days');

        if ($expirationDays === null) {
            return null;
        }

        return now()->addDays($expirationDays);
    }

    /**
     * Check if user's password is expired or will expire soon.
     */
    public function getPasswordStatus(User $user): array
    {
        $expiresAt = $user->password_expires_at;

        if ($expiresAt === null) {
            return [
                'expired' => false,
                'warning' => false,
                'days_remaining' => null,
            ];
        }

        if ($expiresAt->isPast()) {
            return [
                'expired' => true,
                'warning' => false,
                'days_remaining' => 0,
            ];
        }

        $daysRemaining = now()->diffInDays($expiresAt, false);
        $warningDays = config('password.warning_days', 7);

        return [
            'expired' => false,
            'warning' => $daysRemaining <= $warningDays,
            'days_remaining' => $daysRemaining,
        ];
    }

    /**
     * Get password policy requirements for display.
     */
    public function getPolicyRequirements(): array
    {
        $config = config('password');

        return [
            'min_length' => $config['min_length'],
            'max_length' => $config['max_length'],
            'require_uppercase' => $config['require_uppercase'],
            'require_lowercase' => $config['require_lowercase'],
            'require_numbers' => $config['require_numbers'],
            'require_special_chars' => $config['require_special_chars'],
            'special_chars' => $config['special_chars'],
            'history_count' => $config['history_count'],
            'expiration_days' => $config['expiration_days'],
        ];
    }

    /**
     * Calculate password strength score (0-5).
     */
    public function calculatePasswordStrength(string $password): int
    {
        $strength = 0;

        if (strlen($password) >= 8) $strength++;
        if (strlen($password) >= 12) $strength++;
        if (preg_match('/[A-Z]/', $password)) $strength++;
        if (preg_match('/[a-z]/', $password)) $strength++;
        if (preg_match('/[0-9]/', $password)) $strength++;
        if (preg_match('/[^A-Za-z0-9]/', $password)) $strength++;

        return min($strength, 5);
    }
}
