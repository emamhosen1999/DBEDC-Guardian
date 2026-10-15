<?php

namespace App\Http\Controllers;

use App\Models\RfiObjection;
use App\Support\Corridor\Chainage;
use App\Traits\ChainageMatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The objections register for the Daily Works page: paged, filtered, with the chainage read by the shared service and the
 * linked RFIs. The lifecycle actions stay on the existing objection routes (each authorised by the objection policy).
 */
class DailyWorkObjectionRegisterController extends Controller
{
    use ChainageMatcher;

    public function __invoke(Request $request): JsonResponse
    {
        $this->authorize('viewAny', RfiObjection::class);
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:120'], 'status' => ['nullable', Rule::in(array_keys(RfiObjection::$statusLabels))],
            'category' => ['nullable', Rule::in(RfiObjection::$categories)], 'chainage' => ['nullable', 'string', 'max:50'],
            'date_from' => ['nullable', 'date_format:Y-m-d'], 'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $query = RfiObjection::query()->with(['createdBy:employee_id,name', 'chainages', 'dailyWorks:id,number,location'])->withCount('dailyWorks');
        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }
        if (! empty($data['category'])) {
            $query->where('category', $data['category']);
        }
        if (! empty($data['date_from'])) {
            $query->whereDate('created_at', '>=', $data['date_from']);
        }
        if (! empty($data['date_to'])) {
            $query->whereDate('created_at', '<=', $data['date_to']);
        }
        if (! empty($data['chainage'])) {
            $m = $this->parseChainageToMeters($data['chainage']) ?? Chainage::token($data['chainage']);
            abort_if($m === null, 422, 'Enter a chainage such as K5+900.');
            // an objection covers the point when a specific chainage equals it, or the range start..end contains it
            $query->where(fn ($o) => $o->whereHas('chainages', fn ($c) => $c->where('entry_type', 'specific')->where('chainage_meters', $m))
                ->orWhere(fn ($r) => $r->whereHas('chainages', fn ($c) => $c->where('entry_type', 'range_start')->where('chainage_meters', '<=', $m))
                    ->whereHas('chainages', fn ($c) => $c->where('entry_type', 'range_end')->where('chainage_meters', '>=', $m))));
        }
        foreach (array_filter(preg_split('/\s+/', trim((string) ($data['q'] ?? '')))) as $word) {
            $like = '%'.$word.'%';
            $query->where(fn ($w) => $w->where('title', 'like', $like)->orWhere('description', 'like', $like)->orWhere('reason', 'like', $like)
                ->orWhereHas('dailyWorks', fn ($d) => $d->where('number', 'like', $like)));
        }

        $page = $query->orderByDesc('created_at')->orderByDesc('id')->paginate((int) ($data['per_page'] ?? 20));
        $user = $request->user();
        $counts = RfiObjection::query()->toBase()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return response()->json([
            'data' => $page->getCollection()->map(fn (RfiObjection $o): array => [
                'id' => $o->id, 'title' => $o->title, 'status' => $o->status, 'category' => $o->category, 'category_label' => $o->category_label, 'type' => $o->type,
                'description' => $o->description, 'reason' => $o->reason, 'resolution_notes' => $o->resolution_notes, 'created_at' => $o->created_at?->toIso8601String(),
                'resolved_at' => $o->resolved_at?->toIso8601String(), 'created_by' => $o->createdBy?->name, 'lock_version' => (int) $o->lock_version,
                'chainages' => $o->chainages->map(fn ($c) => ['text' => $c->chainage, 'type' => $c->entry_type])->values()->all(),
                'works_count' => $o->daily_works_count, 'works' => $o->dailyWorks->take(5)->map(fn ($w): array => ['id' => $w->id, 'number' => $w->number, 'location' => $w->location])->all(),
                'can' => ['submit' => $user->can('submit', $o), 'review' => $user->can('review', $o)],
            ])->all(),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
            'counts' => $counts,
            'labels' => ['status' => RfiObjection::$statusLabels, 'category' => RfiObjection::$categoryLabels],
        ]);
    }
}
