import React from 'react';
import {
    HomeIcon,
    BuildingOfficeIcon,
    ClockIcon,
    PhotoIcon,
    KeyIcon,
    AtSymbolIcon,
    ChartBarIcon,
    HandThumbUpIcon,
    PencilIcon,
    CurrencyDollarIcon,
    BellIcon,
    LockClosedIcon,
    WrenchScrewdriverIcon,
    ChatBubbleLeftRightIcon,
    RocketLaunchIcon,
    UserGroupIcon,
    ShieldCheckIcon,
    DocumentChartBarIcon,
    Cog8ToothIcon,
    ShoppingBagIcon,
    CreditCardIcon,
    UserIcon,
    ShoppingCartIcon,
    ArchiveBoxIcon,
    AcademicCapIcon,
    TruckIcon,
    TicketIcon,
    BeakerIcon,
    ComputerDesktopIcon
} from '@heroicons/react/24/outline';
import { Squares2X2Icon } from '@heroicons/react/24/outline';

// Function to create settings pages array with enhanced organization aligned with ISO standards
export const getSettingsPages = (permissions = []) => {
    const settings = [];
    // 1. Navigation
    if (permissions.includes('core.dashboard.view')) {
        settings.push({
            name: 'Return to Dashboard', 
            icon: <Squares2X2Icon className="w-5 h-5" />, 
            route: 'dashboard',
            category: 'navigation',
            priority: 1
        });
    }
    // 2. Organization
    if (permissions.includes('company.settings')) {
        settings.push({
            name: 'Organization', 
            icon: <BuildingOfficeIcon className="w-5 h-5" />, 
            route: 'admin.settings.company',
            category: 'organization',
            priority: 2,
            description: 'Configure organizational structure, company information, and brand assets'
        });
    }
    if (permissions.includes('attendance.settings')) {
        settings.push({
            name: 'Time & Attendance', 
            icon: <ClockIcon className="w-5 h-5" />, 
            route: 'attendance-settings.index',
            category: 'organization',
            priority: 3,
            description: 'Configure attendance tracking and workforce management'
        });
    }
    if (permissions.includes('leave-settings.view')) {
        settings.push({
            name: 'Leave Policy', 
            icon: <HandThumbUpIcon className="w-5 h-5" />, 
            route: 'leave-settings',
            category: 'organization',
            priority: 4,
            description: 'Manage leave types and approval workflows'
        });
    }
    // 3. Security
    if (permissions.includes('roles.view')) {
        settings.push({
            name: 'Roles & Permission', 
            icon: <KeyIcon className="w-5 h-5" />, 
            route: 'admin.roles-management',
            category: 'security',
            priority: 5,
            description: 'Manage user roles and permission system'
        });
    }
    
    

    return settings.sort((a, b) => a.priority - b.priority);
};

// Helper function to get settings by category with ISO-aligned organization
export const getSettingsByCategory = (permissions = []) => {
    const settings = getSettingsPages(permissions);
    const categories = {};
    
    settings.forEach(setting => {
        const category = setting.category || 'other';
        if (!categories[category]) {
            categories[category] = [];
        }
        categories[category].push(setting);
    });
    
    return categories;
};

// Utility functions for settings management

// Get settings by ISO standard
export const getSettingsByISOStandard = (permissions = []) => {
    const settings = getSettingsPages(permissions);
    return {
        'ISO 9001 - Quality Management': settings.filter(s => s.category === 'organization'),
        'ISO 27001 - Information Security': settings.filter(s => s.category === 'security'),
        'ISO 20000 - IT Service Management': settings.filter(s => s.category === 'services'),
        'ISO 14001 - Interface Management': settings.filter(s => s.category === 'interface'),
        'Business Process Management': settings.filter(s => s.category === 'business-process'),
        'System Administration': settings.filter(s => s.category === 'system-administration'),
        'Personal Security': settings.filter(s => s.category === 'personal-security')
    };
};

// Get settings by priority level
export const getSettingsByPriority = (permissions = []) => {
    return getSettingsPages(permissions).sort((a, b) => a.priority - b.priority);
};

// Search settings by name or description
export const searchSettings = (searchTerm, permissions = []) => {
    const settings = getSettingsPages(permissions);
    const term = searchTerm.toLowerCase();
    return settings.filter(setting => 
        setting.name.toLowerCase().includes(term) ||
        (setting.description && setting.description.toLowerCase().includes(term))
    );
};

// Get recommended settings for new system setup
export const getRecommendedSettings = (permissions = []) => {
    const settings = getSettingsPages(permissions);
    const recommended = ['Organization Configuration', 'Identity & Access Management', 'User Account Administration'];
    return settings.filter(setting => recommended.includes(setting.name));
};
