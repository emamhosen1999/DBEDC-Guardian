// @vitest-environment jsdom
import React from 'react';
import { createRoot } from 'react-dom/client';
import { act } from 'react-dom/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: {} }),
    Link: ({ href, children, ...rest }) => <a href={href} {...rest}>{children}</a>,
}));

const { default: MapPopup } = await import('../MapPopup.jsx');
const { default: MapRosterDrawer } = await import('../MapRosterDrawer.jsx');
const { default: LayerList } = await import('../LayerList.jsx');
const { default: MapStatsRibbon } = await import('../MapStatsRibbon.jsx');

let root; let host;
afterEach(() => { act(() => root?.unmount()); host?.remove(); });
function mount(ui) { host = document.createElement('div'); document.body.appendChild(host); root = createRoot(host); act(() => root.render(ui)); }

const person = {
    id: 'workforce.punches:127', title: 'Ayesha Rahman', fields: [], tone: 'good', lat: 23.9, lng: 90.4, shape: 'point',
    person: {
        employee_id: '127', name: 'Ayesha Rahman', designation: 'Site Engineer', department: 'Works', status: 'active', date: '2026-10-01', cycles: 1, requires_photo: true,
        punch_in: { lat: 23.9, lng: 90.4, address: 'Gazipur', time: '07:23:30', photo: '/photo-in.jpg' }, punch_out: null, timesheet: '/attendance?date=2026-10-01',
    },
};

describe('map popup', () => {
    it('carries the timesheet popup content: name, designation, status, punch place and photo, timesheet link', () => {
        const onPhoto = vi.fn();
        mount(<MapPopup feature={person} layer={{ label: 'Attendance locations' }} onClose={() => {}} onPhoto={onPhoto} onDetails={() => {}} />);
        expect(host.textContent).toContain('Ayesha Rahman');
        expect(host.textContent).toContain('Site Engineer');
        expect(host.textContent).toContain('Active');
        expect(host.textContent).toContain('07:23');
        expect(host.textContent).toContain('Gazipur');
        expect(host.querySelector('a[href="/attendance?date=2026-10-01"]')).not.toBeNull();
        act(() => host.querySelector('.cy-thumb').dispatchEvent(new MouseEvent('click', { bubbles: true })));
        expect(onPhoto).toHaveBeenCalledWith(expect.objectContaining({ url: '/photo-in.jpg' }));
    });

    it('lists a register feature as label/value rows with its drill-down link', () => {
        const f = { id: 'om.defects:1', title: 'DEF-1', tone: 'warn', shape: 'point', fields: [{ label: 'Severity', value: 'Medium' }] };
        mount(<MapPopup feature={f} layer={{ label: 'Defects', href: '/om/defects' }} onClose={() => {}} drill="/om/defects" />);
        expect(host.querySelector('dt').textContent).toBe('Severity');
        expect(host.querySelector('a').getAttribute('href')).toBe('/om/defects');
    });
});

describe('roster drawer', () => {
    it('filters by status and search', () => {
        const other = { ...person, id: 'x', person: { ...person.person, employee_id: '9', name: 'Bilal Khan', status: 'completed' } };
        mount(<MapRosterDrawer people={[person, other]} selectedId={null} onSelect={() => {}} onClose={() => {}} />);
        expect(host.querySelectorAll('.cy-drawer__item')).toHaveLength(2);
        const done = [...host.querySelectorAll('button.cy-map__btn')].find((b) => b.textContent === 'Done');
        act(() => done.dispatchEvent(new MouseEvent('click', { bubbles: true })));
        expect(host.querySelectorAll('.cy-drawer__item')).toHaveLength(1);
        expect(host.textContent).toContain('Bilal Khan');
    });
});

describe('layer list and ribbon', () => {
    it('groups layers, shows their counts, and toggles', () => {
        const toggle = vi.fn();
        mount(<LayerList layers={[{ key: 'om.defects', label: 'Defects', group: 'om', count: 71, tone: 'warn' }]} groups={{ om: 'Operations & maintenance' }} hidden={new Set()} onToggle={toggle} onSetAll={() => {}} />);
        expect(host.textContent).toContain('Operations & maintenance');
        expect(host.textContent).toContain('71');
        act(() => host.querySelector('input[type=checkbox]').dispatchEvent(new MouseEvent('click', { bubbles: true })));
        expect(toggle).toHaveBeenCalledWith('om.defects');
    });

    it('drops a ribbon cell that has no value instead of showing a dash', () => {
        mount(<MapStatsRibbon items={[{ label: 'Layers', value: '3/3' }, { label: 'Staff', value: null }]} />);
        expect(host.querySelectorAll('.cy-ribbon__cell')).toHaveLength(1);
    });
});
