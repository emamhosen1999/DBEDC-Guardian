<?php

declare(strict_types=1);

namespace App\Services\Aeon\Tools;

use App\Models\User;
use App\Services\Aeon\Data\AeonAccess;

/**
 * Permission gate shared by the specialised Aeon tools: each action requires the same
 * permission(s) as the equivalent page, checked server-side on every call.
 */
class ToolGate
{
    public function __construct(private AeonAccess $access) {}

    public function actor(int|string|null $userId): ?User
    {
        return $this->access->actor($userId);
    }

    /**
     * Does the user hold any of the permissions?
     *
     * @param  array<int, string>  $permissions
     */
    public function allows(int|string|null $userId, array $permissions): bool
    {
        $actor = $this->actor($userId);

        return $actor !== null && $this->access->holdsAny($actor, $permissions);
    }

    /**
     * A ready-made refusal result when the user holds none of the permissions, else null.
     *
     * @param  array<int, string>  $permissions
     * @return array{text: string, blocks: array<int, array<string, mixed>>, data: array<string, mixed>}|null
     */
    public function deny(int|string|null $userId, array $permissions): ?array
    {
        if ($this->allows($userId, $permissions)) {
            return null;
        }

        return [
            'text' => 'You do not have access to this information.',
            'blocks' => [],
            'data' => ['error' => 'forbidden'],
        ];
    }

    /**
     * Refusal unless the user holds EVERY permission (for tools that combine several modules).
     *
     * @param  array<int, string>  $permissions
     * @return array{text: string, blocks: array<int, array<string, mixed>>, data: array<string, mixed>}|null
     */
    public function denyUnlessAll(int|string|null $userId, array $permissions): ?array
    {
        foreach ($permissions as $permission) {
            if ($this->deny($userId, [$permission]) !== null) {
                return $this->deny($userId, [$permission]);
            }
        }

        return null;
    }
}
