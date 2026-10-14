// @vitest-environment jsdom
import React, { useState } from 'react';
import { createRoot } from 'react-dom/client';
import { act } from 'react-dom/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { Alert, Checkbox, DateInput, DateRange, FormField, Input, Segmented, SelectInput, Switch, Textarea } from '../Form.jsx';
import Button from '../Button.jsx';

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

describe('Cyber form controls', () => {
    it('FormField ties its label to the control and announces an error', () => {
        mount(<FormField label="Name" htmlFor="n" error="Required" required><Input id="n" /></FormField>);
        const label = host.querySelector('label');
        expect(label.getAttribute('for')).toBe('n');
        expect(label.textContent).toContain('Name');
        expect(host.querySelector('[role=alert]').textContent).toBe('Required');
    });

    it('Checkbox reports a boolean and is reachable through its label', () => {
        const spy = vi.fn();
        mount(<Checkbox label="Grant comp-off" checked={false} onCheckedChange={spy} />);
        click(host.querySelector('label'));
        expect(spy).toHaveBeenCalledWith(true);
    });

    it('Switch is exposed as role=switch', () => {
        mount(<Switch label="Active" checked onCheckedChange={() => {}} />);
        const el = host.querySelector('input[role=switch]');
        expect(el.checked).toBe(true);
    });

    it('SelectInput passes the chosen value, not the event', () => {
        const spy = vi.fn();
        mount(<SelectInput aria-label="Type" value="a" onChange={spy} options={[{ value: 'a', label: 'A' }, { value: 'b', label: 'B' }]} />);
        const select = host.querySelector('select');
        act(() => { select.value = 'b'; select.dispatchEvent(new Event('change', { bubbles: true })); });
        expect(spy).toHaveBeenCalledWith('b');
    });

    it('Textarea and Input carry aria-invalid when invalid', () => {
        mount(<><Input aria-label="a" invalid /><Textarea aria-label="b" invalid /></>);
        expect([...host.querySelectorAll('[aria-invalid=true]')]).toHaveLength(2);
    });

    it('DateInput keeps the old picker contract (time and date strings)', () => {
        const spy = vi.fn();
        function Harness() {
            const [v, setV] = useState('09:00');
            return <DateInput mode="time" label="Start" value={v} onChange={(x) => { spy(x); setV(x); }} />;
        }
        mount(<Harness />);
        const el = host.querySelector('input[type=time]');
        expect(el.value).toBe('09:00');
        expect(el.getAttribute('aria-label')).toBe('Start');
    });

    it('DateRange limits each end by the other', () => {
        mount(<DateRange value={{ start: '2026-10-01', end: '2026-10-20' }} onChange={() => {}} />);
        const [from, to] = host.querySelectorAll('input[type=date]');
        expect(from.getAttribute('max')).toBe('2026-10-20');
        expect(to.getAttribute('min')).toBe('2026-10-01');
    });

    it('Alert can be dismissed and keeps a live role', () => {
        const spy = vi.fn();
        mount(<Alert tone="danger" onDismiss={spy}>Boom</Alert>);
        expect(host.querySelector('[role=alert]')).not.toBeNull();
        click(host.querySelector('button[aria-label=Dismiss]'));
        expect(spy).toHaveBeenCalled();
    });

    it('Segmented marks the selected option with aria-pressed', () => {
        const spy = vi.fn();
        mount(<Segmented label="View" value="grid" onChange={spy} options={[{ value: 'grid', label: 'Grid' }, { value: 'employee', label: 'Per employee' }]} />);
        const [grid, emp] = host.querySelectorAll('button');
        expect(grid.getAttribute('aria-pressed')).toBe('true');
        click(emp);
        expect(spy).toHaveBeenCalledWith('employee');
    });

    it('Button loading disables it and marks it busy', () => {
        mount(<Button loading>Save</Button>);
        const b = host.querySelector('button');
        expect(b.disabled).toBe(true);
        expect(b.getAttribute('aria-busy')).toBe('true');
    });
});
