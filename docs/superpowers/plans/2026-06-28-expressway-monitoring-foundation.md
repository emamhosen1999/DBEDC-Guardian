# Expressway Monitoring Center — Plan 1: Data Foundation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the tenant-scoped data foundation for the Monitoring Center — expressway topology (routes, sections, directions, lanes), incident-type/SLA config, the four control-room roles, and a chainage display helper — all backend, all TDD, with no UI yet.

**Architecture:** A new `Monitoring` domain mirroring the existing `HRM` conventions (`app/Models/Monitoring/*`, factories under `Database\Factories\Monitoring\*`). Chainage is stored as unsigned-int meters and rendered `KM 42+500` via a pure helper, matching the existing `objection_chainages` pattern. Sections are resolved from a chainage value by range. This plan produces a configured, tested data layer that Plans 2–5 build incidents, lifecycle, realtime, and outputs on top of.

**Tech Stack:** Laravel 11 · PHP 8.2 (enums) · Eloquent + factories · `spatie/laravel-permission` ^6 (roles only) · PHPUnit/Pest on sqlite.

## Global Constraints

- **Access control = ROLES ONLY.** Never use Spatie permission strings (`hasPermissionTo`/`can`/`permission:` middleware). Gate on `$user->hasRole(...)` and `role:` middleware. (Permission usage is being removed app-wide.)
- **Tenancy:** all monitoring tables and queries are tenant-scoped via `stancl/tenancy` ^3 (these tables live in the tenant connection like the rest of the app's domain tables).
- **Chainage** is stored as unsigned integer **meters** (`chainage_m`), displayed as `KM <km>+<mmm>` (3-digit zero-padded metres).
- **Dev DB:** tests run on sqlite, but every new migration MUST also be run on the MySQL `dbedc_guardian` dev DB (`php artisan migrate`) or live pages 500 with "table not found".
- **Dev workflow:** `npm run dev`, test at `https://aero-enterprise-suite.test`. NEVER `npm run build` (auto-commits/pushes).
- **Class naming:** topology models are prefixed `Expressway*` to avoid collision with `Illuminate\Support\Facades\Route`; `$table` is set explicitly on every model.
- **Migration timestamps:** use the `2026_06_28_NNNNNN_` prefix in the order tasks appear so foreign keys resolve.

---

## Task 1: Monitoring roles seeder

**Files:**
- Create: `database/seeders/MonitoringRoleSeeder.php`
- Modify: `database/seeders/CombinedSeeder.php` (add the call)
- Test: `tests/Feature/Monitoring/MonitoringRoleSeederTest.php`

**Interfaces:**
- Produces: four Spatie roles (guard `web`) — `Monitoring Operator`, `Monitoring Supervisor`, `Monitoring Manager`, `Monitoring Viewer`. Plans 2–5 gate behaviour on these exact role names.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Monitoring/MonitoringRoleSeederTest.php
namespace Tests\Feature\Monitoring;

use Database\Seeders\MonitoringRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MonitoringRoleSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_the_four_monitoring_roles(): void
    {
        $this->seed(MonitoringRoleSeeder::class);

        foreach ([
            'Monitoring Operator',
            'Monitoring Supervisor',
            'Monitoring Manager',
            'Monitoring Viewer',
        ] as $role) {
            $this->assertTrue(
                Role::where('name', $role)->where('guard_name', 'web')->exists(),
                "Missing role: {$role}"
            );
        }
    }

    public function test_seeding_twice_is_idempotent(): void
    {
        $this->seed(MonitoringRoleSeeder::class);
        $this->seed(MonitoringRoleSeeder::class);

        $this->assertSame(1, Role::where('name', 'Monitoring Operator')->count());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=MonitoringRoleSeederTest`
Expected: FAIL — class `Database\Seeders\MonitoringRoleSeeder` not found.

- [ ] **Step 3: Implement the seeder**

```php
<?php
// database/seeders/MonitoringRoleSeeder.php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class MonitoringRoleSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ([
            'Monitoring Operator',
            'Monitoring Supervisor',
            'Monitoring Manager',
            'Monitoring Viewer',
        ] as $name) {
            Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
    }
}
```

- [ ] **Step 4: Register in CombinedSeeder**

In `database/seeders/CombinedSeeder.php`, add `MonitoringRoleSeeder::class` to the `$this->call([...])` list (alongside the other role/permission seeders). If the calls are individual statements rather than an array, add `$this->call(MonitoringRoleSeeder::class);` near the other role seeding.

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --filter=MonitoringRoleSeederTest`
Expected: PASS (both).

