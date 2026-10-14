<?php

namespace Tests\Feature\Jurisdiction;

use App\Models\DailyWork;
use App\Models\Jurisdiction;
use App\Models\User;
use App\Services\Corridor\ChainageResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Shared fixtures: a clean world with named permissions, three phases and the people to run them. */
abstract class JurisdictionTestCase extends TestCase
{
    use RefreshDatabase;

    protected const PERMISSIONS = ['jurisdiction.view', 'jurisdiction.create', 'jurisdiction.update', 'jurisdiction.delete', 'jurisdiction.team.manage', 'jurisdiction.history.correct', 'jurisdiction.restructure', 'jurisdiction.retire', 'jurisdiction.restore'];

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (self::PERMISSIONS as $p) {
            Permission::findOrCreate($p, 'web');
        }
        app(ChainageResolver::class)->forget();
    }

    protected function employee(array $permissions = [], array $attrs = []): User
    {
        $u = User::factory()->create($attrs + ['is_active' => true]);
        if ($permissions !== []) {
            $u->givePermissionTo($permissions);
        }

        return $u;
    }

    protected function admin(): User
    {
        return $this->employee(self::PERMISSIONS);
    }

    /** Phase One K0..K27+999, Two K38..K48+999, Three K28..K37+999, as in production. */
    protected function phases(): array
    {
        $one = $this->employee();
        $two = $this->employee();
        $three = $this->employee();

        return [
            'one' => Jurisdiction::create(['code' => 'PH-1', 'location' => 'Phase One', 'start_chainage' => 'K0+000', 'end_chainage' => 'K27+999', 'incharge' => $one->employee_id]),
            'two' => Jurisdiction::create(['code' => 'PH-2', 'location' => 'Phase Two', 'start_chainage' => 'K38+000', 'end_chainage' => 'K48+999', 'incharge' => $two->employee_id]),
            'three' => Jurisdiction::create(['code' => 'PH-3', 'location' => 'Phase Three', 'start_chainage' => 'K28+000', 'end_chainage' => 'K37+999', 'incharge' => $three->employee_id]),
            'people' => [$one, $two, $three],
        ];
    }

    protected function work(string $number, string $location, string $date, string $status, string $incharge, ?string $assigned = null, string $type = 'Pavement'): DailyWork
    {
        return DailyWork::create([
            'date' => $date, 'number' => $number, 'status' => $status, 'type' => $type, 'description' => 'd', 'location' => $location,
            'incharge' => $incharge, 'assigned' => $assigned ?? $incharge,
        ]);
    }
}
