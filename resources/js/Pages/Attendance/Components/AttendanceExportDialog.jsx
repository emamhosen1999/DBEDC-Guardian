import React, { useId, useState } from 'react';

import { Alert, Button, DateRange, FormField, Icon, Segmented } from '@/Components/Cyber';
import Dialog from '@/Components/Cyber/Map/Dialog.jsx';

import { EXPORT_PERIODS, MAX_EXPORT_DAYS, payrollCycle, periodDays, resolveExportPeriod } from '../payrollCycle';

const FORMATS = [
    { value: 'excel', label: 'Excel', icon: 'file-earmark-excel' },
    { value: 'pdf', label: 'PDF', icon: 'file-earmark-pdf' },
];

/**
 * The one export control for every attendance report: a from/to period (payroll cycle by default),
 * the report to export and the Excel/PDF choice. Reports the chosen values; the caller starts the export.
 *
 * reports: [{ value, label, help?, single? }] - `single` reports cover one day (the first date only).
 */
export default function AttendanceExportDialog({ title = 'Export attendance', reports, initial, singleDate, onClose, onExport }) {
    const labelled = useId();
    const [report, setReport] = useState(reports[0].value);
    const [range, setRange] = useState(() => {
        const base = initial?.from && initial?.to ? { from: initial.from, to: initial.to } : payrollCycle();
        return { start: base.from, end: base.to };
    });
    const [format, setFormat] = useState('excel');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);

    const chosen = reports.find((r) => r.value === report) ?? reports[0];
    const days = range.start && range.end ? periodDays(range.start, range.end) : 0;
    const max = MAX_EXPORT_DAYS[format];
    const tooLong = !chosen.single && days > max;
    const invalid = !range.start || !range.end || days < 1;

    // A one-day report takes the screen's date; any other report goes back to the payroll cycle.
    const chooseReport = (value) => {
        setReport(value);
        const next = reports.find((r) => r.value === value);
        if (next?.single) setRange({ start: singleDate ?? range.start, end: singleDate ?? range.start });
        else if (chosen.single) { const c = payrollCycle(); setRange({ start: c.from, end: c.to }); }
    };

    const pick = (value) => {
        const p = resolveExportPeriod(value);
        setRange({ start: p.from, end: p.to });
    };

    const go = async () => {
        setBusy(true);
        setError(null);
        try {
            await onExport({ report, type: format, from: range.start, to: chosen.single ? range.start : range.end });
            onClose();
        } catch (e) {
            setError(e?.message || 'The export could not be started.');
        } finally {
            setBusy(false);
        }
    };

    return (
        <Dialog title={title} onClose={onClose} className="cy-modal" labelledBy={labelled}>
            <div className="cy-modal__body">
                {error && <Alert tone="danger" onDismiss={() => setError(null)}>{error}</Alert>}
                {reports.length > 1 && (
                    <FormField label="Report" htmlFor="att-ex-report">
                        <select id="att-ex-report" className="cy-select cy-select--block" value={report} onChange={(e) => chooseReport(e.target.value)}>
                            {reports.map((r) => <option key={r.value} value={r.value}>{r.label}</option>)}
                        </select>
                    </FormField>
                )}
                {chosen.help && <p className="cy-text-muted">{chosen.help}</p>}
                <FormField label={chosen.single ? 'Date' : 'Period'}>
                    {chosen.single
                        ? <DateRange value={{ start: range.start, end: range.start }} onChange={({ start }) => setRange({ start, end: start })} startLabel="Date" endLabel="Date" />
                        : <DateRange value={range} onChange={setRange} />}
                </FormField>
                {!chosen.single && (
                    <div className="cy-seg" role="group" aria-label="Quick periods">
                        {EXPORT_PERIODS.map((p) => (
                            <button key={p.value} type="button" className="cy-btn cy-btn--outline-default cy-btn--sm" onClick={() => pick(p.value)}>{p.label}</button>
                        ))}
                    </div>
                )}
                <FormField label="Format">
                    <Segmented label="Format" options={FORMATS} value={format} onChange={setFormat} />
                </FormField>
                {!chosen.single && !invalid && (
                    <p role="status" className="cy-text-muted">
                        {days} day{days === 1 ? '' : 's'}{report === 'register' ? '; each department gets its own sheet (PDF page) and the range is split into month sections.' : '.'}
                    </p>
                )}
                {tooLong && <Alert tone="warning">A {format === 'pdf' ? 'PDF' : 'spreadsheet'} covers at most {max} days. Narrow the period{format === 'pdf' ? ' or choose Excel' : ''}.</Alert>}
            </div>
            <div className="cy-modal__foot">
                <Button variant="outline" color="secondary" onClick={onClose}>Cancel</Button>
                <Button loading={busy} disabled={invalid || tooLong} onClick={go}><Icon name="download" /> Export</Button>
            </div>
        </Dialog>
    );
}
