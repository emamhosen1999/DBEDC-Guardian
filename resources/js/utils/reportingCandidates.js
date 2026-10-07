export const NO_DEPARTMENT = 'No department';

/**
 * Filter the server's candidate list by a free-text query (name, designation or department) and group it
 * by department. Groups and people stay in the server's name order; groups sort by department name with
 * "No department" last.
 *
 * @param {Array<{id: string, name: string, designation?: string|null, department?: string|null}>} candidates
 * @param {string} query
 * @returns {Array<{department: string, people: Array<object>}>}
 */
export function groupCandidates(candidates = [], query = '') {
    const needle = String(query ?? '').trim().toLowerCase();
    const matches = (p) => !needle
        || [p.name, p.designation, p.department].some((v) => String(v ?? '').toLowerCase().includes(needle));

    const byDepartment = new Map();
    for (const person of candidates) {
        if (!matches(person)) continue;
        const key = person.department || NO_DEPARTMENT;
        if (!byDepartment.has(key)) byDepartment.set(key, []);
        byDepartment.get(key).push(person);
    }

    return [...byDepartment.entries()]
        .sort(([a], [b]) => (a === NO_DEPARTMENT) - (b === NO_DEPARTMENT) || a.localeCompare(b))
        .map(([department, people]) => ({ department, people }));
}