- [ ] **Step 6: Commit**

```bash
git add database/seeders/MonitoringRoleSeeder.php database/seeders/CombinedSeeder.php tests/Feature/Monitoring/MonitoringRoleSeederTest.php
git commit -m "feat(monitoring): seed the four control-room roles"
```

---

## Task 2: Chainage display helper

**Files:**
- Create: `app/Support/Monitoring/Chainage.php`
- Test: `tests/Unit/Monitoring/ChainageTest.php`

**Interfaces:**
- Produces: `App\Support\Monitoring\Chainage::format(int $meters): string` → `KM 42+500`; `Chainage::parse(string $display): int` (inverse, throws `InvalidArgumentException` on bad input). Used by models (accessor) and every UI/report that shows a location.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Unit/Monitoring/ChainageTest.php
namespace Tests\Unit\Monitoring;

use App\Support\Monitoring\Chainage;
use InvalidArgumentException;
use Tests\TestCase;

class ChainageTest extends TestCase
{
    public function test_it_formats_meters_as_km_plus_metres(): void
    {
        $this->assertSame('KM 42+500', Chainage::format(42500));
        $this->assertSame('KM 0+005', Chainage::format(5));
        $this->assertSame('KM 7+000', Chainage::format(7000));
    }

    public function test_it_parses_display_back_to_meters(): void
    {
        $this->assertSame(42500, Chainage::parse('KM 42+500'));
        $this->assertSame(5, Chainage::parse('42+005'));
    }

    public function test_it_rejects_garbage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Chainage::parse('not-a-chainage');
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=ChainageTest`
Expected: FAIL — class `App\Support\Monitoring\Chainage` not found.

- [ ] **Step 3: Implement the helper**

```php
<?php
// app/Support/Monitoring/Chainage.php
namespace App\Support\Monitoring;

use InvalidArgumentException;

final class Chainage
{
    public static function format(int $meters): string
    {
        if ($meters < 0) {
            throw new InvalidArgumentException('Chainage cannot be negative.');
        }

        $km = intdiv($meters, 1000);
        $rem = $meters % 1000;

        return sprintf('KM %d+%03d', $km, $rem);
    }

