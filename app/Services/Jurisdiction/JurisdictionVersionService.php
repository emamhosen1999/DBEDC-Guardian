<?php

namespace App\Services\Jurisdiction;

use App\Enums\Carriageway;
use App\Enums\FacilitySubtype;
use App\Enums\JurisdictionKind;
use App\Models\DailyWork;
use App\Models\Jurisdiction;
use App\Models\JurisdictionHandover;
use App\Models\JurisdictionMember;
use App\Models\JurisdictionVersion;
use App\Models\User;
use App\Services\Access\AccessAudit;
use App\Services\Corridor\ChainageResolver;
use App\Support\Corridor\Chainage;
use App\Support\Corridor\GeoPolygon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The writer of jurisdiction definitions and their whole lifecycle (SCD Type 2, every action audited):
 *   add (with an effective date) / edit as a new version / split / merge / retire / delete (mistakes only) / restore.
 * A version is never edited, only closed. The current values are mirrored on `jurisdictions` for older readers.
 *
 * Rules:
 *  - the versions of one jurisdiction never overlap in time;
 *  - on any date, the live versions of the SAME KIND (and a conflicting carriageway) never overlap; different kinds may;
 *  - daily works are attributed to SECTIONS only; a change effective in the past re-attributes the works from that date.
 * Retire is removal with history kept (valid_to set; visible as of past dates). Delete is a soft delete for a jurisdiction
 * created by mistake that has no attributed works, no handovers and no team history. Restore reverses either.
 */
class JurisdictionVersionService
{
    /** True while this service (not a direct model write) is saving, so the model hook stays out of the way. */
    public static bool $managing = false;

    public function __construct(
        private readonly AccessAudit $audit,
        private readonly JurisdictionTeamService $team,
        private readonly JurisdictionAttribution $attribution,
    ) {}

    // ── add ─────────────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $data  kind, subtype, carriageway, code, name, start_m, end_m, geometry, description, colour_token
     * @return array{jurisdiction: Jurisdiction, reattributed: int}
     */
    public function create(array $data, string $incharge, ?User $actor, ?string $effective = null, ?string $reason = null): array
    {
        $guard = HistoryGuard::check($actor, $effective, $reason);

        return DB::transaction(function () use ($data, $incharge, $actor, $guard): array {
            $j = $this->createJurisdiction($data, $incharge, $actor, $guard['date'], $guard['reason'] ?? 'Jurisdiction created');
            $this->team->openInitialInCharge($j, $incharge, $actor, $guard['date'], $guard['correction'], $guard['reason']);
            $this->audit->record('jurisdiction.created', Jurisdiction::class, $j->id, null, $this->snapshot($j) + ['effective_date' => $guard['date']]);
            $this->syncMirror($j);

            return ['jurisdiction' => $j->refresh(), 'reattributed' => $this->reattribute($j, $guard['date'])];
        });
    }

    // ── edit as a new version ───────────────────────────────────────────────────────────────

