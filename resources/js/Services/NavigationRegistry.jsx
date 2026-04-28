import { NAVIGATION_MODULES, NAVIGATION_SECTIONS, getIcon, DEFAULT_NAVIGATION_CONFIG } from '../Config/navigation.config.js';

/**
 * Navigation Registry Service
 * Provides a comprehensive, extensible navigation system
 */
class NavigationRegistry {
  constructor(config = {}) {
    this.config = { ...DEFAULT_NAVIGATION_CONFIG, ...config };
    this.modules = { ...NAVIGATION_MODULES };
    this.sections = { ...NAVIGATION_SECTIONS };
  }

  /**
   * Register a new navigation module
   */
  registerModule(moduleId, moduleConfig) {
    this.modules[moduleId] = moduleConfig;
    return this;
  }

  /**
   * Register a new section
   */
  registerSection(sectionId, sectionConfig) {
    this.sections[sectionId] = sectionConfig;
    return this;
  }

  /**
   * Check if user has required roles
   */
  hasRoles(userRoles, requiredRoles) {
    if (!requiredRoles || requiredRoles.length === 0) return true;
    return requiredRoles.some(role => userRoles.includes(role));
  }

  /**
   * Convert icon name to React component
   */
  getIconComponent(iconName) {
    const icon = getIcon(iconName);
    // Ensure we always return a valid React component
    return icon || HomeIcon;
  }

  /**
   * Transform navigation item to sidebar format
   */
  transformItem(item, roles, auth) {
    const IconComponent = this.getIconComponent(item.icon);
    const transformed = {
      name: item.name,
      icon: IconComponent,
      route: item.route,
      priority: item.priority,
      module: item.module,
      method: item.method
    };

    // Handle submenu
    if (item.subMenu && item.subMenu.length > 0) {
      transformed.subMenu = item.subMenu
        .map(subItem => this.transformItem(subItem, roles, auth))
        .filter(subItem => subItem !== null);
    }

    return transformed;
  }

  /**
   * Check if item should be visible to user
   */
  isItemVisible(item, roles, auth) {
    // Check roles only
    if (item.roles && !this.hasRoles(roles, item.roles)) {
      return false;
    }

    // Check auth-specific conditions
    if (item.authCondition && !item.authCondition(auth)) {
      return false;
    }

    return true;
  }

  /**
   * Filter items based on roles
   */
  filterItems(items, roles, auth) {
    return items
      .filter(item => this.isItemVisible(item, roles, auth))
      .map(item => this.transformItem(item, roles, auth))
      .filter(item => item !== null);
  }

  /**
   * Get section header
   */
  getSectionHeader(sectionId) {
    const section = this.sections[sectionId];
    if (!section || !this.config.enableSectionHeaders) return null;

    const IconComponent = this.getIconComponent(section.icon);
    return {
      name: section.name,
      type: 'header',
      icon: <IconComponent />
    };
  }

  /**
   * Get all pages for navigation
   */
  getPages(roles = [], auth = null) {
   
    
    // If no roles, return empty array
    if (!roles || roles.length === 0) {
      console.log('No roles provided, returning empty navigation');
      return [];
    }

    const isOnlyMember = roles?.length === 1 && roles[0] === 'Member';
    const hasSuperAdmin = roles?.includes('Super Administrator');
    const hasMember = roles?.includes('Member');
    const pages = [];

    // Get sorted module keys by priority
    const sortedModuleKeys = Object.keys(this.modules)
      .sort((a, b) => {
        const priorityA = this.modules[a].priority || 999;
        const priorityB = this.modules[b].priority || 999;
        return priorityA - priorityB;
      });

    // Group modules by section
    const sectionGroups = {};
    const standaloneItems = [];

    sortedModuleKeys.forEach(moduleKey => {
      const module = this.modules[moduleKey];
      
      // Filter and transform module items
      const visibleItems = this.filterItems(module.items, roles, auth);

     

      if (visibleItems.length === 0) return;

      // Handle core module (dashboards)
      if (moduleKey === 'core') {
        visibleItems.forEach(item => {
          standaloneItems.push(this.transformItem(item, roles, auth));
        });
      }
      // Handle workspace for Member only (flatten under Main)
      else if (moduleKey === 'workspace' && isOnlyMember) {
        visibleItems.forEach(item => {
          standaloneItems.push(this.transformItem(item, roles, auth));
        });
      }
      // Handle workspace for Super Administrator + Member (group under My Workspace)
      else if (moduleKey === 'workspace' && hasSuperAdmin && hasMember) {
        pages.push({
          name: 'My Workspace',
          icon: this.getIconComponent('home'),
          priority: module.priority,
          subMenu: visibleItems.map(item => this.transformItem(item, roles, auth))
        });
      }
      // Skip workspace for Super Administrator only
      else if (moduleKey === 'workspace' && hasSuperAdmin && !hasMember) {
        // Don't show workspace for Super Administrator only
      }
      // Group by section for other modules
      else if (module.section) {
        // Member only: only show MAIN section
        if (isOnlyMember && module.section !== 'MAIN') {
          return;
        }
        
        if (!sectionGroups[module.section]) {
          sectionGroups[module.section] = {
            name: this.getSectionName(module.section),
            icon: this.getIconComponent(this.getSectionIcon(module.section)),
            priority: this.getSectionPriority(module.section),
            subMenu: []
          };
        }
        
        // Add module items to section submenu
        visibleItems.forEach(item => {
          sectionGroups[module.section].subMenu.push(this.transformItem(item, roles, auth));
        });
      } else {
        // Standalone items
        visibleItems.forEach(item => {
          standaloneItems.push(this.transformItem(item, roles, auth));
        });
      }
    });

    // Add standalone items first (dashboards)
    pages.push(...standaloneItems);

    // Add section groups
    Object.values(sectionGroups).forEach(section => {
      if (section.subMenu.length > 0) {
        pages.push(section);
      }
    });

    // Sort by priority if enabled
    if (this.config.enablePrioritySorting) {
      pages.sort((a, b) => {
        const priorityA = a.priority || 999;
        const priorityB = b.priority || 999;
        return priorityA - priorityB;
      });
    } else {
      // Ensure dashboards appear at the top by default
      pages.sort((a, b) => {
        if (a.name.includes('Dashboard')) return -1;
        if (b.name.includes('Dashboard')) return 1;
        return 0;
      });
    }

  
    return pages;
  }

