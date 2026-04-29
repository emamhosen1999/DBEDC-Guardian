import {
  HomeIcon,
  UserGroupIcon,
  CalendarDaysIcon,
  Cog6ToothIcon,
  CalendarIcon,
  ArrowRightOnRectangleIcon,
  EnvelopeIcon,
  DocumentTextIcon,
  BriefcaseIcon,
  UsersIcon,
  FolderIcon, // Changed from FolderOpenIcon
  ChartBarSquareIcon, // Changed from ChartBarIcon
  CreditCardIcon,
  ShoppingBagIcon,
  BuildingOffice2Icon,
  BanknotesIcon,
  WrenchScrewdriverIcon,
  ClipboardDocumentCheckIcon,
  DocumentDuplicateIcon,
  ShieldCheckIcon,
  ShieldExclamationIcon,
  ComputerDesktopIcon,
  PhoneIcon,
  UserIcon,
  ArchiveBoxIcon,
  AcademicCapIcon,
  TruckIcon,
  ShoppingCartIcon,
  TicketIcon,
  BeakerIcon,
  CubeIcon,
  ScaleIcon,
  BuildingStorefrontIcon,
  ArrowPathIcon,
  CurrencyDollarIcon,
  ClockIcon,
  DocumentTextIcon as DocumentTextIconLegacy, // Legacy document text icon
  ShoppingBagIcon as ShoppingBagIconLegacy, // Legacy shopping bag icon
  MapPinIcon,

} from '@heroicons/react/24/outline';