    /**
     * Name, code, boundary, subtype, carriageway or area differing from the open version make a new version effective on
     * $effective; description and colour are not historical and update in place. The kind never changes.
     *
     * @param  array<string, mixed>  $data
     * @return array{jurisdiction: Jurisdiction, versioned: bool, reattributed: int}
     */
    public function change(Jurisdiction $jurisdiction, array $data, ?User $actor, ?string $effective = null, ?string $reason = null): array
    {
        return DB::transaction(function () use ($jurisdiction, $data, $actor, $effective, $reason): array {
            $j = Jurisdiction::query()->whereKey($jurisdiction->getKey())->lockForUpdate()->firstOrFail();
            $open = $this->latestVersion($j);
            $before = $this->snapshot($j);
            $attrs = $this->attributes($data, $j->kind);

            $same = $open !== null && $this->sameDefinition($open, $attrs);
            if ($same) {
                $this->managed(fn () => $j->forceFill([
                    'description' => array_key_exists('description', $data) ? $data['description'] : $j->description,
                    'colour_token' => array_key_exists('colour_token', $data) ? $data['colour_token'] : $j->colour_token,
                    'updated_by' => $actor?->employee_id,
                ])->save());
                $this->audit->record('jurisdiction.updated', Jurisdiction::class, $j->id, $before, $this->snapshot($j));

                return ['jurisdiction' => $j->refresh(), 'versioned' => false, 'reattributed' => 0];
            }

            $guard = HistoryGuard::check($actor, $effective, $reason);
            $this->assertValid($attrs);
            if ($open !== null && $open->valid_to !== null) {
                throw ValidationException::withMessages(['id' => 'This jurisdiction is retired. Restore it first.']);
            }
            if ($open !== null && $open->valid_from !== null && $guard['date'] <= $open->valid_from->toDateString()) {
                throw ValidationException::withMessages(['effective_date' => 'The effective date must be after the current definition started on '.$open->valid_from->toDateString().'.']);
            }
            $this->assertNoClash($j->id, $attrs, $guard['date'], null);

            if ($open !== null) {
                $open->valid_to = HistoryGuard::dayBefore($guard['date']);
                $open->save();
            }
            $this->makeVersion($j->id, $attrs, $guard['date'], $guard['reason'], $actor);
            $this->managed(fn () => $j->forceFill([
                'description' => array_key_exists('description', $data) ? $data['description'] : $j->description,
                'colour_token' => array_key_exists('colour_token', $data) ? $data['colour_token'] : $j->colour_token,
                'updated_by' => $actor?->employee_id,
            ])->save());
            $this->syncMirror($j);

            $moved = $this->reattribute($j, $guard['date']);
            $this->audit->record($guard['correction'] ? 'jurisdiction.history_corrected' : 'jurisdiction.versioned', Jurisdiction::class, $j->id, $before,
                $this->snapshot($j->refresh()) + ['effective_date' => $guard['date'], 'reason' => $guard['reason'], 'reattributed_works' => $moved]);

            return ['jurisdiction' => $j, 'versioned' => true, 'reattributed' => $moved];
        });
    }

    // ── split / merge ───────────────────────────────────────────────────────────────────────

    /**
     * One section becomes two at chainage $atM from $effective: [start, at-1] and [at, end]. The old section is retired
     * (valid_to the day before) and two new ones open. Works are re-attributed for dates from then on only; the team is
     * copied to both for the admin to adjust.
     *
     * @param  array{code: string, name: string, incharge?: ?string}  $a
     * @param  array{code: string, name: string, incharge?: ?string}  $b
     * @return array{retired: Jurisdiction, first: Jurisdiction, second: Jurisdiction, reattributed: int}
     */
    public function split(Jurisdiction $jurisdiction, int $atM, array $a, array $b, ?User $actor, ?string $effective = null, ?string $reason = null): array
    {
        $guard = HistoryGuard::check($actor, $effective, $reason);

        return DB::transaction(function () use ($jurisdiction, $atM, $a, $b, $actor, $guard): array {
            $j = Jurisdiction::query()->whereKey($jurisdiction->getKey())->lockForUpdate()->firstOrFail();
            $open = $this->requireOpenSection($j, $guard['date']);
            if ($atM - 1 <= $open->start_m || $atM > $open->end_m) {
                throw ValidationException::withMessages(['at_m' => 'The split chainage must fall inside '.Chainage::format($open->start_m).' to '.Chainage::format($open->end_m).', leaving at least two metres on the first part.']);
            }
            $team = $this->team->teamAsOf($j, $guard['date']);
            $incharge = $this->team->inChargeAsOf($j->id, $guard['date']) ?? (string) $j->incharge;
            $before = $this->snapshot($j);

            $this->retireInternal($j, $guard['date'], $guard['reason'] ?? 'Split into two sections');
            $parts = [];
            foreach ([[$a, $open->start_m, $atM - 1], [$b, $atM, $open->end_m]] as [$p, $s, $e]) {
                $new = $this->createJurisdiction([
                    'kind' => 'section', 'carriageway' => $open->carriageway?->value, 'code' => $p['code'], 'name' => $p['name'], 'start_m' => $s, 'end_m' => $e,
                    'description' => $j->description,
                ], $p['incharge'] ?? $incharge, $actor, $guard['date'], 'Split from '.$j->code.' '.$j->location);
                $this->team->copyTeam($new, $team, $p['incharge'] ?? $incharge, $actor, $guard['date']);
                $this->syncMirror($new);
                $parts[] = $new;
            }
            $moved = $this->reattribute($j, $guard['date']);
            $this->audit->record('jurisdiction.split', Jurisdiction::class, $j->id, $before, [
                'at_m' => $atM, 'effective_date' => $guard['date'], 'reason' => $guard['reason'], 'into' => array_map(fn (Jurisdiction $n): array => ['id' => $n->id, 'code' => $n->code], $parts), 'reattributed_works' => $moved,
            ]);

            return ['retired' => $j->refresh(), 'first' => $parts[0]->refresh(), 'second' => $parts[1]->refresh(), 'reattributed' => $moved];
        });
    }

