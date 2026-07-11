<?php

namespace App\Services\Directory;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class EmployeeDirectoryQuery
{
    /** Columns permitted for sorting (allow-list guards against injection). */
    private const SORTABLE = [
        'name', 'employee_id', 'email', 'created_at',
        'department_id', 'designation_id', 'attendance_type_id',
    ];

    public function __construct(private ScopeResolver $scope) {}

    /**
     * Lightweight typeahead search. Returns minimal disambiguation rows.
     */
    public function search(User $requester, array $params): Collection
    {
        $q = trim((string) ($params['q'] ?? ''));
        $limit = (int) ($params['limit'] ?? 20);
        $excludeIds = array_filter(array_map('intval', (array) ($params['excludeIds'] ?? [])));

        $query = $this->scope->applyBaseScope(User::query(), $requester)
            ->whereNull('deleted_at')
            ->with(['department:id,name', 'designation:id,title']);

        $this->applyCallerScope($query, $requester, (string) ($params['scope'] ?? 'all'));

        if ($excludeIds) {
            $query->whereNotIn('id', $excludeIds);
        }

        if ($q !== '') {
            $like = '%'.$q.'%';
            $query->where(function (Builder $w) use ($like) {
                $w->where('name', 'like', $like)
                    ->orWhere('employee_id', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('phone', 'like', $like);
            });
            // Ranking: exact employee_id, then name-prefix, then the rest.
            $query->orderByRaw(
                'CASE
                    WHEN employee_id = ? THEN 0
                    WHEN name LIKE ? THEN 1
                    ELSE 2 END',
                [$q, $q.'%']
            );
        }

        return $query->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'employee_id' => $u->employee_id,
                'avatar_url' => $u->profile_image_url,
                'department_name' => $u->department?->name,
                'designation_name' => $u->designation?->title,
            ]);
    }

    /**
     * Filtered + sorted builder for the full list page (list mode).
     * NOT permission-scoped: the list keeps global visibility (gated upstream by
     * the employees.view permission). Only search() applies applyBaseScope.
     */
    public function baseQuery(User $requester, array $filters): Builder
    {
        $query = User::withTrashed();

        $status = $filters['status'] ?? null;
        $showDeleted = filter_var($filters['showDeleted'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($status && $status !== 'all') {
            $status === 'active'
                ? $query->whereNull('deleted_at')
                : $query->whereNotNull('deleted_at');
        } elseif (! $showDeleted) {
            $query->whereNull('deleted_at');
        }

        if (! empty($filters['role']) && $filters['role'] !== 'all') {
            $query->whereHas('roles', fn ($r) => $r->where('name', $filters['role']));
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function (Builder $w) use ($like) {
                $w->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('employee_id', 'like', $like);
            });
        }

        foreach (['department' => 'department_id', 'designation' => 'designation_id', 'attendanceType' => 'attendance_type_id'] as $key => $col) {
            if (! empty($filters[$key]) && $filters[$key] !== 'all') {
                $query->where($col, $filters[$key]);
            }
        }

        $sort = in_array($filters['sort'] ?? null, self::SORTABLE, true) ? $filters['sort'] : 'created_at';
        $direction = strtolower($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sort, $direction);
        if ($sort !== 'name') {
            $query->orderBy('name'); // stable secondary sort
        }

        return $query;
    }

    /** Caller-supplied scope may only narrow within the resolved ceiling. */
    private function applyCallerScope(Builder $query, User $requester, string $scope): void
    {
        if ($scope === '' || $scope === 'all') {
            return;
        }
        if ($scope === 'myteam') {
            $query->where('report_to', $requester->id);
            return;
        }
        [$type, $id] = array_pad(explode(':', $scope, 2), 2, null);
        if ($type === 'department' && $id !== null) {
            $query->where('department_id', (int) $id);
        } elseif ($type === 'manager' && $id !== null) {
            $query->where('report_to', (int) $id);
        }
    }
}
