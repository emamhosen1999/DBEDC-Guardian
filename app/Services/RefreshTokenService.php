<?php

namespace App\Services;

use App\Models\RefreshToken;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Owns the refresh-token lifecycle: minting, hashing, rotation, and revocation.
 *
 * A refresh token is a random opaque string. Only its SHA-256 hash is stored, so
 * lookups are constant-time by hash and the plaintext is never recoverable from
 * the database. Every use ROTATES the token (the presented one is revoked and a
 * successor minted) which is what lets a stolen-but-unused token be detected on
 * reuse and lets an idle chain be revoked wholesale.
 */
class RefreshTokenService
{
    public function ttlDays(): int
    {
        return max(1, (int) config('security.refresh_token.ttl_days', 30));
    }

    protected function secretBytes(): int
    {
        return max(16, (int) config('security.refresh_token.secret_bytes', 40));
    }

    public function hashToken(string $plainText): string
    {
        return hash('sha256', $plainText);
    }

    protected function generatePlainText(): string
    {
        return bin2hex(random_bytes($this->secretBytes()));
    }

    /**
     * Persist a fresh refresh token for (user, device) and return its plaintext
     * once alongside the stored model.
     *
     * @return array{plain: string, model: RefreshToken}
     */
    public function create(User $user, string $deviceId): array
    {
        $plain = $this->generatePlainText();

        $model = RefreshToken::create([
            'user_id' => $user->id,
            'device_id' => $deviceId !== '' ? $deviceId : null,
            'token_hash' => $this->hashToken($plain),
            'expires_at' => Carbon::now()->addDays($this->ttlDays()),
        ]);

        return ['plain' => $plain, 'model' => $model];
    }

    /**
     * Issue a refresh token as part of a fresh login. Any still-active tokens for
     * the SAME device are revoked first so each login starts a single clean chain
     * for that device (mirrors the single-active-token intent of login).
     *
     * @return array{plain: string, model: RefreshToken}
     */
    public function issueForLogin(User $user, string $deviceId): array
    {
        return DB::transaction(function () use ($user, $deviceId) {
            $this->revokeActiveForUserDevice((int) $user->id, $deviceId);

            return $this->create($user, $deviceId);
        });
    }

    public function findByPlainText(string $plainText): ?RefreshToken
    {
        $plainText = trim($plainText);

        if ($plainText === '') {
            return null;
        }

        return RefreshToken::where('token_hash', $this->hashToken($plainText))->first();
    }

    /**
     * Rotate a valid presented token: mint its successor, then revoke the
     * presented one and link the two (`replaced_by`) for an auditable chain.
     *
     * @return array{plain: string, model: RefreshToken}
     */
    public function rotate(RefreshToken $current, User $user, string $deviceId): array
    {
        return DB::transaction(function () use ($current, $user, $deviceId) {
            $issued = $this->create($user, $deviceId);

            $current->forceFill([
                'revoked_at' => Carbon::now(),
                'replaced_by' => $issued['model']->id,
            ])->save();

            return $issued;
        });
    }

    public function revokeActiveForUserDevice(int $userId, ?string $deviceId): int
    {
        $query = RefreshToken::query()
            ->where('user_id', $userId)
            ->whereNull('revoked_at');

        $this->applyDeviceCondition($query, $deviceId);

        return $query->update(['revoked_at' => Carbon::now()]);
    }

    /**
     * Revoke the entire active chain for a (user, device). Used as the theft
     * response when a revoked/replayed refresh token is presented, and on logout.
     * A null/empty device revokes ALL of the user's active refresh tokens.
     */
    public function revokeChainForUserDevice(int $userId, ?string $deviceId): int
    {
        $query = RefreshToken::query()
            ->where('user_id', $userId)
            ->whereNull('revoked_at');

        if ($deviceId !== null && $deviceId !== '') {
            $query->where('device_id', $deviceId);
        }

        return $query->update(['revoked_at' => Carbon::now()]);
    }

    /**
     * Revoke every active refresh token for the user that is NOT bound to the
     * given device — mirrors the single-device access-token rotation on login.
     */
    public function revokeForOtherDevices(int $userId, string $exceptDeviceId): int
    {
        if ($exceptDeviceId === '') {
            return 0;
        }

        return RefreshToken::query()
            ->where('user_id', $userId)
            ->where('device_id', '!=', $exceptDeviceId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => Carbon::now()]);
    }

    protected function applyDeviceCondition(Builder $query, ?string $deviceId): void
    {
        if ($deviceId === null || $deviceId === '') {
            $query->whereNull('device_id');

            return;
        }

        $query->where('device_id', $deviceId);
    }
}