    /**
     * Two adjacent sections become one from $effective (the reverse of a split). Both are retired; the merged section opens
     * with the union of their teams and the chosen in-charge.
     *
     * @param  array{code: string, name: string, incharge: string}  $into
     * @return array{retired: array<int, Jurisdiction>, merged: Jurisdiction, reattributed: int}
     */
    public function merge(Jurisdiction $first, Jurisdiction $second, array $into, ?User $actor, ?string $effective = null, ?string $reason = null): array
    {
        $guard = HistoryGuard::check($actor, $effective, $reason);

        return DB::transaction(function () use ($first, $second, $into, $actor, $guard): array {
            if ($first->id === $second->id) {
                throw ValidationException::withMessages(['with' => 'Choose a different section to merge with.']);
            }
            $a = Jurisdiction::query()->whereKey($first->getKey())->lockForUpdate()->firstOrFail();
            $b = Jurisdiction::query()->whereKey($second->getKey())->lockForUpdate()->firstOrFail();
            $va = $this->requireOpenSection($a, $guard['date']);
            $vb = $this->requireOpenSection($b, $guard['date']);
            [$lo, $hi] = $va->start_m <= $vb->start_m ? [$va, $vb] : [$vb, $va];
            if ($lo->end_m + 1 !== $hi->start_m) {
                throw ValidationException::withMessages(['with' => 'Only adjacent sections can be merged: '.Chainage::format($lo->end_m).' and '.Chainage::format($hi->start_m).' leave a gap or overlap.']);
            }
            if (! Carriageway::conflict($va->carriageway, $vb->carriageway) || ($va->carriageway !== $vb->carriageway)) {
                throw ValidationException::withMessages(['with' => 'Sections on different carriageways cannot be merged.']);
            }
            $teamA = $this->team->teamAsOf($a, $guard['date']);
            $teamB = $this->team->teamAsOf($b, $guard['date']);
            $before = ['from' => [$this->snapshot($a), $this->snapshot($b)]];

            foreach ([$a, $b] as $old) {
                $this->retireInternal($old, $guard['date'], $guard['reason'] ?? 'Merged into one section');
            }
            $merged = $this->createJurisdiction([
                'kind' => 'section', 'carriageway' => $va->carriageway?->value, 'code' => $into['code'], 'name' => $into['name'], 'start_m' => $lo->start_m, 'end_m' => $hi->end_m,
            ], $into['incharge'], $actor, $guard['date'], 'Merged from '.$a->code.' and '.$b->code);
            $this->team->copyTeam($merged, $teamA->concat($teamB), $into['incharge'], $actor, $guard['date']);
            $this->syncMirror($merged);

            $moved = $this->reattribute($a, $guard['date']);
            $this->audit->record('jurisdiction.merged', Jurisdiction::class, $merged->id, $before, [
                'merged' => ['id' => $merged->id, 'code' => $merged->code], 'effective_date' => $guard['date'], 'reason' => $guard['reason'], 'reattributed_works' => $moved,
            ]);

            return ['retired' => [$a->refresh(), $b->refresh()], 'merged' => $merged->refresh(), 'reattributed' => $moved];
        });
    }

    // ── retire / delete / restore ───────────────────────────────────────────────────────────

