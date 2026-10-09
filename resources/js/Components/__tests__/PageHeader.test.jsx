// @vitest-environment jsdom
import React from 'react';
import { createRoot } from 'react-dom/client';
import { act } from 'react-dom/test-utils';
import { afterEach, describe, expect, it } from 'vitest';

import PageHeader, { MAX_CHIPS } from '../PageHeader.jsx';

let root;
let host;
afterEach(() => { act(() => root?.unmount()); host?.remove(); });

function mount(ui) {
    host = document.createElement('div');
    document.body.appendChild(host);
    root = createRoot(host);
    act(() => root.render(ui));
}

const chips = (n) => Array.from({ length: n }, (_, i) => ({ value: i + 1, label: `Chip ${i + 1}`, tone: 'default' }));

describe('PageHeader chips', () => {
    it('shows every chip when there are five or fewer and no "+N" menu', () => {
        mount(<PageHeader title="Command" chips={chips(MAX_CHIPS)} />);
        expect(host.querySelectorAll('.dl-chip')).toHaveLength(MAX_CHIPS);
        expect(host.querySelector('.dl-chip--more')).toBeNull();
        expect(host.querySelector('.dl-chip--extra')).toBeNull();
    });

    it('keeps the first five in priority order and folds the rest into a "+N" menu', () => {
        mount(<PageHeader title="Command" chips={chips(7)} />);
        const extra = host.querySelectorAll('.dl-chip--extra');
        expect(extra).toHaveLength(2);
        expect([...host.querySelectorAll('.dl-chip:not(.dl-chip--extra):not(.dl-chip--more) .dl-chip__label')].map((e) => e.textContent)).toEqual(['Chip 1', 'Chip 2', 'Chip 3', 'Chip 4', 'Chip 5']);
        const more = host.querySelector('.dl-chip--more');
        expect(more.textContent).toContain('+2');

        act(() => { more.querySelector('button').dispatchEvent(new MouseEvent('click', { bubbles: true })); });
        expect([...host.querySelectorAll('.dl-chip-menu li')].map((li) => li.textContent)).toEqual(['Chip 66', 'Chip 77']);
        act(() => { document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' })); });
        expect(host.querySelector('.dl-chip-menu')).toBeNull();
    });

    it('drops chips without a value and gives every chip a full-text tooltip', () => {
        mount(<PageHeader title="Command" chips={[{ value: null, label: 'Empty' }, { value: 'A very long value', label: 'Long' }]} />);
        expect(host.querySelectorAll('.dl-chip')).toHaveLength(1);
        expect(host.querySelector('.dl-chip').getAttribute('title')).toBe('Long: A very long value');
    });
});
