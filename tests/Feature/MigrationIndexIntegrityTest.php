<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Guards against the class of bug where a migration reports success while
 * creating none of the indexes it declares.
 *
 * RefreshDatabase runs the FULL migration chain on a fresh SQLite database, so
 * these assertions prove the repaired `2026_06_02_120000_add_indexes_for_scalability`
 * migration (and the additive `2026_07_19_000002_converge_scalability_indexes`)
 * genuinely create their indexes on a clean schema — not merely that they are
 * recorded as "Ran".
 */
class MigrationIndexIntegrityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Index names that ONLY the 2026_06_02 migration creates — no earlier or later
     * migration declares these exact names. Their presence after a fresh migrate
     * therefore proves that the repaired migration actively created them rather
     * than swallowing the statement (the original bug), because nothing else could
     * have. (Its other two declared indexes — works_incharge_date_index /
     * loans_user_index — are legitimately skipped on a fresh DB because equivalent
     * indexes on the same columns are created by earlier migrations
     * [2025_11_26 daily_works(incharge,date), 2026_05_24 petty_cash_loans(user_id)];
     * the column-coverage test below asserts those columns are still covered.)
     *
     * @return array<int, array{0: string, 1: string, 2: array<int, string>}>
     */
    public static function scalabilityIndexes(): array
    {
        return [
            ['attendances', 'attendances_user_id_date_index', ['user_id', 'date']],
            ['leaves', 'leaves_user_date_index', ['user_id', 'from_date', 'to_date']],
            ['daily_works', 'works_assigned_date_index', ['assigned', 'date']],
        ];
    }

    /**
     * @param  array<int, string>  $columns
     */
    #[DataProvider('scalabilityIndexes')]
    public function test_repaired_scalability_migration_creates_its_named_indexes(string $table, string $name, array $columns): void
    {
        $index = collect(Schema::getIndexes($table))
            ->first(fn (array $i): bool => strtolower((string) $i['name']) === strtolower($name));

        $this->assertNotNull(
            $index,
            "Index [{$name}] on [{$table}] is missing after a fresh migrate — the migration reported success without creating it."
        );

        $this->assertSame(
            array_map('strtolower', $columns),
            array_map('strtolower', $index['columns'] ?? []),
            "Index [{$name}] on [{$table}] exists but covers the wrong columns."
        );
    }

    /**
     * Every column-list the application depends on must be covered by some index
     * (prefix match) after a fresh migrate — the same contract the
     * schema:verify-indexes command enforces on live connections.
     */
    public function test_all_app_required_index_coverage_is_present_on_a_fresh_database(): void
    {
        $required = [
            ['attendances', ['user_id', 'date']],
            ['attendances', ['updated_at']],
            ['daily_works', ['incharge', 'date']],
            ['daily_works', ['assigned', 'date']],
            ['daily_works', ['date']],
            ['daily_works', ['updated_at']],
            ['daily_work_objection', ['daily_work_id', 'rfi_objection_id']],
            ['daily_work_objection', ['rfi_objection_id']],
            ['rfi_objections', ['status']],
            ['users', ['report_to']],
            ['leaves', ['user_id']],
            ['leaves', ['user_id', 'from_date', 'to_date']],
            ['leaves', ['user_id', 'updated_at', 'id']],
            ['petty_cash_loans', ['user_id']],
            ['sync_tombstones', ['user_id', 'module', 'id']],
        ];

        foreach ($required as [$table, $columns]) {
            $this->assertTrue(
                $this->hasCoveringIndex($table, $columns),
                "No index covers [{$table}] (".implode(', ', $columns).') on a fresh database.'
            );
        }
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function hasCoveringIndex(string $table, array $columns): bool
    {
        $needle = array_map('strtolower', $columns);

        foreach (Schema::getIndexes($table) as $index) {
            $indexColumns = array_map('strtolower', $index['columns'] ?? []);
            if (array_slice($indexColumns, 0, count($needle)) === $needle) {
                return true;
            }
        }

        return false;
    }
}
