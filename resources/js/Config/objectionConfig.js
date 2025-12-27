/**
 * Shared configuration for Objection status and category display.
 * Use this across all components for consistent UI.
 */

import {
    DocumentIcon,
    CheckCircleIcon,
    XCircleIcon,
    ClockIcon,
    ExclamationTriangleIcon,
} from "@heroicons/react/24/outline";
import {
    CheckCircleIcon as CheckCircleSolid,
    XCircleIcon as XCircleSolid,
    ExclamationTriangleIcon as ExclamationTriangleSolid,
    DocumentArrowUpIcon,
} from '@heroicons/react/24/solid';

/**
 * Status configuration for objections
 * Consistent colors and icons across all views
 */
export const STATUS_CONFIG = {
    draft: {
        color: 'default',
        icon: DocumentIcon,
        solidIcon: DocumentIcon,
        label: 'Draft',
        description: 'Objection is being drafted',
    },
    submitted: {
        color: 'primary',
        icon: DocumentArrowUpIcon,
        solidIcon: DocumentArrowUpIcon,
        label: 'Submitted',
        description: 'Objection submitted for review',
    },
    under_review: {
        color: 'warning',
        icon: ClockIcon,
        solidIcon: ExclamationTriangleSolid,
        label: 'Under Review',
        description: 'Objection is being reviewed',
    },
    resolved: {
        color: 'success',
        icon: CheckCircleIcon,
        solidIcon: CheckCircleSolid,
        label: 'Resolved',
        description: 'Objection has been resolved',
    },
    rejected: {
        color: 'danger',
        icon: XCircleIcon,
        solidIcon: XCircleSolid,
        label: 'Rejected',
        description: 'Objection has been rejected',
    },
};

/**
 * Category configuration for objections
 */
export const CATEGORY_CONFIG = {
    design_conflict: {
        label: 'Design Conflict',
        color: 'danger',
        description: 'Conflict between design documents',
    },
    site_mismatch: {
        label: 'Site Condition Mismatch',
        color: 'warning',
        description: 'Actual site conditions differ from plans',
    },
    material_change: {
        label: 'Material Change',
        color: 'secondary',
        description: 'Material specifications need to be changed',
    },
    safety_concern: {
        label: 'Safety Concern',
        color: 'danger',
        description: 'Safety-related issue identified',
    },
    specification_error: {
        label: 'Specification Error',
        color: 'primary',
        description: 'Error in specifications',
    },
    other: {
        label: 'Other',
        color: 'default',
        description: 'Other type of objection',
    },
};

/**
 * Statuses considered "active" (blocking)
 */
export const ACTIVE_STATUSES = ['draft', 'submitted', 'under_review'];

/**
 * Statuses considered "closed" (non-blocking)
 */
export const CLOSED_STATUSES = ['resolved', 'rejected'];

/**
 * Check if an objection status is active (blocking)
 * @param {string} status - The objection status
 * @returns {boolean}
 */
export const isActiveStatus = (status) => ACTIVE_STATUSES.includes(status);

/**
 * Get status configuration, with fallback to draft
 * @param {string} status - The objection status
 * @returns {object}
 */
export const getStatusConfig = (status) => STATUS_CONFIG[status] || STATUS_CONFIG.draft;

/**
 * Get category configuration, with fallback to other
 * @param {string} category - The objection category
 * @returns {object}
 */
export const getCategoryConfig = (category) => CATEGORY_CONFIG[category] || CATEGORY_CONFIG.other;

/**
 * Maximum file size for objection attachments (in bytes)
 */
export const MAX_FILE_SIZE_BYTES = 10 * 1024 * 1024; // 10MB

/**
 * Maximum file size for objection attachments (formatted)
 */
export const MAX_FILE_SIZE_DISPLAY = '10MB';

/**
 * Accepted file types for objection attachments
 */
export const ACCEPTED_FILE_TYPES = {
    images: ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
    documents: [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ],
};

/**
 * Accept string for file inputs
 */
export const FILE_ACCEPT_STRING = 'image/*,application/pdf,.doc,.docx,.xls,.xlsx';

export default {
    STATUS_CONFIG,
    CATEGORY_CONFIG,
    ACTIVE_STATUSES,
    CLOSED_STATUSES,
    isActiveStatus,
    getStatusConfig,
    getCategoryConfig,
    MAX_FILE_SIZE_BYTES,
    MAX_FILE_SIZE_DISPLAY,
    ACCEPTED_FILE_TYPES,
    FILE_ACCEPT_STRING,
};
