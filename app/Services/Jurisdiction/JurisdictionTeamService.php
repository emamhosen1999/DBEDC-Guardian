<?php

namespace App\Services\Jurisdiction;

use App\Models\DailyWork;
use App\Models\Jurisdiction;
use App\Models\JurisdictionHandover;
use App\Models\JurisdictionHandoverTransfer;
use App\Models\JurisdictionMember;
use App\Models\User;
use App\Services\Access\AccessAudit;
use App\Services\Corridor\ChainageResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of jurisdiction_members and handovers. Everything is effective-dated (inclusive Asia/Dhaka dates):
 *   - on any date exactly one in-charge row covers it; an employee holds one role per jurisdiction at a time;
 *   - a change closes the old row the day BEFORE the effective date and opens a new one on it; rows are never deleted;
 *   - a future date is a planned change and takes effect by date range, not by a job;
 *   - a past date is a retroactive correction: jurisdiction.history.correct plus a reason (HistoryGuard), flagged and audited;
 *   - jurisdictions.incharge mirrors the in-charge valid today (older code reads it).
 * A change of in-charge is a handover event with an immutable snapshot of the open daily works. Works are moved to the
 * incoming in-charge only by the explicit, previewed transferOpenWorks(); a work's recorded in-charge is never rewritten
 * otherwise, and completed works never change.
 */
class JurisdictionTeamService
{
    public function __construct(private readonly AccessAudit $audit) {}

