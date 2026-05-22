import {
    STATUS_CONFIG,
    CATEGORY_CONFIG,
} from '@/Config/objectionConfig';

export const statusConfig = Object.fromEntries(
    Object.entries(STATUS_CONFIG).map(([key, val]) => [key, {
        color: val.color,
        icon: val.solidIcon || val.icon,
        label: val.label,
    }])
);

export const categoryConfig = Object.fromEntries(
    Object.entries(CATEGORY_CONFIG).map(([key, val]) => [key, {
        label: val.label,
        color: val.color,
    }])
);
