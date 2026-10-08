<?php

namespace Tests\Feature\Audit;

use App\Models\Aeon\Conversation;
use App\Models\HRM\HrDocument;
use App\Models\HRM\KPI;
use App\Models\HRM\KPIValue;
use App\Models\Project;
use App\Models\QualityNCR;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Guards docs/audit/ENTITY_RELATIONSHIP_AUDIT_2026-10-08.md: relations must point at real columns, the
 * users key is `employee_id` (a string) so default relation keys must be spelled out, and the repair
 * migrations stay idempotent.
 */
class EntityRelationshipTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Models whose tables / columns do not exist in the schema. Dormant (no routes, or behind a flag that is
     * off) and listed as open items in the audit: a model may only be added here with a reason in the audit.
     * The list can shrink, never grow silently.
     */
    private const KNOWN_DORMANT = [
        'App\Models\HRM\Training', 'App\Models\HRM\TrainingAssignment', 'App\Models\HRM\TrainingAssignmentSubmission',
        'App\Models\HRM\TrainingCategory', 'App\Models\HRM\TrainingEnrollment', 'App\Models\HRM\TrainingFeedback',
        'App\Models\HRM\TrainingMaterial', 'App\Models\HRM\TrainingSession',
        'App\Models\HRM\Payroll', 'App\Models\HRM\Payslip', 'App\Models\HRM\PayrollAllowance',
        'App\Models\HRM\PayrollDeduction', 'App\Models\HRM\TaxSlab',
        'App\Models\HRM\JobApplication',
    ];

    /** @return array<int, class-string<Model>> */
    private function models(): array
    {
        $models = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path('Models')));
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            if (! preg_match('/^namespace (.+);/m', $source, $ns) || ! preg_match('/^(?:abstract |final )?class (\w+) extends/m', $source, $cl)) {
                continue;
            }
            $class = $ns[1].'\\'.$cl[1];
            if (class_exists($class) && is_subclass_of($class, Model::class) && ! (new ReflectionClass($class))->isAbstract()) {
                $models[] = $class;
            }
        }

        return $models;
    }

    /** @return array<int, string> broken relations of one model, as "Model::relation: reason" */
    private function brokenRelations(string $class): array
    {
        $model = new $class;
        $broken = [];

        // Tables that exist in production but have no creating migration (audit finding E-07) are missing from
        // the test database; their relations cannot be judged here.
        if (! Schema::hasTable($model->getTable())) {
            return [];
        }

        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->class !== $class || $method->isStatic() || $method->getNumberOfRequiredParameters() > 0) {
                continue;
            }
            $type = $method->getReturnType() ? ltrim((string) $method->getReturnType(), '?') : '';
            if ($type !== '' && ! is_a($type, Relation::class, true)) {
                continue;
            }

            try {
                $relation = $method->invoke($model);
            } catch (\Throwable) {
                continue;
            }
            if (! $relation instanceof Relation) {
                continue;
            }

            $label = class_basename($class).'::'.$method->name;
            $related = $relation->getRelated();
            $relatedTable = $related->getTable();

            if (! Schema::hasTable($relatedTable)) {
                $broken[] = "{$label}: related table `{$relatedTable}` does not exist";

                continue;
            }

            $check = function (string $table, string $column) use (&$broken, $label): void {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                    $broken[] = "{$label}: column `{$table}.{$column}` does not exist";
                }
            };

            match (true) {
                $relation instanceof BelongsTo => $check($model->getTable(), $relation->getForeignKeyName()),
                $relation instanceof HasOne, $relation instanceof HasMany => $check($relatedTable, last(explode('.', $relation->getQualifiedForeignKeyName()))),
                $relation instanceof BelongsToMany => [$check($relation->getTable(), $relation->getForeignPivotKeyName()), $check($relation->getTable(), $relation->getRelatedPivotKeyName())],
                default => null,
            };
        }

        return $broken;
    }

    public function test_no_live_model_relation_points_at_a_missing_table_or_column(): void
    {
        $broken = [];
        foreach ($this->models() as $class) {
            if (in_array($class, self::KNOWN_DORMANT, true)) {
                continue;
            }
            array_push($broken, ...$this->brokenRelations($class));
        }

        $this->assertSame([], $broken, "Broken relations:\n".implode("\n", $broken));
    }

    public function test_the_dormant_allow_list_only_contains_models_that_are_still_broken(): void
    {
        foreach (self::KNOWN_DORMANT as $class) {
            $healthy = Schema::hasTable((new $class)->getTable()) && $this->brokenRelations($class) === [];
            $this->assertFalse($healthy, "{$class} is healthy now: remove it from KNOWN_DORMANT");
        }
    }

    public function test_aeon_conversation_owner_resolves_through_user_id(): void
    {
        $user = User::factory()->create(['employee_id' => 'E-5001']);
        $conversation = Conversation::create(['user_id' => 'E-5001', 'title' => 't']);

        $this->assertSame('E-5001', $conversation->fresh()->user->employee_id);
        $this->assertSame($user->name, $conversation->fresh()->user->name);
    }

    public function test_hr_document_employees_pivot_uses_employee_ids(): void
    {
        $user = User::factory()->create(['employee_id' => 'E-5002']);
        $document = HrDocument::query()->forceCreate(['title' => 'Handbook', 'document_type' => 'policy', 'file_path' => 'x', 'created_by' => 'E-5002']);

        $document->employees()->attach($user->employee_id, ['acknowledgment_status' => 'pending']);

        $this->assertSame(['E-5002'], $document->fresh()->employees->pluck('employee_id')->all());
        $this->assertDatabaseHas('employee_documents', ['user_id' => 'E-5002', 'hr_document_id' => $document->id]);
    }

    public function test_kpi_values_use_the_real_table_and_keys(): void
    {
        $user = User::factory()->create(['employee_id' => 'E-5003']);
        $this->assertSame('kpi_values', (new KPIValue)->getTable());

        $kpi = KPI::query()->forceCreate(['name' => 'Uptime', 'category' => 'ops', 'target_value' => 99, 'unit' => '%', 'frequency' => 'daily', 'status' => 'active', 'responsible_user_id' => 'E-5003']);
        $value = $kpi->values()->create(['value' => 98.5, 'date' => now()->toDateString(), 'recorded_by_user_id' => 'E-5003']);

        $this->assertSame($kpi->id, $value->fresh()->kpi->id);
        $this->assertSame('E-5003', $value->fresh()->recordedBy->employee_id);
    }

    public function test_user_projects_resolve_through_project_resources(): void
    {
        $user = User::factory()->create(['employee_id' => 'E-5004']);
        $project = Project::query()->forceCreate(['project_name' => 'Corridor', 'status' => 'active']);
        $project->resources()->attach($user->employee_id, ['role' => 'engineer']);

        $this->assertSame([$project->id], $user->fresh()->projects->pluck('id')->all());
        $this->assertSame('engineer', $user->fresh()->projects->first()->pivot->role);
    }

    public function test_ncr_can_reference_its_inspection(): void
    {
        $this->assertTrue(Schema::hasColumn('quality_ncrs', 'inspection_id'));
        $this->assertInstanceOf(BelongsTo::class, (new QualityNCR)->inspection());
    }

    public function test_relationship_migration_is_idempotent_and_indexes_the_hot_keys(): void
    {
        $migration = require database_path('migrations/2026_10_08_000002_add_missing_relationship_columns_and_indexes.php');
        $migration->up();
        $migration->up();

        foreach ([['departments', 'manager_id'], ['designations', 'parent_id'], ['roster_days', 'assignment_id'], ['shift_swap_requests', 'approved_by']] as [$table, $column]) {
            $indexed = collect(Schema::getIndexes($table))->contains(fn ($i) => ($i['columns'][0] ?? null) === $column);
            $this->assertTrue($indexed, "{$table}.{$column} should be indexed");
        }
    }

    public function test_legacy_actor_ids_are_translated_only_when_no_employee_owns_them(): void
    {
        if (! Schema::hasTable('activity_log')) { // production table without a creating migration (audit finding E-07)
            Schema::create('activity_log', function ($table) {
                $table->id();
                $table->string('log_name')->nullable();
                $table->text('description');
                $table->string('causer_type')->nullable();
                $table->string('causer_id', 50)->nullable();
                $table->timestamps();
            });
        }
        User::factory()->create(['employee_id' => '151']);   // legacy 18 maps to 151
        User::factory()->create(['employee_id' => '26']);    // a real employee that happens to share a legacy id
        $row = fn (string|int $causer) => DB::table('activity_log')->insertGetId([
            'log_name' => 'default', 'description' => 'x', 'causer_type' => 'App\\Models\\User', 'causer_id' => $causer,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $legacy = $row(18);
        $already = $row(151);
        $owned = $row(26);

        $migration = require database_path('migrations/2026_10_08_000001_align_integer_user_references_with_employee_ids.php');
        $migration->remapLegacyActors();
        $migration->remapLegacyActors(); // idempotent

        $this->assertSame('151', (string) DB::table('activity_log')->where('id', $legacy)->value('causer_id'));
        $this->assertSame('151', (string) DB::table('activity_log')->where('id', $already)->value('causer_id'));
        $this->assertSame('26', (string) DB::table('activity_log')->where('id', $owned)->value('causer_id'), 'an id an employee owns is a real reference');
    }

    public function test_new_migrations_never_create_an_integer_foreign_key_to_users(): void
    {
        // users.employee_id is a string: foreignId()/unsignedBigInteger() + constrained('users') creates a column that
        // cannot hold it (audit finding E-08). Migrations from the audit date onwards must use string('...', 50).
        $offenders = [];
        foreach (glob(database_path('migrations/*.php')) as $file) {
            if (basename($file) < '2026_10_08_000003') {
                continue;
            }
            $source = file_get_contents($file);
            if (preg_match("/foreignId\\([^)]*\\)[^;]*constrained\\('users'\\)/", $source)
                || preg_match('/foreignIdFor\\(\\s*(?:\\\\App\\\\Models\\\\)?User::class/', $source)) {
                $offenders[] = basename($file);
            }
        }

        $this->assertSame([], $offenders);
    }
}
