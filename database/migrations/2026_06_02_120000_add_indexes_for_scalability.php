<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scalability indexes for the high-traffic lookup tables.
 *
 * HISTORY / WHY THIS FILE WAS REWRITTEN
 * -------------------------------------
 * The original version wrapped each block in a catch-all
 * `catch (Exception $e)` that swallowed anything matching 'Duplicate key',
 * 'already exists' or 'too long'. Two problems fell out of that:
 *
 *   1. The `daily_works` block declared TWO indexes inside a SINGLE closure, so
 *      when the first statement threw, the second was silently discarded with
 *      it — one failure lost the pair.
 *   2. A migration could report itself as "Ran" while creating NONE of the
 *      indexes it names. That is exactly what happened on the development DB:
 *      of the five declared indexes only `attendances_user_id_date_index`
 *      survived; the other four were swallowed and the environments drifted
 *      apart with no signal. down() then named indexes that were never created.
 *
 * This rewrite keeps the migration additive and idempotent but makes it HONEST:
 * every index is created individually, deduped by NAME and by COLUMN LIST, and
 * then VERIFIED to exist afterwards. If a create silently does nothing, or a
 * genuine error (bad column, key too long, missing table) occurs, the migration
 * now throws instead of lying about success.
 *
 * The index NAMES are unchanged from the original so that any environment where
 * they already exist (production) treats a replay as a clean skip. Deduping by
 * column list additionally means that if this migration is replayed AFTER the
 * later `2026_07_19_000001_add_hot_path_performance_indexes` has already added
 * equivalent `(incharge, date)` / `(assigned, date)` indexes under different
 * names, no duplicate index is created.
 *
 * Written with Blueprint (not raw DDL) so the SQLite test suite runs it too.
 */
return new class extends Migration
{
    /**
     * table => [index name => [columns]]
     *
     * @var array<string, array<string, array<int, string>>>
     */
    private array $indexes = [
        'attendances' => [
            'attendances_user_id_date_index' => ['user_id', 'date'],
        ],
        // 'status' deliberately excluded to stay under the InnoDB key-length limit
        // (the original hit error 1071 with it included).
        'leaves' => [
            'leaves_user_date_index' => ['user_id', 'from_date', 'to_date'],
        ],
        'petty_cash_loans' => [
            'loans_user_index' => ['user_id'],
        ],
        'daily_works' => [
            'works_incharge_date_index' => ['incharge', 'date'],
            'works_assigned_date_index' => ['assigned', 'date'],
        ],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $table => $definitions) {
            if (! Schema::hasTable($table)) {
                // A table this migration targets is genuinely absent — that is not
                // a benign "already there" condition, so surface it loudly.
                throw new RuntimeException("add_indexes_for_scalability: table [{$table}] does not exist.");
            }

            foreach ($definitions as $name => $columns) {
                $existingNames = $this->existingIndexNames($table);
                $existingColumns = $this->existingIndexColumns($table);

                // Idempotent by NAME: already created here on a previous run / on prod.
                if (in_array(strtolower($name), $existingNames, true)) {
                    continue;
                }

                // Idempotent by COLUMN LIST: an equivalent index already exists under
                // another name (e.g. added by the 2026_07_19 hot-path migration).
                // Adding a second identical index only costs write throughput.
                if (in_array($this->columnKey($columns), $existingColumns, true)) {
                    continue;
                }

                if (! Schema::hasColumns($table, $columns)) {
                    throw new RuntimeException(
                        "add_indexes_for_scalability: cannot index [{$table}] on ("
                        .implode(', ', $columns).') — column(s) missing.'
                    );
                }

                Schema::table($table, function (Blueprint $blueprint) use ($columns, $name) {
                    $blueprint->index($columns, $name);
                });

                // VERIFY: prove the index now exists rather than assuming success.
                // Either the exact name or a column-list match is acceptable (a
                // pre-existing equivalent index would have been skipped above, so
                // reaching here means we expected to create one).
                $after = $this->existingIndexNames($table);
                $afterColumns = $this->existingIndexColumns($table);
                if (! in_array(strtolower($name), $after, true)
                    && ! in_array($this->columnKey($columns), $afterColumns, true)) {
                    throw new RuntimeException(
                        "add_indexes_for_scalability: index [{$name}] on [{$table}] ("
                        .implode(', ', $columns).') was not created — refusing to report success.'
                    );
                }
            }
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as $table => $definitions) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach (array_keys($definitions) as $name) {
                if (! in_array(strtolower($name), $this->existingIndexNames($table), true)) {
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
