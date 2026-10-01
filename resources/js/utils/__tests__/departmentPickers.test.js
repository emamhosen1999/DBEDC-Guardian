import { describe, expect, it } from 'vitest';
import { resolveDepartmentScope } from '@/Hooks/useDepartmentScope';
import { deviceOptionsForLocation } from '@/utils/deviceOptions';
import { eligibleManagers } from '@/utils/reportingLine';

const ALL = [
    { id: 1, name: 'Inspection' },
    { id: 2, name: 'Operations' },
    { id: 3, name: 'Finance' },
];

describe('resolveDepartmentScope', () => {
    it('gives a global actor the page\'s full list and nothing locked', () => {
        const scope = resolveDepartmentScope({ global: true, departments: [] }, ALL);
        expect(scope.isGlobal).toBe(true);
        expect(scope.isSingle).toBe(false);
        expect(scope.departments).toHaveLength(3);
    });

    it('locks a single-department actor to that department', () => {
        const scope = resolveDepartmentScope({ global: false, departments: [{ id: 1, name: 'Inspection' }] }, ALL);
        expect(scope.isSingle).toBe(true);
        expect(scope.single).toEqual({ id: 1, name: 'Inspection' });
        expect(scope.departments.map((d) => d.id)).toEqual([1]);
    });

    it('limits a multi-department actor to exactly their departments, in the page\'s own shape', () => {
        const scope = resolveDepartmentScope({ global: false, departments: [{ id: 1, name: 'Inspection' }, { id: 3, name: 'Finance' }] }, ALL);
        expect(scope.isMulti).toBe(true);
        expect(scope.isSingle).toBe(false);
        expect(scope.departments.map((d) => d.id)).toEqual([1, 3]);
    });

    it('falls back to the scope\'s own list when the page list does not carry the department', () => {
        const scope = resolveDepartmentScope({ global: false, departments: [{ id: 9, name: 'Quality' }] }, ALL);
        expect(scope.single).toEqual({ id: 9, name: 'Quality' });
    });

    it('treats a missing scope as nothing to pick, never as everything', () => {
        const scope = resolveDepartmentScope(undefined, ALL);
        expect(scope.isGlobal).toBe(false);
        expect(scope.departments).toEqual([]);
    });

    it('treats an attendance administrator as company-wide only on the attendance pages', () => {
        const scope = { global: false, attendance: true, departments: [{ id: 1, name: 'Inspection' }] };
        expect(resolveDepartmentScope(scope, ALL).isSingle).toBe(true);
        expect(resolveDepartmentScope(scope, ALL, { attendance: true }).isGlobal).toBe(true);
        expect(resolveDepartmentScope(scope, ALL, { attendance: true }).departments).toHaveLength(3);
    });
});

describe('deviceOptionsForLocation', () => {
    const devices = [
        { id: 1, name: 'Gate A', is_active: true },
        { id: 2, name: 'Gate B', is_active: true },
        { id: 3, name: 'Old', is_active: false },
    ];
    const locations = [
        { id: 10, biometric_devices: [{ id: 2, name: 'Gate B', is_active: true }, { id: 3, name: 'Old', is_active: false }] },
        { id: 11, biometric_devices: [] },
    ];

    it('offers the active devices linked to the location', () => {
        expect(deviceOptionsForLocation(10, locations, devices).map((d) => d.id)).toEqual([2]);
    });

    it('falls back to every active device when the location has none linked', () => {
        expect(deviceOptionsForLocation(11, locations, devices).map((d) => d.id)).toEqual([1, 2]);
    });

    it('falls back to every active device when no location is chosen', () => {
        expect(deviceOptionsForLocation('', locations, devices).map((d) => d.id)).toEqual([1, 2]);
        expect(deviceOptionsForLocation(null, locations, devices).map((d) => d.id)).toEqual([1, 2]);
    });

    it('is empty only when there are no active devices at all', () => {
        expect(deviceOptionsForLocation(11, locations, [])).toEqual([]);
    });
});

describe('eligibleManagers', () => {
    const managers = [
        { id: '1537', department_id: 1, designation_hierarchy_level: 999 }, // the acting admin, no designation
        { id: '2001', department_id: 1, designation_hierarchy_level: 999 },
        { id: '2002', department_id: 1, designation_hierarchy_level: 3 },
        { id: '2003', department_id: 1, designation_hierarchy_level: 5 },
        { id: '3001', department_id: 2, designation_hierarchy_level: 1 },
    ];

    it('lists same-department people (the actor included) when nobody has a designation', () => {
        const ids = eligibleManagers({ managers, subject: { id: '9000', department_id: 1 }, actorId: '1537' }).map((m) => m.id);
        expect(ids).toEqual(['1537', '2001', '2002', '2003']);
    });

    it('never lists the employee themselves', () => {
        const ids = eligibleManagers({ managers, subject: { id: '2001', department_id: 1 }, actorId: '1537' }).map((m) => m.id);
        expect(ids).not.toContain('2001');
    });

    it('keeps the actor even outside the department', () => {
        const ids = eligibleManagers({ managers, subject: { id: '9000', department_id: 2 }, subjectLevel: 4, actorId: '1537' }).map((m) => m.id);
        expect(ids).toContain('1537');
    });

    it('compares rank only where both sides have a designation', () => {
        const ids = eligibleManagers({ managers, subject: { id: '9000', department_id: 1 }, subjectLevel: 4, actorId: '1537' }).map((m) => m.id);
        // 2002 (level 3) outranks level 4; 2003 (level 5) does not; the unranked colleague 2001 qualifies as a colleague.
        expect(ids).toContain('2002');
        expect(ids).not.toContain('2003');
        expect(ids).toContain('1537');
    });

    it('keeps an existing manager visible in their own picker', () => {
        const ids = eligibleManagers({ managers, subject: { id: '9000', department_id: 1 }, subjectLevel: 4, currentManagerId: '2003' }).map((m) => m.id);
        expect(ids).toContain('2003');
    });

    it('lets the most senior person of a department report across departments when allowed', () => {
        const withCross = eligibleManagers({ managers, subject: { id: '2002', department_id: 1 }, subjectLevel: 3, crossDepartmentHead: true }).map((m) => m.id);
        expect(withCross).toContain('3001');
        const without = eligibleManagers({ managers, subject: { id: '2002', department_id: 1 }, subjectLevel: 3 }).map((m) => m.id);
        expect(without).not.toContain('3001');
    });

    it('keeps string employee ids intact (no numeric coercion)', () => {
        const ids = eligibleManagers({
            managers: [{ id: '0123', department_id: 1, designation_hierarchy_level: 999 }],
            subject: { id: '0999', department_id: 1 },
        }).map((m) => m.id);
        expect(ids).toEqual(['0123']);
    });
});
