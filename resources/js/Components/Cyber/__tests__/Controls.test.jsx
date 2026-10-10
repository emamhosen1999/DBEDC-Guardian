// @vitest-environment jsdom
import React, { useState } from 'react';
import { createRoot } from 'react-dom/client';
import { act } from 'react-dom/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import Pagination, { pageWindow } from '../Pagination.jsx';
import Tabs, { panelId, tabId } from '../Tabs.jsx';
import Accordion from '../Accordion.jsx';

let root;
let host;
afterEach(() => { act(() => root?.unmount()); host?.remove(); });
function mount(ui) {
    host = document.createElement('div');
    document.body.appendChild(host);
    root = createRoot(host);
    act(() => root.render(ui));
}
const click = (el) => act(() => { el.dispatchEvent(new MouseEvent('click', { bubbles: true })); });
const key = (el, k) => act(() => { el.dispatchEvent(new KeyboardEvent('keydown', { key: k, bubbles: true })); });

describe('Cyber Pagination', () => {
    it('windows at most five page numbers around the current page', () => {
        expect(pageWindow(1, 3)).toEqual([1, 2, 3]);
        expect(pageWindow(1, 20)).toEqual([1, 2, 3, 4, 5]);
        expect(pageWindow(10, 20)).toEqual([8, 9, 10, 11, 12]);
        expect(pageWindow(20, 20)).toEqual([16, 17, 18, 19, 20]);
    });

    it('renders nothing for an empty list', () => {
        mount(<Pagination pagination={{ currentPage: 1, perPage: 20, total: 0 }} />);
        expect(host.querySelector('nav')).toBeNull();
    });

    it('shows the range, marks the current page, disables Previous on page 1 and pages on click', () => {
        const onPageChange = vi.fn();
        const onRowsPerPageChange = vi.fn();
        mount(<Pagination pagination={{ currentPage: 1, perPage: 20, total: 45 }} onPageChange={onPageChange} onRowsPerPageChange={onRowsPerPageChange} />);
        expect(host.querySelector('.cy-pagination__info').textContent).toBe('1–20 of 45');
        expect(host.querySelector('[aria-label="Previous page"]').disabled).toBe(true);
        expect(host.querySelector('[aria-current="page"]').textContent).toBe('1');
        click(host.querySelector('[aria-label="Page 3"]'));
        expect(onPageChange).toHaveBeenCalledWith(3);
        click(host.querySelector('[aria-label="Next page"]'));
        expect(onPageChange).toHaveBeenCalledWith(2);
        const select = host.querySelector('select');
        act(() => { select.value = '50'; select.dispatchEvent(new Event('change', { bubbles: true })); });
        expect(onRowsPerPageChange).toHaveBeenCalledWith(50);
    });

    it('does not page while loading', () => {
        const onPageChange = vi.fn();
        mount(<Pagination pagination={{ currentPage: 2, perPage: 20, total: 100 }} loading onPageChange={onPageChange} />);
        expect(host.querySelector('[aria-label="Next page"]').disabled).toBe(true);
        click(host.querySelector('[aria-label="Page 3"]'));
        expect(onPageChange).not.toHaveBeenCalled();
    });
});

describe('Cyber Tabs', () => {
    const tabs = [{ key: 'all', label: 'All', count: 9 }, { key: 'emp', label: 'Employees', count: 8 }, { key: 'rfi', label: 'RFIs', count: 1 }];
    function Harness() {
        const [value, setValue] = useState('all');
        return <Tabs tabs={tabs} value={value} onChange={setValue} idPrefix="t" label="Groups" />;
    }

    it('exposes tablist semantics with one tab stop and linked panel ids', () => {
        mount(<Harness />);
        const buttons = [...host.querySelectorAll('[role="tab"]')];
        expect(host.querySelector('[role="tablist"]').getAttribute('aria-label')).toBe('Groups');
        expect(buttons.map((b) => b.getAttribute('aria-selected'))).toEqual(['true', 'false', 'false']);
        expect(buttons.map((b) => b.tabIndex)).toEqual([0, -1, -1]);
        expect(buttons[1].id).toBe(tabId('t', 'emp'));
        expect(buttons[1].getAttribute('aria-controls')).toBe(panelId('t', 'emp'));
    });

    it('moves selection with the arrow keys, wrapping, and with Home / End', () => {
        mount(<Harness />);
        const sel = () => host.querySelector('[aria-selected="true"]').textContent;
        key(host.querySelector('[role="tab"]'), 'ArrowRight');
        expect(sel()).toContain('Employees');
        key(host.querySelector('[aria-selected="true"]'), 'End');
        expect(sel()).toContain('RFIs');
        key(host.querySelector('[aria-selected="true"]'), 'ArrowRight');
        expect(sel()).toContain('All');
        key(host.querySelector('[aria-selected="true"]'), 'ArrowLeft');
        expect(sel()).toContain('RFIs');
    });
});

describe('Cyber Accordion', () => {
    const items = [{ key: 'a', title: 'First', content: 'one' }, { key: 'b', title: 'Second', content: 'two' }];

    it('opens one item at a time and closes the open one on a second click', () => {
        mount(<Accordion items={items} />);
        const heads = () => [...host.querySelectorAll('.cy-accordion__button')];
        const panels = () => [...host.querySelectorAll('.cy-accordion__panel')];
        expect(heads().map((b) => b.getAttribute('aria-expanded'))).toEqual(['true', 'false']);
        expect(panels().map((p) => p.hidden)).toEqual([false, true]);
        click(heads()[1]);
        expect(panels().map((p) => p.hidden)).toEqual([true, false]);
        click(heads()[1]);
        expect(panels().map((p) => p.hidden)).toEqual([true, true]);
    });

    it('links each header to its panel', () => {
        mount(<Accordion items={items} />);
        const head = host.querySelector('.cy-accordion__button');
        expect(host.querySelector(`[id="${head.getAttribute('aria-controls')}"]`)).not.toBeNull();
    });
});
