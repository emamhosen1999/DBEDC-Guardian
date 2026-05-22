import { useMemo } from 'react';
import { usePage } from '@inertiajs/react';

export function useObjectionsAccess() {
    const { auth } = usePage().props;

    return useMemo(() => {
        const roles = auth?.roles ?? [];

        const userIsAdmin = roles.includes('Administrator')
            || roles.includes('Super Administrator')
            || roles.includes('Daily Work Manager');

        const canReviewObjections = roles.some(role =>
            ['Super Administrator', 'Administrator', 'Project Manager', 'Consultant', 'HR Manager'].includes(role)
        );

        return {
            roles,
            userIsAdmin,
            canReviewObjections,
        };
    }, [auth?.roles]);
}
