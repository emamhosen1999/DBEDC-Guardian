import React from 'react';
import { Flex, Tooltip } from '@radix-ui/themes';
import { LockClosedIcon } from '@radix-ui/react-icons';

/**
 * Why a field is read-only for this viewer. A person never edits their own org placement, roles,
 * pay or attendance method (separation of duties); anyone else is locked out by rank or scope.
 */
export function lockReason(row, field) {
    if (field === 'role') return 'Assigned by Super Admin';
    if (row?.can?.is_self) return `Managed by HR — you can't change your own ${field}`;
    return 'Managed by a higher-level admin';
}

/** Read-only value + a small lock icon with a tooltip explaining why it can't be changed. */
export default function LockedFieldHint({ row, field, children }) {
    const reason = lockReason(row, field);

    return (
        <Flex align="center" gap="1" data-testid="locked-field">
            {children}
            <Tooltip content={reason}>
                <span role="img" aria-label={reason} tabIndex={0} style={{ display: 'inline-flex', color: 'var(--gray-9)' }}>
                    <LockClosedIcon style={{ width: 11, height: 11 }} />
                </span>
            </Tooltip>
        </Flex>
    );
}
