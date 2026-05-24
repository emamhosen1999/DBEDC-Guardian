import {
  ArrowRightOnRectangleIcon,
  BeakerIcon,
  BriefcaseIcon,
  BuildingOffice2Icon,
  BuildingOfficeIcon,
  CalendarDaysIcon,
  CalendarIcon,
  ChartBarSquareIcon,
  ClipboardDocumentCheckIcon,
  ClockIcon,
  Cog6ToothIcon,
  ComputerDesktopIcon,
  CubeIcon,
  DocumentDuplicateIcon,
  DocumentTextIcon,
  FolderIcon,
  HandThumbUpIcon,
  HomeIcon,
  KeyIcon,
  MapPinIcon,
  PhoneIcon,
  ScaleIcon,
  UserGroupIcon,
  UsersIcon,
  CurrencyDollarIcon
} from '@heroicons/react/24/outline';

export const getPages = (roles, permissions, auth = null) => {
  
  // 1. Define the condition
  const isOnlyEmployee = roles?.length === 1 && roles[0] === 'Employee';

  // 2. Define the shared items list (so we don't write it twice)
  const workspaceItems = [
    ...(permissions.includes('daily-works.view') ? [
      { name: 'Daily Works', icon: <DocumentTextIcon />, route: 'daily-works-unified' },

    ] : []),

    ...(permissions.includes('attendance.own.view') ? [
      { name: 'My Attendance', icon: <CalendarDaysIcon />, route: 'attendance.unified' }
    ] : []),
    ...(permissions.includes('leave.own.view') ? [
      { name: 'My Leaves', icon: <ArrowRightOnRectangleIcon />, route: 'leaves-employee' }
    ] : []),
    { name: 'Petty Cash', icon: <CurrencyDollarIcon />, route: 'petty-cash.index' },

  ];

  return [
    // 1. Dashboard (ISO 9000 - Information Management)
    ...(permissions.includes('core.dashboard.view') ? [{
      name: 'Dashboard',
      icon: <HomeIcon className="" />, 
      route: 'dashboard',
      priority: 1,
      module: 'core'
    }] : []),

    // 2. Workspace (Self-Service)
    ...(workspaceItems.length > 0 ? (
        isOnlyEmployee 
          ? workspaceItems 
          : [{
              name: 'Workspace',
              icon: <UserGroupIcon className="" />,
              priority: 2,
              module: 'self-service',
              subMenu: workspaceItems
            }]
      ) : []),

    // 3. HR (Human Resources) - Reorganized with submodule groups
    ...((permissions.includes('employees.view') || 
        permissions.includes('hr.onboarding.view') || 
        permissions.includes('hr.skills.view') || 
        permissions.includes('hr.benefits.view') || 
        permissions.includes('hr.safety.view') || 
        permissions.includes('hr.analytics.view') ||
        permissions.includes('departments.view') ||
        permissions.includes('designations.view') ||
        permissions.includes('attendance.view') ||
        permissions.includes('holidays.view') ||
        permissions.includes('leaves.view')) ? [{
      name: 'Workforce',
      icon: <UserGroupIcon className="" />,
      priority: 3,
      module: 'hrm',
      subMenu: [
        // Core Employee Management
        ...((permissions.includes('employees.view') || permissions.includes('departments.view') || permissions.includes('designations.view')) ? [{
          name: 'Organization',
          icon: <UserGroupIcon  />,
          category: 'core',
          route: 'organization.index'
        }] : []),
        
        // Time & Attendance Management
        ...((permissions.includes('attendance.view') || permissions.includes('holidays.view') || permissions.includes('leaves.view') || permissions.includes('hr.timeoff.view')) ? [{
          name: 'Time/Attendance',
          icon: <CalendarDaysIcon  />,
          category: 'time',
          subMenu: [
            ...(permissions.includes('attendance.view') ? [{ name: 'Attendances', icon: <ClockIcon  />, route: 'attendance.unified' }] : []),
            ...(permissions.includes('holidays.view') ? [{ name: 'Holidays', icon: <CalendarIcon  />, route: 'holidays' }] : []),
            ...(permissions.includes('leaves.view') ? [
              { name: 'Leave Management', icon: <ArrowRightOnRectangleIcon  />, route: 'leaves.index' },

            ] : []),
          ]
        }] : []),
        
        /* HR Documents — enable when Inertia pages + HrDocumentController exist */
      ]
    }] : []),

    /* Quality module — enable when resources/js/Pages/Quality/* exist */

    // 8. Admin & Settings (System Administration)
    ...((permissions.includes('users.view') || permissions.includes('settings.view') || permissions.includes('roles.view') || permissions.includes('modules.view') || permissions.includes('company.settings') || permissions.includes('attendance.settings') || permissions.includes('leave-settings.view')) ? [{
      name: 'Admin',
      icon: <Cog6ToothIcon className="" />,
      priority: 8,
      module: 'admin',
      subMenu: [
         
          ...(permissions.includes('users.view') ? [{ 
              name: 'Users/Roles', 
              icon: <UsersIcon />, 
              route: 'users',
              description: 'Manage system users and access credentials'
          }] : []),
         
          ...(permissions.includes('company.settings') ? [{
            name: 'Company Details', 
            icon: <BuildingOfficeIcon className="w-5 h-5" />, 
            route: 'admin.settings.company',
            priority: 2,
            description: 'Configure organizational structure, company information, and brand assets'
          }] : []),
         
       
          ...(permissions.includes('request_logs.view') ? [{
            name: 'Request Logs',
            icon: <DocumentTextIcon />,
            route: 'request-logs.index',
            description: 'View and manage all HTTP request logs'
          }] : []),
          ...(auth?.user && auth?.roles?.includes('Super Administrator') ? [{
            name: 'Monitoring',
            icon: <ComputerDesktopIcon />,
            route: 'admin.system-monitoring',
            description: 'View system health and analytics logs'
          }] : []),
      ]
    }] : []),
  ];
}

// Utility functions for navigation management

// Get pages by module for better organization
export const getPagesByModule = (permissions) => {
  const pages = getPages(permissions);
  const modules = {};
  
  pages.forEach(page => {
    const module = page.module || 'core';
    if (!modules[module]) {
      modules[module] = [];
    }
    modules[module].push(page);
  });
  
  return modules;
};

// Get pages sorted by priority
export const getPagesByPriority = (permissions) => {
  return getPages(permissions).sort((a, b) => (a.priority || 999) - (b.priority || 999));
};

// Get navigation breadcrumb path
export const getNavigationPath = (currentRoute, permissions) => {
  const pages = getPages(permissions);
  const path = [];
  // Find the current page in the navigation structure
  const findPageInMenu = (menuItems, targetRoute, currentPath = []) => {
    for (const item of menuItems) {
      const newPath = [...currentPath, item];
      if (item.route === targetRoute) {
        return newPath;
      }
      if (item.subMenu) {
        const result = findPageInMenu(item.subMenu, targetRoute, newPath);
        if (result) return result;
      }
    }
    return null;
  };
  return findPageInMenu(pages, currentRoute) || [];
};
