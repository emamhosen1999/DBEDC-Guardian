<?php

namespace Database\Seeders;

use App\Services\Access\RoleCatalog;
use Illuminate\Console\Command;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class ComprehensiveRolePermissionSeeder extends Seeder
{
    /**
     * The command instance.
     *
     * @var Command
     */
    protected $command;

    /**
     * Set the console command instance.
     *
     * @return void
     */
    public function setCommand(Command $command)
    {
        $this->command = $command;

        return $this;
    }

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Clear cache
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Create permissions for all modules
        $this->createPermissions();

        // Create roles with hierarchy
        $this->createRoles();

        // Assign permissions to roles
        $this->assignPermissionsToRoles();

        if ($this->command) {
            $this->command->info('✅ Comprehensive role and permission system created successfully!');
        }
    }

    /**
     * Create all permissions based on modules
     */
    private function createPermissions(): void
    {
        $modules = [
            // Core System
            'core' => [
                'core.dashboard.view' => 'View dashboard and analytics',
                'core.stats.view' => 'View system statistics',
                'core.updates.view' => 'View system updates',
            ],

            // Self Service Module
            'self-service' => [
                'attendance.own.view' => 'View own attendance records',
                'attendance.own.punch' => 'Punch in/out attendance',
                'leave.own.view' => 'View own leave requests',
                'leave.own.create' => 'Create own leave requests',
                'leave.own.update' => 'Update own leave requests',
                'leave.own.delete' => 'Delete own leave requests',
                'communications.own.view' => 'View own communications',
                'profile.own.view' => 'View own profile',
                'profile.own.update' => 'Update own profile',
                'profile.password.change' => 'Change own password',
            ],

            // Human Resource Management
            'hrm' => [
                'employees.view' => 'View employee records',
                'employees.create' => 'Create employee records',
                'employees.update' => 'Update employee records',
                'employees.delete' => 'Delete employee records',
                'employees.import' => 'Import employee data',
                'employees.export' => 'Export employee data',
                'employees.placement.update' => "Change employees' designation, reporting line and work location",
                'employees.attendance-config.update' => "Change employees' attendance method and biometric device rules",
                'employees.compensation.view' => "View employees' salary and statutory details",
                'employees.compensation.update' => "Change employees' salary and statutory details",
                'employees.password.reset' => "Reset employees' passwords",
                'employees.devices.manage' => "Manage employees' device lock, registered devices and sessions",
                'employees.access.manage' => "Manage employees' roles and direct permissions",
                'employees.restore' => 'Restore deactivated employees',
                'departments.view' => 'View departments',
                'departments.create' => 'Create departments',
                'departments.update' => 'Update departments',
                'departments.delete' => 'Delete departments',
                'designations.view' => 'View designations/positions',
                'designations.create' => 'Create designations',
                'designations.update' => 'Update designations',
                'designations.delete' => 'Delete designations',
                'attendance.view' => 'View all attendance records',
                'attendance.create' => 'Create attendance records',
                'attendance.update' => 'Update attendance records',
                'attendance.delete' => 'Delete attendance records',
                'attendance.correct' => 'Correct attendance records (edit punch-in/out)',
                'attendance.import' => 'Import attendance data',
                'attendance.export' => 'Export attendance data',
                'holidays.view' => 'View holidays',
                'holidays.create' => 'Create holidays',
                'holidays.update' => 'Update holidays',
                'holidays.delete' => 'Delete holidays',
                'leaves.view' => 'View all leave requests',
                'leaves.create' => 'Create leave requests',
                'leaves.update' => 'Update leave requests',
                'leaves.delete' => 'Delete leave requests',
                'leaves.approve' => 'Approve/reject leave requests',
                'leaves.analytics' => 'View leave analytics',
                'leave-settings.view' => 'View leave policy settings',
                'leave-settings.update' => 'Update leave policy settings',
                'jurisdiction.view' => 'View work locations',
                'jurisdiction.create' => 'Create work locations',
                'jurisdiction.update' => 'Update work locations',
                'jurisdiction.delete' => 'Delete work locations',

                // New HRM Permissions
                // Onboarding & Offboarding
                'hr.onboarding.view' => 'View employee onboarding',
                'hr.onboarding.create' => 'Create onboarding process',
                'hr.onboarding.update' => 'Update onboarding process',
                'hr.onboarding.delete' => 'Delete onboarding process',
                'hr.offboarding.view' => 'View employee offboarding',
                'hr.offboarding.create' => 'Create offboarding process',
                'hr.offboarding.update' => 'Update offboarding process',
                'hr.offboarding.delete' => 'Delete offboarding process',
                'hr.settlement.manage' => 'Create full and final settlement drafts',
                'hr.settlement.approve' => 'Approve full and final settlements',
                'hr.settlement.disburse' => 'Disburse full and final settlements',
                'hr.assets.view' => 'View company assets and assignments',
                'hr.assets.manage' => 'Create, assign, return and delete company assets',
                'hr.probation.manage' => 'Confirm employees after probation',
                'hr.checklists.view' => 'View HR checklists',
                'hr.checklists.create' => 'Create HR checklists',
                'hr.checklists.update' => 'Update HR checklists',
                'hr.checklists.delete' => 'Delete HR checklists',

                // Skills & Competency Management
                'hr.skills.view' => 'View skills database',
                'hr.skills.create' => 'Create skills',
                'hr.skills.update' => 'Update skills',
                'hr.skills.delete' => 'Delete skills',
                'hr.competencies.view' => 'View competencies',
                'hr.competencies.create' => 'Create competencies',
                'hr.competencies.update' => 'Update competencies',
                'hr.competencies.delete' => 'Delete competencies',
                'hr.employee.skills.view' => 'View employee skills',
                'hr.employee.skills.create' => 'Add employee skills',
                'hr.employee.skills.update' => 'Update employee skills',
                'hr.employee.skills.delete' => 'Remove employee skills',

                // Employee Benefits Administration
                'hr.benefits.view' => 'View benefits programs',
                'hr.benefits.create' => 'Create benefits programs',
                'hr.benefits.update' => 'Update benefits programs',
                'hr.benefits.delete' => 'Delete benefits programs',
                'hr.employee.benefits.view' => 'View employee benefits',
                'hr.employee.benefits.assign' => 'Assign benefits to employees',
                'hr.employee.benefits.update' => 'Update employee benefits',
                'hr.employee.benefits.remove' => 'Remove employee benefits',

                // Enhanced Time-off Management
                'hr.timeoff.view' => 'View time-off management',
                'hr.timeoff.calendar.view' => 'View time-off calendar',
                'hr.timeoff.approve' => 'Approve time-off requests',
                'hr.timeoff.reject' => 'Reject time-off requests',
                'hr.timeoff.reports.view' => 'View time-off reports',
                'hr.timeoff.settings.view' => 'View time-off settings',
                'hr.timeoff.settings.update' => 'Update time-off settings',

                // Workplace Health & Safety
                'hr.safety.view' => 'View workplace safety',
                'hr.safety.incidents.view' => 'View safety incidents',
                'hr.safety.incidents.create' => 'Create safety incidents',
                'hr.safety.incidents.update' => 'Update safety incidents',
                'hr.safety.inspections.view' => 'View safety inspections',
                'hr.safety.inspections.create' => 'Create safety inspections',
                'hr.safety.inspections.update' => 'Update safety inspections',
                'hr.safety.training.view' => 'View safety training',
                'hr.safety.training.create' => 'Create safety training',
                'hr.safety.training.update' => 'Update safety training',

                // HR Analytics & Reporting
                'hr.analytics.view' => 'View HR analytics',
                'hr.analytics.attendance' => 'View attendance analytics',
                'hr.analytics.performance' => 'View performance analytics',
                'hr.analytics.recruitment' => 'View recruitment analytics',
                'hr.analytics.turnover' => 'View employee turnover analytics',
                'hr.analytics.training' => 'View training analytics',
                'hr.analytics.reports.view' => 'View HR reports',
                'hr.analytics.reports.generate' => 'Generate HR reports',

                // HR Document Management
                'hr.documents.view' => 'View HR documents',
                'hr.documents.create' => 'Create HR documents',
                'hr.documents.update' => 'Update HR documents',
                'hr.documents.delete' => 'Delete HR documents',
                'hr.documents.categories.view' => 'View document categories',
                'hr.documents.categories.create' => 'Create document categories',
                'hr.documents.categories.update' => 'Update document categories',
                'hr.documents.categories.delete' => 'Delete document categories',
                'hr.employee.documents.view' => 'View employee documents',
                'hr.employee.documents.create' => 'Create employee documents',
                'hr.employee.documents.delete' => 'Delete employee documents',

                // Enhanced Employee Self-Service
                'hr.selfservice.view' => 'Access self-service portal',
                'hr.selfservice.profile.view' => 'View own profile in self-service',
                'hr.selfservice.profile.update' => 'Update own profile in self-service',
                'hr.selfservice.documents.view' => 'View own documents in self-service',
                'hr.selfservice.benefits.view' => 'View own benefits in self-service',
                'hr.selfservice.timeoff.view' => 'View own time-off in self-service',
                'hr.selfservice.timeoff.request' => 'Request time-off in self-service',
                'hr.selfservice.trainings.view' => 'View own trainings in self-service',
                'hr.selfservice.payslips.view' => 'View own payslips in self-service',
                'hr.selfservice.performance.view' => 'View own performance in self-service',

                // Payroll Management System
                'hr.payroll.view' => 'View payroll records',
                'hr.payroll.create' => 'Create payroll records',
                'hr.payroll.update' => 'Update payroll records',
                'hr.payroll.delete' => 'Delete payroll records',
                'hr.payroll.process' => 'Process payroll records',
                'hr.payroll.bulk' => 'Bulk payroll operations',
                'hr.payslips.view' => 'View payslips',
                'hr.payslips.download' => 'Download payslips',
                'hr.payslips.email' => 'Email payslips',
                'hr.payroll.reports' => 'View payroll reports',
                'hr.payroll.analytics' => 'View payroll analytics',
            ],

            // Project & Portfolio Management
            'ppm' => [
                'daily-works.view' => 'View work logs',
                'daily-works.create' => 'Create work logs',
                'daily-works.update' => 'Update work logs',
                'daily-works.delete' => 'Delete work logs',
                'daily-works.import' => 'Import work log data',
                'daily-works.export' => 'Export work log data',
                'projects.analytics' => 'View project analytics',
                'tasks.view' => 'View tasks',
                'tasks.create' => 'Create tasks',
                'tasks.update' => 'Update tasks',
                'tasks.delete' => 'Delete tasks',
                'tasks.assign' => 'Assign tasks',
                'reports.view' => 'View reports',
                'reports.create' => 'Create reports',
                'reports.update' => 'Update reports',
                'reports.delete' => 'Delete reports',
            ],

            // Operations & Maintenance (O&M) and Traffic Monitoring Center (TMC)
            'om' => [
                'om.dashboard.view' => 'View O&M overview and command dashboard',
                'om.traffic.view' => 'View traffic monitoring center and VMS controller',
                'om.traffic.manage' => 'Manage VMS messages and traffic operations',
                'om.toll.view' => 'View toll operations and revenue statistics',
                'om.toll.manage' => 'Manage toll audits and operational records',
                'om.incidents.view' => 'View incidents and emergency patrol dispatches',
                'om.incidents.manage' => 'Create and manage incidents and patrol dispatches',
                'om.maintenance.view' => 'View defects and maintenance work orders',
                'om.maintenance.manage' => 'Create and manage defects and maintenance work orders',
                'om.equipment.view' => 'View equipment status and asset uptime',
                'om.equipment.manage' => 'Create and manage equipment and asset records',
                'om.shift.manage' => 'Create and acknowledge operational shift handovers',
            ],

            // HR Performance Management
            'performance' => [
                'performance-reviews.view' => 'View performance reviews',
                'performance-reviews.create' => 'Create performance reviews',
                'performance-reviews.update' => 'Update performance reviews',
                'performance-reviews.delete' => 'Delete performance reviews',
                'performance-reviews.approve' => 'Approve/reject performance reviews',
                'performance-reviews.own.view' => 'View own performance reviews',
                'performance-reviews.own.create' => 'Create own performance reviews',
                'performance-reviews.own.update' => 'Update own performance reviews',
                'performance-templates.view' => 'View performance review templates',
                'performance-templates.create' => 'Create performance review templates',
                'performance-templates.update' => 'Update performance review templates',
                'performance-templates.delete' => 'Delete performance review templates',
                'performance-analytics.view' => 'View performance analytics',
            ],

            // HR Training Management
            'training' => [
                'training-sessions.view' => 'View training sessions',
                'training-sessions.create' => 'Create training sessions',
                'training-sessions.update' => 'Update training sessions',
                'training-sessions.delete' => 'Delete training sessions',
                'training-categories.view' => 'View training categories',
                'training-categories.create' => 'Create training categories',
                'training-categories.update' => 'Update training categories',
                'training-categories.delete' => 'Delete training categories',
                'training-materials.view' => 'View training materials',
                'training-materials.create' => 'Create training materials',
                'training-materials.update' => 'Update training materials',
                'training-materials.delete' => 'Delete training materials',
                'training-enrollments.view' => 'View training enrollments',
                'training-enrollments.create' => 'Create training enrollments',
                'training-enrollments.update' => 'Update training enrollments',
                'training-enrollments.delete' => 'Delete training enrollments',
                'training-assignments.view' => 'View training assignments',
                'training-assignments.create' => 'Create training assignments',
                'training-assignments.update' => 'Update training assignments',
                'training-assignments.delete' => 'Delete training assignments',
                'training-assignment-submissions.view' => 'View training assignment submissions',
                'training-assignment-submissions.create' => 'Create training assignment submissions',
                'training-assignment-submissions.update' => 'Update training assignment submissions',
                'training-assignment-submissions.grade' => 'Grade training assignment submissions',
                'training-feedback.view' => 'View training feedback',
                'training-feedback.create' => 'Create training feedback',
                'training-feedback.own.view' => 'View own training enrollments',
                'training-feedback.own.create' => 'Create own training feedback',
                'training-analytics.view' => 'View training analytics',
            ],

            // HR Recruitment Management
            'recruitment' => [
                'jobs.view' => 'View job postings',
                'jobs.create' => 'Create job postings',
                'jobs.update' => 'Update job postings',
                'jobs.delete' => 'Delete job postings',
                'job-applications.view' => 'View job applications',
                'job-applications.create' => 'Create job applications',
                'job-applications.update' => 'Update job applications',
                'job-applications.delete' => 'Delete job applications',
                'job-hiring-stages.view' => 'View job hiring stages',
                'job-hiring-stages.create' => 'Create job hiring stages',
                'job-hiring-stages.update' => 'Update job hiring stages',
                'job-hiring-stages.delete' => 'Delete job hiring stages',
                'job-interviews.view' => 'View job interviews',
                'job-interviews.create' => 'Create job interviews',
                'job-interviews.update' => 'Update job interviews',
                'job-interviews.delete' => 'Delete job interviews',
                'job-interview-feedback.view' => 'View job interview feedback',
                'job-interview-feedback.create' => 'Create job interview feedback',
                'job-interview-feedback.update' => 'Update job interview feedback',
                'job-offers.view' => 'View job offers',
                'job-offers.create' => 'Create job offers',
                'job-offers.update' => 'Update job offers',
                'job-offers.delete' => 'Delete job offers',
                'job-offers.approve' => 'Approve job offers',
                'recruitment-analytics.view' => 'View recruitment analytics',
            ],

            // Document & Knowledge Management
            'dms' => [
                'letters.view' => 'View official correspondence',
                'letters.create' => 'Create official correspondence',
                'letters.update' => 'Update official correspondence',
                'letters.delete' => 'Delete official correspondence',
                'documents.view' => 'View documents',
                'documents.create' => 'Create documents',
                'documents.update' => 'Update documents',
                'documents.delete' => 'Delete documents',
            ],

            // Customer Relationship Management (Future)
            'crm' => [
                'customers.view' => 'View customer records',
                'customers.create' => 'Create customer records',
                'customers.update' => 'Update customer records',
                'customers.delete' => 'Delete customer records',
                'leads.view' => 'View leads and opportunities',
                'leads.create' => 'Create leads',
                'leads.update' => 'Update leads',
                'leads.delete' => 'Delete leads',
                'feedback.view' => 'View customer feedback',
                'feedback.create' => 'Create feedback records',
                'feedback.update' => 'Update feedback',
                'feedback.delete' => 'Delete feedback',
            ],

            // Supply Chain & Inventory Management (Future)
            'scm' => [
                'inventory.view' => 'View inventory',
                'inventory.create' => 'Create inventory items',
                'inventory.update' => 'Update inventory',
                'inventory.delete' => 'Delete inventory items',
                'suppliers.view' => 'View suppliers',
                'suppliers.create' => 'Create supplier records',
                'suppliers.update' => 'Update suppliers',
                'suppliers.delete' => 'Delete suppliers',
                'purchase-orders.view' => 'View purchase orders',
                'purchase-orders.create' => 'Create purchase orders',
                'purchase-orders.update' => 'Update purchase orders',
                'purchase-orders.delete' => 'Delete purchase orders',
                'warehousing.view' => 'View warehouse operations',
                'warehousing.manage' => 'Manage warehouse operations',
            ],

            // Retail & Sales Operations (Future)
            'retail' => [
                'pos.view' => 'View point of sale',
                'pos.operate' => 'Operate POS terminal',
                'sales.view' => 'View sales records',
                'sales.create' => 'Create sales transactions',
                'sales.analytics' => 'View sales analytics',
            ],

            // Financial Management & Accounting (Future)
            'finance' => [
                'accounts-payable.view' => 'View accounts payable',
                'accounts-payable.manage' => 'Manage accounts payable',
                'accounts-receivable.view' => 'View accounts receivable',
                'accounts-receivable.manage' => 'Manage accounts receivable',
                'ledger.view' => 'View general ledger',
                'ledger.manage' => 'Manage general ledger',
                'financial-reports.view' => 'View financial reports',
                'financial-reports.create' => 'Create financial reports',
                'petty-cash.view-all' => 'View other employees\' petty cash loans and transactions',
                'petty-cash.approve' => 'Approve or reject petty cash loans',
                'petty-cash.manage' => 'Record, edit and close transactions on other employees\' petty cash loans',
            ],

            // System Administration
            'admin' => [
                'users.view' => 'View user accounts',
                'users.create' => 'Create user accounts',
                'users.update' => 'Update user accounts',
                'users.delete' => 'Delete user accounts',
                'users.impersonate' => 'Impersonate other users',
                'department.scopes.manage' => 'Grant and revoke department admin / acting scopes',
                'department.admin' => 'Administer own department (employees, attendance, leave, lifecycle)',
                'access.self-administration' => 'Self-administration: act on oneself like on department employees (audited, global admins notified)',
                'roles.view' => 'View roles and permissions',
                'roles.create' => 'Create roles',
                'roles.update' => 'Update roles',
                'roles.delete' => 'Delete roles',
                'permissions.assign' => 'Assign permissions to roles',
                'settings.view' => 'View system settings',
                'settings.update' => 'Update system settings',
                'company.settings' => 'Manage company settings',
                'attendance.settings' => 'Manage attendance settings',
                'attendance.roster.manage' => 'Assign shifts, edit the roster and decide shift swaps (no company-wide attendance settings)',
                'email.settings' => 'Manage email settings',
                'notification.settings' => 'Manage notification settings',
                'theme.settings' => 'Manage theme and branding',
                'localization.settings' => 'Manage localization settings',
                'performance.settings' => 'Manage performance settings',
                'approval.settings' => 'Manage approval workflows',
                'invoice.settings' => 'Manage invoice settings',
                'salary.settings' => 'Manage salary settings',
                'system.settings' => 'Manage system architecture',
                'audit.view' => 'View audit logs',
                'audit.export' => 'Export audit data',
                'backup.create' => 'Create system backups',
                'backup.restore' => 'Restore system backups',
                'request_logs.view' => 'View request logs',
                'request_logs.delete' => 'Delete request logs',
                'request_logs.clear_all' => 'Clear all request logs',
                'notifications.settings' => 'Manage notification type settings (channels, recipients)',
            ],

            // Compliance Management
            'compliance' => [
                'compliance.view' => 'Access compliance module',
                'compliance.dashboard.view' => 'View compliance dashboard',
                'compliance.documents.view' => 'View compliance documents',
                'compliance.documents.create' => 'Create compliance documents',
                'compliance.documents.update' => 'Update compliance documents',
                'compliance.documents.delete' => 'Delete compliance documents',
                'compliance.audits.view' => 'View compliance audits',
                'compliance.audits.create' => 'Create compliance audits',
                'compliance.audits.update' => 'Update compliance audits',
                'compliance.audits.delete' => 'Delete compliance audits',
                'compliance.requirements.view' => 'View compliance requirements',
                'compliance.requirements.create' => 'Create compliance requirements',
                'compliance.requirements.update' => 'Update compliance requirements',
                'compliance.requirements.delete' => 'Delete compliance requirements',
                'compliance.settings' => 'Manage compliance settings',
            ],

            // Quality Management
            'quality' => [
                'quality.view' => 'Access quality control module',
                'quality.dashboard.view' => 'View quality dashboard',
                'quality.inspections.view' => 'View quality inspections',
                'quality.inspections.create' => 'Create quality inspections',
                'quality.inspections.update' => 'Update quality inspections',
                'quality.inspections.delete' => 'Delete quality inspections',
                'quality.ncr.view' => 'View non-conformance reports',
                'quality.ncr.create' => 'Create non-conformance reports',
                'quality.ncr.update' => 'Update non-conformance reports',
                'quality.ncr.delete' => 'Delete non-conformance reports',
                'quality.calibrations.view' => 'View equipment calibrations',
                'quality.calibrations.create' => 'Create equipment calibrations',
                'quality.calibrations.update' => 'Update equipment calibrations',
                'quality.calibrations.delete' => 'Delete equipment calibrations',
                'quality.settings' => 'Manage quality control settings',
            ],

            // Analytics & Business Intelligence
            'analytics' => [
                'analytics.view' => 'Access analytics module',
                'analytics.reports.view' => 'View analytics reports',
                'analytics.reports.create' => 'Create analytics reports',
                'analytics.reports.update' => 'Update analytics reports',
                'analytics.reports.delete' => 'Delete analytics reports',
                'analytics.reports.schedule' => 'Schedule analytics reports',
                'analytics.dashboards.view' => 'View analytics dashboards',
                'analytics.dashboards.create' => 'Create analytics dashboards',
                'analytics.dashboards.update' => 'Update analytics dashboards',
                'analytics.dashboards.delete' => 'Delete analytics dashboards',
                'analytics.kpi.view' => 'View key performance indicators',
                'analytics.kpi.create' => 'Create key performance indicators',
                'analytics.kpi.update' => 'Update key performance indicators',
                'analytics.kpi.delete' => 'Delete key performance indicators',
                'analytics.kpi.log' => 'Log KPI values',
                'analytics.settings' => 'Manage analytics settings',
            ],

            // Project Management (Extended)
            'project-management' => [
                'project-management.view' => 'Access project management module',
                'project-management.dashboard.view' => 'View project management dashboard',
                'project-management.projects.view' => 'View projects',
                'project-management.projects.create' => 'Create projects',
                'project-management.projects.update' => 'Update projects',
                'project-management.projects.delete' => 'Delete projects',
                'project-management.milestones.view' => 'View project milestones',
                'project-management.milestones.create' => 'Create project milestones',
                'project-management.milestones.update' => 'Update project milestones',
                'project-management.milestones.delete' => 'Delete project milestones',
                'project-management.tasks.view' => 'View project tasks',
                'project-management.tasks.create' => 'Create project tasks',
                'project-management.tasks.update' => 'Update project tasks',
                'project-management.tasks.delete' => 'Delete project tasks',
                'project-management.tasks.assign' => 'Assign project tasks',
                'project-management.resources.view' => 'View project resources',
                'project-management.resources.assign' => 'Assign project resources',
                'project-management.issues.view' => 'View project issues',
                'project-management.issues.create' => 'Create project issues',
                'project-management.issues.update' => 'Update project issues',
                'project-management.issues.delete' => 'Delete project issues',
                'project-management.reports.view' => 'View project reports',
                'project-management.settings' => 'Manage project settings',
            ],

            // Learning Management System (LMS)
            'lms' => [
                'lms.view' => 'View LMS dashboard',

                // Course Management
                'lms.courses.view' => 'View courses',
                'lms.courses.create' => 'Create courses',
                'lms.courses.update' => 'Update courses',
                'lms.courses.delete' => 'Delete courses',

                // Student Management
                'lms.students.view' => 'View students',
                'lms.students.create' => 'Enroll students',
                'lms.students.update' => 'Update student records',
                'lms.students.delete' => 'Remove students',

                // Instructor Management
                'lms.instructors.view' => 'View instructors',
                'lms.instructors.create' => 'Add instructors',
                'lms.instructors.update' => 'Update instructor records',
                'lms.instructors.delete' => 'Remove instructors',

                // Assessment Management
                'lms.assessments.view' => 'View assessments',
                'lms.assessments.create' => 'Create assessments',
                'lms.assessments.update' => 'Update assessments',
                'lms.assessments.delete' => 'Delete assessments',

                // Certificate Management
                'lms.certificates.view' => 'View certificates',
                'lms.certificates.create' => 'Issue certificates',
                'lms.certificates.update' => 'Update certificates',
                'lms.certificates.delete' => 'Revoke certificates',

                // Reports and Analytics
                'lms.reports.view' => 'View LMS reports',

                // Settings
                'lms.settings.manage' => 'Manage LMS settings',
            ],
        ];

        foreach ($modules as $module => $permissions) {
            foreach ($permissions as $permissionName => $description) {
                Permission::firstOrCreate([
                    'name' => $permissionName,
                    'guard_name' => 'web',
                ], [
                    'module' => $module,
                    'description' => $description,
                ]);
            }
        }

        if ($this->command) {
            $this->command->info('✅ Permissions created for all modules');
        }
    }

    /**
     * Create the catalog's roles with their hierarchy levels. Retired roles (Admin, Project Manager,
     * Senior Employee, Contractor, Intern) are NOT recreated, and neither is the old name of a renamed one.
     */
    private function createRoles(): void
    {
        foreach (RoleCatalog::definitions() as $name => $definition) {
            Role::firstOrCreate(['name' => $name, 'guard_name' => 'web'], [
                'description' => $definition['description'],
                'hierarchy_level' => $definition['level'],
                'is_system_role' => $definition['system'],
            ]);
        }

        if ($this->command) {
            $this->command->info('✅ Roles created with hierarchy levels');
        }
    }

    /**
     * Every role gets its EXACT permission set from the one RoleCatalog definition (docs/audit/ROLE_CATALOG_2026-10-03.md,
     * section 4.2): synced, never additive, no LIKE wildcards - so re-seeding repairs drift instead of adding to it.
     */
    private function assignPermissionsToRoles(): void
    {
        RoleCatalog::seed();

        // Finance roles (not seeded everywhere - grant only where they exist)
        Role::whereIn('name', ['Finance Manager', 'Accountant'])->where('guard_name', 'web')->get()
            ->each->givePermissionTo(['petty-cash.view-all', 'petty-cash.approve', 'petty-cash.manage']);

        // DWC and Quality Contributor are DEPARTMENT default roles: Quality Control employees receive them
        // automatically (departments.default_roles). Only seeds an unconfigured Quality Control; never
        // overwrites what a Super Administrator set.
        DB::table('departments')
            ->where('name', 'Quality Control')->whereNull('default_roles')
            ->update(['default_roles' => json_encode(['Daily Works Contributor', 'Quality Contributor'])]);

        if ($this->command) {
            $this->command->info('✅ Permissions assigned to all roles');
        }
    }

    /**
     * The field-reporting permissions of the Daily Works Contributor role (mirrored by migration
     * 2026_10_01_000002_split_daily_works_contributor_from_employee).
     *
     * @return array<int, string>
     */
    public static function dailyWorksContributorPermissionNames(): array
    {
        return ['daily-works.view', 'daily-works.create', 'daily-works.export', 'tasks.view'];
    }

    /**
     * Department Admin = a DELEGATED department administrator (the Microsoft Entra administrative
     * unit / Google Workspace OU-admin model): dashboard, own self-service and full people
     * administration inside his department(s) — Employees (create, edit, placement, attendance
     * config, compensation, password reset, devices, delete/restore), Designations of his own
     * department, Attendance (+ roster / shift assignment inside it), Leave, Onboarding,
     * Offboarding and Asset Management — all confined by DepartmentScope to the holder's home
     * department (grants in user_department_scopes stay optional, for exceptions). The list is
     * EXACT, not derived from another role. Company-wide configuration stays global: never
     * departments, roles / access management (employees.access.manage), payroll / F&F, settings,
     * feature flags, company-wide attendance settings, projects, quality, O&M, camera or analytics.
     *
     * Mirrored verbatim by migrations 2026_09_30_000005_seed_department_scope_permissions_and_role
     * (the first 40) and 2026_10_01_000001_split_employee_permissions_and_extend_department_admin
     * (production does not re-run seeders) — tests/Feature/Access/DepartmentAdminRoleTest
     * fails if they drift apart.
     *
     * @return array<int, string>
     */
    public static function departmentAdminPermissionNames(): array
    {
        return [
            'department.admin',
            // Dashboard + the same self-service base every Employee gets (modules core + self-service).
            'core.dashboard.view', 'core.stats.view', 'core.updates.view',
            'attendance.own.view', 'attendance.own.punch',
            'leave.own.view', 'leave.own.create', 'leave.own.update', 'leave.own.delete',
            'communications.own.view',
            'profile.own.view', 'profile.own.update', 'profile.password.change',
            // Workforce -> Employees: the granular set, everything but access (roles) management.
            'employees.view', 'employees.create', 'employees.update',
            'employees.placement.update', 'employees.attendance-config.update',
            'employees.compensation.view', 'employees.compensation.update',
            'employees.password.reset', 'employees.devices.manage',
            'employees.delete', 'employees.restore',
            'users.create', 'users.update',
            // Designations of his own department (scoped by DepartmentScope).
            'designations.view', 'designations.create', 'designations.update', 'designations.delete',
            // Time/Attendance -> Attendances (+ roster / shift assignment / swaps), not settings.
            'attendance.view', 'attendance.create', 'attendance.update', 'attendance.correct', 'attendance.export',
            'attendance.roster.manage',
            // Leave Management.
            'leaves.view', 'leaves.create', 'leaves.update', 'leaves.approve', 'leaves.delete',
            // Lifecycle.
            'hr.onboarding.view', 'hr.onboarding.create', 'hr.onboarding.update', 'hr.onboarding.delete',
            'hr.offboarding.view', 'hr.offboarding.create', 'hr.offboarding.update', 'hr.offboarding.delete',
            'hr.assets.view', 'hr.assets.manage',
        ];
    }
}