  /**
   * Get section name by key
   */
  getSectionName(sectionKey) {
    const sections = this.sections || {};
    return sections[sectionKey]?.name || sectionKey;
  }

  /**
   * Get section icon by key
   */
  getSectionIcon(sectionKey) {
    const sections = this.sections || {};
    return sections[sectionKey]?.icon || 'home';
  }

  /**
   * Get section priority by key
   */
  getSectionPriority(sectionKey) {
    const sections = this.sections || {};
    return sections[sectionKey]?.priority || 999;
  }

  /**
   * Get settings pages for settings navigation
   */
  getSettingsPages(roles = [], auth = null) {
    const settings = [];
    
    // Return to Dashboard
    if (roles.includes('Super Administrator') || roles.includes('Member')) {
      settings.push({
        name: 'Return to Dashboard',
        icon: this.getIconComponent('home'),
        route: 'dashboard',
        category: 'navigation',
        priority: 1
      });
    }
    
    // Only Super Administratoristrator can access settings
    if (!roles.includes('Super Administrator')) {
      return settings;
    }
    
    // Organization Settings
    settings.push({
      name: 'Organization',
      icon: this.getIconComponent('building'),
      route: 'admin.settings.company',
      category: 'organization',
      priority: 2
    });
    
    // Time & Attendance Settings
    settings.push({
      name: 'Time Settings',
      icon: this.getIconComponent('clock'),
      route: 'attendance-settings.index',
      category: 'organization',
      priority: 3
    });
    
    // Leave Policy Settings
    settings.push({
      name: 'Leave Settings',
      icon: this.getIconComponent('clipboard'),
      route: 'leave-settings',
      category: 'organization',
      priority: 4
    });
    
    // Roles & Permission Settings
    settings.push({
      name: 'Role Settings',
      icon: this.getIconComponent('shield'),
      route: 'roles-settings',
      category: 'security',
      priority: 5
    });
    
    return settings.sort((a, b) => a.priority - b.priority);
  }

  /**
   * Get navigation items by module
   */
  getModuleItems(moduleId, roles, auth) {
    const module = this.modules[moduleId];
    if (!module) return [];

    return this.filterItems(module.items, roles, auth);
  }

  /**
   * Get all available modules
   */
  getAllModules() {
    return Object.keys(this.modules).map(key => ({
      id: key,
      ...this.modules[key]
    }));
  }

  /**
   * Get navigation statistics
   */
  getStatistics(roles, auth) {
    const stats = {
      totalModules: Object.keys(this.modules).length,
      totalSections: Object.keys(this.sections).length,
      visibleItems: 0,
      visibleModules: 0
    };

    Object.values(this.modules).forEach(module => {
      const visibleItems = this.filterItems(module.items, roles, auth);
      if (visibleItems.length > 0) {
        stats.visibleModules++;
        stats.visibleItems += visibleItems.length;
      }
    });

    return stats;
  }
}

/**
 * Create singleton instance
 */
const navigationRegistry = new NavigationRegistry();

/**
 * Export convenience function for backward compatibility
 */
export const getPages = (roles = [], permissions = [], auth = null) => {
  return navigationRegistry.getPages(roles, auth);
};

/**
 * Export settings pages function
 */
export const getSettingsPages = (roles = [], permissions = [], auth = null) => {
  return navigationRegistry.getSettingsPages(roles, auth);
};

/**
 * Export registry instance for advanced usage
 */
export default navigationRegistry;
