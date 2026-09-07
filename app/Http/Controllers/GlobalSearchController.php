<?php

namespace App\Http\Controllers;

use App\Models\DailyWork;
use App\Models\OmIncident;
use App\Models\OmWorkOrder;
use App\Models\RfiObjection;
use App\Models\User;
use App\Services\Project\DailyWorkService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class GlobalSearchController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $query = trim((string) ($validated['q'] ?? ''));
        $groups = [];

        if (Str::length($query) >= 2) {
            $groups = array_values(array_filter([
                $this->employees($request, $query),
                $this->dailyWorks($request, $query),
                $this->objections($request, $query),
                $this->workOrders($request, $query),
                $this->incidents($request, $query),
            ]));
        }

        return Inertia::render('Search/Index', [
            'query' => $query,
            'groups' => $groups,
            'minimumLength' => 2,
        ]);
    }

    private function employees(Request $request, string $search): ?array
    {
        $actor = $request->user();
        if (! $actor?->can('employees.view')) {
            return null;
        }

        $query = User::query()
            ->select(['employee_id', 'name', 'email', 'department_id'])
            ->with('department:id,name')
            ->where(function (Builder $builder) use ($search): void {
                $builder->where('employee_id', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });

        if (! $actor->hasRole(['Super Administrator', 'Administrator', 'HR Manager']) && $actor->department_id !== null) {
            $query->where('department_id', $actor->department_id);
        }

        $items = $query->orderBy('name')->limit(8)->get()->map(fn (User $employee): array => [
            'id' => (string) $employee->getKey(),
            'title' => $employee->name,
            'subtitle' => trim($employee->employee_id.' · '.($employee->department?->name ?? 'No department'), ' ·'),
            'meta' => $employee->email,
            'url' => route('employees', ['search' => $employee->employee_id]),
        ]);

        return $this->group('employees', 'Employees', $items);
    }

    private function dailyWorks(Request $request, string $search): ?array
    {
        $actor = $request->user();
        if (! $actor?->can('daily-works.view') || ! $actor->can('viewAny', DailyWork::class)) {
            return null;
        }

        $items = app(DailyWorkService::class)->buildFilteredDailyWorksQuery($actor, [])
            ->where(function (Builder $builder) use ($search): void {
                $builder->where('number', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('location', 'like', "%{$search}%");
            })
            ->latest('date')
            ->orderByDesc('id')
            ->lazy(50)
            ->filter(fn (DailyWork $work): bool => $actor->can('view', $work))
            ->take(8)
            ->collect()
            ->map(fn (DailyWork $work): array => [
                'id' => (string) $work->getKey(),
                'title' => $work->number ?: "Daily work #{$work->getKey()}",
                'subtitle' => $work->description ?: $work->type,
                'meta' => collect([$work->location, $work->status])->filter()->implode(' · '),
                'url' => route('daily-works-unified', [
                    'search' => $work->number ?: $work->getKey(),
                    'date' => $work->date?->format('Y-m-d'),
                ]),
            ])
            ->values();

        return $this->group('daily_works', 'Daily works / RFIs', $items);
    }

    private function objections(Request $request, string $search): ?array
    {
        $actor = $request->user();
        if (! $actor?->can('daily-works.view') || ! $actor->can('viewAny', RfiObjection::class)) {
            return null;
        }

        $query = RfiObjection::query()
            ->with('dailyWorks:id,incharge,assigned')
            ->where(function (Builder $builder) use ($search): void {
                $builder->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('reason', 'like', "%{$search}%")
                    ->orWhere('chainage_from', 'like', "%{$search}%")
                    ->orWhere('chainage_to', 'like', "%{$search}%");
            });

        // Match the record policy before limiting, so unrelated recent records
        // cannot crowd authorized results out of the search window.
        if (! $actor->hasRole(['Super Administrator', 'Super Admin', 'Admin', 'HR Manager'])) {
            $query->where(function (Builder $builder) use ($actor): void {
                $builder->where('created_by', $actor->getKey())
                    ->orWhereHas('dailyWorks', function (Builder $works) use ($actor): void {
                        $works->where('incharge', $actor->getKey())->orWhere('assigned', $actor->getKey());
                    });
            });
        }

        $items = $query->latest()->orderByDesc('id')->lazy(50)
            ->filter(fn (RfiObjection $objection): bool => $actor->can('view', $objection))
            ->take(8)
            ->collect()
            ->map(fn (RfiObjection $objection): array => [
                'id' => (string) $objection->getKey(),
                'title' => $objection->title,
                'subtitle' => $objection->description ?: $objection->reason,
                'meta' => collect([$objection->category_label, $objection->status_label])->filter()->implode(' · '),
                'url' => route('objections.index', ['search' => $objection->title]),
            ])
            ->values();

        return $this->group('objections', 'Objections', $items);
    }

    private function workOrders(Request $request, string $search): ?array
    {
        if (! $request->user()?->can('om.maintenance.view')) {
            return null;
        }

        $items = OmWorkOrder::query()
            ->where(function (Builder $builder) use ($search): void {
                $builder->where('work_order_number', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('location', 'like', "%{$search}%");
            })
            ->latest()
            ->limit(8)
            ->get()
            ->map(fn (OmWorkOrder $workOrder): array => [
                'id' => (string) $workOrder->getKey(),
                'title' => $workOrder->work_order_number.' · '.$workOrder->title,
                'subtitle' => $workOrder->description,
                'meta' => collect([$workOrder->location, $workOrder->status])->filter()->implode(' · '),
                'url' => route('om.work-orders', ['search' => $workOrder->work_order_number]),
            ]);

        return $this->group('work_orders', 'Maintenance work orders', $items);
    }

    private function incidents(Request $request, string $search): ?array
    {
        if (! $request->user()?->can('om.incidents.view')) {
            return null;
        }

        $items = OmIncident::query()
            ->where(function (Builder $builder) use ($search): void {
                $builder->where('incident_number', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('chainage', 'like', "%{$search}%");
            })
            ->latest()
            ->limit(8)
            ->get()
            ->map(fn (OmIncident $incident): array => [
                'id' => (string) $incident->getKey(),
                'title' => $incident->incident_number.' · '.$incident->title,
                'subtitle' => $incident->description,
                'meta' => collect([$incident->chainage, $incident->status])->filter()->implode(' · '),
                'url' => route('om.incidents', ['search' => $incident->incident_number]),
            ]);

        return $this->group('incidents', 'Incidents', $items);
    }

    private function group(string $key, string $label, Collection $items): ?array
    {
        if ($items->isEmpty()) {
            return null;
        }

        return [
            'key' => $key,
            'label' => $label,
            'items' => $items->values()->all(),
        ];
    }
}
