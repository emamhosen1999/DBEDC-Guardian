<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Models\User;

/**
 * Resolves a manager's team from the `report_to` reporting tree.
 * Extracted from three Api/V1 controllers that each held an identical copy.
 */
trait ResolvesTeamMembers
{
    protected function isManagerUser(User $user): bool
    {
        return $user->hasRole([
            'Super Admin',
            'Admin',
            'HR Manager',
            'Project Manager',
            'Consultant',
            'Super Administrator',
            'Administrator',
        ]);
    }

    protected function isAdminLikeUser(User $user): bool
    {
        return $user->hasRole([
            'Super Admin',
            'Admin',
            'HR Manager',
            'Super Administrator',
            'Administrator',
        ]);
    }

    /**
     * A manager sees their direct reports AND everyone below them.
     * The manager's own id is NOT included.
     *
     * @return array<int, int>
     */
    protected function resolveTeamMemberIds(User $user): array
    {
        return $this->collectDescendantIds($user->id);
    }

    /**
     * Walk the report_to hierarchy and collect all descendant user IDs.
     * Depth-capped at 10 levels and 500 users to guard against circular
     * references and runaway queries in very large orgs.
     *
     * @return array<int, int>
     */
    protected function collectDescendantIds(int $rootId, int $maxDepth = 10): array
    {
        $collected = [];
        $currentLevelIds = [$rootId];
        $visited = [$rootId => true];

        for ($depth = 0; $depth < $maxDepth; $depth++) {
            $children = User::query()
                ->whereNull('deleted_at')
                ->whereIn('report_to', $currentLevelIds)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->filter(fn ($id) => ! isset($visited[$id]))
                ->values()
                ->all();

            if ($children === []) {
                break;
            }

            foreach ($children as $childId) {
                $visited[$childId] = true;
                $collected[] = $childId;
            }

            $currentLevelIds = $children;

            if (count($collected) >= 500) {
                break;
            }
        }

        return $collected;
    }
}