    /**
     * Remove with history kept: the jurisdiction closes the day before $effective. Its team rows end the same day; its works,
     * handovers and past versions stay and are visible as of past dates. Open works and a standing team must be confirmed.
     *
     * @return array{jurisdiction: Jurisdiction, open_works: int, team: int}
     */
    public function retire(Jurisdiction $jurisdiction, ?User $actor, ?string $effective = null, ?string $reason = null, bool $confirmTeam = false, bool $confirmOpenWorks = false): array
    {
        $guard = HistoryGuard::check($actor, $effective, $reason);

        return DB::transaction(function () use ($jurisdiction, $guard, $confirmTeam, $confirmOpenWorks): array {
            $j = Jurisdiction::query()->whereKey($jurisdiction->getKey())->lockForUpdate()->firstOrFail();
            $open = $this->latestVersion($j);
            if ($open === null || $open->valid_to !== null) {
                throw ValidationException::withMessages(['id' => 'This jurisdiction is already retired.']);
            }
            if ($open->valid_from !== null && $guard['date'] <= $open->valid_from->toDateString()) {
                throw ValidationException::withMessages(['effective_date' => 'The effective date must be after the current definition started on '.$open->valid_from->toDateString().'.']);
            }
            $teamCount = $this->team->teamAsOf($j, $guard['date'])->where('role', '!=', JurisdictionMember::IN_CHARGE)->count();
            $openWorks = $j->kind === JurisdictionKind::Section
                ? DailyWork::query()->where('jurisdiction_id', $j->id)->whereNotIn('status', JurisdictionStatsService::CLOSED)->count() : 0;
            if ($teamCount > 0 && ! $confirmTeam) {
                throw ValidationException::withMessages(['confirm_team' => "The team still has {$teamCount} people besides the in-charge. They leave the team on this date: confirm to continue."]);
            }
            if ($openWorks > 0 && ! $confirmOpenWorks) {
                throw ValidationException::withMessages(['confirm_open_works' => "{$openWorks} open works are still attributed to this section. Hand them over first, or confirm that they stay recorded under it."]);
            }
            $before = $this->snapshot($j);
            $this->retireInternal($j, $guard['date'], $guard['reason'] ?? 'Jurisdiction retired');
            $moved = $this->reattribute($j, $guard['date']);
            $this->audit->record('jurisdiction.retired', Jurisdiction::class, $j->id, $before, ['effective_date' => $guard['date'], 'reason' => $guard['reason'], 'open_works' => $openWorks, 'team' => $teamCount, 'reattributed_works' => $moved]);

            return ['jurisdiction' => $j->refresh(), 'open_works' => $openWorks, 'team' => $teamCount];
        });
    }

    /** Why a jurisdiction cannot be deleted (null = it was a mistake and may be). */
    public function deleteBlocker(Jurisdiction $j): ?string
    {
        if (DailyWork::withTrashed()->where('jurisdiction_id', $j->id)->exists()) {
            return 'Daily works are attributed to it.';
        }
        if (JurisdictionHandover::query()->where('jurisdiction_id', $j->id)->exists()) {
            return 'It has handovers on record.';
        }
        if (JurisdictionVersion::query()->where('jurisdiction_id', $j->id)->count() > 1) {
            return 'Its definition has changed over time.';
        }
        if (JurisdictionMember::query()->where('jurisdiction_id', $j->id)->count() > 1) {
            return 'Its team has changed over time.';
        }

        return null;
    }

    /** Delete is for a jurisdiction created by mistake: soft delete, refused once any history exists (retire it instead). */
    public function delete(Jurisdiction $jurisdiction, ?User $actor, ?string $reason = null): Jurisdiction
    {
        return DB::transaction(function () use ($jurisdiction, $actor, $reason): Jurisdiction {
            $j = Jurisdiction::query()->whereKey($jurisdiction->getKey())->lockForUpdate()->firstOrFail();
            if (($why = $this->deleteBlocker($j)) !== null) {
                throw ValidationException::withMessages(['id' => $why.' Retire it instead: that keeps its history.']);
            }
            $before = $this->snapshot($j);
            $this->managed(function () use ($j, $actor): void {
                $j->forceFill(['updated_by' => $actor?->employee_id])->saveQuietly();
                $j->delete();
            });
            $this->audit->record('jurisdiction.deleted', Jurisdiction::class, $j->id, $before, ['deleted_at' => now()->toIso8601String(), 'reason' => $reason]);

            return $j;
        });
    }

