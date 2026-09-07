<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
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

        // 3. Define Standard O&M Roles
        $rolesConfig = [
            'Super Administrator' => [
                'description' => 'Unrestricted access to all system functions and modules',
                'hierarchy_level' => 1,
                'is_system_role' => true,
            ],
            'Administrator' => [
                'description' => 'Administrative access to most system functions',
                'hierarchy_level' => 10,
                'is_system_role' => true,
            ],
            'O&M Director' => [
                'description' => 'Executive director of operations, maintenance, traffic management, and expressway concessions',
                'hierarchy_level' => 15,
                'is_system_role' => false,
            ],
            'Maintenance Inspector / QC Specialist' => [
                'description' => 'Field maintenance inspector, quality assurance engineer, and defect verification specialist',
                'hierarchy_level' => 35,
                'is_system_role' => false,
            ],
            'TMC Operator' => [
                'description' => 'Traffic Management Center control room operator, CCTV/VMS controller, and shift logger',
                'hierarchy_level' => 45,
                'is_system_role' => false,
            ],
            'Highway Patrol Officer' => [
                'description' => 'Highway patrol officer, emergency responder, and crash damage assessor',
                'hierarchy_level' => 55,
                'is_system_role' => false,
            ],
        ];

        $roles = [];
        foreach ($rolesConfig as $roleName => $meta) {
            $roles[$roleName] = Role::firstOrCreate(
                ['name' => $roleName, 'guard_name' => 'web'],
                $meta
            );
        }

        // 4. Assign Permissions to Roles

        // Super Administrator -> ALWAYS receives ALL permissions in the database unconditionally
        $allPermissions = Permission::all();
        $roles['Super Administrator']->syncPermissions($allPermissions);

        // Administrator -> All permissions except destructive backups
        $adminPermissions = Permission::whereNotIn('name', [
            'users.impersonate',
            'backup.create',
            'backup.restore',
        ])->get();
        $roles['Administrator']->syncPermissions($adminPermissions);

        // O&M Director -> Complete O&M, Quality, Assets, Safety, PPM, and Management Permissions
        $omDirectorPermissions = Permission::where(function ($q) {
            $q->where('name', 'like', 'om.%')
              ->orWhere('name', 'like', 'quality.%')
              ->orWhere('name', 'like', 'assets.%')
              ->orWhere('name', 'like', 'daily-works.%')
              ->orWhere('name', 'like', 'compliance.%')
              ->orWhere('name', 'like', 'attendance.own.%')
              ->orWhere('name', 'like', 'leave.own.%')
              ->orWhere('name', 'like', 'profile.own.%')
              ->orWhereIn('name', [
                  'core.dashboard.view',
                  'core.stats.view',
                  'employees.view',
                  'departments.view',
                  'designations.view',
                  'attendance.view',
                  'leaves.view',
              ]);
        })->get();
        $roles['O&M Director']->syncPermissions($omDirectorPermissions);

        // Maintenance Inspector / QC Specialist (Md. Habibur Rahman)
        $qcInspectorPermissions = Permission::where(function ($q) {
            $q->where('name', 'like', 'quality.%')
              ->orWhere('name', 'like', 'attendance.own.%')
              ->orWhere('name', 'like', 'leave.own.%')
              ->orWhere('name', 'like', 'profile.own.%')
              ->orWhere('name', 'like', 'daily-works.%')
              ->orWhereIn('name', [
                  'core.dashboard.view',
                  'om.dashboard.view',
                  'om.maintenance.view',
                  'om.maintenance.manage',
                  'om.pm.manage',
                  'om.inspections.manage',
                  'om.inventory.manage',
                  'om.equipment.view',
                  'om.equipment.manage',
                  'om.safety.view',
                  'om.safety.manage',
                  'om.sla.view',
                  'om.research.view',
                  'om.ai.manage',
              ]);
        })->get();
        $roles['Maintenance Inspector / QC Specialist']->syncPermissions($qcInspectorPermissions);

        // TMC Operator (Control Room)
        $tmcPermissions = Permission::where(function ($q) {
            $q->where('name', 'like', 'attendance.own.%')
              ->orWhere('name', 'like', 'leave.own.%')
              ->orWhere('name', 'like', 'profile.own.%')
              ->orWhere('name', 'like', 'daily-works.%')
              ->orWhereIn('name', [
                  'core.dashboard.view',
                  'om.dashboard.view',
                  'om.traffic.view',
                  'om.traffic.manage',
                  'om.toll.view',
                  'om.toll.manage',
                  'om.incidents.view',
                  'om.incidents.manage',
                  'om.equipment.view',
                  'om.shift.manage',
                  'om.sla.view',
                  'om.safety.view',
              ]);
        })->get();
        $roles['TMC Operator']->syncPermissions($tmcPermissions);

        // Highway Patrol Officer (Field Patrol & Emergency)
        $patrolPermissions = Permission::where(function ($q) {
            $q->where('name', 'like', 'attendance.own.%')
              ->orWhere('name', 'like', 'leave.own.%')
              ->orWhere('name', 'like', 'profile.own.%')
              ->orWhere('name', 'like', 'daily-works.%')
              ->orWhereIn('name', [
                  'core.dashboard.view',
                  'om.dashboard.view',
                  'om.incidents.view',
                  'om.incidents.manage',
                  'om.patrol.manage',
                  'om.maintenance.view',
                  'om.maintenance.manage',
                  'om.safety.view',
                  'om.safety.manage',
                  'om.tppd.view',
                  'om.tppd.manage',
                  'om.research.view',
                  'om.shift.manage',
              ]);
        })->get();
        $roles['Highway Patrol Officer']->syncPermissions($patrolPermissions);

        // 5. Assign Roles to Targeted Staff

        // Habib (Md. Habibur Rahman, ID: 127) -> Maintenance Inspector / QC Specialist
        $habib = User::where('employee_id', '127')->first();
        if ($habib) {
            $currentRoleNames = $habib->roles->pluck('name')->toArray();
            if (!in_array('Maintenance Inspector / QC Specialist', $currentRoleNames)) {
                $habib->assignRole('Maintenance Inspector / QC Specialist');
            }
        }

        // Mr. Wang Fu (ID: 896) -> O&M Director
        $wangfu = User::where('employee_id', '896')->first();
        if ($wangfu) {
            $currentRoleNames = $wangfu->roles->pluck('name')->toArray();
            if (!in_array('O&M Director', $currentRoleNames)) {
                $wangfu->assignRole('O&M Director');
            }
        }

        // Traffic Management Center Department TMC Operators
        $tmcOperatorIds = ['305', '306', '308', '309', '397'];
        foreach ($tmcOperatorIds as $opId) {
            $operator = User::where('employee_id', $opId)->first();
            if ($operator) {
                $currentRoleNames = $operator->roles->pluck('name')->toArray();
                if (!in_array('TMC Operator', $currentRoleNames)) {
                    $operator->assignRole('TMC Operator');
                }
            }
        }

        // Emam Hosen (ID: 151) -> Super Administrator
        $emam = User::where('employee_id', '151')->first();
        if ($emam && !$emam->hasRole('Super Administrator')) {
            $emam->assignRole('Super Administrator');
        }

        // Clear permission cache again so all new assignments take effect instantly
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
