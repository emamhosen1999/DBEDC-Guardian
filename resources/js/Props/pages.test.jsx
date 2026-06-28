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
      
      // Should show Petty Cash + 3 permitted workspace items directly
      expect(pages).toHaveLength(4);
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
      expect(adminMenu.subMenu).toHaveLength(2);
      expect(adminMenu.subMenu.map(i => i.name)).toContain('Company Details');
      expect(adminMenu.subMenu.map(i => i.name)).toContain('Request Logs');
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
      expect(adminMenu.subMenu).toHaveLength(1);
      expect(adminMenu.subMenu[0].name).toBe('Monitoring');
      expect(adminMenu.subMenu[0].route).toBe('admin.system-monitoring');
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