    public static function parse(string $display): int
    {
        if (! preg_match('/(\d+)\s*\+\s*(\d{1,3})/', trim($display), $m)) {
            throw new InvalidArgumentException("Unparseable chainage: {$display}");
        }

        return ((int) $m[1] * 1000) + (int) $m[2];
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=ChainageTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Support/Monitoring/Chainage.php tests/Unit/Monitoring/ChainageTest.php
git commit -m "feat(monitoring): chainage meters<->display helper"
```

---

## Task 3: Expressway route table + model

**Files:**
- Create: `database/migrations/2026_06_28_000001_create_monitoring_routes_table.php`
- Create: `app/Models/Monitoring/ExpresswayRoute.php`
- Create: `database/factories/Monitoring/ExpresswayRouteFactory.php`
- Test: `tests/Feature/Monitoring/ExpresswayRouteTest.php`

**Interfaces:**
- Produces: `ExpresswayRoute` with `code, name, total_length_m (int), is_active (bool)`; `ExpresswayRoute::factory()`. Sections/directions/lanes/incidents all `belongsTo` it.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Monitoring/ExpresswayRouteTest.php
namespace Tests\Feature\Monitoring;

use App\Models\Monitoring\ExpresswayRoute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpresswayRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_route_with_expected_casts(): void
    {
        $route = ExpresswayRoute::factory()->create([
            'code' => 'N1',
            'name' => 'Dhaka–Chittagong Expressway',
            'total_length_m' => 240000,
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('monitoring_routes', ['code' => 'N1']);
        $this->assertIsInt($route->fresh()->total_length_m);
        $this->assertTrue($route->fresh()->is_active);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=ExpresswayRouteTest`
Expected: FAIL — model/table missing.

- [ ] **Step 3: Write the migration**

```php
<?php
// database/migrations/2026_06_28_000001_create_monitoring_routes_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_routes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->unsignedInteger('total_length_m')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_routes');
    }
};
```

- [ ] **Step 4: Write the model**

```php
<?php
// app/Models/Monitoring/ExpresswayRoute.php
namespace App\Models\Monitoring;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExpresswayRoute extends Model
{
    use HasFactory;

    protected $table = 'monitoring_routes';

    protected $fillable = ['code', 'name', 'total_length_m', 'is_active'];

    protected $casts = ['total_length_m' => 'integer', 'is_active' => 'boolean'];

    public function sections(): HasMany
    {
        return $this->hasMany(ExpresswaySection::class, 'route_id');
    }

    public function directions(): HasMany
    {
        return $this->hasMany(ExpresswayDirection::class, 'route_id');
    }

    public function lanes(): HasMany
    {
        return $this->hasMany(ExpresswayLane::class, 'route_id');
    }
}
```

- [ ] **Step 5: Write the factory**

```php
<?php
// database/factories/Monitoring/ExpresswayRouteFactory.php
namespace Database\Factories\Monitoring;

use App\Models\Monitoring\ExpresswayRoute;
use Illuminate\Database\Eloquent\Factories\Factory;

class ExpresswayRouteFactory extends Factory
{
    protected $model = ExpresswayRoute::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper($this->faker->unique()->bothify('RT-##')),
            'name' => $this->faker->streetName().' Expressway',
            'total_length_m' => $this->faker->numberBetween(10000, 300000),
            'is_active' => true,
        ];
    }
}
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `php artisan test --filter=ExpresswayRouteTest`
Expected: PASS.

- [ ] **Step 7: Run on the MySQL dev DB**

Run: `php artisan migrate` (targets MySQL `dbedc_guardian`).
Expected: `monitoring_routes` migrated.

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_06_28_000001_create_monitoring_routes_table.php app/Models/Monitoring/ExpresswayRoute.php database/factories/Monitoring/ExpresswayRouteFactory.php tests/Feature/Monitoring/ExpresswayRouteTest.php
git commit -m "feat(monitoring): expressway route table + model"
```

---

## Task 4: Expressway sections + chainage-to-section resolver

**Files:**
- Create: `database/migrations/2026_06_28_000002_create_monitoring_sections_table.php`
- Create: `app/Models/Monitoring/ExpresswaySection.php`
- Create: `database/factories/Monitoring/ExpresswaySectionFactory.php`
- Test: `tests/Feature/Monitoring/ExpresswaySectionTest.php`

**Interfaces:**
- Consumes: `ExpresswayRoute`.
- Produces: `ExpresswaySection` with `route_id, code, name, from_chainage_m, to_chainage_m, sort_order`; static `ExpresswaySection::resolveForChainage(int $routeId, int $chainageM): ?ExpresswaySection` (the section whose `[from,to)` range contains the chainage). Incidents (Plan 2) use this to derive `section_id`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Monitoring/ExpresswaySectionTest.php
namespace Tests\Feature\Monitoring;

use App\Models\Monitoring\ExpresswayRoute;
use App\Models\Monitoring\ExpresswaySection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpresswaySectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolve_for_chainage_returns_the_containing_section(): void
    {
        $route = ExpresswayRoute::factory()->create();
        ExpresswaySection::factory()->for($route, 'route')->create([
            'code' => 'S1', 'from_chainage_m' => 0, 'to_chainage_m' => 10000,
        ]);
        $s2 = ExpresswaySection::factory()->for($route, 'route')->create([
            'code' => 'S2', 'from_chainage_m' => 10000, 'to_chainage_m' => 20000,
        ]);

        $hit = ExpresswaySection::resolveForChainage($route->id, 15000);
        $this->assertNotNull($hit);
        $this->assertSame($s2->id, $hit->id);
    }

    public function test_resolve_for_chainage_returns_null_when_out_of_range(): void
    {
        $route = ExpresswayRoute::factory()->create();
        ExpresswaySection::factory()->for($route, 'route')->create([
            'from_chainage_m' => 0, 'to_chainage_m' => 10000,
        ]);

        $this->assertNull(ExpresswaySection::resolveForChainage($route->id, 99999));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=ExpresswaySectionTest`
Expected: FAIL — model/table missing.

- [ ] **Step 3: Write the migration**

```php
<?php
// database/migrations/2026_06_28_000002_create_monitoring_sections_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('route_id')->constrained('monitoring_routes')->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name');
            $table->unsignedInteger('from_chainage_m');
            $table->unsignedInteger('to_chainage_m');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['route_id', 'from_chainage_m', 'to_chainage_m']);
            $table->unique(['route_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_sections');
    }
};
```

- [ ] **Step 4: Write the model (with resolver)**

```php
<?php
// app/Models/Monitoring/ExpresswaySection.php
namespace App\Models\Monitoring;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpresswaySection extends Model
{
    use HasFactory;