    /** The employee id who was in charge of the jurisdiction on a date, from the team rows. */
    public function inChargeAsOf(int $jurisdictionId, ?string $date = null): ?string
    {
        $date ??= ChainageResolver::today();

        return JurisdictionMember::query()->where('jurisdiction_id', $jurisdictionId)->where('role', JurisdictionMember::IN_CHARGE)
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $date))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date))
            ->orderByDesc('id')->value('employee_id');
    }

    /** @return Collection<int, JurisdictionMember> the team valid on a date, in-charge first */
    public function teamAsOf(Jurisdiction $jurisdiction, ?string $date = null): Collection
    {
        $date ??= ChainageResolver::today();

        return JurisdictionMember::query()->where('jurisdiction_id', $jurisdiction->id)
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $date))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date))
            ->with(['employee:employee_id,name,designation_id', 'employee.designation:id,title', 'employee.media'])
            ->get()->sortBy(fn (JurisdictionMember $m): string => JurisdictionMember::ROLE_RANK[$m->role].'|'.$m->employee?->name)->values();
    }

    /**
     * Make $employeeId the in-charge from $effective. The outgoing in-charge's row ends the day before; $previousBecomes
     * keeps them on the team as 'deputy_in_charge' or 'member' (null = they leave the team). Records the handover.
     *
     * @return array{member: JurisdictionMember, handover: ?JurisdictionHandover}
     */
    public function setInCharge(Jurisdiction $jurisdiction, string $employeeId, ?User $actor = null, ?string $previousBecomes = null, ?string $effective = null, ?string $reason = null, ?string $note = null): array
    {
        if ($previousBecomes !== null && ! in_array($previousBecomes, [JurisdictionMember::DEPUTY, JurisdictionMember::MEMBER], true)) {
            throw ValidationException::withMessages(['previous_becomes' => 'The previous in-charge can become a deputy in-charge or a member, or leave the team.']);
        }
        $guard = HistoryGuard::check($actor, $effective, $reason);

        return DB::transaction(function () use ($jurisdiction, $employeeId, $actor, $previousBecomes, $guard, $note): array {
            $locked = Jurisdiction::query()->whereKey($jurisdiction->getKey())->lockForUpdate()->firstOrFail();
            $date = $guard['date'];

            $latest = $this->latestRow($locked->id, JurisdictionMember::IN_CHARGE);
            if ($latest?->employee_id === $employeeId && $latest->effective_to === null && ($latest->effective_from === null || $latest->effective_from->toDateString() <= $date)) {
                return ['member' => $latest, 'handover' => null];
            }
            $this->assertAfterStart($latest, $date);

            $before = $this->snapshot($locked);
            $outgoing = $latest?->employee_id;
            if ($latest !== null && $latest->effective_to === null) {
                $this->close($latest, HistoryGuard::dayBefore($date));
            }
            // The incoming in-charge may already hold another role here: that row ends the day before.
            $held = JurisdictionMember::query()->where('jurisdiction_id', $locked->id)->where('employee_id', $employeeId)->whereNull('effective_to')->first();
            if ($held !== null) {
                $this->assertAfterStart($held, $date);
                $this->close($held, HistoryGuard::dayBefore($date));
            }
            $this->assertNoOverlap($locked->id, $employeeId, $date);

            $row = $this->open($locked, $employeeId, JurisdictionMember::IN_CHARGE, $actor, $date, $guard);
            if ($outgoing !== null && $previousBecomes !== null && $outgoing !== $employeeId) {
                $this->open($locked, $outgoing, $previousBecomes, $actor, $date, $guard);
            }

            $handover = $outgoing === null ? null : $this->recordHandover($locked, $outgoing, $employeeId, $date, $note ?? $guard['reason'], $actor);
            $this->syncMirror($locked, $actor);
            $this->audit->record($guard['correction'] ? 'jurisdiction.team.history_corrected' : 'jurisdiction.team.in_charge_changed', Jurisdiction::class, $locked->id, $before,
                $this->snapshot($locked) + ['effective_date' => $date, 'reason' => $guard['reason'], 'handover_id' => $handover?->id]);

            return ['member' => $row, 'handover' => $handover];
        });
    }

    /** Add a deputy in-charge or a member from $effective. */
    public function addMember(Jurisdiction $jurisdiction, string $employeeId, string $role, ?User $actor = null, ?string $effective = null, ?string $reason = null): JurisdictionMember
    {
        if (! in_array($role, [JurisdictionMember::DEPUTY, JurisdictionMember::MEMBER], true)) {
            throw ValidationException::withMessages(['role' => 'Add a deputy in-charge or a member. The in-charge is changed with "Change in-charge".']);
        }
        $guard = HistoryGuard::check($actor, $effective, $reason);

        return DB::transaction(function () use ($jurisdiction, $employeeId, $role, $actor, $guard): JurisdictionMember {
            $locked = Jurisdiction::query()->whereKey($jurisdiction->getKey())->lockForUpdate()->firstOrFail();
            $held = JurisdictionMember::query()->where('jurisdiction_id', $locked->id)->where('employee_id', $employeeId)->whereNull('effective_to')->first();
            if ($held !== null) {
                throw ValidationException::withMessages(['employee_id' => 'This employee is already on the team of '.$locked->location.' as '.$this->roleLabel($held->role).'.']);
            }
            $this->assertNoOverlap($locked->id, $employeeId, $guard['date']);

            $before = $this->snapshot($locked);
            $row = $this->open($locked, $employeeId, $role, $actor, $guard['date'], $guard);
            $this->audit->record($guard['correction'] ? 'jurisdiction.team.history_corrected' : 'jurisdiction.team.member_added', Jurisdiction::class, $locked->id, $before,
                $this->snapshot($locked) + ['effective_date' => $guard['date'], 'reason' => $guard['reason']]);

            return $row;
        });
    }

    /** Move a deputy/member to the other of the two roles from $effective (old row ends the day before, a new one opens). */
    public function changeRole(JurisdictionMember $member, string $role, ?User $actor = null, ?string $effective = null, ?string $reason = null): JurisdictionMember
    {
        if (! in_array($role, [JurisdictionMember::DEPUTY, JurisdictionMember::MEMBER], true)) {
            throw ValidationException::withMessages(['role' => 'A role can be changed to deputy in-charge or member.']);
        }
        $guard = HistoryGuard::check($actor, $effective, $reason);

        return DB::transaction(function () use ($member, $role, $actor, $guard): JurisdictionMember {
            $locked = Jurisdiction::query()->whereKey($member->jurisdiction_id)->lockForUpdate()->firstOrFail();
            $fresh = JurisdictionMember::query()->findOrFail($member->getKey());
            if (! $fresh->isOpenEnded()) {
                throw ValidationException::withMessages(['member' => 'This membership has already ended.']);
            }
            if ($fresh->role === JurisdictionMember::IN_CHARGE) {
                throw ValidationException::withMessages(['member' => 'The in-charge is changed with "Change in-charge".']);
            }
            if ($fresh->role === $role) {
                return $fresh;
            }
            $this->assertAfterStart($fresh, $guard['date']);

            $before = $this->snapshot($locked);
            $this->close($fresh, HistoryGuard::dayBefore($guard['date']));
            $row = $this->open($locked, $fresh->employee_id, $role, $actor, $guard['date'], $guard);
            $this->audit->record($guard['correction'] ? 'jurisdiction.team.history_corrected' : 'jurisdiction.team.role_changed', Jurisdiction::class, $locked->id, $before,
                $this->snapshot($locked) + ['effective_date' => $guard['date'], 'reason' => $guard['reason']]);

            return $row;
        });
    }

    /** End a membership from $effective (the last day on the team is the day before). The in-charge cannot leave without a successor. */
    public function endMembership(JurisdictionMember $member, ?User $actor = null, ?string $effective = null, ?string $reason = null): JurisdictionMember
    {
        $guard = HistoryGuard::check($actor, $effective, $reason);

        return DB::transaction(function () use ($member, $guard): JurisdictionMember {
            $locked = Jurisdiction::query()->whereKey($member->jurisdiction_id)->lockForUpdate()->firstOrFail();
            $fresh = JurisdictionMember::query()->findOrFail($member->getKey());
            if (! $fresh->isOpenEnded()) {
                throw ValidationException::withMessages(['member' => 'This membership has already ended.']);
            }
            if ($fresh->role === JurisdictionMember::IN_CHARGE) {
                throw ValidationException::withMessages(['member' => 'A jurisdiction always has an in-charge: choose the new in-charge instead.']);
            }
            $this->assertAfterStart($fresh, $guard['date']);

            $before = $this->snapshot($locked);
            $this->close($fresh, HistoryGuard::dayBefore($guard['date']));
            $this->audit->record($guard['correction'] ? 'jurisdiction.team.history_corrected' : 'jurisdiction.team.member_removed', Jurisdiction::class, $locked->id, $before,
                $this->snapshot($locked) + ['effective_date' => $guard['date'], 'reason' => $guard['reason']]);

            return $fresh->refresh();
        });
    }

    /**
     * Copy a team onto a new jurisdiction from $from (split, merge): the in-charge first, then every other person once, in
     * their own role. The admin adjusts afterwards; nothing is invented.
     *
     * @param  Collection<int, JurisdictionMember>  $members
     */
    public function copyTeam(Jurisdiction $new, Collection $members, string $incharge, ?User $actor, string $from): void
    {
        $guard = ['correction' => false, 'reason' => null];
        $this->open($new, $incharge, JurisdictionMember::IN_CHARGE, $actor, $from, $guard);
        $seen = [$incharge => true];
        foreach ($members->sortBy(fn (JurisdictionMember $m): int => JurisdictionMember::ROLE_RANK[$m->role]) as $m) {
            if (isset($seen[$m->employee_id]) || $m->role === JurisdictionMember::IN_CHARGE) {
                continue;
            }
            $seen[$m->employee_id] = true;
            $this->open($new, (string) $m->employee_id, $m->role, $actor, $from, $guard);
        }
    }

    /** End every open-ended row of the jurisdiction on $lastDay (retire, split, merge): history stays. */
    public function endAll(Jurisdiction $j, string $lastDay): void
    {
        JurisdictionMember::query()->where('jurisdiction_id', $j->id)->whereNull('effective_to')
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $lastDay))->get()
            ->each(fn (JurisdictionMember $m) => $this->close($m, $lastDay));
    }

    /** First in-charge of a new jurisdiction (the create is audited as a whole). */
    public function openInitialInCharge(Jurisdiction $jurisdiction, string $employeeId, ?User $actor, string $from, bool $correction = false, ?string $reason = null): JurisdictionMember
    {
        return $this->open($jurisdiction, $employeeId, JurisdictionMember::IN_CHARGE, $actor, $from, ['correction' => $correction, 'reason' => $reason]);
    }

    /**
     * A write made directly on the model (older code, tests) set `incharge`: keep the team in step. No in-charge row yet ->
     * one opens "since records began"; a different employee -> an ordinary change of in-charge effective today.
     */
    public function syncInChargeFromModel(Jurisdiction $j): void
    {
        if ($j->incharge === null || $j->incharge === '') {
            return;
        }
        $latest = $this->latestRow($j->id, JurisdictionMember::IN_CHARGE);
        if ($latest === null) {
            JurisdictionMember::query()->create(['jurisdiction_id' => $j->id, 'employee_id' => (string) $j->incharge, 'role' => JurisdictionMember::IN_CHARGE, 'effective_from' => null, 'effective_to' => null]);

            return;
        }
        if ($j->wasChanged('incharge') && $this->inChargeAsOf($j->id) !== (string) $j->incharge) {
            $this->setInCharge($j, (string) $j->incharge);
        }
    }

    /**
     * Preview of what moving the open works would do: the works of this jurisdiction that are still open and still
     * recorded under the OUTGOING in-charge. Nothing is written.
     *
     * @return array{handover: JurisdictionHandover, works: Collection<int, DailyWork>}
     */
    public function previewTransfer(JurisdictionHandover $handover): array
    {
        $works = DailyWork::query()->where('jurisdiction_id', $handover->jurisdiction_id)->where('incharge', $handover->outgoing_employee_id)
            ->whereNotIn('status', JurisdictionStatsService::CLOSED)->orderBy('date')->orderBy('id')
            ->get(['id', 'number', 'date', 'status', 'type', 'location', 'incharge']);

        return ['handover' => $handover, 'works' => $works];
    }

    /**
     * The explicit, audited move: open works recorded under the outgoing in-charge are re-assigned to the incoming one.
     * Only works the caller confirmed (`$ids` must be a subset of the preview) and only open ones; completed works never change.
     *
     * @param  array<int, int>  $ids
     */
    public function transferOpenWorks(JurisdictionHandover $handover, array $ids, ?User $actor): JurisdictionHandoverTransfer
    {
        return DB::transaction(function () use ($handover, $ids, $actor): JurisdictionHandoverTransfer {
            $eligible = $this->previewTransfer($handover)['works']->keyBy('id');
            $picked = array_values(array_intersect($ids, $eligible->keys()->all()));
            if ($picked === []) {
                throw ValidationException::withMessages(['work_ids' => 'None of the selected works is open and still under '.($handover->outgoing?->name ?? 'the outgoing in-charge').'.']);
            }
            $previous = [];
            foreach (DailyWork::query()->whereIn('id', $picked)->get() as $work) {
                $previous[$work->id] = $work->incharge;
                $work->incharge = $handover->incoming_employee_id;
                $work->save();
            }
            $transfer = JurisdictionHandoverTransfer::query()->create([
                'handover_id' => $handover->id, 'to_employee_id' => $handover->incoming_employee_id, 'works_count' => count($picked),
                'work_ids' => $picked, 'previous_incharge' => $previous, 'performed_by' => $actor?->employee_id, 'performed_at' => now(),
            ]);
            $this->audit->record('jurisdiction.handover.works_transferred', JurisdictionHandover::class, $handover->id, ['incharge' => $handover->outgoing_employee_id],
                ['incharge' => $handover->incoming_employee_id, 'works' => count($picked), 'work_ids' => $picked]);

            return $transfer;
        });
    }

    /** @return Collection<int, JurisdictionMember> every row, newest first */
    public function history(Jurisdiction $jurisdiction): Collection
    {
        return JurisdictionMember::query()->where('jurisdiction_id', $jurisdiction->id)
            ->with(['employee:employee_id,name,designation_id', 'employee.designation:id,title', 'assigner:employee_id,name'])
            ->orderByRaw('effective_from IS NULL DESC')->orderByDesc('effective_from')->orderByDesc('id')->get();
    }

    public function roleLabel(string $role): string
    {
        return ['in_charge' => 'in-charge', 'deputy_in_charge' => 'deputy in-charge', 'member' => 'member'][$role] ?? $role;
    }

    private function latestRow(int $jurisdictionId, string $role): ?JurisdictionMember
    {
        return JurisdictionMember::query()->where('jurisdiction_id', $jurisdictionId)->where('role', $role)->orderByRaw('effective_to IS NULL DESC')->orderByDesc('effective_to')->orderByDesc('id')->first();
    }

    /** A new line may only start after the row it replaces started (a NULL start = since records began). */
    private function assertAfterStart(?JurisdictionMember $row, string $date): void
    {
        if ($row !== null && $row->effective_from !== null && $date <= $row->effective_from->toDateString()) {
            throw ValidationException::withMessages(['effective_date' => 'The effective date must be after '.$row->effective_from->toDateString().', when the current assignment started.']);
        }
        if ($row !== null && $row->effective_to !== null && $date <= $row->effective_to->toDateString()) {
            throw ValidationException::withMessages(['effective_date' => 'The effective date must be after '.$row->effective_to->toDateString().', when the latest assignment ended.']);
        }
    }

    /** An employee may not hold two roles in one jurisdiction on the same day. */
    private function assertNoOverlap(int $jurisdictionId, string $employeeId, string $from): void
    {
        $clash = JurisdictionMember::query()->where('jurisdiction_id', $jurisdictionId)->where('employee_id', $employeeId)
            ->whereNotNull('effective_to')->where('effective_to', '>=', $from)->first();
        if ($clash !== null) {
            throw ValidationException::withMessages(['employee_id' => 'This employee already holds a role in this jurisdiction until '.$clash->effective_to->toDateString().'.']);
        }
    }

    /** @param array{correction: bool, reason: ?string} $guard */
    private function open(Jurisdiction $j, string $employeeId, string $role, ?User $actor, string $from, array $guard): JurisdictionMember
    {
        return JurisdictionMember::query()->create([
            'jurisdiction_id' => $j->id, 'employee_id' => $employeeId, 'role' => $role,
            'effective_from' => $from, 'effective_to' => null, 'assigned_by' => $actor?->employee_id,
            'reason' => $guard['reason'], 'is_correction' => $guard['correction'],
        ]);
    }

    private function close(JurisdictionMember $row, string $lastDay): void
    {
        $row->effective_to = $lastDay;
        $row->save();
    }

    /** Snapshot the OPEN works of the jurisdiction at this moment: count and ids, immutable. */
    private function recordHandover(Jurisdiction $j, string $outgoing, string $incoming, string $date, ?string $note, ?User $actor): JurisdictionHandover
    {
        $ids = DailyWork::query()->where('jurisdiction_id', $j->id)->whereNotIn('status', JurisdictionStatsService::CLOSED)->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();

        return JurisdictionHandover::query()->create([
            'jurisdiction_id' => $j->id, 'effective_date' => $date, 'outgoing_employee_id' => $outgoing, 'incoming_employee_id' => $incoming,
            'note' => $note, 'open_works_count' => count($ids), 'open_work_ids' => $ids, 'snapshot_at' => now(), 'created_by' => $actor?->employee_id,
        ]);
    }

    private function syncMirror(Jurisdiction $j, ?User $actor): void
    {
        $now = $this->inChargeAsOf($j->id);
        if ($now !== null && $now !== $j->incharge) {
            $was = JurisdictionVersionService::$managing;
            JurisdictionVersionService::$managing = true;
            try {
                $j->forceFill(['incharge' => $now, 'updated_by' => $actor?->employee_id])->saveQuietly();
            } finally {
                JurisdictionVersionService::$managing = $was;
            }
        }
    }

    /** @return array<string, mixed> the team rows still open-ended (the lines in force or planned), for the audit before/after */
    private function snapshot(Jurisdiction $j): array
    {
        return [
            'incharge' => $j->incharge,
            'team' => JurisdictionMember::query()->where('jurisdiction_id', $j->id)->whereNull('effective_to')->orderBy('id')
                ->get(['employee_id', 'role', 'effective_from'])->map(fn ($m): array => ['employee_id' => $m->employee_id, 'role' => $m->role, 'from' => $m->effective_from?->toDateString()])->all(),
        ];
    }
}
