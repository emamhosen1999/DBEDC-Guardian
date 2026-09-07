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
  ExclamationTriangleIcon,
  FolderIcon,
  HandThumbUpIcon,
  HomeIcon,
  KeyIcon,
  MapPinIcon,
  PhoneIcon,
  ScaleIcon,
  UserGroupIcon,
  UsersIcon,
  CurrencyDollarIcon,
  WrenchScrewdriverIcon,
  ShieldCheckIcon,
  TruckIcon,
  CpuChipIcon,
  ArchiveBoxIcon,
  ChatBubbleLeftRightIcon,
  CloudIcon,
  DocumentMagnifyingGlassIcon,
  ArrowTrendingUpIcon,
  PresentationChartLineIcon,
  CalculatorIcon,
  DocumentChartBarIcon,
  AdjustmentsHorizontalIcon
} from '@heroicons/react/24/outline';

export const getPages = (roles, permissions, auth = null) => {
  // Super Administrator bypass helper: guarantees Super Admin receives 100% access across all modules
  const isSuperAdmin = Boolean(
    auth?.isSuperAdmin ||
    auth?.roles?.includes('Super Administrator') ||
    roles?.includes('Super Administrator')
  );
  const can = (permission) => isSuperAdmin || permissions?.includes(permission);
  const canAny = (perms) => isSuperAdmin || perms.some((p) => permissions?.includes(p));

  // 1. Define the condition
  const isOnlyEmployee = !isSuperAdmin && roles?.length === 1 && roles[0] === 'Employee';
  const hasEmployeeRole = roles?.includes('Employee');

  // 2. Define the shared items list (so we don't write it twice)
  const workspaceItems = [
    ...(can('daily-works.view') ? [
      { name: 'Daily Works', icon: <DocumentTextIcon />, route: 'daily-works-unified' },

    ] : []),

    ...(can('attendance.own.view') ? [
      { name: 'My Attendance', icon: <CalendarDaysIcon />, route: 'attendance-employee' }
    ] : []),
    ...(can('leave.own.view') ? [
      { name: 'My Leaves', icon: <ArrowRightOnRectangleIcon />, route: 'leaves-employee' }
    ] : []),
    { name: 'Petty Cash', icon: <CurrencyDollarIcon />, route: 'petty-cash.index' },

  ];

  return [
    // 1. Dashboard (ISO 9000 - Information Management)
    ...(isOnlyEmployee ? [{
      name: 'Employee Dashboard',
      icon: <HomeIcon className="" />, 
      route: 'employee-dashboard',
      priority: 1,
      module: 'core'
    }] : can('core.dashboard.view') ? [{
      name: 'Dashboard',
      icon: <HomeIcon className="" />, 
      route: 'dashboard',
      priority: 1,
      module: 'core'
    }] : []),

    // 1b. Self-Service Dashboard for mixed roles (e.g. Admin + Employee)
    ...(!isOnlyEmployee && hasEmployeeRole ? [{
      name: 'Employee Dashboard',
      icon: <HomeIcon className="" />, 
      route: 'employee-dashboard',
      priority: 2,
      module: 'core'
    }] : []),

    // 2. Workspace (Self-Service)
    ...(workspaceItems.length > 0 ? (
        workspaceItems
      ) : []),

    // 3. HR (Human Resources) - Reorganized with submodule groups
    ...(canAny([
        'employees.view',
        'hr.onboarding.view',
        'hr.skills.view',
        'hr.benefits.view',
        'hr.safety.view',
        'hr.analytics.view',
        'departments.view',
        'designations.view',
        'attendance.view',
        'holidays.view',
        'leaves.view'
      ]) ? [{
      name: 'Workforce',
      icon: <UserGroupIcon className="" />,
      priority: 3,
      module: 'hrm',
      subMenu: [
        // Core Employee Management
        ...(canAny(['employees.view', 'departments.view', 'designations.view']) ? [{
          name: 'Employees',
          icon: <UserGroupIcon  />,
          category: 'core',
          route: 'employees'
        }] : []),
        
        // Time & Attendance Management
        ...(canAny(['attendance.view', 'holidays.view', 'leaves.view', 'hr.timeoff.view']) ? [{
          name: 'Time/Attendance',
          icon: <CalendarDaysIcon  />,
          category: 'time',
          subMenu: [
            ...(can('attendance.view') ? [{ name: 'Attendances', icon: <ClockIcon  />, route: 'attendance.unified' }] : []),
            ...(can('holidays.view') ? [{ name: 'Holidays', icon: <CalendarIcon  />, route: 'holidays' }] : []),
            ...(can('leaves.view') ? [
              { name: 'Leave Management', icon: <ArrowRightOnRectangleIcon  />, route: 'leaves.index' },

            ] : []),
          ]
        }] : []),
        
        /* HR Documents — enable when Inertia pages + HrDocumentController exist */
      ]
    }] : []),

    // Operations & Maintenance (O&M) & Traffic Monitoring Center (TMC)
    ...(canAny([
        'om.dashboard.view',
        'om.traffic.view',
        'om.incidents.view',
        'om.maintenance.view',
        'om.equipment.view',
        'om.safety.view',
        'om.contractors.view',
        'om.tppd.view',
        'om.research.view',
        'om.sla.view',
        'om.analytics.view'
      ]) ? [{
      name: 'Operations & Maintenance',
      icon: <WrenchScrewdriverIcon className="" />,
      priority: 4,
      module: 'om',
      subMenu: [
        // 1. Routine & Maintenance
        ...(canAny(['om.maintenance.view', 'om.pm.manage', 'quality.inspections.view']) ? [{
          name: 'Routine & Maintenance',
          icon: <WrenchScrewdriverIcon />,
          category: 'maintenance',
          subMenu: [
            ...(can('om.maintenance.view') ? [{
              name: 'Defects Management',
              icon: <ExclamationTriangleIcon />,
              route: 'om.defects',
              description: 'Defect logging, SLA countdown, and work order conversion',
            }] : []),
            ...(can('om.maintenance.view') ? [{
              name: 'Daily CEO Report',
              icon: <DocumentChartBarIcon />,
              route: 'om.daily-report',
              description: 'Daily Road Maintenance Monitoring Report (09:15 AM Executive Briefing)',
            }] : []),
            ...(can('om.maintenance.view') ? [{
              name: 'Maintenance Work Orders',
              icon: <WrenchScrewdriverIcon />,
              route: 'om.work-orders',
              description: 'Routine maintenance, lifecycle tracking, and verification',
            }] : []),
            ...(can('om.maintenance.view') ? [{
              name: 'Work Orders Calendar',
              icon: <CalendarIcon />,
              route: 'om.work-orders.calendar',
              description: 'Monthly & weekly schedule view of maintenance assignments',
            }] : []),
            ...(canAny(['om.pm.manage', 'om.maintenance.view']) ? [{
              name: 'Preventive Maintenance',
              icon: <CalendarDaysIcon />,
              route: 'om.pm',
              description: 'Recurring PM schedules, auto-WO generator, and intervals',
            }] : []),
            ...(canAny(['om.maintenance.view', 'quality.inspections.view', 'om.inspections.manage']) ? [{
              name: 'Inspection Checklists',
              icon: <ClipboardDocumentCheckIcon />,
              route: 'om.inspections',
              description: 'Digital asset inspections, scoring, and auto-defect triggers',
            }] : []),
            ...(canAny(['om.equipment.view', 'om.maintenance.view']) ? [{
              name: 'Asset Inventory',
              icon: <CubeIcon />,
              route: 'om.assets',
              description: 'Linear expressway asset registry and condition ratings',
            }] : []),
            ...(canAny(['om.inventory.manage', 'om.maintenance.view']) ? [{
              name: 'Spare Parts & Materials',
              icon: <ArchiveBoxIcon />,
              route: 'om.inventory',
              description: 'O&M stock levels, consumption logs, and reorder alerts',
            }] : []),
          ],
        }] : []),

        // 2. Traffic & Control Center (TMC)
        ...(canAny(['om.traffic.view', 'om.equipment.view', 'om.shift.manage']) ? [{
          name: 'Traffic & Control (TMC)',
          icon: <ComputerDesktopIcon />,
          category: 'tmc',
          subMenu: [
            ...(canAny(['om.traffic.view', 'om.incidents.view']) ? [{
              name: 'Traffic Monitoring Center',
              icon: <ComputerDesktopIcon />,
              route: 'om.traffic',
              description: 'Live traffic density, CCTV surveillance, and WIM overload alerts',
            }] : []),
            ...(canAny(['om.equipment.view', 'om.traffic.view']) ? [{
              name: 'Equipment & Facilities',
              icon: <CpuChipIcon />,
              route: 'om.equipment',
              description: 'CCTV, optical sensors, WIM scales, and generator health status',
            }] : []),
            ...(canAny(['om.shift.manage', 'om.traffic.view', 'om.incidents.view']) ? [{
              name: 'Shift Handover Logs',
              icon: <ClipboardDocumentCheckIcon />,
              route: 'om.shift-logs',
              description: 'Digital shift logbook and operator handover records',
            }] : []),
          ],
        }] : []),

        // 3. Patrol & Safety Operations
        ...(canAny(['om.incidents.view', 'om.safety.view', 'om.tppd.view']) ? [{
          name: 'Patrol & Safety',
          icon: <ShieldCheckIcon />,
          category: 'patrol',
          subMenu: [
            ...(canAny(['om.incidents.view', 'om.traffic.view', 'om.patrol.manage']) ? [{
              name: 'Incidents & Patrol',
              icon: <TruckIcon />,
              route: 'om.incidents',
              description: 'Incident response SLAs, dispatching, and highway patrol',
            }] : []),
            ...(canAny(['om.safety.view', 'om.safety.manage', 'om.maintenance.view']) ? [{
              name: 'Safety Management (EHS)',
              icon: <ShieldCheckIcon />,
              route: 'om.safety',
              description: 'Near-miss reporting, PPE tracking, and lost-time metrics',
            }] : []),
            ...(canAny(['om.safety.manage', 'om.maintenance.manage', 'om.safety.view', 'om.maintenance.view']) ? [{
              name: 'Toolbox Safety Briefings',
              icon: <ChatBubbleLeftRightIcon />,
              route: 'om.toolbox-talks',
              description: 'Pre-work safety talks, crew attendance, and hazard mitigation',
            }] : []),
            ...(canAny(['om.tppd.view', 'om.tppd.manage', 'om.incidents.view']) ? [{
              name: 'TPPD Crash Recovery',
              icon: <DocumentMagnifyingGlassIcon />,
              route: 'om.tppd',
              description: 'Third-party asset damage recovery, FIR dossiers, and BOQ claim tracker',
            }] : []),
          ],
        }] : []),

        // 4. Engineering & Concession Analytics
        ...(canAny(['om.dashboard.view', 'om.sla.view', 'om.analytics.view', 'om.contractors.view', 'om.research.view']) ? [{
          name: 'Engineering & Analytics',
          icon: <ChartBarSquareIcon />,
          category: 'analytics',
          subMenu: [
            ...(canAny(['om.dashboard.view', 'om.maintenance.view', 'om.incidents.view', 'om.traffic.view']) ? [{
              name: 'O&M Overview',
              icon: <ChartBarSquareIcon />,
              route: 'om.dashboard',
              description: 'Operations and maintenance command center overview',
            }] : []),
            ...(canAny(['om.sla.view', 'om.dashboard.view', 'om.maintenance.view']) ? [{
              name: 'SLA Compliance Matrix',
              icon: <ClockIcon />,
              route: 'om.sla',
              description: 'Real-time SLA breach registry and resolution rate tracking',
            }] : []),
            ...(canAny(['om.analytics.view', 'om.dashboard.view']) ? [{
              name: 'O&M Analytics',
              icon: <ChartBarSquareIcon />,
              route: 'om.analytics',
              description: 'MTTR, defect trends, category breakdowns, and safety KPIs',
            }] : []),
            ...(canAny(['om.contractors.view', 'om.contractors.manage']) ? [{
              name: 'Contractor Scorecards',
              icon: <BriefcaseIcon />,
              route: 'om.contractors',
              description: 'Vendor SLA compliance, quality ratings, and work orders',
            }] : []),
            ...(canAny(['om.research.view', 'om.maintenance.view']) ? [{
              name: 'Pavement Roughness IRI',
              icon: <ArrowTrendingUpIcon />,
              route: 'om.iri',
              description: 'Continuous 48-km linear roughness heatmap, deterioration alerts, and ride quality',
            }] : []),
            ...(canAny(['om.traffic.view', 'om.research.view']) ? [{
              name: 'WIM Overload & Fatigue',
              icon: <ScaleIcon />,
              route: 'om.wim',
              description: 'AASHTO 4th power law damage factors, ESAL fatigue accumulation, and road wear cost',
            }] : []),
            ...(canAny(['om.research.view', 'om.maintenance.view']) ? [{
              name: 'Pavement Life Forecaster',
              icon: <CalculatorIcon />,
              route: 'om.pavement.deterioration',
              description: 'Markov chain condition degradation matrix & 65% LCCA optimal intervention savings',
            }] : []),
            ...(canAny(['om.equipment.view', 'om.research.view']) ? [{
              name: 'ITS Reliability (RCM)',
              icon: <PresentationChartLineIcon />,
              route: 'om.its.rcm',
              description: 'SAE JA1011 Reliability-Centered Maintenance, MTBF/MTTR rankings, and Weibull curves',
            }] : []),
            ...(canAny(['om.maintenance.view', 'om.safety.view', 'om.traffic.view']) ? [{
              name: 'Environmental Monitoring',
              icon: <CloudIcon />,
              route: 'om.environmental',
              description: 'Air, noise, runoff quality readings and weather compliance',
            }] : []),
            ...(canAny(['om.maintenance.view', 'settings.view']) ? [{
              name: 'O&M Lookups & Standards',
              icon: <AdjustmentsHorizontalIcon />,
              route: 'om.lookups',
              description: 'Manage defect categories, severities, locations, and SLA thresholds',
            }] : []),
          ],
        }] : []),
      ],
    }] : []),

    // 8. Admin & Settings (System Administration)
    ...(canAny(['users.view', 'settings.view', 'roles.view', 'modules.view', 'company.settings', 'attendance.settings', 'leave-settings.view', 'request_logs.view']) ? [{
      name: 'Admin',
      icon: <Cog6ToothIcon className="" />,
      priority: 8,
      module: 'admin',
      subMenu: [
          ...(can('company.settings') ? [{
            name: 'Company Details', 
            icon: <BuildingOfficeIcon className="w-5 h-5" />, 
            route: 'admin.settings.company',
            priority: 2,
            description: 'Configure organizational structure, company information, and brand assets'
          }] : []),
          ...(can('request_logs.view') ? [{
            name: 'Request Logs',
            icon: <DocumentTextIcon />,
            route: 'request-logs.index',
            description: 'View and manage all HTTP request logs'
          }] : []),
          ...(isSuperAdmin ? [{
            name: 'Monitoring',
            icon: <ComputerDesktopIcon />,
            route: 'admin.system-monitoring',
            description: 'View system health and analytics logs'
          }] : []),
          ...(can('notifications.settings') ? [{
            name: 'Notifications',
            icon: <Cog6ToothIcon className="w-5 h-5" />,
            route: 'admin.settings.notifications',
            description: 'Configure notification types, channels, and recipients'
          }] : []),
          ...(can('users.view') ? [{
            name: 'Device Sessions',
            icon: <ComputerDesktopIcon className="w-5 h-5" />,
            route: 'admin.device-sessions.index',
            description: 'View active device sessions and revoke them per device'
          }] : []),
          ...(can('users.view') ? [{
            name: 'Feature Flags',
            icon: <Cog6ToothIcon className="w-5 h-5" />,
            route: 'admin.feature-flags.index',
            description: 'Toggle server-controlled feature flags and remote config'
          }] : []),
          ...(can('users.view') ? [{
            name: 'Client Diagnostics',
            icon: <ExclamationTriangleIcon className="w-5 h-5" />,
            route: 'admin.client-errors.index',
            description: 'Mobile app crashes and errors, grouped and triaged'
          }] : []),
      ]
    }] : []),
  ];
}

// Utility functions for navigation management

// Get pages by module for better organization
export const getPagesByModule = (roles, permissions, auth = null) => {
  const pages = getPages(roles, permissions, auth);
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
export const getPagesByPriority = (roles, permissions, auth = null) => {
  return getPages(roles, permissions, auth).sort((a, b) => (a.priority || 999) - (b.priority || 999));
};

// Get navigation breadcrumb path
export const getNavigationPath = (currentRoute, roles, permissions, auth = null) => {
  const pages = getPages(roles, permissions, auth);
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
