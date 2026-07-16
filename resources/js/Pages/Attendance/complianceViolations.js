/**
 * Working-time compliance violations helpers.
 *
 * The backend (WorkTimeComplianceService) returns violations shaped as
 * `{ date, rule, message, severity: 'error'|'warning', details }`. Depending
 * on the endpoint they arrive in one of two shapes:
 *
 *  - flat array:   RosterController@updateCell (single user, caller already
 *                   knows which user/date — no key needed).
 *  - keyed object: { [userId]: Violation[] } — RosterController@generate,
 *                   ShiftController@storeAssignment/updateAssignment/
 *                   storeBulkAssignment, ShiftSwapController@approve.
 *
 * `normalizeViolations` flattens either shape into one array of
 * `{ userId: number|null, date, rule, message, severity, details }`.
 */

export function normalizeViolations(raw) {
    if (!raw) return [];

    if (Array.isArray(raw)) {
        return raw.map(v => ({ userId: null, ...v }));
    }

    if (typeof raw === 'object') {
        return Object.entries(raw).flatMap(([userId, list]) =>
            (Array.isArray(list) ? list : []).map(v => ({ userId: Number(userId), ...v }))
        );
    }

    return [];
}

/**
 * Pull `compliance_violations` out of either a successful response payload
 * or a caught ApiError (which carries the raw axios `response` — used for
 * 422 "blocked" replies where the write did not happen).
 */
export function violationsFromResult(result) {
    const raw = result?.compliance_violations ?? result?.response?.data?.compliance_violations;
    return normalizeViolations(raw);
}

export function hasBlockingViolation(violations = []) {
    return violations.some(v => v?.severity === 'error');
}

export const SEVERITY_COLOR = { error: 'red', warning: 'amber' };

/**
 * Group a flat violation list by userId, attaching a display name from a
 * `{ [id]: { name } }` (or array-of-{id,name}) lookup.
 */
export function groupViolationsByEmployee(violations = [], employeesById = {}) {
    const groups = new Map();

    for (const v of violations) {
        const key = v.userId ?? 'unknown';
        if (!groups.has(key)) {
            groups.set(key, {
                userId: v.userId,
                name: (v.userId != null && employeesById[v.userId]?.name) || (v.userId != null ? `#${v.userId}` : 'Unknown'),
                violations: [],
            });
        }
        groups.get(key).violations.push(v);
    }

    return Array.from(groups.values());
}

/** Build an `{ [id]: employee }` lookup from an employees array (id, name, ...). */
export function keyEmployeesById(employees = []) {
    return employees.reduce((acc, e) => {
        acc[e.id] = e;
        return acc;
    }, {});
}
