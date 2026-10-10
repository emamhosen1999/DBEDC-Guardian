// @vitest-environment jsdom
import React from 'react';
import { createRoot } from 'react-dom/client';
import { act } from 'react-dom/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { auth: { user: { employee_id: 'E-1' } } } }),
    Link: ({ href, children, ...rest }) => <a href={href} {...rest}>{children}</a>,
}));

const { default: Card } = await import('../Card.jsx');

let root;
let host;
beforeEach(() => { window.localStorage.clear(); });
afterEach(() => { act(() => root?.unmount()); host?.remove(); });

function mount(ui) {
    host = document.createElement('div');
    document.body.appendChild(host);
    root = createRoot(host);
    act(() => root.render(ui));
}
const click = (el) => act(() => { el.dispatchEvent(new MouseEvent('click', { bubbles: true })); });

describe('Cyber Card header tools', () => {
    it('offers minimize and maximize, and never a close tool', () => {
        mount(<Card title="Team today"><p>body</p></Card>);
        const labels = [...host.querySelectorAll('button')].map((b) => b.getAttribute('aria-label'));
        expect(labels).toEqual(['Collapse Team today', 'Full screen: Team today']);
        expect(host.querySelector('[aria-label*="lose"], [aria-label*="emove"]')).toBeNull();
    });

    it('draws the drill-down as the first header tool, an icon link with an accessible name', () => {
        mount(<Card title="Team today" href="/attendance"><p>body</p></Card>);
        const tools = [...host.querySelectorAll('.dl-card__tools > *')];
        expect(tools.map((t) => t.tagName)).toEqual(['A', 'BUTTON', 'BUTTON']);
        expect(tools[0].getAttribute('href')).toBe('/attendance');
        expect(tools[0].getAttribute('aria-label')).toBe('Open Team today');
        expect(host.querySelector('.dl-card__sub')).toBeNull();
    });

    it('remembers the minimized state per viewer, but never the maximized state', () => {
        mount(<Card id="widget:team.today" title="Team today"><p>body</p></Card>);
        click(host.querySelector('button[aria-label^="Collapse"]'));
        expect(window.localStorage.getItem('cy-card:E-1:widget:team.today')).toBe('1');
        click(host.querySelector('button[aria-label^="Full screen"]'));
        expect(host.querySelector('.dl-card--expanded')).not.toBeNull();
        expect(Object.keys(window.localStorage)).toEqual(['cy-card:E-1:widget:team.today']);

        act(() => root.unmount());
        host.remove();
        mount(<Card id="widget:team.today" title="Team today"><p>body</p></Card>);
        expect(host.querySelector('.dl-card--collapsed')).not.toBeNull();
        expect(host.querySelector('.dl-card--expanded')).toBeNull();
    });

    it('closes the maximized card with Escape and returns focus to its toggle', () => {
        mount(<Card id="x" title="Panel"><p>body</p></Card>);
        const toggle = host.querySelector('button[aria-label^="Full screen"]');
        click(toggle);
        expect(document.activeElement).toBe(host.querySelector('section'));
        act(() => { document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' })); });
        expect(host.querySelector('.dl-card--expanded')).toBeNull();
        expect(document.activeElement).toBe(host.querySelector('button[aria-label^="Full screen"]'));
    });
});
