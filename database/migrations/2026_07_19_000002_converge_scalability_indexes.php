<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Converge the two scalability indexes that the 2026_06_02 migration CLAIMED to
 * create but silently swallowed.
 *
 * `2026_06_02_120000_add_indexes_for_scalability` is recorded as Ran on every
 * environment, so its (now repaired) up() will not replay on machines that have
 * already migrated. On the development database that left two indexes it named
 * genuinely absent:
 *
 *   - leaves.(user_id, from_date, to_date)   — leave date-range / overlap lookups
 *   - petty_cash_loans.(user_id)             — the loans table had NO secondary
 *                                              index at all beyond its primary key
 *
 * Neither is covered by the later 2026_07_19_000001 hot-path migration, so this
 * additive, reversible migration closes the gap going forward. It follows that
 * migration's reference pattern verbatim: guarded on table + column presence,
 * deduped by BOTH index name and COLUMN LIST so it is a clean no-op on any
 * environment that already has an equivalent index (including a fresh DB where
 * the repaired 06_02 migration created them under the same names). Secondary
 * index ADDs only — no data is rewritten; InnoDB builds them in-place.
 */
return new class extends Migration
{
    /**
     * table => [index name => [columns]]
     *
     * @var array<string, array<string, array<int, string>>>
     */
    private array $indexes = [
        'leaves' => [
            'leaves_user_date_index' => ['user_id', 'from_date', 'to_date'],
        ],
        'petty_cash_loans' => [
            'loans_user_index' => ['user_id'],
        ],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $table => $definitions) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($definitions as $name => $columns) {
                $existingNames = $this->existingIndexNames($table);
                $existingColumns = $this->existingIndexColumns($table);

                if (in_array(strtolower($name), $existingNames, true)) {
                    continue;
                }

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
