import {
  AcademicCapIcon,
  ArrowRightOnRectangleIcon,
  BriefcaseIcon,
  BuildingOffice2Icon,
  CalendarDaysIcon,
  CalendarIcon,
  ChartBarSquareIcon,
  ClipboardDocumentCheckIcon,
  ClockIcon,
  Cog6ToothIcon,
  ComputerDesktopIcon,
  CreditCardIcon,
  CubeIcon,
  CurrencyDollarIcon,
  DocumentDuplicateIcon,
  DocumentTextIcon,
  EnvelopeIcon,
  FolderIcon,
  HomeIcon,
  MapPinIcon,
  ScaleIcon,
  ShieldCheckIcon,
  ShieldExclamationIcon,
  UserGroupIcon,
  UserIcon,
  UsersIcon
} from '@heroicons/react/24/outline';

export const getPages = (roles, permissions, auth = null) => {
  
  const isOnlyEmployee = roles?.length === 1 && roles[0] === 'Employee';

  const workspaceItems = [
    ...(permissions.includes('daily-works.view') ? [
      { name: 'Daily Work', icon: <DocumentTextIcon />, route: 'daily-works' },
      { name: 'Work Summary', icon: <ChartBarSquareIcon />, route: 'daily-works-summary' },
      { name: 'Objections', icon: <ShieldExclamationIcon />, route: 'objections.index' },
    ] : []),
    
    ...(permissions.includes('attendance.own.view') ? [
      { name: 'Attendance', icon: <CalendarDaysIcon />, route: 'attendance-employee' }
    ] : []),
    ...(permissions.includes('leave.own.view') ? [
      { name: 'Leaves', icon: <ArrowRightOnRectangleIcon />, route: 'leaves-employee' }
    ] : []),
  ];

  return [
    // 1. Dashboard
    ...(permissions.includes('core.dashboard.view') ? [{
      name: 'Dashboard',
      icon: <HomeIcon />, 
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
              icon: <UserGroupIcon />,
              priority: 2,
              module: 'self-service',
              subMenu: workspaceItems
            }]
      ) : []),

    // 3. Workforce / Employees (Formerly inside HR)
    ...((permissions.includes('employees.view') || permissions.includes('departments.view') || permissions.includes('designations.view')) ? [{
      name: 'Workforce',
      icon: <UserGroupIcon />,
      priority: 3,
      module: 'hrm',
      subMenu: [
        ...(permissions.includes('employees.view') ? [{ name: 'Directory', icon: <UserGroupIcon />, route: 'employees' }] : []),
        ...(permissions.includes('departments.view') ? [{ name: 'Departments', icon: <BuildingOffice2Icon />, route: 'departments' }] : []),
        ...(permissions.includes('designations.view') ? [{ name: 'Designations', icon: <BriefcaseIcon />, route: 'designations.index' }] : []),
        ...(permissions.includes('jurisdiction.view') ? [{ name: 'Work Locations', icon: <MapPinIcon />, route: 'jurisdiction' }] : []),
      ]
    }] : []),

    // 4. Time & Attendance (Formerly inside HR)
    ...((permissions.includes('attendance.view') || permissions.includes('holidays.view') || permissions.includes('leaves.view') || permissions.includes('hr.timeoff.view')) ? [{
      name: 'Time',
      icon: <CalendarDaysIcon />,
      priority: 4,
      module: 'hrm',
      subMenu: [
        ...(permissions.includes('attendance.view') ? [{ name: 'Attendance', icon: <CalendarDaysIcon />, route: 'attendances' }] : []),
        ...(permissions.includes('attendance.view') ? [{ name: 'Timesheet', icon: <ClockIcon />, route: 'timesheet' }] : []),
        ...(permissions.includes('hr.timeoff.view') ? [{ name: 'Time-off', icon: <CalendarIcon />, route: 'hr.timeoff.index' }] : []),
        ...(permissions.includes('holidays.view') ? [{ name: 'Holidays', icon: <CalendarIcon />, route: 'holidays' }] : []),
        ...(permissions.includes('leaves.view') ? [
          { name: 'Leaves', icon: <ArrowRightOnRectangleIcon />, route: 'leaves' },
          { name: 'Analytics', icon: <ChartBarSquareIcon />, route: 'leave-summary' },
          { name: 'Policies', icon: <Cog6ToothIcon />, route: 'leave-settings' },
        ] : []),
      ]
    }] : []),

    // 8. Admin (System Administration)
    ...(permissions.includes('users.view') || permissions.includes('settings.view') || permissions.includes('roles.view') || permissions.includes('modules.view') ? [{
      name: 'Admin',
      icon: <Cog6ToothIcon />,
      priority: 8,
      module: 'admin',
      subMenu: [
        ...(permissions.includes('users.view') ? [{ name: 'Users', icon: <UsersIcon />, route: 'users' }] : []),
        ...(permissions.includes('roles.view') ? [
          { name: 'Roles', icon: <UserGroupIcon />, route: 'admin.roles-management' }
        ] : []),
        ...(permissions.includes('modules.view') ? [
          { name: 'Modules', icon: <CubeIcon />, route: 'modules.index' }
        ] : []),
        ...(auth?.user && auth?.roles?.includes('Super Administrator') ? [
          { name: 'Monitoring', icon: <ComputerDesktopIcon />, route: 'admin.system-monitoring' }
        ] : []),
        ...(permissions.includes('settings.view') ? [{ name: 'Settings', icon: <Cog6ToothIcon />, route: 'admin.settings.company' }] : []),
      ]
    }] : []),
  ];
}