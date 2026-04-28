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

/**
 * Centralized Icon Registry
 * Maps icon names to their React components
 */
export const ICON_REGISTRY = {
  home: HomeIcon,
  dashboard: ChartBarSquareIcon,
  chart: ChartBarSquareIcon,
  document: DocumentTextIcon,
  users: UsersIcon,
  user: UserIcon,
  userGroup: UserGroupIcon,
  calendar: CalendarIcon,
  calendarDays: CalendarDaysIcon,
  clock: ClockIcon,
  settings: Cog6ToothIcon,
  cog: Cog6ToothIcon,
  building: BuildingOffice2Icon,
  briefcase: BriefcaseIcon,
  mapPin: MapPinIcon,
  shield: ShieldCheckIcon,
  shieldExclamation: ShieldExclamationIcon,
  arrowRight: ArrowRightOnRectangleIcon,
  cube: CubeIcon,
  computer: ComputerDesktopIcon,
  academicCap: AcademicCapIcon,
  clipboard: ClipboardDocumentCheckIcon,
  creditCard: CreditCardIcon,
  currency: CurrencyDollarIcon,
  documentDuplicate: DocumentDuplicateIcon,
  envelope: EnvelopeIcon,
  folder: FolderIcon,
  scale: ScaleIcon,
};

/**
 * Get icon component by name
 */
export const getIcon = (iconName) => {
  return ICON_REGISTRY[iconName] || HomeIcon;
};

/**
 * Navigation Module Configuration
 * Each module can register its own navigation items
 */
export const NAVIGATION_MODULES = {
  core: {
    name: 'Core',
    priority: 0,
    items: [
      {
        id: 'admin-dashboard',
        name: 'Admin Dashboard',
        icon: 'home',
        route: 'admin.dashboard',
        roles: ['Super Administrator']
      },
      {
        id: 'employee-dashboard',
        name: 'Member Dashboard',
        icon: 'home',
        route: 'employee.dashboard',
        roles: ['Member']
      }
    ]
  },

  workspace: {
    name: 'My Workspace',
    priority: 2,
    section: 'MAIN',
    items: [
      {
        id: 'daily-works',
        name: 'My Daily Work',
        icon: 'document',
        route: 'daily-works',
        roles: ['Member']
      },
      {
        id: 'objections',
        name: 'My Objections',
        icon: 'shieldExclamation',
        route: 'objections.index',
        roles: ['Member']
      },
      {
        id: 'attendance-employee',
        name: 'My Attendance',
        icon: 'calendarDays',
        route: 'attendance-employee',
        roles: ['Member']
      },
      {
        id: 'leaves-employee',
        name: 'My Leaves',
        icon: 'arrowRight',
        route: 'leaves-employee',
        roles: ['Member']
      }
    ]
  },

  workforce: {
    name: 'Workforce',
    priority: 3,
    section: 'WORKFORCE',
    items: [
      {
        id: 'employee-directory',
        name: 'Member List',
        icon: 'userGroup',
        route: 'employees',
        roles: ['Super Administrator', 'Manager']
      },
      {
        id: 'departments',
        name: 'Departments',
        icon: 'building',
        route: 'departments',
        roles: ['Super Administrator', 'Manager']
      },
      {
        id: 'designations',
        name: 'Designations',
        icon: 'briefcase',
        route: 'designations.index',
        roles: ['Super Administrator', 'Manager']
      },
      {
        id: 'work-locations',
        name: 'Work Locations',
        icon: 'mapPin',
        route: 'jurisdiction',
        roles: ['Super Administrator', 'Manager']
      },
      {
        id: 'attendance',
        name: 'Attendance',
        icon: 'calendarDays',
        route: 'attendances',
        roles: ['Super Administrator', 'Manager']
      },
      {
        id: 'timesheet',
        name: 'Timesheet',
        icon: 'clock',
        route: 'timesheet',
        roles: ['Super Administrator', 'Manager']
      },
      {
        id: 'holidays',
        name: 'Holidays',
        icon: 'calendar',
        route: 'holidays',
        roles: ['Super Administrator', 'Manager']
      },
      {
        id: 'leaves',
        name: 'Leave Mgmt',
        icon: 'arrowRight',
        route: 'leaves',
        roles: ['Super Administrator', 'Manager']
      },
      {
        id: 'leave-analytics',
        name: 'Leave Analytics',
        icon: 'chart',
        route: 'leave-summary',
        roles: ['Super Administrator', 'Manager']
      },
      {
        id: 'leave-policies',
        name: 'Leave Policies',
        icon: 'cog',
        route: 'leave-settings',
        roles: ['Super Administrator', 'Manager']
      }
    ]
  },

  dailyworks: {
    name: 'Daily Works',
    priority: 4,
    section: 'DAILYWORKS',
    items: [
      {
        id: 'daily-works',
        name: 'Daily Work',
        icon: 'document',
        route: 'daily-works',
        roles: ['Super Administrator', 'Manager']
      },
      {
        id: 'daily-works-analytics',
        name: 'Daily Work Analytics',
        icon: 'chart',
        route: 'daily-works-analytics',
        roles: ['Super Administrator', 'Manager']
      }
    ]
  },

  admin: {
    name: 'Admin',
    priority: 8,
    section: 'ADMINISTRATION',
    items: [
      {
        id: 'users',
        name: 'User Mgmt',
        icon: 'users',
        route: 'users',
        roles: ['Super Administrator']
      },
      {
        id: 'roles',
        name: 'Role Mgmt',
        icon: 'userGroup',
        route: 'admin.roles-management',
        roles: ['Super Administrator']
      },
      {
        id: 'modules',
        name: 'Module Mgmt',
        icon: 'cube',
        route: 'modules.index',
        roles: ['Super Administrator']
      },
      {
        id: 'monitoring',
        name: 'System Monitor',
        icon: 'computer',
        route: 'admin.system-monitoring',
        roles: ['Super Administrator']
      }
    ]
  },

  settings: {
    name: 'Settings',
    priority: 9,
    section: 'SETTINGS',
    items: [
      {
        id: 'company-settings',
        name: 'Organization',
        icon: 'building',
        route: 'admin.settings.company',
        roles: ['Super Administrator']
      },
      {
        id: 'attendance-settings',
        name: 'Time Settings',
        icon: 'clock',
        route: 'attendance-settings.index',
        roles: ['Super Administrator']
      },
      {
        id: 'leave-settings',
        name: 'Leave Settings',
        icon: 'clipboard',
        route: 'leave-settings',
        roles: ['Super Administrator']
      },
      {
        id: 'roles-settings',
        name: 'Role Settings',
        icon: 'shield',
        route: 'roles-settings',
        roles: ['Super Administrator']
      }
    ]
  }
};

/**
 * Section Headers Configuration
 */
export const NAVIGATION_SECTIONS = {
  MAIN: {
    name: 'Main',
    icon: 'home',
    priority: 1
  },
  DAILYWORKS: {
    name: 'Daily Work',
    icon: 'document',
    priority: 2
  },
  WORKFORCE: {
    name: 'Workforce',
    icon: 'userGroup',
    priority: 3
  },
  ADMINISTRATION: {
    name: 'Administration',
    icon: 'cog',
    priority: 8
  },
  SETTINGS: {
    name: 'Settings',
    icon: 'cog',
    priority: 9
  }
};

/**
 * Default navigation configuration
 */
export const DEFAULT_NAVIGATION_CONFIG = {
  enableSectionHeaders: true,
  enableWorkspaceGrouping: true,
  enablePrioritySorting: true,
  maxNestingLevel: 3,
  iconSize: 'medium',
  showItemCount: true
};
