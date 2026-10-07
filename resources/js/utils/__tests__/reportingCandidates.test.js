import { describe, expect, it } from 'vitest';
import { groupCandidates, NO_DEPARTMENT } from '@/utils/reportingCandidates';

const people = [
    { id: '1', name: 'Abul Bashar', designation: 'Head', department: 'QC' },
    { id: '2', name: 'Wang Fu', designation: 'Manager', department: 'O&M' },
    { id: '3', name: 'Rina', designation: 'Engineer', department: 'QC' },
    { id: '4', name: 'Zed', designation: null, department: null },
];

describe('groupCandidates', () => {
    it('groups by department with no-department last', () => {
        const groups = groupCandidates(people);
        expect(groups.map((g) => g.department)).toEqual(['O&M', 'QC', NO_DEPARTMENT]);
        expect(groups[1].people.map((p) => p.id)).toEqual(['1', '3']);
    });

    it('searches name, designation and department case-insensitively', () => {
        expect(groupCandidates(people, 'wang')[0].people[0].id).toBe('2');
        expect(groupCandidates(people, 'ENGINEER')[0].people[0].id).toBe('3');
        expect(groupCandidates(people, 'qc')[0].people).toHaveLength(2);
        expect(groupCandidates(people, 'nobody')).toEqual([]);
    });
});