    /**
     * Restore a deleted or retired jurisdiction. A deleted one simply returns; a retired one opens a new version from
     * $effective with the last definition, and an in-charge ($incharge, else the last one).
     */
    public function restore(int $id, ?User $actor, ?string $effective = null, ?string $reason = null, ?string $incharge = null): Jurisdiction
    {
        $guard = HistoryGuard::check($actor, $effective, $reason);

        return DB::transaction(function () use ($id, $actor, $guard, $incharge): Jurisdiction {
            $j = Jurisdiction::withTrashed()->whereKey($id)->lockForUpdate()->firstOrFail();
            $latest = $this->latestVersion($j);
            if ($latest === null) {
                throw ValidationException::withMessages(['id' => 'This jurisdiction has no definition to restore.']);
            }
            $wasDeleted = $j->trashed();
            if (! $wasDeleted && $latest->valid_to === null) {
                throw ValidationException::withMessages(['id' => 'This jurisdiction is not retired or deleted.']);
            }
            $attrs = $this->attributesOfVersion($latest);
            if ($wasDeleted) {
                $this->assertNoClash($j->id, $attrs, $guard['date'], null);
                $this->managed(fn () => $j->restore());
            }
            if ($latest->valid_to !== null) {
                if ($guard['date'] <= $latest->valid_to->toDateString()) {
                    throw ValidationException::withMessages(['effective_date' => 'It was retired until '.$latest->valid_to->toDateString().'; restore it from a later date.']);
                }
                $this->assertNoClash($j->id, $attrs, $guard['date'], null);
                $this->makeVersion($j->id, $attrs, $guard['date'], $guard['reason'] ?? 'Restored', $actor);
                $who = $incharge ?? JurisdictionMember::query()->where('jurisdiction_id', $j->id)->where('role', JurisdictionMember::IN_CHARGE)->orderByDesc('id')->value('employee_id') ?? (string) $j->incharge;
                if ($who === '' || $who === null) {
                    throw ValidationException::withMessages(['incharge' => 'Choose an in-charge for the restored jurisdiction.']);
                }
                $this->team->openInitialInCharge($j, (string) $who, $actor, $guard['date'], $guard['correction'], $guard['reason']);
            }
            $this->syncMirror($j);
            $this->audit->record('jurisdiction.restored', Jurisdiction::class, $j->id, null, ['effective_date' => $guard['date'], 'was_deleted' => $wasDeleted, 'reason' => $guard['reason']]);
            $this->reattribute($j, $guard['date']);

            return $j->refresh();
        });
    }

    // ── mirror, legacy writes, reads ────────────────────────────────────────────────────────

