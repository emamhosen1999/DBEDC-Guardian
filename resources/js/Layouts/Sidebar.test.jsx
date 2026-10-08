// @vitest-environment jsdom
import React from 'react';
import { createRoot } from 'react-dom/client';
import { act } from 'react-dom/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { Theme } from '@radix-ui/themes';

let currentUrl = '/holidays';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ url: currentUrl, props: { auth: { user: { id: 'E1', name: 'Test Employee' } }, app: { version: '4.0.0' } } }),
    Link: ({ href, children, preserveState, preserveScroll, method, ...rest }) => <a href={href} {...rest}>{children}</a>,
    router: { post: vi.fn(), visit: vi.fn() },
}));

const { default: Sidebar } = await import('./Sidebar');

const ROUTES = {
    dashboard: '/dashboard',
    employees: '/employees',
    'attendance.index': '/attendance',
    holidays: '/holidays',
    'om.defects': '/om/defects',
    'om.work-orders': '/om/work-orders',
    'request-logs.index': '/request-logs',
};

const pages = [
    { name: 'Dashboard', route: 'dashboard' },
    {
        name: 'Workforce',
        subMenu: [
            { name: 'Employees', route: 'employees' },
            { name: 'Time/Attendance', subMenu: [{ name: 'Attendances', route: 'attendance.index' }, { name: 'Holidays', route: 'holidays' }] },
        ],
    },
    {
        name: 'Operations & Maintenance',
        subMenu: [
            { name: 'Routine & Maintenance', subMenu: [{ name: 'Defects', route: 'om.defects' }, { name: 'Work Orders', route: 'om.work-orders' }] },
        ],
    },
    { name: 'Admin', subMenu: [{ name: 'Request Logs', route: 'request-logs.index' }] },
];

let container;
let root;

const render = () => {
    container = document.createElement('div');
    document.body.appendChild(container);
    root = createRoot(container);
    act(() => {
        root.render(<Theme><Sidebar pages={pages} url={currentUrl} toggleSideBar={() => {}} /></Theme>);
    });
};

const itemFor = (label) => [...container.querySelectorAll('.dl-nav-item--has-sub')]
    .find((li) => li.querySelector(':scope > .dl-nav-link').textContent.includes(label));
const click = (el) => act(() => { el.dispatchEvent(new MouseEvent('click', { bubbles: true })); });

beforeEach(() => {
    currentUrl = '/holidays';
    globalThis.route = (name) => ROUTES[name] ?? '/';
    window.matchMedia = window.matchMedia || ((query) => ({ matches: false, media: query, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} }));
});

afterEach(() => {
    act(() => root.unmount());
    container.remove();
    delete globalThis.route;
});

describe('Cyber sidebar', () => {
    it('renders top-level groups as section headers with their children as items', () => {
        render();
        const headers = [...container.querySelectorAll('.dl-nav-header__title')].map((h) => h.textContent);
        expect(headers).toEqual(['Navigation', 'Workforce', 'Operations & Maintenance', 'Admin']);
        // Section headers are not interactive.
        expect(container.querySelector('.dl-nav-header button, .dl-nav-header a')).toBeNull();
        // Only nested groups are expandable.
        expect([...container.querySelectorAll('.dl-nav-item--has-sub > .dl-nav-link')].map((b) => b.textContent))
            .toEqual(['Time/Attendance', 'Routine & Maintenance']);
    });

    it('opens the group of the active route on load, without the slide-in animation', () => {
        render();
        const group = itemFor('Time/Attendance');
        expect(group.classList.contains('dl-nav-item--open')).toBe(true);
        expect(group.classList.contains('dl-nav-item--expand')).toBe(false);
        expect(group.querySelector(':scope > .dl-nav-link').getAttribute('aria-expanded')).toBe('true');
        expect(container.querySelector('a[aria-current="page"]').textContent).toBe('Holidays');
    });

    it('closes an active group on its first click, like Cyber', () => {
        render();
        const group = itemFor('Time/Attendance');
        click(group.querySelector(':scope > .dl-nav-link'));
        expect(group.classList.contains('dl-nav-item--open')).toBe(false);
        expect(group.querySelector(':scope > .dl-nav-link').getAttribute('aria-expanded')).toBe('false');
    });

    it('keeps one item open per depth and animates the one the Employee opened', () => {
        render();
        const routine = itemFor('Routine & Maintenance');
        const time = itemFor('Time/Attendance');
        click(routine.querySelector(':scope > .dl-nav-link'));
        expect(routine.classList.contains('dl-nav-item--open')).toBe(true);
        expect(routine.classList.contains('dl-nav-item--expand')).toBe(true);
        // Opening one closes the others at the same depth, the active one included.
        expect(time.classList.contains('dl-nav-item--open')).toBe(false);

        click(time.querySelector(':scope > .dl-nav-link'));
        expect(time.classList.contains('dl-nav-item--expand')).toBe(true);
        expect(routine.classList.contains('dl-nav-item--open')).toBe(false);
    });
});
