// @vitest-environment jsdom
import React from 'react';
import { createRoot } from 'react-dom/client';
import { act } from 'react-dom/test-utils';
import dayjs from 'dayjs';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { payrollCycle } from '../../payrollCycle';

const { default: AttendanceExportDialog } = await import('../AttendanceExportDialog.jsx');

let root; let host;
afterEach(() => { act(() => root?.unmount()); document.body.innerHTML = ''; });

async function mount(props) {
    host = document.createElement('div'); document.body.appendChild(host); root = createRoot(host);
    await act(async () => { root.render(<AttendanceExportDialog onClose={() => {}} {...props} />); });
}
const dates = () => [...document.querySelectorAll('input[type=date]')].map((i) => i.value);
const button = (text) => [...document.querySelectorAll('button')].find((b) => b.textContent.trim().includes(text));

describe('AttendanceExportDialog', () => {
    it('defaults to the payroll cycle and starts an Excel register export', async () => {
        const onExport = vi.fn().mockResolvedValue();
        await mount({ reports: [{ value: 'register', label: 'Register' }], onExport });
        const cycle = payrollCycle();
        expect(dates()).toEqual([cycle.from, cycle.to]);
        await act(async () => { button('Export').click(); });
        expect(onExport).toHaveBeenCalledWith({ report: 'register', type: 'excel', from: cycle.from, to: cycle.to });
    });

    it('offers the same quick periods and the PDF choice', async () => {
        const onExport = vi.fn().mockResolvedValue();
        await mount({ reports: [{ value: 'register', label: 'Register' }], onExport });
        await act(async () => { button('This month').click(); });
        expect(dates()).toEqual([dayjs().startOf('month').format('YYYY-MM-DD'), dayjs().endOf('month').format('YYYY-MM-DD')]);
        await act(async () => { button('PDF').click(); });
        await act(async () => { button('Export').click(); });
        expect(onExport.mock.calls[0][0].type).toBe('pdf');
    });

    it('blocks a PDF longer than the server accepts', async () => {
        await mount({ reports: [{ value: 'register', label: 'Register' }], initial: { from: '2026-01-01', to: '2026-12-31' }, onExport: vi.fn() });
        await act(async () => { button('PDF').click(); });
        expect(document.body.textContent).toContain('at most 93 days');
        expect(button('Export').disabled).toBe(true);
    });

    it('a one-day report takes the screen date', async () => {
        const onExport = vi.fn().mockResolvedValue();
        await mount({
            reports: [{ value: 'register', label: 'Register' }, { value: 'daily', label: 'Daily timesheet', single: true }],
            singleDate: '2026-09-02', onExport,
        });
        const select = document.querySelector('select');
        await act(async () => { select.value = 'daily'; select.dispatchEvent(new Event('change', { bubbles: true })); });
        await act(async () => { button('Export').click(); });
        expect(onExport).toHaveBeenCalledWith({ report: 'daily', type: 'excel', from: '2026-09-02', to: '2026-09-02' });
    });
});