    /** Make the mirror columns on `jurisdictions` equal what is valid today (definition, in-charge). */
    public function syncMirror(Jurisdiction $j): void
    {
        $today = ChainageResolver::today();
        $v = JurisdictionVersion::query()->where('jurisdiction_id', $j->id)
            ->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $today))
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $today))->orderByDesc('id')->first();
        $incharge = $this->team->inChargeAsOf($j->id, $today);

        $this->managed(function () use ($j, $v, $incharge): void {
            $attrs = [];
            if ($v !== null) {
                $attrs += ['code' => $v->code, 'name' => $v->name, 'location' => $v->name, 'start_m' => $v->start_m, 'end_m' => $v->end_m,
                    'start_chainage' => $v->start_chainage ?? '', 'end_chainage' => $v->end_chainage ?? '', 'subtype' => $v->subtype, 'carriageway' => $v->carriageway, 'geometry' => $v->geometry];
            }
            if ($incharge !== null) {
                $attrs['incharge'] = $incharge;
            }
            if ($attrs !== []) {
                $j->forceFill($attrs)->saveQuietly();
            }
        });
        app(ChainageResolver::class)->forget();
    }

    public function syncAllMirrors(): int
    {
        $n = 0;
        foreach (Jurisdiction::query()->get() as $j) {
            $this->syncMirror($j);
            $n++;
        }

        return $n;
    }

    /**
     * A write made directly on the model (older code, tests) rather than through this service: keep a version in step.
     * No version yet -> an open one "since records began". A differing open version that started today or later is a
     * same-day correction and is corrected in place; otherwise it is closed yesterday and a new one opens today.
     */
    public function syncFromModel(Jurisdiction $j): void
    {
        $this->team->syncInChargeFromModel($j);
        if (($j->start_m === null || $j->end_m === null) && $j->geometry === null) {
            return;
        }
        $open = JurisdictionVersion::query()->where('jurisdiction_id', $j->id)->whereNull('valid_to')->orderByDesc('id')->first();
        $values = ['kind' => $j->kind?->value ?? 'section', 'subtype' => $j->subtype, 'carriageway' => $j->carriageway?->value, 'geometry' => $j->geometry,
            'code' => $j->code, 'name' => $j->location, 'start_m' => $j->start_m, 'end_m' => $j->end_m,
            'start_chainage' => $j->start_chainage === '' ? null : $j->start_chainage, 'end_chainage' => $j->end_chainage === '' ? null : $j->end_chainage];
        if ($open === null) {
            if (! JurisdictionVersion::query()->where('jurisdiction_id', $j->id)->exists()) {
                JurisdictionVersion::query()->create($values + ['jurisdiction_id' => $j->id, 'valid_from' => null, 'valid_to' => null, 'change_reason' => 'Initial definition (records began)']);
            }

            return;
        }
        if ($this->sameDefinition($open, $values)) {
            return;
        }
        $today = ChainageResolver::today();
        if ($open->valid_from !== null && $open->valid_from->toDateString() >= $today) {
            $open->fill($values)->save();

            return;
        }
        $open->valid_to = HistoryGuard::dayBefore($today);
        $open->save();
        JurisdictionVersion::query()->create($values + ['jurisdiction_id' => $j->id, 'valid_from' => $today, 'valid_to' => null, 'change_reason' => 'Changed outside the history workflow']);
    }

    /** @return Collection<int, JurisdictionVersion> newest first */
    public function history(Jurisdiction $j)
    {
        return JurisdictionVersion::query()->where('jurisdiction_id', $j->id)->orderByDesc('id')->get();
    }

    // ── internals ───────────────────────────────────────────────────────────────────────────

    private function latestVersion(Jurisdiction $j): ?JurisdictionVersion
    {
        return JurisdictionVersion::query()->where('jurisdiction_id', $j->id)->orderByDesc('id')->first();
    }

    /** The open version of a live section covering $date (split and merge only act on sections). */
    private function requireOpenSection(Jurisdiction $j, string $date): JurisdictionVersion
    {
        $v = $this->latestVersion($j);
        if ($j->kind !== JurisdictionKind::Section || $v === null || $v->valid_to !== null || $j->trashed()) {
            throw ValidationException::withMessages(['id' => $j->location.' is not a current section.']);
        }
        if ($v->valid_from !== null && $date <= $v->valid_from->toDateString()) {
            throw ValidationException::withMessages(['effective_date' => $j->location.' started on '.$v->valid_from->toDateString().'; choose a later date.']);
        }

        return $v;
    }

    /** Close the open version the day before $date and end the open-ended team rows the same day. */
    private function retireInternal(Jurisdiction $j, string $date, string $reason): void
    {
        $last = HistoryGuard::dayBefore($date);
        $open = $this->latestVersion($j);
        if ($open !== null && $open->valid_to === null) {
            $open->valid_to = $last;
            $open->change_reason = $reason;
            $open->save();
        }
        $this->team->endAll($j, $last);
        $this->managed(fn () => $j->forceFill([])->saveQuietly());
        app(ChainageResolver::class)->forget();
    }

    /** @param array<string, mixed> $data */
    private function createJurisdiction(array $data, string $incharge, ?User $actor, string $from, ?string $reason): Jurisdiction
    {
        $attrs = $this->attributes($data, null);
        $this->assertValid($attrs);
        $this->assertNoClash(null, $attrs, $from, null);

        $j = $this->managed(fn () => Jurisdiction::query()->create([
            'kind' => $attrs['kind'], 'subtype' => $attrs['subtype'], 'carriageway' => $attrs['carriageway'], 'geometry' => $attrs['geometry'],
            'code' => $attrs['code'], 'name' => $attrs['name'], 'start_m' => $attrs['start_m'], 'end_m' => $attrs['end_m'],
            'incharge' => $incharge, 'description' => $data['description'] ?? null, 'colour_token' => $data['colour_token'] ?? null,
            'created_by' => $actor?->employee_id, 'updated_by' => $actor?->employee_id,
        ]));
        $this->makeVersion($j->id, $attrs, $from, $reason, $actor);

        return $j;
    }

    /** @param array<string, mixed> $attrs */
    private function makeVersion(int $jurisdictionId, array $attrs, ?string $from, ?string $reason, ?User $actor): JurisdictionVersion
    {
        return JurisdictionVersion::query()->create([
            'jurisdiction_id' => $jurisdictionId, 'kind' => $attrs['kind'], 'subtype' => $attrs['subtype'], 'carriageway' => $attrs['carriageway'], 'geometry' => $attrs['geometry'],
            'code' => $attrs['code'], 'name' => $attrs['name'], 'start_m' => $attrs['start_m'], 'end_m' => $attrs['end_m'],
            'start_chainage' => $attrs['start_m'] === null ? null : Chainage::format((int) $attrs['start_m']), 'end_chainage' => $attrs['end_m'] === null ? null : Chainage::format((int) $attrs['end_m']),
            'valid_from' => $from, 'valid_to' => null, 'change_reason' => $reason, 'changed_by' => $actor?->employee_id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $d
     * @return array{kind: string, subtype: ?string, carriageway: ?string, code: string, name: string, start_m: ?int, end_m: ?int, geometry: ?array}
     */
    private function attributes(array $d, ?JurisdictionKind $fixedKind): array
    {
        $kind = $fixedKind ?? JurisdictionKind::from((string) ($d['kind'] ?? 'section'));
        $chain = $kind->usesChainage();

        return [
            'kind' => $kind->value,
            'subtype' => $kind === JurisdictionKind::Facility ? ($d['subtype'] ?? null) : null,
            'carriageway' => $chain ? ($d['carriageway'] ?? null) : null,
            'code' => (string) $d['code'], 'name' => (string) $d['name'],
            'start_m' => $chain && isset($d['start_m']) ? (int) $d['start_m'] : null,
            'end_m' => $chain && isset($d['end_m']) ? (int) $d['end_m'] : null,
            'geometry' => $kind === JurisdictionKind::Area ? ($d['geometry'] ?? null) : null,
        ];
    }

    /** @return array<string, mixed> */
    private function attributesOfVersion(JurisdictionVersion $v): array
    {
        return ['kind' => $v->kind?->value ?? 'section', 'subtype' => $v->subtype, 'carriageway' => $v->carriageway?->value, 'code' => $v->code, 'name' => $v->name,
            'start_m' => $v->start_m, 'end_m' => $v->end_m, 'geometry' => $v->geometry];
    }

    /** @param array<string, mixed> $attrs */
    private function sameDefinition(JurisdictionVersion|array $open, array $attrs): bool
    {
        $o = $open instanceof JurisdictionVersion ? $this->attributesOfVersion($open) : [
            'kind' => $open['kind'] instanceof JurisdictionKind ? $open['kind']->value : $open['kind'], 'subtype' => $open['subtype'] ?? null, 'carriageway' => $open['carriageway'] ?? null,
            'code' => $open['code'] ?? null, 'name' => $open['name'] ?? null, 'start_m' => $open['start_m'] ?? null, 'end_m' => $open['end_m'] ?? null, 'geometry' => $open['geometry'] ?? null,
        ];
        foreach (['kind', 'subtype', 'carriageway', 'code', 'name'] as $k) {
            if (($o[$k] ?? null) !== ($attrs[$k] ?? null)) {
                return false;
            }
        }
        foreach (['start_m', 'end_m'] as $k) {
            if (($o[$k] === null ? null : (int) $o[$k]) !== ($attrs[$k] === null ? null : (int) $attrs[$k])) {
                return false;
            }
        }

        return json_encode($o['geometry'] ?? null) === json_encode($attrs['geometry'] ?? null);
    }

    /** @param array<string, mixed> $a */
    private function assertValid(array $a): void
    {
        $kind = JurisdictionKind::from($a['kind']);
        if ($kind === JurisdictionKind::Area) {
            if (empty($a['geometry'])) {
                throw ValidationException::withMessages(['geometry' => 'An area needs a polygon: choose a geofence or upload GeoJSON.']);
            }

            return;
        }
        if ($a['start_m'] === null || $a['end_m'] === null) {
            throw ValidationException::withMessages(['start_m' => 'Start and end chainage are required.']);
        }
        if ($a['start_m'] < ChainageResolver::CORRIDOR_START_M || $a['end_m'] > ChainageResolver::CORRIDOR_END_M) {
            throw ValidationException::withMessages(['end_m' => 'Chainage must be inside the corridor (K0+000 to K48+999).']);
        }
        if ($kind === JurisdictionKind::Section && $a['start_m'] >= $a['end_m']) {
            throw ValidationException::withMessages(['end_m' => 'End chainage must be after the start chainage ('.Chainage::format($a['start_m']).').']);
        }
        if ($kind === JurisdictionKind::Facility) {
            if ($a['end_m'] < $a['start_m']) {
                throw ValidationException::withMessages(['end_m' => 'End chainage cannot be before the start chainage.']);
            }
            if (FacilitySubtype::tryFrom((string) $a['subtype']) === null) {
                throw ValidationException::withMessages(['subtype' => 'Choose what kind of facility this is.']);
            }
        }
    }

    /**
     * The new interval [from, to] may not share road (or ground) with any OTHER live jurisdiction of the same kind and a
     * conflicting carriageway that is valid at any time in it. Different kinds may overlap freely.
     *
     * @param  array<string, mixed>  $a
     */
    private function assertNoClash(?int $selfId, array $a, string $from, ?string $to): void
    {
        $kind = JurisdictionKind::from($a['kind']);
        $candidates = JurisdictionVersion::query()
            ->whereIn('jurisdiction_id', Jurisdiction::query()->select('id'))
            ->when($selfId, fn ($q) => $q->where('jurisdiction_id', '<>', $selfId))
            ->where('kind', $kind->value)
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('valid_from')->orWhere('valid_from', '<=', $to)))
            ->when($kind->usesChainage(), fn ($q) => $q->where('start_m', '<=', $a['end_m'])->where('end_m', '>=', $a['start_m']))
            ->orderBy('start_m')->get();

        $mine = Carriageway::tryFrom((string) ($a['carriageway'] ?? ''));
        foreach ($candidates as $c) {
            if ($kind->usesChainage() && ! Carriageway::conflict($mine, $c->carriageway)) {
                continue;
            }
            if ($kind === JurisdictionKind::Area && ! GeoPolygon::overlaps($a['geometry'], $c->geometry ?? [])) {
                continue;
            }
            throw ValidationException::withMessages([$kind->usesChainage() ? 'start_m' : 'geometry' => sprintf(
                'Overlaps %s%s on %s onward. Two %ss cannot share the same ground at the same time (a %s may overlap a different kind).',
                $c->name, $kind->usesChainage() ? ' ('.Chainage::format($c->start_m).' to '.Chainage::format($c->end_m).')' : '', $from, $kind->value, $kind->value,
            )]);
        }
    }

    /** Re-attribute works from $date (sections only affect attribution); returns how many rows changed. */
    private function reattribute(Jurisdiction $j, string $date): int
    {
        return $j->kind === JurisdictionKind::Section ? $this->attribution->run($date, null, true)['changed'] : 0;
    }

    /** @return array<string, mixed> */
    private function snapshot(Jurisdiction $j): array
    {
        return ['kind' => $j->kind?->value, 'subtype' => $j->subtype, 'carriageway' => $j->carriageway?->value, 'code' => $j->code, 'name' => $j->location,
            'start_m' => $j->start_m, 'end_m' => $j->end_m, 'incharge' => $j->incharge, 'description' => $j->description, 'colour_token' => $j->colour_token];
    }

    /** @template T @param callable(): T $fn @return T */
    private function managed(callable $fn): mixed
    {
        $was = self::$managing;
        self::$managing = true;
        try {
            return $fn();
        } finally {
            self::$managing = $was;
        }
    }
}
