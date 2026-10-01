/** The level of someone with no designation: they rank below every designated person. */
export const UNRANKED = 999;

/**
 * Who may an employee report to? One rule for the employee form and the table's inline cell.
 *
 * A candidate (an entry of the `allManagers` list: { id, department_id, designation_hierarchy_level, ... },
 * where a missing designation reads as UNRANKED) is eligible when:
 *   - they are not the employee themselves (nobody reports to themselves), and
 *   - they are the CURRENT manager (an existing assignment never disappears from its own picker), or
 *   - they are the person doing the administering (`actorId`) — a department admin is the natural
 *     supervisor of the people he registers, even in a department with no designations yet, or
 *   - they rank above the employee (lower hierarchy level = higher rank) in the same department; and
 *     where either side has no designation there is no rank to compare, so any colleague in the same
 *     department qualifies (a department without designations must still have a reports-to list).
 *
 * `crossDepartmentHead` keeps the inline table's long-standing extra: the most senior person of a
 * department (nobody in it outranks them) may report to a higher-ranked person elsewhere.
 *
 * The server independently refuses a manager outside the actor's scope.
 *
 * @param {object}  options
 * @param {Array<object>} options.managers   the `allManagers` list
 * @param {{id: string|number, department_id?: string|number|null}} options.subject  the employee being placed
 * @param {number|null} [options.subjectLevel]  hierarchy level of the employee's designation (null = none)
 * @param {string|number|null} [options.actorId]
 * @param {string|number|null} [options.currentManagerId]
 * @param {boolean} [options.crossDepartmentHead]
 */
export function eligibleManagers({
    managers = [],
    subject,
    subjectLevel = null,
    actorId = null,
    currentManagerId = null,
    crossDepartmentHead = false,
}) {
    if (!Array.isArray(managers) || managers.length === 0) return [];

    const sameId = (a, b) => a !== null && a !== undefined && b !== null && b !== undefined && String(a) === String(b);
    const levelOf = (person) => person?.designation_hierarchy_level ?? UNRANKED;
    const sameDepartment = (person) => sameId(person.department_id, subject?.department_id);
    const subjectRank = subjectLevel ?? UNRANKED;

    // Ranked comparison only exists when the employee has a designation.
    const seniorColleagues = managers.filter(
        (person) => !sameId(person.id, subject?.id) && sameDepartment(person) && levelOf(person) < subjectRank,
    );
    const isDepartmentHead = crossDepartmentHead && subjectRank !== UNRANKED && seniorColleagues.length === 0;

    return managers.filter((person) => {
        if (sameId(person.id, subject?.id)) return false;
        if (sameId(person.id, currentManagerId) || sameId(person.id, actorId)) return true;

        const rank = levelOf(person);
        if (subjectRank === UNRANKED || rank === UNRANKED) return sameDepartment(person);
        if (rank >= subjectRank) return false;

        return sameDepartment(person) || isDepartmentHead;
    });
}

export default eligibleManagers;
