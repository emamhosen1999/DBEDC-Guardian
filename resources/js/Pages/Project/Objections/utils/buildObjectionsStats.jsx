import React from 'react';
import {
    ShieldExclamationIcon,
    ExclamationTriangleIcon,
    ClockIcon,
    CheckCircleIcon,
} from '@heroicons/react/24/outline';

/**
 * Build stat card config for the Objections index page.
 */
export function buildObjectionsStats(apiStats, objections) {
    const data = apiStats || {};
    const total = data.total || objections?.total || 0;
    const active = data.active || 0;
    const resolved = data.resolved || 0;
    const pending = data.pending || 0;
    const resolutionRate = total > 0 ? Math.round((resolved / total) * 100) : 0;

    return [
        {
            title: 'Total Objections',
            value: total.toLocaleString(),
            icon: <ShieldExclamationIcon className="w-5 h-5" />,
            color: 'text-blue-600',
            bgColor: 'bg-blue-50 dark:bg-blue-900/20',
            description: `${active} active`,
            trend: active > 0 ? 'down' : 'neutral',
        },
        {
            title: 'Active Issues',
            value: active.toLocaleString(),
            icon: <ExclamationTriangleIcon className="w-5 h-5" />,
            color: active > 10 ? 'text-red-600' : 'text-warning-600',
            bgColor: active > 10 ? 'bg-red-50 dark:bg-red-900/20' : 'bg-warning-50 dark:bg-warning-900/20',
            description: active > 0 ? 'Require attention' : 'All clear!',
            trend: active > 10 ? 'down' : active > 0 ? 'neutral' : 'up',
        },
        {
            title: 'Pending Review',
            value: pending.toLocaleString(),
            icon: <ClockIcon className="w-5 h-5" />,
            color: 'text-orange-600',
            bgColor: 'bg-orange-50 dark:bg-orange-900/20',
            description: pending > 0 ? 'Awaiting action' : 'All caught up!',
            trend: pending > 5 ? 'down' : 'neutral',
        },
        {
            title: 'Resolution Rate',
            value: `${resolutionRate}%`,
            icon: <CheckCircleIcon className="w-5 h-5" />,
            color: resolutionRate >= 80 ? 'text-green-600' : resolutionRate >= 50 ? 'text-yellow-600' : 'text-red-600',
            bgColor: resolutionRate >= 80 ? 'bg-green-50 dark:bg-green-900/20' : resolutionRate >= 50 ? 'bg-yellow-50 dark:bg-yellow-900/20' : 'bg-red-50 dark:bg-red-900/20',
            description: `${resolved} resolved`,
            trend: resolutionRate >= 80 ? 'up' : resolutionRate >= 50 ? 'neutral' : 'down',
        },
    ];
}