    protected $table = 'monitoring_sections';

    protected $fillable = ['route_id', 'code', 'name', 'from_chainage_m', 'to_chainage_m', 'sort_order'];

    protected $casts = [
        'from_chainage_m' => 'integer',
        'to_chainage_m' => 'integer',
        'sort_order' => 'integer',
    ];

    public function route(): BelongsTo
    {
        return $this->belongsTo(ExpresswayRoute::class, 'route_id');
    }

    /**
     * The section whose [from, to) range contains the given chainage, or null.
     */
    public static function resolveForChainage(int $routeId, int $chainageM): ?self
    {
        return static::where('route_id', $routeId)
            ->where('from_chainage_m', '<=', $chainageM)
            ->where('to_chainage_m', '>', $chainageM)
            ->orderBy('from_chainage_m')
            ->first();
    }
}
```

- [ ] **Step 5: Write the factory**

```php
<?php
// database/factories/Monitoring/ExpresswaySectionFactory.php
namespace Database\Factories\Monitoring;

use App\Models\Monitoring\ExpresswayRoute;
use App\Models\Monitoring\ExpresswaySection;
use Illuminate\Database\Eloquent\Factories\Factory;

class ExpresswaySectionFactory extends Factory
{
    protected $model = ExpresswaySection::class;

