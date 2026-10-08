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

const { WidgetGrid, widgetByKey, statValue } = await import('../WidgetGrid.jsx');

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
