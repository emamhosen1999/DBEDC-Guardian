// @vitest-environment jsdom
import React from 'react';
import { createRoot } from 'react-dom/client';
import { act } from 'react-dom/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: {} }),
    Link: ({ href, children, ...rest }) => <a href={href} {...rest}>{children}</a>,
}));
vi.mock('@/Components/ErrorBoundary/ErrorBoundary', () => ({ default: ({ children }) => <>{children}</> }));

const { WidgetGrid, widgetByKey, statValue, packRows, kpiRows, STRIP_COLUMNS } = await import('../WidgetGrid.jsx');

const payload = {
    sections: [{ key: 'team', label: 'My team' }, { key: 'project', label: 'Project delivery' }],
    widgets: [
        { key: 'team.today', title: 'Team today', section: 'team', type: 'stats', href: '/attendance', error: null,
            data: { scope: { label: 'Whole organization' }, stats: [{ key: 'present', label: 'Present', value: 12, tone: 'good', href: '/attendance' }, { key: 'absent', label: 'Absent', value: 3, tone: 'crit' }] } },
        { key: 'team.broken', title: 'Broken', section: 'team', type: 'stats', href: null, data: null, error: 'This widget is temporarily unavailable.' },
        { key: 'main.command', title: 'Command center', section: 'project', type: 'command', href: null, error: null, data: { project: {} } },
    ],
};

let root;
let host;
afterEach(() => { act(() => root?.unmount()); host?.remove(); });

function mount(ui) {
    host = document.createElement('div');
    document.body.appendChild(host);
    root = createRoot(host);
    act(() => root.render(ui));
}

describe('WidgetGrid', () => {
    it('renders real figures, scope, drill-down links and a per-card error state', () => {
        mount(<WidgetGrid payload={payload} />);
        expect(host.textContent).toContain('Team today');
        expect(host.textContent).toContain('Whole organization');
        expect(host.textContent).toContain('12');
        expect(host.querySelector('a[href="/attendance"]')).not.toBeNull();
        expect(host.textContent).toContain('temporarily unavailable');
    });

    it('does not render the command widget (the page owns it) or empty sections', () => {
        mount(<WidgetGrid payload={payload} />);
        expect(host.textContent).not.toContain('Command center');
        expect(host.textContent).not.toContain('Project delivery');
    });

    it('honours skip and shows a loading state before the payload arrives', () => {
        mount(<WidgetGrid payload={payload} skip={['team.today']} />);
        expect(host.textContent).not.toContain('Team today');
        act(() => root.render(<WidgetGrid payload={null} />));
        expect(host.querySelector('[aria-busy="true"]')).not.toBeNull();
    });

    it('reads a stat value and ignores errored widgets', () => {
        expect(statValue(payload, 'team.today', 'absent')).toBe(3);
        expect(statValue(payload, 'team.today', 'missing')).toBeNull();
        expect(widgetByKey(payload, 'team.broken')).toBeNull();
    });
});

describe('row packing', () => {
    it('fills every row to twelve columns without leaving a gap', () => {
        const widgets = [4, 8, 12, 6, 6, 6, 4, 8, 6].map((span, i) => ({ key: `w${i}`, span }));
        packRows(widgets).forEach((row) => expect(row.reduce((sum, c) => sum + c.span, 0)).toBe(12));
    });

    it('stretches a lone widget and a short last row to the full width', () => {
        const rows = packRows([{ key: 'a', span: 8 }]);
        expect(rows).toHaveLength(1);
        expect(rows[0][0].span).toBe(12);
        const last = packRows([{ key: 'a', span: 6 }, { key: 'b', span: 6 }, { key: 'c', span: 4 }]).at(-1);
        expect(last.reduce((sum, c) => sum + c.span, 0)).toBe(12);
    });

    it('splits 7 KPI tiles into 4 + 3 (and 2-up on a phone) with equal tiles in every row', () => {
        const rowsOf = (spans) => { const rows = []; let used = 0; let row = []; spans.forEach((s) => { row.push(s); used += s; if (used === STRIP_COLUMNS) { rows.push(row); row = []; used = 0; } }); return rows; };
        expect(rowsOf(kpiRows(7, 7)).map((r) => r.length)).toEqual([7]);
        expect(rowsOf(kpiRows(7, 4)).map((r) => r.length)).toEqual([4, 3]);
        expect(rowsOf(kpiRows(7, 2)).map((r) => r.length)).toEqual([2, 2, 2, 1]);
        [1, 2, 3, 4, 5, 6, 7].forEach((m) => expect(new Set(kpiRows(m, m)).size).toBe(1));
    });
});
