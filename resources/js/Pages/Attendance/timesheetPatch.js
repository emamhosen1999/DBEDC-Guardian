/**
 * Pure optimistic patch for the daily-timesheet cache.
 *
 * Time-correction edits a single punch's punch_in / punch_out on an existing
 * attendance row. This applies that edit in place so the visible row updates
 * instantly, without refetching the whole list. The mutation reconciles with
 * the server on settle.
 *
 * @param {object} oldData      The cached daily-timesheet payload ({ attendances, total, ... }).
 * @param {object} variables    { attendanceId: <punch id>, data: { punchin?, punchout? } }
 * @returns {object} a new payload with the targeted punch patched (or oldData unchanged).
 */
export function patchTimesheetPunch(oldData, { attendanceId, data } = {}) {
    if (!oldData || !Array.isArray(oldData.attendances)) return oldData;

    const nextIn = data?.punchin;
    const nextOut = data?.punchout;
    if (nextIn === undefined && nextOut === undefined) return oldData;

    let changed = false;
    const attendances = oldData.attendances.map((att) => {
        if (!Array.isArray(att.punches) || !att.punches.some((p) => p.id === attendanceId)) {
            return att;
        }
        return {
            ...att,
            punches: att.punches.map((p) => {
                if (p.id !== attendanceId) return p;
                changed = true;
                return {
                    ...p,
                    ...(nextIn !== undefined ? { punch_in: nextIn } : {}),
                    ...(nextOut !== undefined ? { punch_out: nextOut } : {}),
                };
            }),
        };
    });

    return changed ? { ...oldData, attendances } : oldData;
}
