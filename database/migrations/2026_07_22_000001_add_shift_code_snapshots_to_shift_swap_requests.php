<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Persist the give-up / take shift CODES on each swap at request time so the
 * display no longer reads them live from the roster. Live derivation broke once
 * a swap was approved: applySwap rewrites the roster, so the later lookup
 * returned the POST-swap shift (typically OFF) instead of what was swapped.
 *
 * `requester_shift_code` doubles as the "row is snapshotted" sentinel — new rows
 * always set it (OFF when the person is off), so a NULL reliably marks a legacy
 * row that must fall back to derivation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shift_swap_requests', function (Blueprint $table) {
            $table->string('requester_shift_code', 16)->nullable()->after('requested_shift_id');
            $table->string('counterparty_shift_code', 16)->nullable()->after('requester_shift_code');
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::table('shift_swap_requests', function (Blueprint $table) {
            $table->dropColumn(['requester_shift_code', 'counterparty_shift_code']);
        });
    }

    /**
     * Best-effort backfill of existing rows:
     *  - approved (already applied): reconstruct from the swapped cells — the
     *    give-up shift is what the counterparty now holds on requester_date, the
     *    take shift is what the requester now holds on counterparty_date.
     *  - otherwise (pending/rejected/cancelled, not applied): the live roster
     *    still reflects the original shifts (except the rare case where a later
     *    swap moved that cell — unrecoverable, left best-effort).
     */
    private function backfill(): void
    {
        $codeByShiftId = DB::table('shifts')->pluck('code', 'id');

        $shiftCodeFor = function (?int $userId, ?string $date) use ($codeByShiftId): ?string {
            if (! $userId || ! $date) {
                return null;
            }
            $shiftId = DB::table('roster_days')
                ->where('user_id', $userId)
                ->where('date', $date)
                ->value('shift_id');

            if ($shiftId === null) {
                return 'OFF';
            }

            return $codeByShiftId[$shiftId] ?? null;
        };

        DB::table('shift_swap_requests')->orderBy('id')->each(function ($swap) use ($shiftCodeFor) {
            $reqDate = $swap->requester_date;
            $cpDate = $swap->counterparty_date;
            $applied = $swap->status === 'approved';

            if ($applied) {
                // Reconstruct from the post-swap roster.
                $requesterCode = $shiftCodeFor($swap->counterparty_id, $reqDate);
                $counterpartyCode = ($swap->type === 'cover' || ! $cpDate)
                    ? null
                    : $shiftCodeFor($swap->requester_id, $cpDate);
            } else {
                // Not applied — roster still holds the original shifts.
                $requesterCode = $shiftCodeFor($swap->requester_id, $reqDate);
                $counterpartyCode = ($swap->type === 'cover' || ! $cpDate)
                    ? null
                    : $shiftCodeFor($swap->counterparty_id, $cpDate);
            }

            // requester_shift_code is the sentinel — never leave it null on an
            // existing row, or display would treat the row as un-snapshotted.
            DB::table('shift_swap_requests')->where('id', $swap->id)->update([
                'requester_shift_code' => $requesterCode ?? 'OFF',
                'counterparty_shift_code' => $counterpartyCode,
            ]);
        });
    }
};
