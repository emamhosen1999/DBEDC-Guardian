<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seeds the single real project — Dhaka Bypass Expressway (N-105) PPP — plus its
 * work-package milestones, financing budget + monthly certified spend, and the
 * authoritative NCR + Site-Instruction registers.
 *
 * Sources:
 *  - IE "Status of NCR" & "Status of Site Instruction" registers (07/04-Jul-2026).
 *  - Public record: Wikipedia, RHD PPP Authority (pppo.gov.bd), dhakabypass.com.
 *
 * Idempotent: re-running replaces the seeded project / NCR / SI rows.
 */
class DhakaBypassCommandSeeder extends Seeder
{
    private const QC_DEPT = 11; // Quality Control

    public function run(): void
    {
        $now = now();
        $reporter = DB::table('users')->min('id') ?: 1;
        $leader = $reporter;
        $teamLeader = DB::table('users')->where('id', '!=', $reporter)->min('id') ?: $reporter;

        // ─────────────────────────────────────────── PROJECT ────────────
        $name = 'Dhaka Bypass Expressway (N-105) — PPP';
        DB::table('projects')->where('project_name', $name)->delete();

        $projectId = DB::table('projects')->insertGetId(array_filter([
            'project_name'   => $name,
            'description'    => 'Upgrading of Joydevpur–Debogram–Bhulta–Madanpur (Dhaka Bypass) Road '
                . 'N-105 into a 4-lane access-controlled expressway with service roads, under a '
                . 'DBFOM Public–Private Partnership. Contracting Authority: Roads & Highways Department (RHD). '
                . 'Project Company: Dhaka Bypass Expressway Development Company Ltd (DBEDC — SRBG 70%, Shamim '
                . 'Enterprise & UDC 30%). Independent Engineer: Intercontinental Consultants & Technocrats (India) '
                . 'with Sheladia Associates (USA).',
            'status'         => 'in_progress',
            'priority'       => 'high',
            'progress'       => 72,
            'project_leader_id' => $leader,
            'team_leader_id'    => $teamLeader,
            'color'          => '#EAB308',
            'start_date'     => '2019-12-26',
            'end_date'       => '2027-06-30',
            'budget'         => 35850000000,     // ৳ 3,585 crore
            'created_at'     => $now,
            'updated_at'     => $now,
        ], fn ($v) => $v !== null));

        // enhanced fields (nullable / may vary by install) — set what exists
        $enh = array_filter([
            'planned_start_date' => '2019-12-26',
            'planned_end_date'   => '2026-12-31',
            'actual_start_date'  => '2019-12-26',
            'budget_allocated'   => 35850000000,
            'budget_spent'       => 23000000000,
            'budget_utilization' => 64.15,
            'health_status'      => 'at_risk',
            'risk_level'         => 'high',
            'spi'                => 0.92,
            'cpi'                => 0.97,
            'current_phase'      => 'Bituminous & Structures',
            'next_milestone'     => 'Bituminous (DBM/BC) — Ch 0–39',
            'next_milestone_date'=> '2026-09-30',
        ], fn ($v) => $v !== null);
        $cols = collect($enh)->filter(fn ($v, $k) => Schema::hasColumn('projects', $k))->all();
        if ($cols) {
            DB::table('projects')->where('id', $projectId)->update($cols);
        }

        // ─────────────────────────────────────────── MILESTONES ─────────
        DB::table('project_milestones')->where('project_id', $projectId)->delete();
        $packages = [
            ['Earthwork & Embankment',    'Ch 0–48 · formation to subgrade',      20, 'completed',   '2023-06-30'],
            ['Sub-base & WMM',            'Ch 0–46 · granular pavement layers',   15, 'completed',   '2024-03-31'],
            ['Bituminous (DBM / BC)',     'Ch 0–39 · binder & wearing course',    20, 'in_progress', '2026-09-30'],
            ['Bridges & Structures',      '7 structures · girders, MSE walls',    20, 'in_progress', '2026-11-30'],
            ['Drainage & Cross-drainage', 'both sides · culverts & duct',         10, 'in_progress', '2026-08-31'],
            ['Road Furniture & Marking',  'Ch 0–48 · safety barriers, signage',    8, 'not_started', '2027-01-31'],
            ['Toll Plaza & ITS',          'toll systems & intelligent transport',  7, 'not_started', '2027-04-30'],
        ];
        foreach ($packages as $i => [$mname, $desc, $weight, $status, $due]) {
            DB::table('project_milestones')->insert([
                'project_id' => $projectId, 'name' => $mname, 'description' => $desc,
                'due_date' => $due, 'status' => $status, 'weight' => $weight, 'order' => $i + 1,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        // ─────────────────────────────────────────── BUDGET (৳ crore) ───
        DB::table('project_budgets')->where('project_id', $projectId)->delete();
        // Financing sources (public record) — stored in ৳ crore to fit decimal(12,2)
        $financing = [
            ['China Development Bank Loan', 'debt',   1614.00],
            ['BIFFL Loan',                  'debt',   1075.00],
            ['Government Share (RHD)',      'equity',  674.34],
            ['Viability Gap Financing',     'grant',   224.00],
        ];
        $primaryBudgetId = null;
        foreach ($financing as [$cat, $type, $amt]) {
            $spent = round($amt * 0.6415, 2);
            $id = DB::table('project_budgets')->insertGetId([
                'project_id' => $projectId, 'category' => $cat, 'budget_type' => $type,
                'initial_budget' => $amt, 'allocated_budget' => $amt, 'spent_amount' => $spent,
                'remaining_budget' => round($amt - $spent, 2), 'currency' => 'BDT',
                'description' => 'Dhaka Bypass PPP financing — ৳ crore',
                'start_date' => '2019-12-26', 'end_date' => '2027-06-30', 'status' => 'active',
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $primaryBudgetId ??= $id;
        }

        // Monthly certified spend for burn-down (Jan-2024 → Jun-2026), ৳ crore
        if (Schema::hasTable('project_budget_expenses')) {
            DB::table('project_budget_expenses')->where('project_id', $projectId)->delete();
            $start = \Carbon\Carbon::create(2024, 1, 1);
            $months = 30;
            for ($m = 0; $m < $months; $m++) {
                $date = (clone $start)->addMonths($m);
                // S-curve monthly certification, ramps mid-programme (৳ crore)
                $base = 60 + 55 * sin(($m / $months) * M_PI);       // 60→~115→60
                $amount = round($base + (($m * 7) % 13) - 6, 2);     // mild texture
                DB::table('project_budget_expenses')->insert([
                    'budget_id' => $primaryBudgetId, 'project_id' => $projectId,
                    'category' => 'Certified Works', 'description' => 'IPC certified spend — ৳ crore',
                    'amount' => max(20, $amount), 'currency' => 'BDT',
                    'expense_date' => $date->toDateString(), 'approved' => true,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }

        $this->seedNcrs($reporter, $now);
        $this->seedSiteInstructions($now);
    }

    /** NCR register — 101 issued · 41 open · 14 IE-consent · 46 closed. */
    private function seedNcrs(int $reporter, $now): void
    {
        DB::table('quality_ncrs')->delete();

        // Open NCR categories (authoritative ID lists)
        $design      = [31, 47, 48, 55, 60, 61, 65, 66, 67, 69, 71, 73];         // 12
        $qcPavement  = [20, 27, 28, 38, 49, 50, 70, 74, 76, 77, 79, 80, 81, 84,  // 28
                        85, 86, 87, 88, 89, 90, 91, 92, 93, 94, 95, 97, 98, 100];
        $maintenance = [75];                                                      // 1
        $inProcess   = [20, 27, 48, 73, 75, 93];   // In Process for Submission
        $underIeRev  = [31];                        // Currently Under Review by IE
        $consent     = [10, 52, 59, 62, 63, 64, 68, 72, 78, 82, 83, 96, 99, 101];// 14 (IE→closure, RHD consent)

        // Rich detail for the 14 IE-consent NCRs [num => [title, chainage_m, severity, corrective]]
        $detail = [
            10  => ['Inadequate box-culvert size at Km 8+127', 8127, 'major', 'IE recommended closure (RHD Consent) — Letter 4956, 02-Jun-2026.'],
            52  => ['Potholes & missing safety barriers, Km 37+150–47+835', 37150, 'minor', 'Maintenance activity; IE recommended closure (RHD Consent) — Letter 5035, 01-Jul-2026.'],
            59  => ['Crash-barrier surface cracks, Km 7+900–8+050 median (Toll)', 7900, 'major', 'IE recommended closure (RHD Consent) — TL-3430, 09-Dec-2024.'],
            62  => ['Settlement on CS improved subgrade, Km 39+200–39+895 SR (LHS)', 39200, 'major', 'IE recommended closure (RHD Consent) — TL-3913, 31-May-2025.'],
            63  => ['Reused footpath cover slabs, Ulukhula SR Bridge Km 17+207', 17207, 'minor', 'Rebound-hammer test satisfactory; IE recommended closure — TL-4960, 03-Jun-2026.'],
            64  => ['Transverse cracks in AC-20 binder, Km 6+175–20+777 SR', 6175, 'major', 'Repaired with anti-crack membrane; IE Letters 3592/3594, 12-Feb-2025.'],
            68  => ['Transverse reflective cracks AC-20, Km 20+650–21+220 SR (LHS)', 20650, 'major', 'In continuation of NCR-64; under RHD consent.'],
            72  => ['MSE-wall backfill non-conformity, Km 23+000–27+000', 23000, 'critical', 'IE recommended closure — TL-4030, 10-Jul-2025 (RHD consent TL-4043).'],
            78  => ['MSE-wall failure ZK 27+546–27+621 SR (LHS)', 27546, 'critical', 'Defective work removed; IE recommended closure — TL-4569, 04-Nov-2025.'],
            82  => ['Manual laying of AC-20 binder, Km 3+247–3+300 SR (LHS)', 3247, 'minor', 'IE recommended closure (RHD Consent) — TL-4977, 11-Jun-2026.'],
            83  => ['Asphalt mix over-temperature (>175°C), Km 24+970–25+940 SR (RHS)', 24970, 'major', 'IE recommended closure (RHD Consent) — TL-4983, 15-Jun-2026.'],
            96  => ['Unauthorised construction at Km 33+704 Underpass', 33704, 'critical', 'IE recommended closure (RHD Consent) — TL-4939, 18-May-2026.'],
            99  => ['Girder interface surface-prep deficiency (Ramp-B, span 9–10)', null, 'major', 'IE recommended closure (RHD Consent) — TL-5036, 01-Jul-2026.'],
            101 => ['Early-age cracks, approach slab Twin-Cell Frame Bridge K30+745', 30745, 'critical', 'IE recommended closure (RHD Consent) — TL-5033(A), 01-Jul-2026.'],
        ];

        $seen = [];
        $insert = function (int $num, string $discipline, string $severity, string $status, $detectedDate) use (&$seen, $reporter, $now, $detail) {
            if (isset($seen[$num])) return;
            $seen[$num] = true;
            $d = $detail[$num] ?? null;
            DB::table('quality_ncrs')->insert(array_filter([
                'ncr_number'   => sprintf('NCR-%03d', $num),
                'title'        => $d[0] ?? "$discipline non-conformity (NCR-$num)",
                'description'  => ($d[0] ?? "$discipline non-conformity") . '. Independent Engineer non-conformance report, Dhaka Bypass Expressway (N-105).',
                'severity'     => $d[2] ?? $severity,
                'status'       => $status,
                'reported_by'  => $reporter,
                'department_id' => self::QC_DEPT,
                'detected_date'=> $detectedDate,
                'corrective_action' => $d[3] ?? null,
                'closure_date' => $status === 'closed' ? '2025-12-01' : null,
                'created_at'   => $now,
                'updated_at'   => $now,
            ], fn ($v) => $v !== null));
        };

        // Open — status funnel (in-process / under-review / open)
        $statusFor = fn ($n) => in_array($n, $inProcess) ? 'action_in_progress'
            : (in_array($n, $underIeRev) ? 'under_review' : 'open');
        foreach ($design as $n)      $insert($n, 'Design', 'major', $statusFor($n), '2025-08-15');
        foreach ($qcPavement as $n)  $insert($n, 'Quality/Pavement', 'minor', $statusFor($n), '2025-10-10');
        foreach ($maintenance as $n) $insert($n, 'Maintenance', 'minor', $statusFor($n), '2026-01-20');
        // IE-consent (recommended for closure, awaiting RHD consent) → verified
        foreach ($consent as $n)     $insert($n, 'Quality/Structure', 'major', 'verified', '2024-11-05');
        // Remaining issued → closed (complete the 101 total)
        for ($n = 1; $n <= 101; $n++) $insert($n, 'Quality Control', 'minor', 'closed', '2023-06-01');
    }

    /** Site-Instruction register — 29 issued · 11 open · 18 closed. */
    private function seedSiteInstructions($now): void
    {
        DB::table('site_instructions')->delete();

        // 11 open SIs (authoritative detail)
        $open = [
            [4,  'SI/IE/SQCME/004',       'Quality Control', 'Quality Control', null,                     null,  'Field & laboratory tests to be prepared and submitted to IE on a daily basis (Clause 14.1b).', '2022-02-14'],
            [8,  'SI/IE/SQCME/008',       'Quality Control', 'Quality Control', null,                     null,  'RFI format not in line with PPP contract; RFIs from K3–K22 pending submission to IE.',          '2022-02-27'],
            [12, 'SI/IE/HE/012',          'Quality Control', 'Structure',       'Km 37+120–37+400 LHS',   37120, 'Embankment sand filling beside RE wall — undulated bed, geogrid tensioning & alignment.',       '2022-05-30'],
            [17, 'SI/IE/SSE/017',         'Structure',       'Structure',       'Km 6+660',               6660,  'Hold PUP construction at Km 6+660 until road profile is finalised (design issue).',             '2023-03-21'],
            [18, 'SI/IE/SSE/018',         'Quality Control', 'Structure',       'Km 18+270',              18270, 'Hold VUP construction at Km 18+270 until road profile & structure size finalised.',             '2023-03-22'],
            [24, 'SI/IE/SQCME/024',       'Quality Control', 'Quality Control', 'Km 15+000–30+200',       15000, 'Daily cement-consumption record not submitted for CS ISG / sub-base / base layer.',            '2024-02-06'],
            [25, 'SI/IE/SPE/025',         'Pavement',        'Pavement',        'Km 26+300–27+800 RHS',   26300, 'Service-road pavement crust to extend to paved shoulder; full-width single-pass laying.',        '2024-11-30'],
            [26, 'SI/IE/SQCME/026',       'Quality Control', 'Pavement',        'Km 30+200 RHS',          30200, 'AC-20 asphalt mixing temperature 182–183°C exceeds 160–175°C limit (related to NCR-83).',       '2024-12-12'],
            [27, 'SI/IE/SQCME/027',       'Quality Control', 'Pavement',        'Km 30+200 RHS',          30200, 'ATB-25 asphalt mixing temperature exceeds RHD/JTG limit (similar to SI-26).',                   '2024-12-23'],
            [28, 'SI/IE/SPE/028',         'Pavement',        'Pavement',        'Km 29+751–29+765 RHS',   29751, 'Ravelling on AC-20 service-road surface and potholes at Km 29+765 (RHS).',                       '2025-06-21'],
            [29, 'SI/IE/SPE/029',         'Pavement',        'Pavement',        'Km 38+900–38+938',       38900, 'Service-road damage from rain undercutting between Km 38+932–38+938 (RHS) and Km 38+900 (LHS).', '2025-06-18'],
        ];
        foreach ($open as [$num, $ref, $dept, $cat, $loc, $ch, $desc, $issued]) {
            DB::table('site_instructions')->insert([
                'si_number' => sprintf('SI-%02d', $num), 'ie_ref' => $ref,
                'department' => $dept, 'category' => $cat, 'location' => $loc, 'chainage_meters' => $ch,
                'description' => $desc, 'status' => 'open', 'issued_date' => $issued,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        // 18 closed SIs (complete the 29 issued) — refs archived
        $closedNums = [1, 2, 3, 5, 6, 7, 9, 10, 11, 13, 14, 15, 16, 19, 20, 21, 22, 23];
        foreach ($closedNums as $num) {
            DB::table('site_instructions')->insert([
                'si_number' => sprintf('SI-%02d', $num), 'ie_ref' => sprintf('SI/IE/%03d', $num),
                'department' => 'Quality Control', 'category' => 'Quality Control',
                'description' => 'Site Instruction closed — compliance verified by Independent Engineer.',
                'status' => 'closed', 'issued_date' => '2023-01-15', 'closed_date' => '2024-06-30',
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }
}
