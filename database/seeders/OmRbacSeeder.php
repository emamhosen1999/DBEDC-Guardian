<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\Access\RoleCatalog;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class OmRbacSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Reset cached roles and permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // 2. Define Granular O&M and Asset Permissions
        $omPermissions = [
            // Core O&M Overview & Navigation
            'om.dashboard.view' => 'View O&M overview and command dashboard',
            'om.analytics.view' => 'View O&M analytics, MTTR/MTBF, and trend reports',
            'om.sla.view' => 'View SLA compliance dashboard and breach registry',

            // Maintenance & Field Operations
            'om.maintenance.view' => 'View defects and maintenance work orders',
            'om.maintenance.manage' => 'Create and manage defects and maintenance work orders',
            'om.pm.manage' => 'Manage preventive maintenance schedules and auto-generation',
            'om.inspections.manage' => 'Create and perform digital asset inspections',
            'om.inventory.manage' => 'Manage spare parts stock and log work order material usage',

            // Incidents, Patrol & Safety
            'om.incidents.view' => 'View incidents and emergency patrol dispatches',
            'om.incidents.manage' => 'Create and manage incidents and patrol dispatches',
            'om.patrol.manage' => 'Start/end highway patrol shifts and log GPS telemetry',
            'om.safety.view' => 'View safety incidents and hazard records',
            'om.safety.manage' => 'Create and manage safety records and toolbox briefings',
            'om.tppd.view' => 'View third-party property damage recovery claims',
            'om.tppd.manage' => 'Manage TPPD legal dossiers and BOQ claim settlements',

            // TMC & Traffic Monitoring
            'om.traffic.view' => 'View traffic monitoring center and VMS controller',
            'om.traffic.manage' => 'Manage VMS messages and traffic operations',
            'om.toll.view' => 'View toll operations and revenue statistics',
            'om.toll.manage' => 'Manage toll audits and operational records',
            'om.equipment.view' => 'View equipment status, CCTV, and asset uptime',
            'om.equipment.manage' => 'Create and manage equipment and asset records',
            'om.shift.manage' => 'Create and acknowledge operational shift handovers',

            // Advanced Engineering & Research
            'om.research.view' => 'View pavement roughness IRI, WIM fatigue, and deterioration models',
            'om.contractors.view' => 'View contractor directory and scorecards',
            'om.contractors.manage' => 'Manage contractor evaluations and contracts',
            'om.ai.manage' => 'Review and approve AI distress detections into work orders',
        ];

        foreach ($omPermissions as $name => $desc) {
            Permission::firstOrCreate(
                ['name' => $name, 'guard_name' => 'web'],
                ['created_at' => now(), 'updated_at' => now()]
            );
        }

        // 3. Roles and their EXACT permission sets come from the one catalog definition (App\Services\Access\RoleCatalog):
        //    O&M Director, Maintenance Inspector, TMC Operator and Highway Patrol Officer are catalog roles.
        RoleCatalog::seed();

        // 5. Assign Roles to Targeted Staff

        // Habib (Md. Habibur Rahman, ID: 127) -> Maintenance Inspector
        $habib = User::where('employee_id', '127')->first();
        if ($habib) {
            $currentRoleNames = $habib->roles->pluck('name')->toArray();
            if (! in_array('Maintenance Inspector', $currentRoleNames)) {
                $habib->assignRole('Maintenance Inspector');
            }
        }

        // Mr. Wang Fu (ID: 896) -> O&M Director
        $wangfu = User::where('employee_id', '896')->first();
        if ($wangfu) {
            $currentRoleNames = $wangfu->roles->pluck('name')->toArray();
            if (! in_array('O&M Director', $currentRoleNames)) {
                $wangfu->assignRole('O&M Director');
            }
        }

        // Traffic Management Center Department TMC Operators
        $tmcOperatorIds = ['305', '306', '308', '309', '397'];
        foreach ($tmcOperatorIds as $opId) {
            $operator = User::where('employee_id', $opId)->first();
            if ($operator) {
                $currentRoleNames = $operator->roles->pluck('name')->toArray();
                if (! in_array('TMC Operator', $currentRoleNames)) {
                    $operator->assignRole('TMC Operator');
                }
            }
        }

        // Emam Hosen (ID: 151) -> Super Administrator
        $emam = User::where('employee_id', '151')->first();
        if ($emam && ! $emam->hasRole('Super Administrator')) {
            $emam->assignRole('Super Administrator');
        }

        // Clear permission cache again so all new assignments take effect instantly
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
