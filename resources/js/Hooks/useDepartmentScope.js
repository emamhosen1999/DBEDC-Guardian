import { useMemo } from 'react';
import { usePage } from '@inertiajs/react';

/**
 * Turn the shared `auth.scope` prop ({ global, departments: [{ id, name }] }) into what a
 * department picker needs. Pure, so it is unit-testable without Inertia.
 *
 *  - global actor          -> the page's FULL department list, everything pickable
 *  - one department        -> `isSingle`: the picker is replaced by a read-only badge / field
 *  - several departments   -> `isMulti`: a LIMITED list (the page's list intersected with the scope)
 *  - none (no department)  -> nothing to pick
 *
 * The server enforces the same scope on every query; this only keeps the UI from offering
 * choices that would be refused.
 *
 * @param {{global?: boolean, attendance?: boolean, departments?: Array<{id: number|string, name: string}>}|null|undefined} scope
 * @param {Array<{id: number|string, name: string}>|null} [pageDepartments] the page's own list, if it has one
 * @param {{attendance?: boolean}} [options] `attendance: true` for the Attendance pages, where an attendance
 *        administrator (holder of `attendance.settings`) is company-wide although their people scope is not
 */
export function resolveDepartmentScope(scope, pageDepartments = null, { attendance = false } = {}) {
    const isGlobal = Boolean(scope?.global) || (attendance && Boolean(scope?.attendance));
    const scoped = Array.isArray(scope?.departments) ? scope.departments : [];
    const universe = Array.isArray(pageDepartments) ? pageDepartments : null;

    let departments;
    if (isGlobal) {
        departments = universe ?? [];
    } else if (universe) {
        const allowed = new Set(scoped.map((department) => String(department.id)));
        const limited = universe.filter((department) => allowed.has(String(department.id)));
        // A page whose own list happens not to carry the scoped department still gets it.
        departments = limited.length > 0 ? limited : scoped;
    } else {
        departments = scoped;
    }

    const isSingle = !isGlobal && departments.length === 1;

    return {
        isGlobal,
        departments,
        isSingle,
        isMulti: !isGlobal && departments.length > 1,
        single: isSingle ? departments[0] : null,
    };
}

/**
 * The signed-in actor's department scope. Pass the page's own department list when it has
 * one (it may carry extra fields); omit it to use the scope's own list.
 */
export function useDepartmentScope(pageDepartments = null, { attendance = false } = {}) {
    const { auth } = usePage().props;

    return useMemo(
        () => resolveDepartmentScope(auth?.scope, pageDepartments, { attendance }),
        [auth?.scope, pageDepartments, attendance],
    );
}

export default useDepartmentScope;
