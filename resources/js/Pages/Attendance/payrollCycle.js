import dayjs from 'dayjs';

const fmt = (d) => d.format('YYYY-MM-DD');

/** The payroll cycle containing `on`: the 26th of the previous month to the 25th (PHP twin: AttendanceRegisterService::payrollCycle). */
export const payrollCycle = (on = dayjs()) => {
    const d = dayjs(on);
    const start = d.date() >= 26 ? d.date(26) : d.subtract(1, 'month').date(26);
    return { from: fmt(start), to: fmt(start.add(1, 'month').date(25)) };
};

/** Quick period choices every attendance export offers. */
export const EXPORT_PERIODS = [
    { value: 'cycle', label: 'Payroll cycle (26th–25th)' },
    { value: 'month', label: 'This month' },
    { value: 'last_month', label: 'Last month' },
];

export const resolveExportPeriod = (value, on = dayjs()) => {
    const d = dayjs(on);
    if (value === 'month') return { from: fmt(d.startOf('month')), to: fmt(d.endOf('month')) };
    if (value === 'last_month') { const l = d.subtract(1, 'month'); return { from: fmt(l.startOf('month')), to: fmt(l.endOf('month')) }; }
    return payrollCycle(d);
};

/** Longest period the server accepts, per format (AttendanceRegisterService::MAX_DAYS_*). */
export const MAX_EXPORT_DAYS = { excel: 366, pdf: 93 };

export const periodDays = (from, to) => dayjs(to).diff(dayjs(from), 'day') + 1;