export const getPages = (roles, permissions, auth = null) => {
  
  // 1. Define the condition
  const isOnlyEmployee = roles?.length === 1 && roles[0] === 'Employee';
  console.log(roles, isOnlyEmployee? 'Only Employee': "Not only Employee");

  // 2. Define the shared items list (so we don't write it twice)
  const workspaceItems = [
    ...(permissions.includes('daily-works.view') ? [
      { name: 'Daily Work', icon: <DocumentTextIcon />, route: 'daily-works' },
      ...(!isOnlyEmployee ? [{ name: 'Work Summary', icon: <ChartBarSquareIcon />, route: 'daily-works-summary' }] : []),
      { name: 'Objections', icon: <ShieldExclamationIcon />, route: 'objections.index' },
    ] : []),
    
    ...(permissions.includes('attendance.own.view') ? [
      { name: 'Attendance', icon: <CalendarDaysIcon />, route: 'attendance-employee' }
    ] : []),
    ...(permissions.includes('leave.own.view') ? [
      { name: 'Leaves', icon: <ArrowRightOnRectangleIcon />, route: 'leaves-employee' }
    ] : []),
    ...(permissions.includes('communications.own.view') ? [
      { name: 'Communications', icon: <EnvelopeIcon />, route: 'emails' },
    ] : []),
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
        permissions.includes('hr.documents.view')) ? [{
      name: 'HR',
      icon: <UserGroupIcon className="" />,
      priority: 3,
      module: 'hrm',
      subMenu: [
        // Core Employee Management
        ...((permissions.includes('employees.view') || permissions.includes('departments.view') || permissions.includes('designations.view')) ? [{
          name: 'Employees',
          icon: <UserGroupIcon  />,
          category: 'core',
          subMenu: [
            ...(permissions.includes('employees.view') ? [{ name: 'Employee', icon: <UserGroupIcon  />, route: 'employees' }] : []),
            ...(permissions.includes('departments.view') ? [{ name: 'Departments', icon: <BuildingOffice2Icon  />, route: 'departments' }] : []),
            ...(permissions.includes('designations.view') ? [{ name: 'Designations', icon: <BriefcaseIcon  />, route: 'designations.index' }] : []),
            ...(permissions.includes('jurisdiction.view') ? [{ name: 'Work Locations', icon: <MapPinIcon />, route: 'jurisdiction' }] : []),
          ]
        }] : []),
        
        // Time & Attendance Management
        ...((permissions.includes('attendance.view') || permissions.includes('holidays.view') || permissions.includes('leaves.view') || permissions.includes('hr.timeoff.view')) ? [{
          name: 'Time',
          icon: <CalendarDaysIcon  />,
          category: 'time',
          subMenu: [
            ...(permissions.includes('attendance.view') ? [{ name: 'Attendance', icon: <CalendarDaysIcon  />, route: 'attendances' }] : []),
            ...(permissions.includes('attendance.view') ? [{ name: 'Timesheet', icon: <ClockIcon  />, route: 'timesheet' }] : []),
            ...(permissions.includes('hr.timeoff.view') ? [{ name: 'Time-off', icon: <CalendarIcon  />, route: 'hr.timeoff.index' }] : []),
            ...(permissions.includes('holidays.view') ? [{ name: 'Holidays', icon: <CalendarIcon  />, route: 'holidays' }] : []),
            ...(permissions.includes('leaves.view') ? [
              { name: 'Leaves', icon: <ArrowRightOnRectangleIcon  />, route: 'leaves' },
              { name: 'Analytics', icon: <ChartBarSquareIcon  />, route: 'leave-summary' },
              { name: 'Policies', icon: <Cog6ToothIcon  />, route: 'leave-settings' },
            ] : []),
          ]
        }] : []),
        
        // Employee Lifecycle Management
        ...((permissions.includes('hr.onboarding.view') || permissions.includes('hr.offboarding.view') || permissions.includes('hr.checklists.view') || permissions.includes('jobs.view')) ? [{
          name: 'Lifecycle',
          icon: <UserIcon  />,
          category: 'lifecycle',
          subMenu: [
            ...(permissions.includes('jobs.view') ? [{ name: 'Recruit', icon: <BriefcaseIcon  />, route: 'hr.recruitment.index' }] : []),
            ...(permissions.includes('hr.onboarding.view') ? [{ name: 'Onboard', icon: <UserIcon  />, route: 'hr.onboarding.index' }] : []),
            ...(permissions.includes('hr.offboarding.view') ? [{ name: 'Offboard', icon: <ArrowRightOnRectangleIcon  />, route: 'hr.offboarding.index' }] : []),
            ...(permissions.includes('hr.checklists.view') ? [{ name: 'Checklists', icon: <ClipboardDocumentCheckIcon  />, route: 'hr.checklists.index' }] : []),
          ]
        }] : []),
        
        // Performance & Development
        ...((permissions.includes('performance-reviews.view') || permissions.includes('training-sessions.view') || permissions.includes('hr.skills.view') || permissions.includes('hr.competencies.view')) ? [{
          name: 'Development',
          icon: <AcademicCapIcon  />,
          category: 'development',
          subMenu: [
            ...(permissions.includes('performance-reviews.view') ? [{ name: 'Reviews', icon: <ChartBarSquareIcon  />, route: 'hr.performance.index' }] : []),
            ...(permissions.includes('training-sessions.view') ? [{ name: 'Training', icon: <AcademicCapIcon  />, route: 'hr.training.index' }] : []),
            ...(permissions.includes('hr.skills.view') ? [{ name: 'Skills', icon: <AcademicCapIcon  />, route: 'hr.skills.index' }] : []),
            ...(permissions.includes('hr.competencies.view') ? [{ name: 'Competency', icon: <ScaleIcon  />, route: 'hr.competencies.index' }] : []),
          ]
        }] : []),
        
        // Benefits & Compensation
        ...(permissions.includes('hr.benefits.view') ? [{
          name: 'Benefits',
          icon: <CreditCardIcon  />,
          category: 'benefits',
          subMenu: [
            { name: 'Plans', icon: <CreditCardIcon  />, route: 'hr.benefits.index' },
          ]
        }] : []),
        
        // Workplace Safety & Compliance
        ...((permissions.includes('hr.safety.view') || permissions.includes('hr.safety.incidents.view') || permissions.includes('hr.safety.training.view')) ? [{
          name: 'Safety',
          icon: <ShieldCheckIcon  />,
          category: 'safety',
          subMenu: [
            ...(permissions.includes('hr.safety.view') ? [{ name: 'Overview', icon: <ShieldCheckIcon  />, route: 'hr.safety.index' }] : []),
            ...(permissions.includes('hr.safety.incidents.view') ? [{ name: 'Incidents', icon: <DocumentTextIcon  />, route: 'hr.safety.incidents.index' }] : []),
            ...(permissions.includes('hr.safety.training.view') ? [{ name: 'Training', icon: <AcademicCapIcon  />, route: 'hr.safety.training.index' }] : []),
          ]
        }] : []),
        
        // Document Management
        ...(permissions.includes('hr.documents.view') ? [{
          name: 'Documents',
          icon: <DocumentDuplicateIcon  />,
          category: 'documents',
          subMenu: [
            { name: 'Files', icon: <DocumentDuplicateIcon  />, route: 'hr.documents.index' },
            { name: 'Categories', icon: <FolderIcon  />, route: 'hr.documents.categories.index' },
          ]
        }] : []),
        
      
        
        // HR Analytics & Reporting
        ...(permissions.includes('hr.analytics.view') ? [{
          name: 'Analytics',
          icon: <ChartBarSquareIcon  />,
          category: 'analytics',
          subMenu: [
            { name: 'Overview', icon: <ChartBarSquareIcon  />, route: 'hr.analytics.index' },
            { name: 'Attendance', icon: <CalendarDaysIcon  />, route: 'hr.analytics.attendance' },
            { name: 'Performance', icon: <ChartBarSquareIcon  />, route: 'hr.analytics.performance' },
            { name: 'Recruitment', icon: <UserGroupIcon  />, route: 'hr.analytics.recruitment' },
            { name: 'Turnover', icon: <ArrowRightOnRectangleIcon  />, route: 'hr.analytics.turnover' },
          ]
        }] : []),
        
        // Payroll Management
        ...(permissions.includes('hr.payroll.view') ? [{
          name: 'Payroll',
          icon: <CurrencyDollarIcon  />,
          category: 'payroll',
          subMenu: [
            { name: 'Overview', icon: <HomeIcon  />, route: 'hr.payroll.index' },
            { name: 'Generate', icon: <DocumentTextIcon  />, route: 'hr.payroll.create' },
            { name: 'Payslips', icon: <DocumentDuplicateIcon  />, route: 'hr.selfservice.payslips' },
            { name: 'Reports', icon: <ChartBarSquareIcon  />, route: 'hr.payroll.reports' },
          ]
        }] : []),
      ]
    }] : []),

    // 5. DMS (Document Management System)
    ...(permissions.includes('dms.view') ? [{
      name: 'Documents',
      icon: <FolderIcon className="" />,
      priority: 6,
      module: 'dms',
      subMenu: [
        { name: 'Overview', icon: <HomeIcon  />, route: 'dms.index' },
        { name: 'Files', icon: <DocumentTextIcon  />, route: 'dms.documents' },
        { name: 'Upload', icon: <DocumentDuplicateIcon  />, route: 'dms.documents.create' },
        { name: 'Categories', icon: <FolderIcon  />, route: 'dms.categories' },
        { name: 'Shared', icon: <UserGroupIcon  />, route: 'dms.shared' },
        { name: 'Analytics', icon: <ChartBarSquareIcon  />, route: 'dms.analytics' },
        // Legacy document routes
        ...(permissions.includes('letters.view') ? [
          { name: 'Letters', icon: <EnvelopeIcon  />, route: 'letters' },
        ] : []),
      ]
    }] : []),
    // 6. Compliance
    ...(permissions.includes('compliance.view') ? [{
      name: 'Compliance',
      icon: <ShieldCheckIcon className="" />,
      priority: 6,
      module: 'compliance',
      subMenu: [
        ...(permissions.includes('compliance.dashboard.view') ? [{ name: 'Overview', icon: <ChartBarSquareIcon  />, route: 'compliance.dashboard' }] : []),
        ...(permissions.includes('compliance.policies.view') ? [{ name: 'Policies', icon: <DocumentTextIcon  />, route: 'compliance.policies.index' }] : []),
        ...(permissions.includes('compliance.regulatory_requirements.view') ? [{ name: 'Regulatory', icon: <ScaleIcon  />, route: 'compliance.regulatory-requirements.index' }] : []),
        ...(permissions.includes('compliance.risks.view') ? [{ name: 'Risks', icon: <ShieldCheckIcon  />, route: 'compliance.risks.index' }] : []),
        ...(permissions.includes('compliance.audits.view') ? [{ name: 'Audits', icon: <ClipboardDocumentCheckIcon  />, route: 'compliance.audits.index' }] : []),
        ...(permissions.includes('compliance.training_records.view') ? [{ name: 'Training', icon: <AcademicCapIcon  />, route: 'compliance.training-records.index' }] : []),
        ...(permissions.includes('compliance.controlled_documents.view') ? [{ name: 'Controlled', icon: <DocumentDuplicateIcon  />, route: 'compliance.controlled-documents.index' }] : []),
      ]
    }] : []),
    // 7. Quality Management
    ...(permissions.includes('quality.view') ? [{
      name: 'Quality',
      icon: <BeakerIcon className="" />,
      priority: 7,
      module: 'quality',
      subMenu: [
        ...(permissions.includes('quality.inspections.view') ? [{ name: 'Inspections', icon: <ClipboardDocumentCheckIcon  />, route: 'quality.inspections.index' }] : []),
        ...(permissions.includes('quality.ncr.view') ? [{ name: 'NCRs', icon: <DocumentTextIcon  />, route: 'quality.ncrs.index' }] : []),
        ...(permissions.includes('quality.calibrations.view') ? [{ name: 'Calibrations', icon: <ScaleIcon  />, route: 'quality.calibrations.index' }] : []),
        ...(permissions.includes('quality.dashboard.view') ? [{ name: 'Analytics', icon: <ChartBarSquareIcon  />, route: 'quality.dashboard' }] : []),
      ]
    }] : []),
    // 8. Admin (System Administration)
    ...(permissions.includes('users.view') || permissions.includes('settings.view') || permissions.includes('roles.view') || permissions.includes('modules.view') ? [{
      name: 'Admin',
      icon: <Cog6ToothIcon className="" />,
      priority: 8,
      module: 'admin',
      subMenu: [
        ...(permissions.includes('users.view') ? [{ name: 'Users', icon: <UsersIcon  />, route: 'users' }] : []),
        ...(permissions.includes('roles.view') ? [
          { name: 'Roles', icon: <UserGroupIcon  />, route: 'admin.roles-management' }
        ] : []),
        ...(permissions.includes('modules.view') ? [
          { name: 'Modules', icon: <CubeIcon  />, route: 'modules.index' }
        ] : []),
        ...(auth?.user && auth?.roles?.includes('Super Administrator') ? [
          { name: 'Monitoring', icon: <ComputerDesktopIcon  />, route: 'admin.system-monitoring' }
        ] : []),
        ...(permissions.includes('settings.view') ? [{ name: 'Settings', icon: <Cog6ToothIcon  />, route: 'admin.settings.company' }] : []),
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
