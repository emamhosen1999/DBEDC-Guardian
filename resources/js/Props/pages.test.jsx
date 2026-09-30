import { describe, it, expect } from 'vitest';
import { getPages, getPagesByModule, getPagesByPriority, getNavigationPath } from './pages.jsx';

describe('pages.jsx utility module', () => {
  describe('getPages', () => {
    it('returns Petty Cash as the sole top-level item when roles/permissions are empty', () => {
      const pages = getPages([], []);
      // Workspace items are spread at top level (no "Workspace" wrapper); Petty Cash is always available.
      expect(pages).toHaveLength(1);
      expect(pages[0].name).toBe('Petty Cash');
      expect(pages[0].route).toBe('petty-cash.index');
    });

    it('returns workspace items directly for single Employee role (no submenu wrapping)', () => {
      const roles = ['Employee'];
      const permissions = [
        'daily-works.view',
        'attendance.own.view',
        'leave.own.view'
      ];
      
      const pages = getPages(roles, permissions);
      
      // Employee Dashboard + Petty Cash + 3 permitted workspace items, all top level
      expect(pages).toHaveLength(5);
      expect(pages.map(p => p.name)).toContain('Employee Dashboard');
      expect(pages.map(p => p.name)).toContain('Daily Works');
      expect(pages.map(p => p.name)).toContain('My Attendance');
      expect(pages.map(p => p.name)).toContain('My Leaves');
      expect(pages.map(p => p.name)).toContain('Petty Cash');
      
      // None of them should be grouped under a "Workspace" dropdown item
      expect(pages.find(p => p.name === 'Workspace')).toBeUndefined();
    });

    it('spreads workspace items at top level (no Workspace wrapper) regardless of role mix', () => {
      const roles = ['Employee', 'Super Administrator'];
      const permissions = [
        'daily-works.view',
        'attendance.own.view',
        'leave.own.view'
      ];

      const pages = getPages(roles, permissions);

      // Workspace items are no longer wrapped in a "Workspace" folder — they are top-level.
      expect(pages.find(p => p.name === 'Workspace')).toBeUndefined();
      const names = pages.map(p => p.name);
      expect(names).toContain('Daily Works');
      expect(names).toContain('My Attendance');
      expect(names).toContain('My Leaves');
      expect(names).toContain('Petty Cash');
    });

    it('shows Workforce navigation options with subMenu groups for HR Managers', () => {
      const roles = ['HR Manager'];
      // HR Manager permissions
      const permissions = [
        'employees.view',
        'attendance.view',
        'holidays.view',
        'leaves.view'
      ];

      const pages = getPages(roles, permissions);
      
      // Workforce menu should be present
      const workforceMenu = pages.find(p => p.name === 'Workforce');
      expect(workforceMenu).toBeDefined();
      expect(workforceMenu.subMenu).toBeDefined();

      // Employee management link (formerly "Organization")
      const empItem = workforceMenu.subMenu.find(i => i.name === 'Employees');
      expect(empItem).toBeDefined();
      expect(empItem.route).toBe('employees');

      // Check for Time/Attendance submenu folder
      const timeMenu = workforceMenu.subMenu.find(i => i.name === 'Time/Attendance');
      expect(timeMenu).toBeDefined();
      expect(timeMenu.subMenu).toBeDefined();
      expect(timeMenu.subMenu.map(i => i.name)).toContain('Attendances');
      expect(timeMenu.subMenu.map(i => i.name)).toContain('Holidays');
      expect(timeMenu.subMenu.map(i => i.name)).toContain('Leave Management');
    });

    it('renders Admin navigation options according to admin permissions', () => {
      const roles = ['Administrator'];
      const permissions = [
        'users.view',
        'company.settings',
        'request_logs.view'
      ];

      const pages = getPages(roles, permissions);

      const adminMenu = pages.find(p => p.name === 'Admin');
      expect(adminMenu).toBeDefined();
      expect(adminMenu.subMenu).toBeDefined();
      // users.view also opens the fleet-wide admin surfaces
      expect(adminMenu.subMenu.map(i => i.name)).toEqual([
        'Company Details',
        'Request Logs',
        'Device Sessions',
        'Feature Flags',
        'Client Diagnostics',
      ]);
      // ...but never the Super Administrator-only monitoring page
      expect(adminMenu.subMenu.map(i => i.name)).not.toContain('Monitoring');
    });

    it('shows Monitoring only under Admin menu for Super Administrators', () => {
      const permissions = [];
      const roles = ['Super Administrator'];
      const auth = {
        user: { id: 1, name: 'Super Admin' },
        roles: ['Super Administrator']
      };

      // When the user has Super Admin role but other permissions are false
      const adminPages = getPages(roles, permissions, auth);
      const adminMenu = adminPages.find(p => p.name === 'Admin');
      expect(adminMenu).toBeDefined();
      expect(adminMenu.subMenu).toBeDefined();
      const monitoring = adminMenu.subMenu.find(i => i.name === 'Monitoring');
      expect(monitoring).toBeDefined();
      expect(monitoring.route).toBe('admin.system-monitoring');
    });
  });

  describe('Department Admin navigation', () => {
    // Mirrors ComprehensiveRolePermissionSeeder::departmentAdminPermissionNames() and migration
    // 2026_09_30_000005 (tests/Feature/Access/DepartmentAdminRoleTest pins the PHP side).
    const DEPARTMENT_ADMIN_PERMISSIONS = [
      'department.admin',
      'core.dashboard.view', 'core.stats.view', 'core.updates.view',
      'attendance.own.view', 'attendance.own.punch',
      'leave.own.view', 'leave.own.create', 'leave.own.update', 'leave.own.delete',
      'communications.own.view',
      'profile.own.view', 'profile.own.update', 'profile.password.change',
      'employees.view', 'employees.create', 'employees.update',
      'users.create', 'users.update',
      'attendance.view', 'attendance.create', 'attendance.update', 'attendance.correct', 'attendance.export',
      'attendance.roster.manage',
      'leaves.view', 'leaves.create', 'leaves.update', 'leaves.approve', 'leaves.delete',
      'hr.onboarding.view', 'hr.onboarding.create', 'hr.onboarding.update', 'hr.onboarding.delete',
      'hr.offboarding.view', 'hr.offboarding.create', 'hr.offboarding.update', 'hr.offboarding.delete',
      'hr.assets.view', 'hr.assets.manage',
    ];

    /** Render the menu as an indented list of names. */
    const outline = (items, depth = 0) => items.flatMap((item) => [
      `${'  '.repeat(depth)}${item.name}`,
      ...(item.subMenu ? outline(item.subMenu, depth + 1) : []),
    ]);

    it('shows exactly Dashboard, own self-service and the HR modules — nothing else', () => {
      const pages = getPages(['Department Admin'], DEPARTMENT_ADMIN_PERMISSIONS, null, { hr_payroll: true });

      expect(outline(pages)).toEqual([
        'Dashboard',
        'My Attendance',
        'My Leaves',
        'Petty Cash',
        'Workforce',
        '  Employees',
        '  Time/Attendance',
        '    Attendances',
        '    Leave Management',
        '  Onboarding',
        '  Offboarding',
        '  Asset Management',
      ]);
    });

    it('never reaches payroll, holidays, settings, admin or O&M even with the payroll flag on', () => {
      const names = outline(getPages(['Department Admin'], DEPARTMENT_ADMIN_PERMISSIONS, null, { hr_payroll: true }));

      ['Payroll', 'Holidays', 'Daily Works', 'Admin', 'Company Details', 'Device Sessions', 'Feature Flags',
        'Client Diagnostics', 'Operations & Maintenance', 'Monitoring'].forEach((forbidden) => {
        expect(names.map((n) => n.trim())).not.toContain(forbidden);
      });
    });

    it('drops a group whose children are all hidden (a stale parent gate cannot show an empty shell)', () => {
      // hr.skills.view / hr.safety.view used to open an empty Workforce group.
      const pages = getPages(['Custom'], ['hr.skills.view', 'hr.safety.view', 'hr.analytics.view', 'hr.benefits.view']);

      expect(pages.map((p) => p.name)).toEqual(['Petty Cash']);
    });
  });

  describe('getPagesByModule', () => {
    it('groups pages by their module property', () => {
      const permissions = ['core.dashboard.view', 'users.view'];
      // We pass Super Administrator to ensure Admin menu is loaded
      const modules = getPagesByModule(['Super Administrator'], permissions);
      
      // Core module should contain Dashboard
      expect(modules.core).toBeDefined();
      expect(modules.core.map(p => p.name)).toContain('Dashboard');

      // Admin module should contain Admin (which houses users.view subMenu)
      expect(modules.admin).toBeDefined();
      expect(modules.admin.map(p => p.name)).toContain('Admin');
    });
  });

  describe('getPagesByPriority', () => {
    it('sorts pages by priority ascending (unprioritized items sort last)', () => {
      const permissions = ['users.view', 'core.dashboard.view'];
      // Dashboard has priority 1, Admin has priority 8, Petty Cash has no priority (→ 999)
      const sorted = getPagesByPriority(['Super Administrator'], permissions);

      expect(sorted[0].name).toBe('Dashboard');
      // Admin (priority 8) sorts ahead of the unprioritized Petty Cash
      const adminIdx = sorted.findIndex(p => p.name === 'Admin');
      const pettyIdx = sorted.findIndex(p => p.name === 'Petty Cash');
      expect(adminIdx).toBeGreaterThanOrEqual(0);
      expect(adminIdx).toBeLessThan(pettyIdx);
    });
  });

  describe('getNavigationPath', () => {
    it('finds top-level pages successfully', () => {
      const permissions = ['core.dashboard.view'];
      const path = getNavigationPath('dashboard', [], permissions);
      
      expect(path).toHaveLength(1);
      expect(path[0].name).toBe('Dashboard');
    });

    it('finds deep subMenu items successfully and builds correct hierarchy path', () => {
      const permissions = ['attendance.view', 'holidays.view', 'leaves.view'];
      // We pass the role HR Manager so it's not Only Employee
      const path = getNavigationPath('leaves.index', ['HR Manager'], permissions);
      
      expect(path).toHaveLength(3);
      expect(path[0].name).toBe('Workforce');
      expect(path[1].name).toBe('Time/Attendance');
      expect(path[2].name).toBe('Leave Management');
    });

    it('returns empty array if page is not found', () => {
      const path = getNavigationPath('non-existent-route', [], []);
      expect(path).toEqual([]);
    });
  });
});