    public function definition(): array
    {
        return [
            'route_id' => ExpresswayRoute::factory(),
            'code' => strtoupper($this->faker->unique()->bothify('S-##')),
            'name' => 'Section '.$this->faker->word(),
            'from_chainage_m' => 0,
            'to_chainage_m' => 10000,
            'sort_order' => 0,
        ];
    }
}
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `php artisan test --filter=ExpresswaySectionTest`
Expected: PASS (both).

- [ ] **Step 7: Run on the MySQL dev DB**

Run: `php artisan migrate`
Expected: `monitoring_sections` migrated.

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_06_28_000002_create_monitoring_sections_table.php app/Models/Monitoring/ExpresswaySection.php database/factories/Monitoring/ExpresswaySectionFactory.php tests/Feature/Monitoring/ExpresswaySectionTest.php
git commit -m "feat(monitoring): expressway sections + chainage->section resolver"
```

---

## Task 5: Route directions

**Files:**
- Create: `database/migrations/2026_06_28_000003_create_monitoring_route_directions_table.php`
- Create: `app/Models/Monitoring/ExpresswayDirection.php`
- Create: `database/factories/Monitoring/ExpresswayDirectionFactory.php`
- Test: `tests/Feature/Monitoring/ExpresswayDirectionTest.php`

**Interfaces:**
- Consumes: `ExpresswayRoute`.
- Produces: `ExpresswayDirection` with `route_id, code, label`. Incidents `belongsTo` it via `direction_id`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Monitoring/ExpresswayDirectionTest.php
namespace Tests\Feature\Monitoring;

use App\Models\Monitoring\ExpresswayDirection;
use App\Models\Monitoring\ExpresswayRoute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpresswayDirectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_route_has_many_directions(): void
    {
        $route = ExpresswayRoute::factory()->create();
        ExpresswayDirection::factory()->for($route, 'route')->create(['code' => 'NB', 'label' => 'Toward Dhaka']);
        ExpresswayDirection::factory()->for($route, 'route')->create(['code' => 'SB', 'label' => 'Toward Chittagong']);

        $this->assertCount(2, $route->refresh()->directions);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=ExpresswayDirectionTest`
Expected: FAIL — model/table missing.

- [ ] **Step 3: Write the migration**

```php
<?php
// database/migrations/2026_06_28_000003_create_monitoring_route_directions_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_route_directions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('route_id')->constrained('monitoring_routes')->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('label');
            $table->timestamps();

            $table->unique(['route_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_route_directions');
    }
};
```

- [ ] **Step 4: Write the model**

```php
<?php
// app/Models/Monitoring/ExpresswayDirection.php
namespace App\Models\Monitoring;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpresswayDirection extends Model
{
    use HasFactory;

    protected $table = 'monitoring_route_directions';

    protected $fillable = ['route_id', 'code', 'label'];

    public function route(): BelongsTo
    {
        return $this->belongsTo(ExpresswayRoute::class, 'route_id');
    }
}
```

- [ ] **Step 5: Write the factory**

```php
<?php
// database/factories/Monitoring/ExpresswayDirectionFactory.php
namespace Database\Factories\Monitoring;

use App\Models\Monitoring\ExpresswayDirection;
use App\Models\Monitoring\ExpresswayRoute;
use Illuminate\Database\Eloquent\Factories\Factory;

class ExpresswayDirectionFactory extends Factory
{
    protected $model = ExpresswayDirection::class;

    public function definition(): array
    {
        return [
            'route_id' => ExpresswayRoute::factory(),
            'code' => strtoupper($this->faker->unique()->lexify('??')),
            'label' => 'Toward '.$this->faker->city(),
        ];
    }
}
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `php artisan test --filter=ExpresswayDirectionTest`
Expected: PASS.

- [ ] **Step 7: Run on the MySQL dev DB**

Run: `php artisan migrate`
Expected: `monitoring_route_directions` migrated.

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_06_28_000003_create_monitoring_route_directions_table.php app/Models/Monitoring/ExpresswayDirection.php database/factories/Monitoring/ExpresswayDirectionFactory.php tests/Feature/Monitoring/ExpresswayDirectionTest.php
git commit -m "feat(monitoring): route directions table + model"
```

---

## Task 6: Lanes

**Files:**
- Create: `database/migrations/2026_06_28_000004_create_monitoring_lanes_table.php`
- Create: `app/Models/Monitoring/ExpresswayLane.php`
- Create: `database/factories/Monitoring/ExpresswayLaneFactory.php`
- Test: `tests/Feature/Monitoring/ExpresswayLaneTest.php`

**Interfaces:**
- Consumes: `ExpresswayRoute` (nullable — `route_id` null = global lane definition).
- Produces: `ExpresswayLane` with `route_id (nullable), code, label, kind (carriageway|shoulder|ramp), sort_order`. Incidents `belongsTo` it via nullable `lane_id`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Monitoring/ExpresswayLaneTest.php
namespace Tests\Feature\Monitoring;

use App\Models\Monitoring\ExpresswayLane;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpresswayLaneTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_global_lane_has_null_route(): void
    {
        $lane = ExpresswayLane::factory()->create([
            'route_id' => null, 'code' => 'L1', 'label' => 'Lane 1', 'kind' => 'carriageway',
        ]);

        $this->assertNull($lane->fresh()->route_id);
        $this->assertSame('carriageway', $lane->fresh()->kind);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=ExpresswayLaneTest`
Expected: FAIL — model/table missing.

- [ ] **Step 3: Write the migration**

```php
<?php
// database/migrations/2026_06_28_000004_create_monitoring_lanes_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_lanes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('route_id')->nullable()->constrained('monitoring_routes')->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('label');
            $table->enum('kind', ['carriageway', 'shoulder', 'ramp'])->default('carriageway');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['route_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_lanes');
    }
};
```

- [ ] **Step 4: Write the model**

```php
<?php
// app/Models/Monitoring/ExpresswayLane.php
namespace App\Models\Monitoring;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpresswayLane extends Model
{
    use HasFactory;

    protected $table = 'monitoring_lanes';

    protected $fillable = ['route_id', 'code', 'label', 'kind', 'sort_order'];

    protected $casts = ['sort_order' => 'integer'];

    public function route(): BelongsTo
    {
        return $this->belongsTo(ExpresswayRoute::class, 'route_id');
    }
}
```

- [ ] **Step 5: Write the factory**

```php
<?php
// database/factories/Monitoring/ExpresswayLaneFactory.php
namespace Database\Factories\Monitoring;

use App\Models\Monitoring\ExpresswayLane;
use Illuminate\Database\Eloquent\Factories\Factory;

class ExpresswayLaneFactory extends Factory
{
    protected $model = ExpresswayLane::class;

