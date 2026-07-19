<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive performance indexes for the measured hot paths.
 *
 * Every index below was justified against an EXPLAIN of a query that is actually
 * issued by the mobile API or the dashboard on the live schema — before this
 * migration every one of those plans was `type=ALL` (full scan of ~21k rows),
 * most with `Using filesort` on top.
 *
 * SAFETY ON A LIVE TABLE
 * ----------------------
 * These are secondary-index ADDs only: no column, type or constraint changes and
 * no data is rewritten. InnoDB performs `ADD INDEX` in-place (ALGORITHM=INPLACE,
 * LOCK=NONE) so concurrent SELECT/INSERT/UPDATE keep running; only a brief
 * metadata lock is taken at the start and end. At the current ~21k rows each
 * index builds in well under a second.
 *
 * Every step is guarded on table/column presence AND on the index not already
 * existing, so the migration is safe to run against a schema that has drifted or
 * that has been partially indexed by hand. down() drops only what up() added.
 *
 * Written with Blueprint (not raw DDL) deliberately: the test suite runs the same
 * migrations on SQLite, which would reject MySQL-specific ALTER syntax.
 */
return new class extends Migration
{
    /**
     * table => [index name => [columns]]
     *
     * @var array<string, array<string, array<int, string>>>
     */
    private array $indexes = [
        'daily_works' => [
            // Mobile daily-works list + summary. Visibility is always
            // `incharge = ?` (or `incharge IN (…)`) and the list is always
            // `ORDER BY date DESC, id DESC`. Leading column is the equality
            // predicate, second is the sort/range column — the only order in
            // which MySQL can use one index for both filter and sort.
            'daily_works_incharge_date_index' => ['incharge', 'date'],

            // The second arm of the `incharge = ? OR assigned = ?` visibility
            // filter (lets the optimiser index-merge instead of scanning), and
            // it also serves the summary's `WHERE assigned IS NOT NULL` count.
            'daily_works_assigned_date_index' => ['assigned', 'date'],

            // Privileged/unscoped listing has no user predicate at all, so it
            // needs a standalone sort index for `ORDER BY date DESC LIMIT n`.
            // Also serves CommandCenterService's `WHERE date >= ?`,
            // `WHERE date BETWEEN ? AND ?`, `MAX(date)` and the
            // `SELECT DISTINCT date ORDER BY date` selectable-dates endpoint.
            'daily_works_date_index' => ['date'],
        ],

        // The objection pivot had NO index beyond its primary key. The daily-works
        // list runs `withCount('activeObjections')` — a correlated subquery that
        // joins this pivot ONCE PER ROW RETURNED — and it was resolved by a full
        // pivot scan every time. This is the single worst index gap found.
        'daily_work_objection' => [
            'daily_work_objection_daily_work_id_objection_id_index' => ['daily_work_id', 'rfi_objection_id'],
            // Reverse direction: objection -> its daily works (objection detail,
            // objection queue, and the manager dashboard's objection stats).
            'daily_work_objection_rfi_objection_id_index' => ['rfi_objection_id'],
        ],

        // `activeObjections` filters `rfi_objections.status IN (…)` on both the
        // withCount subquery and the objection queue screens.
        'rfi_objections' => [
            'rfi_objections_status_index' => ['status'],
        ],

        // ResolvesTeamMembers walks the reporting tree with `WHERE report_to IN (…)`
        // up to 10 times per manager-dashboard request, and the daily-works list
        // resolves assignee candidates with `WHERE report_to = ?`. Unindexed.
        'users' => [
            'users_report_to_index' => ['report_to'],
        ],

        // Mobile leave sync pages with `WHERE user_id = ? ORDER BY updated_at, id`.
        // The existing FK index on user_id alone satisfied the filter but left a
        // filesort for the cursor ordering; this covers the whole cursor.
        'leaves' => [
            'leaves_user_id_updated_at_id_index' => ['user_id', 'updated_at', 'id'],
        ],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $table => $definitions) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $existingNames = $this->existingIndexNames($table);
            $existingColumns = $this->existingIndexColumns($table);

            foreach ($definitions as $name => $columns) {
                if (in_array(strtolower($name), $existingNames, true)) {
                    continue;
                }

                // Dedupe by COLUMN LIST as well as by name. The earlier
                // `2026_06_02_120000_add_indexes_for_scalability` migration declares
                // `(incharge, date)` and `(assigned, date)` on daily_works under
                // different names (`works_*`); it is recorded as Ran but its
                // statements were swallowed by a catch-all, so those indexes are
                // absent in practice. If that migration is ever repaired and
                // replayed, this check stops the table acquiring two identical
                // indexes — duplicate indexes cost write throughput and buy nothing.
                if (in_array($this->columnKey($columns), $existingColumns, true)) {
                    continue;
                }

                if (! Schema::hasColumns($table, $columns)) {
                    continue;
                }

                Schema::table($table, function (Blueprint $blueprint) use ($columns, $name) {
                    $blueprint->index($columns, $name);
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as $table => $definitions) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $existingNames = $this->existingIndexNames($table);

            foreach (array_keys($definitions) as $name) {
                if (! in_array(strtolower($name), $existingNames, true)) {
                    continue;
                }

                Schema::table($table, function (Blueprint $blueprint) use ($name) {
                    $blueprint->dropIndex($name);
                });
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function existingIndexNames(string $table): array
    {
        return array_map(
            static fn (array $index): string => strtolower((string) $index['name']),
            Schema::getIndexes($table)
        );
    }

    /**
     * Column-list fingerprint of every index already on the table.
     *
     * @return array<int, string>
     */
    private function existingIndexColumns(string $table): array
    {
        return array_map(
            fn (array $index): string => $this->columnKey($index['columns'] ?? []),
            Schema::getIndexes($table)
        );
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function columnKey(array $columns): string
    {
        return strtolower(implode(',', $columns));
    }
};
