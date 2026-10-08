import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { buildNavSections, collectGroupPaths, filterNavPages, isGroupActive } from './navSections';

const pages = [
    { name: 'Dashboard', route: 'dashboard' },
    { name: 'Daily Works', route: 'daily-works-unified' },
    {
        name: 'Workforce',
        subMenu: [
            { name: 'Employees', route: 'employees' },
            {
                name: 'Time/Attendance',
                subMenu: [
                    { name: 'Attendances', route: 'attendance.index' },
                    { name: 'Holidays', route: 'holidays' },
                ],
            },
        ],
    },
    {
        name: 'Admin',
        subMenu: [{ name: 'Request Logs', route: 'request-logs.index' }],
    },
];

describe('buildNavSections', () => {
    it('puts top-level leaves under Navigation and turns every group into a section', () => {
        const sections = buildNavSections(pages);
        expect(sections.map((s) => s.label)).toEqual(['Navigation', 'Workforce', 'Admin']);
        expect(sections[0].items.map((i) => i.name)).toEqual(['Dashboard', 'Daily Works']);
        expect(sections[1].items.map((i) => i.name)).toEqual(['Employees', 'Time/Attendance']);
    });

    it('keeps the nav model untouched (same objects, same order)', () => {
        const sections = buildNavSections(pages);
        expect(sections[1].items).toBe(pages[2].subMenu);
        expect(sections[0].items[0]).toBe(pages[0]);
    });

    it('drops sections with nothing visible', () => {
        expect(buildNavSections([{ name: 'Empty', subMenu: [] }])).toEqual([]);
        expect(buildNavSections([])).toEqual([]);
    });

    it('collects top-level settings leaves into a trailing Settings section', () => {
        const sections = buildNavSections([...pages, { name: 'Company', route: 'company', category: 'settings' }]);
        expect(sections.at(-1)).toMatchObject({ label: 'Settings' });
    });
});

describe('filterNavPages', () => {
    it('keeps matching leaves and the groups that contain them', () => {
        const filtered = filterNavPages(pages, 'holi');
        expect(filtered).toHaveLength(1);
        expect(filtered[0].name).toBe('Workforce');
        expect(filtered[0].subMenu[0].name).toBe('Time/Attendance');
        expect(filtered[0].subMenu[0].subMenu.map((p) => p.name)).toEqual(['Holidays']);
    });

    it('keeps every child of a group whose own name matches', () => {
        const filtered = filterNavPages(pages, 'time/att');
        expect(filtered[0].subMenu[0].subMenu).toHaveLength(2);
    });

    it('returns the model unchanged for an empty term', () => {
        expect(filterNavPages(pages, '  ')).toBe(pages);
    });
});

describe('collectGroupPaths', () => {
    it('lists expandable items per depth with the paths the sidebar uses', () => {
        expect(collectGroupPaths(buildNavSections(pages))).toEqual({ 0: ['Workforce/Time/Attendance'] });
    });
});

describe('isGroupActive', () => {
    beforeEach(() => {
        globalThis.route = (name) => ({
            'attendance.index': '/attendance',
            holidays: '/holidays',
            employees: '/employees',
        })[name];
    });
    afterEach(() => { delete globalThis.route; });

    it('is true when any descendant route is active', () => {
        const group = pages[2].subMenu[1];
        expect(isGroupActive(group, '/holidays')).toBe(true);
        expect(isGroupActive(group, '/attendance/2026')).toBe(true);
        expect(isGroupActive(group, '/employees')).toBe(false);
    });
});
