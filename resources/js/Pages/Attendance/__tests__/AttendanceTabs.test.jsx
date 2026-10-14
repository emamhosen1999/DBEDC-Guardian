// @vitest-environment jsdom
import React from 'react';
import { createRoot } from 'react-dom/client';
import { act } from 'react-dom/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { auth: { user: { employee_id: 'E-1' } } } }),
    Link: ({ href, children, ...rest }) => <a href={href} {...rest}>{children}</a>,
}));

const { default: RosterCalendar } = await import('../Components/RosterCalendar.jsx');
const { default: RosterLegend } = await import('../Components/RosterLegend.jsx');
const { default: RosterCellPopover } = await import('../Components/RosterCellPopover.jsx');
const { default: RejectReasonDialog } = await import('../Components/RejectReasonDialog.jsx');
const { default: ConfirmDialog } = await import('../Components/ConfirmDialog.jsx');

let root;
let host;
afterEach(() => { act(() => root?.unmount()); host?.remove(); document.body.innerHTML = ''; });
function mount(ui) {
    host = document.createElement('div');
    document.body.appendChild(host);
    root = createRoot(host);
    act(() => root.render(ui));
}
const click = (el) => act(() => { el.dispatchEvent(new MouseEvent('click', { bubbles: true })); });
const type = (el, value) => act(() => {
    const proto = el.tagName === 'TEXTAREA' ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype;
    Object.getOwnPropertyDescriptor(proto, 'value').set.call(el, value);
    el.dispatchEvent(new Event('input', { bubbles: true }));
});

const shifts = [{ id: 1, code: 'QCD', name: 'Day', color: '#09f', start_time: '09:00', end_time: '17:00' }];
const days = ['2026-10-01', '2026-10-02'];
const roster = { 7: { name: 'Ada Lovelace', days: { '2026-10-01': { code: 'QCD', color: '#09f' }, '2026-10-02': { off: true } } } };

describe('RosterCalendar', () => {
    it('renders a table with one labelled, keyboard-reachable button per day cell and opens the cell on click', () => {
        const onCellClick = vi.fn();
        mount(<RosterCalendar roster={roster} days={days} holidays={{ '2026-10-02': 'Victory Day' }} shifts={shifts} onCellClick={onCellClick} />);
        expect(host.querySelector('table.cy-table')).not.toBeNull();
        const buttons = host.querySelectorAll('button.cy-roster__btn');
        expect(buttons).toHaveLength(2);
        expect(buttons[0].getAttribute('aria-label')).toContain('Ada Lovelace');
        expect(buttons[1].textContent).toContain('Holiday');
        click(buttons[0]);
        expect(onCellClick).toHaveBeenCalledWith('7', '2026-10-01', roster[7].days['2026-10-01']);
    });

    it('shows an honest empty state with no rows', () => {
        mount(<RosterCalendar roster={{}} days={days} shifts={shifts} />);
        expect(host.textContent).toContain('No roster data for this range');
    });
});

describe('RosterLegend', () => {
    it('lists only the shifts it is given plus Off', () => {
        mount(<RosterLegend shifts={shifts} showHoliday />);
        expect(host.textContent).toContain('QCD');
        expect(host.textContent).toContain('Off');
        expect(host.textContent).toContain('Holiday');
    });
    it('renders nothing without shifts', () => {
        mount(<RosterLegend shifts={[]} />);
        expect(host.innerHTML).toBe('');
    });
});

describe('RosterCellPopover (Cyber dialog)', () => {
    it('confirms the picked shifts and the post', () => {
        const onPick = vi.fn();
        mount(<RosterCellPopover open onOpenChange={() => {}} shifts={shifts} selectedShiftIds={[]} workLocations={[{ id: 4, name: 'Toll Office' }]} onPick={onPick} cellDate="2026-10-01" />);
        const dialog = document.body.querySelector('[role=dialog]');
        expect(dialog).not.toBeNull();
        click(dialog.querySelector('input[type=checkbox]'));
        click([...dialog.querySelectorAll('button')].find((b) => b.textContent === 'Confirm'));
        expect(onPick).toHaveBeenCalledWith([1], null);
    });

    it('warns that approved leave wins over a working shift', () => {
        mount(<RosterCellPopover open onOpenChange={() => {}} shifts={shifts} selectedShiftIds={[1]} leave={{ type: 'Casual', status: 'approved' }} onPick={() => {}} />);
        expect(document.body.querySelector('[data-testid=roster-leave-warning]').textContent).toContain('leave takes precedence');
        expect(document.body.textContent).toContain('Assign anyway');
    });

    it('renders nothing when closed', () => {
        mount(<RosterCellPopover open={false} onOpenChange={() => {}} shifts={shifts} onPick={() => {}} />);
        expect(document.body.querySelector('[role=dialog]')).toBeNull();
    });
});

describe('RejectReasonDialog', () => {
    it('does not reject without a reason, then sends the trimmed reason', () => {
        const onConfirm = vi.fn();
        mount(<RejectReasonDialog onConfirm={onConfirm} onClose={() => {}} />);
        const submit = document.body.querySelector('button[type=submit]');
        expect(submit.disabled).toBe(true);
        type(document.body.querySelector('textarea'), '  not valid  ');
        expect(submit.disabled).toBe(false);
        act(() => { document.body.querySelector('form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })); });
        expect(onConfirm).toHaveBeenCalledWith('not valid');
    });
});

describe('ConfirmDialog', () => {
    it('acts only on confirm and closes on Escape without acting', () => {
        const onConfirm = vi.fn();
        const onClose = vi.fn();
        mount(<ConfirmDialog title="Delete shift" message="Sure?" onConfirm={onConfirm} onClose={onClose} />);
        act(() => { document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true })); });
        expect(onClose).toHaveBeenCalled();
        expect(onConfirm).not.toHaveBeenCalled();
        click([...document.body.querySelectorAll('button')].find((b) => b.textContent === 'Delete'));
        expect(onConfirm).toHaveBeenCalled();
    });
});
