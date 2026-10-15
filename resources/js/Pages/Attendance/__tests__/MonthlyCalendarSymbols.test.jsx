// @vitest-environment jsdom
import React from 'react';
import { createRoot } from 'react-dom/client';
import { act } from 'react-dom/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { MARKS_LEGEND_TEXT, SYMBOL, SYMBOL_KEY_TEXT, SYMBOL_TOTALS } from '@/utils/attendanceSymbols';

const day = (symbol, extra = {}) => ({ status: symbol, symbol, ...extra });
const ROWS = [{
    user_id: 'E1', name: 'Test Person One',
    '2026-08-01': day('√'),
    '2026-08-02': day('▼'),
    '2026-08-03': day('/'),
    '2026-08-04': day('·'),
    '2026-08-05': day('§'),
    '2026-08-06': day('×'),
    '2026-08-07': day('□'),
    '2026-08-08': day('■'),
    '2026-08-09': day('◎'),
    '2026-08-10': day('△'),
    '2026-08-11': day('#', { is_festival: true }),
    '2026-08-12': day('√', { is_festival: true }),
    '2026-08-13': day('L'),
    '2026-08-14': day('√', { marks: { fill: 'ot_unapplied', font: null, bold: false, border: false, ot: null } }),
    '2026-08-15': day('√', { marks: { fill: 'ot_declined', font: null, bold: false, border: false, ot: 'declined' } }),
    '2026-08-16': day('√', { marks: { fill: null, font: 'ot_green', bold: false, border: false, ot: 'approved' } }),
    '2026-08-17': day('√', { marks: { fill: 'ot_unapplied', font: 'ot_green', bold: false, border: true, ot: null } }),
    '2026-08-18': day('§', { marks: { fill: null, font: null, bold: true, border: false, ot: null } }),
}];

vi.mock('@/api/queries/useAttendanceQuery', () => ({
    useMonthlySummary: () => ({ data: { data: ROWS, total: 1, last_page: 1, leaveTypes: [], leaveCounts: {}, settings: { weekend_days: ['friday'] } }, isLoading: false, refetch: vi.fn() }),
    useExportMonthlyCalendar: () => ({ isPending: false, mutateAsync: vi.fn() }),
}));
vi.mock('@/Hooks/useQueryFilters', () => ({
    useQueryFilters: () => ({ values: { m_dept: 'all', m_page: 1, m_per: 20 }, set: vi.fn(), setPage: vi.fn() }),
}));
vi.mock('@/Hooks/useMediaQuery.js', () => ({ useMediaQuery: () => false }));
vi.mock('@inertiajs/react', () => ({ usePage: () => ({ props: { auth: { permissions: ['attendance.view'] }, url: '/attendance' } }) }));
vi.mock('@/utils/toastUtils', () => ({ showToast: { success: vi.fn(), error: vi.fn(), info: vi.fn() } }));
vi.mock('../Components/MonthlySidebar', () => ({ default: () => null }));
vi.mock('../Components/DepartmentSelect', () => ({ default: () => null }));

const { default: MonthlyCalendarTab } = await import('../MonthlyCalendarTab.jsx');

let root; let host;
afterEach(() => { act(() => root?.unmount()); host?.remove(); });

async function mount() {
    host = document.createElement('div'); document.body.appendChild(host); root = createRoot(host);
    await act(async () => { root.render(<MonthlyCalendarTab selectedMonth="2026-08" onMonthChange={() => {}} departments={[]} onSummary={() => {}} />); });
}

const normalise = (t) => t.replace(/\s+/g, ' ').trim();

describe('Monthly Calendar shows the register symbols', () => {
    it('writes the shared symbol in every day cell, not a stand-in glyph', async () => {
        await mount();
        const cells = [...host.querySelectorAll('tbody .cy-mark')];
        expect(cells.slice(0, 13).map((c) => c.textContent)).toEqual(['√', '▼', '/', '·', '§', '×', '□', '■', '◎', '△', '#', '√', 'L']);
        // days with no data fall back to the not-yet-recorded symbol
        expect(cells[18].textContent).toBe(SYMBOL.NOT_YET);
        // each cell keeps a text alternative with the symbol's meaning
        expect(cells[2].getAttribute('aria-label')).toContain('Weekly Off / Rest Day');
        expect(cells[10].getAttribute('aria-label')).toContain('Festival Holiday');
    });

    it('colours festival days like the register: red date, pink # taken, green √ worked', async () => {
        await mount();
        const heads = [...host.querySelectorAll('thead th')];
        const festivalHeads = heads.filter((h) => h.classList.contains('is-festival')).map((h) => h.textContent);
        expect(festivalHeads.some((t) => t.startsWith('11'))).toBe(true);
        expect(festivalHeads.some((t) => t.startsWith('12'))).toBe(true);
        const marks = [...host.querySelectorAll('tbody .cy-mark')];
        expect(marks[10].dataset.festival).toBe('taken');
        expect(marks[11].dataset.festival).toBe('worked');
        expect(marks[0].dataset.festival).toBeUndefined();
        // colour is a secondary cue: the symbol is always the text
        expect(marks[10].textContent).toBe('#');
    });

    it('prints a symbol key worded exactly like the register', async () => {
        await mount();
        const key = host.querySelector('ul[aria-label="Symbol Key"]');
        expect(normalise(key.textContent)).toBe(normalise(SYMBOL_KEY_TEXT));
    });

    it('shows overtime, rest-day and leave marks and a legend worded like the register', async () => {
        await mount();
        const marks = [...host.querySelectorAll('tbody .cy-mark')];
        const at = (i) => marks[i].dataset;
        expect(at(13).markFill).toBe('ot_unapplied');
        expect(at(14).markFill).toBe('ot_declined');
        expect(at(15).markFont).toBe('ot_green');
        expect(at(16)).toMatchObject({ markBorder: 'true', markFont: 'ot_green', markFill: 'ot_unapplied' });
        expect(at(17).markBold).toBe('true');
        expect(marks[17].textContent).toBe('§');
        const legend = host.querySelector('ul[aria-label="Marks"]');
        expect(normalise(legend.textContent)).toBe(normalise(MARKS_LEGEND_TEXT));
    });

    it('totals the eight register columns from the day cells', async () => {
        await mount();
        const heads = [...host.querySelectorAll('thead th.cy-cal__leave')].map((h) => normalise(h.textContent));
        expect(heads).toEqual(SYMBOL_TOTALS.map(([heading, symbol]) => `${heading}(${symbol})`));
        const counts = [...host.querySelectorAll('tbody td.cy-cal__leave')].map((c) => c.textContent);
        // Abs 1, Per 1, Sick 1, Mar 1, Fun 1, Mat 1, Ann 1, Fest 1 (L is counted in none of the eight)
        expect(counts).toEqual(['1', '2', '1', '1', '1', '1', '1', '1']);
    });
});