    public function definition(): array
    {
        return [
            'route_id' => null,
            'code' => strtoupper($this->faker->unique()->bothify('L#')),
            'label' => 'Lane '.$this->faker->numberBetween(1, 4),
            'kind' => 'carriageway',
            'sort_order' => 0,
        ];
    }
}
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `php artisan test --filter=ExpresswayLaneTest`
Expected: PASS.

- [ ] **Step 7: Run on the MySQL dev DB**

Run: `php artisan migrate`
Expected: `monitoring_lanes` migrated.

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_06_28_000004_create_monitoring_lanes_table.php app/Models/Monitoring/ExpresswayLane.php database/factories/Monitoring/ExpresswayLaneFactory.php tests/Feature/Monitoring/ExpresswayLaneTest.php
git commit -m "feat(monitoring): lanes table + model"
```

---

## Task 7: Incident types + severity enum (with SLA targets)

**Files:**
- Create: `app/Enums/Monitoring/IncidentSeverity.php`
- Create: `database/migrations/2026_06_28_000005_create_monitoring_incident_types_table.php`
- Create: `app/Models/Monitoring/IncidentType.php`
- Create: `database/factories/Monitoring/IncidentTypeFactory.php`
- Test: `tests/Feature/Monitoring/IncidentTypeTest.php`

**Interfaces:**
- Produces: `IncidentSeverity` enum (`Minor`, `Major`, `Critical`); `IncidentType` with `code, name, color, icon, default_severity (IncidentSeverity cast), sla_response_minutes (?int), sla_clearance_minutes (?int), is_active`. Plan 2 incidents `belongsTo` IncidentType and read its SLA targets.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Monitoring/IncidentTypeTest.php
namespace Tests\Feature\Monitoring;

use App\Enums\Monitoring\IncidentSeverity;
use App\Models\Monitoring\IncidentType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncidentTypeTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_casts_severity_to_enum_and_keeps_null_sla(): void
    {
        $type = IncidentType::factory()->create([
            'code' => 'ACC',
            'name' => 'Accident',
            'default_severity' => IncidentSeverity::Major,
            'sla_response_minutes' => 15,
            'sla_clearance_minutes' => null,
        ]);

        $fresh = $type->fresh();
        $this->assertSame(IncidentSeverity::Major, $fresh->default_severity);
        $this->assertSame(15, $fresh->sla_response_minutes);
        $this->assertNull($fresh->sla_clearance_minutes);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=IncidentTypeTest`
Expected: FAIL — enum/model/table missing.

- [ ] **Step 3: Write the severity enum**

```php
<?php
// app/Enums/Monitoring/IncidentSeverity.php
namespace App\Enums\Monitoring;

enum IncidentSeverity: string
{
    case Minor = 'minor';
    case Major = 'major';
    case Critical = 'critical';
}
```

- [ ] **Step 4: Write the migration**

```php
<?php
// database/migrations/2026_06_28_000005_create_monitoring_incident_types_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_incident_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->string('color', 20)->nullable();
            $table->string('icon', 50)->nullable();
            $table->enum('default_severity', ['minor', 'major', 'critical'])->default('minor');
            $table->unsignedInteger('sla_response_minutes')->nullable();
            $table->unsignedInteger('sla_clearance_minutes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_incident_types');
    }
};
```

- [ ] **Step 5: Write the model**

```php
<?php
// app/Models/Monitoring/IncidentType.php
namespace App\Models\Monitoring;

use App\Enums\Monitoring\IncidentSeverity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IncidentType extends Model
{
    use HasFactory;

    protected $table = 'monitoring_incident_types';

    protected $fillable = [
        'code', 'name', 'color', 'icon', 'default_severity',
        'sla_response_minutes', 'sla_clearance_minutes', 'is_active',
    ];

    protected $casts = [
        'default_severity' => IncidentSeverity::class,
        'sla_response_minutes' => 'integer',
        'sla_clearance_minutes' => 'integer',
        'is_active' => 'boolean',
    ];
}
```

- [ ] **Step 6: Write the factory**

```php
<?php
// database/factories/Monitoring/IncidentTypeFactory.php
namespace Database\Factories\Monitoring;

use App\Enums\Monitoring\IncidentSeverity;
use App\Models\Monitoring\IncidentType;
use Illuminate\Database\Eloquent\Factories\Factory;

class IncidentTypeFactory extends Factory
{
    protected $model = IncidentType::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper($this->faker->unique()->lexify('???')),
            'name' => ucfirst($this->faker->word()),
            'color' => $this->faker->hexColor(),
            'icon' => 'alert-triangle',
            'default_severity' => IncidentSeverity::Minor,
            'sla_response_minutes' => 15,
            'sla_clearance_minutes' => 90,
            'is_active' => true,
        ];
    }
}
```

- [ ] **Step 7: Run the test to verify it passes**

Run: `php artisan test --filter=IncidentTypeTest`
Expected: PASS.

- [ ] **Step 8: Run on the MySQL dev DB**

Run: `php artisan migrate`
Expected: `monitoring_incident_types` migrated.

- [ ] **Step 9: Commit**

```bash
git add app/Enums/Monitoring/IncidentSeverity.php database/migrations/2026_06_28_000005_create_monitoring_incident_types_table.php app/Models/Monitoring/IncidentType.php database/factories/Monitoring/IncidentTypeFactory.php tests/Feature/Monitoring/IncidentTypeTest.php
git commit -m "feat(monitoring): incident types + severity enum + SLA targets"
```

---

## Self-Review (Plan 1)

- **Spec coverage:** topology (routes/sections/directions/lanes) ✓ T3–T6; incident-type/SLA config ✓ T7; four roles ✓ T1; chainage helper ✓ T2; tenancy/roles-only constraints carried in Global Constraints. Config **UI**, incidents, lifecycle, realtime, duty, and the four outputs are intentionally deferred to Plans 2–5 below.
- **Type consistency:** `route_id` FK name consistent across sections/directions/lanes; `ExpresswaySection::resolveForChainage(int,int): ?self` matches its test; `IncidentSeverity` cases (`Minor/Major/Critical`) consistent in enum, migration enum values (`minor/major/critical`), and factory.
- **No placeholders:** every code/test step is runnable.

---

# Roadmap — Plans 2–5 (written just-in-time before each executes)

Each plan produces working, testable software and is expanded into full bite-sized TDD tasks immediately before execution (mirroring the repo's living-realtime plan convention), because each builds on what the previous one teaches.

### Plan 2 — Topology & incident-type config UI + RBAC
- `MonitoringConfigPolicy` (gates on `Monitoring Manager`/admin roles via `hasRole`), `role:` middleware on routes, controllers returning JSON + an Inertia `Pages/Monitoring/Config/*` admin screen to CRUD routes, sections, directions, lanes, and incident types. Seed a starter route + directions + lanes + common incident types. **Deliverable:** a manager can configure the whole topology from the UI; non-managers are denied (tested).

### Plan 3 — Incident core (create/update/register) + append-only journal
- `IncidentStatus` enum, `monitoring_incidents` (+ `reference_no` generator, denormalized stage timestamps, `updated_at` concurrency), `monitoring_incident_journal_entries` (append-only + amendment linkage), `monitoring_incident_dispatches`. `IncidentPolicy` (roles), `IncidentController` (operator create/update/journal/dispatch), Form Requests (chainage-within-route validation, derive `section_id` via `ExpresswaySection::resolveForChainage`), optimistic + `expected_updated_at` 409. Searchable **incident register** page + Excel export. **Deliverable:** operators log and work incidents; everything is recorded immutably and findable.

### Plan 4 — Lifecycle state machine + SLA + duty/handover
- `IncidentLifecycle` service (legal-transition table, 422 on illegal, Supervisor-only backward correction, atomic transition + cached-timestamp write + optional journal note), `monitoring_incident_status_transitions` (append-only). SLA derivation service (response/clearance actuals; on-track/at-risk/breached). `monitoring_duty_sessions` + open/close/handover flow; **shift handover report** snapshot + dompdf PDF. **Deliverable:** full TMC lifecycle with SLA timing and shift handovers.

### Plan 5 — Live dashboard, analytics, and realtime
- `Pages/Monitoring/Dashboard` (status-lane board, live SLA timers, on-duty panel) and `Pages/Monitoring/Analytics` (response/clearance stats, SLA compliance %, counts by type/section/direction/time, Excel export). Realtime: emit ID-only RTDB signals on create/transition/journal/dispatch via the existing `kreait` pattern + `useRealtimeSignals('incident', …)`; React Query `refetchInterval` polling fallback with a capability flag that disables polling once Firebase is provisioned. Playwright E2E for the full operator→supervisor flow and two-tab propagation. **Deliverable:** the living 24/7 control-room screen.
```
