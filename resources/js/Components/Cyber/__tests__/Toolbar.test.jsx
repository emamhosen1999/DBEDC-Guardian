// @vitest-environment jsdom
import React from 'react';
import { createRoot } from 'react-dom/client';
import { act } from 'react-dom/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { Field, IconButton, Select, Toolbar, ToolbarGroup } from '../Toolbar.jsx';
import StatStrip, { spansFor, tilesPerRow } from '../StatStrip.jsx';
import Tabs from '../Tabs.jsx';

let root;
let host;
afterEach(() => { act(() => root?.unmount()); host?.remove(); });
function mount(ui) {
    host = document.createElement('div');
    document.body.appendChild(host);
    root = createRoot(host);
    act(() => root.render(ui));
}

describe('Toolbar controls', () => {
    it('names every control for assistive technology and reports changes', () => {
        const onSelect = vi.fn();
        const onClick = vi.fn();
        mount(
            <Toolbar label="Filters">
                <ToolbarGroup>
                    <Field icon="search" type="search" label="Search people" value="" onChange={() => {}} />
                    <Select label="Department" value="all" onChange={onSelect} options={[{ value: 'all', label: 'All' }, { value: '2', label: 'Ops' }]} />
                </ToolbarGroup>
                <ToolbarGroup end><IconButton icon="arrow-clockwise" label="Refresh" onClick={onClick} /></ToolbarGroup>
            </Toolbar>,
        );
        expect(host.querySelector('[role=toolbar]').getAttribute('aria-label')).toBe('Filters');
        expect(host.querySelector('input').closest('label').textContent).toContain('Search people');
        const select = host.querySelector('select');
        expect(select.getAttribute('aria-label')).toBe('Department');
        act(() => { select.value = '2'; select.dispatchEvent(new Event('change', { bubbles: true })); });
        expect(onSelect).toHaveBeenCalledWith('2');
        const button = host.querySelector('button[aria-label=Refresh]');
        act(() => { button.dispatchEvent(new MouseEvent('click', { bubbles: true })); });
        expect(onClick).toHaveBeenCalled();
    });
});

describe('StatStrip', () => {
    it('fills every row edge to edge with equal tiles', () => {
        expect(spansFor(5, 5)).toEqual([84, 84, 84, 84, 84]);
        expect(spansFor(5, 3)).toEqual([140, 140, 140, 210, 210]);
        expect(spansFor(5, 2)).toEqual([210, 210, 210, 210, 420]);
        expect(tilesPerRow(1440, 5)).toBe(5);
        expect(tilesPerRow(800, 5)).toBe(3);
        expect(tilesPerRow(390, 5)).toBe(2);
    });

    it('shows a dash for a figure that is still loading, never a zero', () => {
        mount(<StatStrip items={[{ key: 'a', label: 'Present', value: undefined }, { key: 'b', label: 'Absent', value: 0 }]} busy />);
        const values = [...host.querySelectorAll('.cy-stat__value')].map((n) => n.textContent);
        expect(values).toEqual(['—', '0']);
        expect(host.querySelector('[role=list]').getAttribute('aria-busy')).toBe('true');
    });
});

describe('Tabs additions', () => {
    it('takes a modifier class and colours a count by tone', () => {
        mount(<Tabs idPrefix="t" label="x" className="cy-tabs--scroll" value="a" onChange={() => {}} tabs={[{ key: 'a', label: 'Present', count: 3, tone: 'success' }, { key: 'b', label: 'Other' }]} />);
        expect(host.querySelector('.cy-tabs').classList.contains('cy-tabs--scroll')).toBe(true);
        expect(host.querySelector('.cy-tabs__count').getAttribute('data-tone')).toBe('success');
    });
});
