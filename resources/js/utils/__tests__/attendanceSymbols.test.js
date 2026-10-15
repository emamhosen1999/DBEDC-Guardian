import { describe, expect, it } from 'vitest';

import { MARKS_LEGEND_TEXT, MARK_LEGEND, markAttrs, SYMBOL, SYMBOL_KEY, SYMBOL_KEY_TEXT, SYMBOL_TOTALS, TERM, festivalFill, isKnownSymbol, symbolLabel, symbolTone, symbolTotals } from '../attendanceSymbols';
import { payrollCycle } from '@/Pages/Attendance/payrollCycle';

describe('the register symbol key', () => {
    it('has the eleven symbols and the key row the register prints', () => {
        expect(SYMBOL_KEY).toHaveLength(11);
        expect(SYMBOL_KEY_TEXT).toBe('Symbol Key :    √ = Present     ▼ = Absent     / = Weekly Off / Rest Day     · = Not Yet Recorded     § = Personal Leave     × = Sick Leave     □ = Marital Leave     ■ = Funeral Leave     ◎ = Maternity Leave     △ = Annual Holiday     # = Festival Holiday');
    });

    it('never reuses the day-off slash for an unknown leave', () => {
        expect(SYMBOL.UNKNOWN_LEAVE).not.toBe(SYMBOL.DAY_OFF);
        expect(isKnownSymbol(SYMBOL.UNKNOWN_LEAVE)).toBe(true);
        expect(symbolLabel(SYMBOL.UNKNOWN_LEAVE)).toBe(TERM.ON_LEAVE);
        expect(symbolLabel('?')).toBe('No data');
    });

    it('colours are a secondary cue by meaning', () => {
        expect(symbolTone(SYMBOL.PRESENT)).toBe('success');
        expect(symbolTone(SYMBOL.ABSENT)).toBe('danger');
        expect(symbolTone(SYMBOL.PERSONAL)).toBe('leave');
        expect(symbolTone('?')).toBe('none');
    });

    it('festival colouring: # taken is pink, √ worked is green, only on a festival date', () => {
        expect(festivalFill('#', true)).toBe('taken');
        expect(festivalFill('√', true)).toBe('worked');
        expect(festivalFill('√', false)).toBeNull();
        expect(festivalFill('▼', true)).toBeNull();
    });

    it('counts the eight register columns from day cells', () => {
        const totals = symbolTotals([{ symbol: '▼' }, { status: '▼' }, '§', '#', '√', 'L']);
        expect(totals.map((t) => t.heading)).toEqual(SYMBOL_TOTALS.map(([h]) => h));
        expect(Object.fromEntries(totals.map((t) => [t.heading, t.count]))).toEqual({ Abs: 2, Per: 1, Sick: 0, Mar: 0, Fun: 0, Mat: 0, Ann: 0, Fest: 1 });
    });
});

describe('overtime, leave and rest-day marks', () => {
    it('prints the mark legend exactly as the register does', () => {
        expect(MARK_LEGEND.map(([, t]) => t)).toEqual([
            'green √ = overtime approved', 'yellow = worked on a rest day/holiday, not claimed', 'light red = overtime declined',
            'bold border = worked on rest day', 'bold symbol = on leave',
        ]);
        expect(MARKS_LEGEND_TEXT.startsWith('Marks :    green √ = overtime approved     yellow')).toBe(true);
    });

    it('turns the server marks into DOM attributes', () => {
        expect(markAttrs({ marks: { fill: 'ot_unapplied', font: 'ot_green', bold: false, border: true } }, '√')).toEqual({ 'data-mark-fill': 'ot_unapplied', 'data-mark-font': 'ot_green', 'data-mark-border': 'true' });
        expect(markAttrs({ marks: { fill: 'festival_worked', font: null, bold: false, border: false } }, '√')).toEqual({ 'data-festival': 'worked' });
        expect(markAttrs({ marks: { fill: null, font: null, bold: true, border: false } }, '§')).toEqual({ 'data-mark-bold': 'true' });
        // without server marks only the festival colouring applies
        expect(markAttrs({ is_festival: true }, '#')).toEqual({ 'data-festival': 'taken' });
        expect(markAttrs({}, '√')).toEqual({});
    });
});

describe('payroll cycle', () => {
    it('runs from the 26th of the previous month to the 25th', () => {
        expect(payrollCycle('2026-09-10')).toEqual({ from: '2026-08-26', to: '2026-09-25' });
        expect(payrollCycle('2026-09-26')).toEqual({ from: '2026-09-26', to: '2026-10-25' });
        expect(payrollCycle('2026-01-05')).toEqual({ from: '2025-12-26', to: '2026-01-25' });
    });
});
