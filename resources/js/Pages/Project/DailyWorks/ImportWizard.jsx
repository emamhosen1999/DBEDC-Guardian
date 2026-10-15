import React, { useId, useMemo, useState } from 'react';
import axios from 'axios';
import { useQuery } from '@tanstack/react-query';

import { Alert, Badge, Button, Checkbox, FormField, Icon, SelectInput, Tabs, Textarea } from '@/Components/Cyber';
import Dialog from '@/Components/Cyber/Map/Dialog.jsx';
import { showToast } from '@/utils/toastUtils';

import { download, errorsOf, formatDay, formatMoment, getJson, postJson } from './dwUtils.js';

const ACTION = {
    create: { label: 'Will be added', tone: 'success' },
    resubmit: { label: 'Resubmission', tone: 'warning' },
    skip: { label: 'Already recorded', tone: 'secondary' },
    reject: { label: 'Rejected', tone: 'danger' },
};
const LEVEL_FILTERS = [
    { value: 'attention', label: 'Needs attention' }, { value: 'all', label: 'All rows' }, { value: 'error', label: 'Errors' },
    { value: 'warning', label: 'Warnings' }, { value: 'skip', label: 'Already recorded' },
];
const SHOW = 200;

function Review({ preview, overrides, setOverrides }) {
    const [filter, setFilter] = useState(preview.analysis.counts.error + preview.analysis.counts.warning > 0 ? 'attention' : 'all');
    const [limit, setLimit] = useState(SHOW);
    const all = useMemo(() => preview.analysis.sheets.flatMap((s) => s.rows.map((r) => ({ ...r, sheetName: s.sheet }))), [preview]);
    const rows = useMemo(() => all.filter((r) => (filter === 'all' ? true : filter === 'attention' ? r.level !== 'valid' && r.action !== 'skip' : filter === 'skip' ? r.action === 'skip' : r.level === filter)), [all, filter]);
    const c = preview.analysis.counts;
    return (
        <>
            <ul className="dw-countrow" aria-label="Review summary">
                <li><strong>{c.rows}</strong> rows in {preview.analysis.sheets.length} sheet{preview.analysis.sheets.length === 1 ? '' : 's'}</li>
                <li><Badge soft color="success">{c.create} to add</Badge></li>
                <li><Badge soft color="warning">{c.resubmit} resubmissions</Badge></li>
                <li><Badge soft color="secondary">{c.skip} already recorded</Badge></li>
                <li><Badge soft color={c.error ? 'danger' : 'secondary'}>{c.error} errors</Badge></li>
            </ul>
            {preview.already_imported && <Alert tone="info" title="This file was imported before">Batch #{preview.already_imported.batch_id} imported the same file on {formatMoment(preview.already_imported.committed_at)}. Rows that are already recorded are skipped, so importing it again adds nothing twice.</Alert>}
            <div className="dw-wiz__filter">
                <FormField label="Show" htmlFor="dw-wz-f"><SelectInput id="dw-wz-f" size="sm" value={filter} onChange={(val) => { setFilter(val); setLimit(SHOW); }} options={LEVEL_FILTERS} /></FormField>
                <span className="dw-muted" aria-live="polite">{rows.length} row{rows.length === 1 ? '' : 's'} shown</span>
            </div>
            <div className="dw-tablewrap dw-wiz__table">
                <table className="cy-table">
                    <caption className="visually-hidden">Rows found in the file</caption>
                    <thead><tr><th scope="col">Sheet, row</th><th scope="col">RFI</th><th scope="col">Date</th><th scope="col">Type</th><th scope="col">Location</th><th scope="col">Jurisdiction</th><th scope="col">In-charge</th><th scope="col">Result</th></tr></thead>
                    <tbody>
                        {rows.slice(0, limit).map((r) => {
                            const needsPerson = !r.incharge?.id && r.action !== 'skip';
                            return (
                                <tr key={`${r.sheetName}-${r.row}`} className={r.level === 'error' ? 'is-error' : undefined}>
                                    <td className="cy-nowrap">{r.sheetName}, {r.row}</td>
                                    <td>{r.rfi_number}</td><td className="cy-nowrap">{r.date ? formatDay(r.date) : ''}</td><td>{r.type}</td>
                                    <td>{r.location}{r.span && <span className="dw-muted"> ({r.span.from === r.span.to ? `${r.span.from} m` : `${r.span.from} to ${r.span.to} m`})</span>}</td>
                                    <td>{r.jurisdiction?.code ?? <span className="dw-muted">None</span>}</td>
                                    <td>
                                        {r.incharge?.name ?? (needsPerson ? (
                                            <SelectInput size="sm" aria-label={`In-charge for RFI ${r.rfi_number}`} value={overrides[r.rfi_number] ?? ''} onChange={(val) => setOverrides((p) => ({ ...p, [r.rfi_number]: val }))} options={[{ value: '', label: 'Choose' }, ...preview.incharges.map((p) => ({ value: String(p.id), label: p.name }))]} />
                                        ) : <span className="dw-muted">None</span>)}
                                    </td>
                                    <td>
                                        <Badge soft color={r.level === 'error' ? 'danger' : ACTION[r.action]?.tone ?? 'secondary'}>{r.level === 'error' ? 'Error' : ACTION[r.action]?.label}</Badge>
                                        {r.issues.length > 0 && <ul className="dw-issues">{r.issues.map((i, n) => <li key={n} data-level={i.level}>{i.message}</li>)}</ul>}
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
                {rows.length === 0 && <p className="dw-state" role="status">No rows in this view.</p>}
            </div>
            {rows.length > limit && <Button size="sm" variant="outline" color="secondary" onClick={() => setLimit((l) => l + SHOW)}>Show {Math.min(SHOW, rows.length - limit)} more of {rows.length - limit}</Button>}
        </>
    );
}

function Batches({ canReverse, onChanged }) {
    const [open, setOpen] = useState(null);
    const [reason, setReason] = useState('');
    const [skip, setSkip] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);
    const list = useQuery({ queryKey: ['daily-works', 'imports'], queryFn: () => getJson(route('dailyWorks.imports.index')), staleTime: 10_000 });
    const one = useQuery({ queryKey: ['daily-works', 'imports', open], queryFn: () => getJson(route('dailyWorks.imports.show', open)), enabled: !!open });
    const reverse = async () => {
        setBusy(true); setError(null);
        try {
            await postJson(route('dailyWorks.imports.reverse', open), { reason, skip_conflicts: skip || undefined });
            showToast.success('Import reversed'); setReason(''); await Promise.all([one.refetch(), list.refetch()]); onChanged();
        } catch (e) { const r = errorsOf(e); setError(r.fields.reason || r.fields.conflicts || r.message); } finally { setBusy(false); }
    };
    if (list.isPending) return <p role="status">Loading past imports…</p>;
    if (list.isError) return <Alert tone="danger" title="Past imports could not be loaded">Try again in a moment.</Alert>;
    if (!list.data.data.length) return <p role="status">No file has been imported yet.</p>;
    const plan = one.data?.plan;
    const b = one.data?.batch;
    return (
        <>
            <div className="dw-tablewrap"><table className="cy-table">
                <caption className="visually-hidden">Past imports</caption>
                <thead><tr><th scope="col">File</th><th scope="col">When</th><th scope="col">By</th><th scope="col">Added</th><th scope="col">Resubmitted</th><th scope="col">Rejected</th><th scope="col">State</th><th scope="col"><span className="visually-hidden">Open</span></th></tr></thead>
                <tbody>{list.data.data.map((r) => (
                    <tr key={r.id}><td>{r.filename}</td><td className="cy-nowrap">{formatMoment(r.committed_at)}</td><td>{r.by}</td><td className="cy-num">{r.rows_created}</td><td className="cy-num">{r.rows_resubmitted}</td><td className="cy-num">{r.rows_error}</td>
                        <td><Badge soft color={r.status === 'reversed' ? 'danger' : 'success'}>{r.status}</Badge></td>
                        <td><Button size="sm" variant="outline" color="secondary" onClick={() => setOpen(r.id)}>Review</Button></td></tr>
                ))}</tbody>
            </table></div>
            {open && one.data && (
                <section className="dw-movebox" aria-label={`Import ${open}`}>
                    <strong>Import #{b.id}: {b.filename}</strong>
                    <p>{b.rows_created} added, {b.rows_resubmitted} resubmitted, {b.rows_skipped} skipped, {b.rows_error} rejected.{b.reverse_reason && <> Reversed: {b.reverse_reason}</>}</p>
                    {b.rows_error > 0 && <Button size="sm" variant="outline" color="secondary" onClick={() => download(route('dailyWorks.imports.errors', b.id), {}, `import-${b.id}-rejected-rows.csv`)}><Icon name="download" /> Rejected rows (CSV)</Button>}
                    {b.status === 'committed' && canReverse && plan && (
                        <>
                            <p>Reversing removes <strong>{plan.delete.length}</strong> works (they stay in deleted works and can be restored) and takes back <strong>{plan.revert.length}</strong> resubmissions.</p>
                            {plan.conflicts.length > 0 && <Alert tone="warning" title={`${plan.conflicts.length} works were worked on since the import`}>{plan.conflicts.slice(0, 8).map((c) => c.number).join(', ')}{plan.conflicts.length > 8 ? ' and more' : ''}. They are left as they are if you choose to skip them.</Alert>}
                            {error && <Alert tone="danger">{error}</Alert>}
                            <FormField label="Reason for reversing" htmlFor="dw-rv" required hint="Recorded in the audit trail."><Textarea id="dw-rv" rows={2} value={reason} onChange={(e) => setReason(e.target.value)} /></FormField>
                            {plan.conflicts.length > 0 && <Checkbox label="Skip the works that changed and reverse the rest" checked={skip} onCheckedChange={setSkip} />}
                            <Button size="sm" loading={busy} disabled={reason.trim().length < 5} onClick={reverse}>Reverse this import</Button>
                        </>
                    )}
                </section>
            )}
        </>
    );
}

/** Import wizard: choose a file, review every row (valid, warning, error, what each will do), confirm, then see and, if needed, reverse the result. */
export default function ImportWizard({ can, onClose, onDone }) {
    const labelled = useId();
    const [tab, setTab] = useState('new');
    const [step, setStep] = useState('upload');
    const [file, setFile] = useState(null);
    const [preview, setPreview] = useState(null);
    const [overrides, setOverrides] = useState({});
    const [partial, setPartial] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);
    const [result, setResult] = useState(null);

    const analyse = async () => {
        setBusy(true); setError(null);
        try {
            const form = new FormData(); form.append('file', file);
            const res = await axios.post(route('dailyWorks.previewImport'), form);
            setPreview(res.data.summary); setStep('review');
        } catch (e) { const r = errorsOf(e); setError(r.fields.file || r.message); } finally { setBusy(false); }
    };
    const commit = async () => {
        setBusy(true); setError(null);
        try {
            const res = await postJson(route('dailyWorks.import'), { token: preview.token, allow_partial: partial || undefined, incharge_overrides: JSON.stringify(Object.fromEntries(Object.entries(overrides).filter(([, v]) => v))) });
            setResult(res); setStep('done'); onDone();
        } catch (e) { const r = errorsOf(e); setError(r.fields.file || r.fields.token || r.message); } finally { setBusy(false); }
    };

    const c = preview?.analysis.counts;
    const needsPartial = c && c.error > 0;
    const writable = c ? c.create + c.resubmit : 0;

    return (
        <Dialog title="Import daily works" onClose={onClose} className="cy-modal cy-modal--wide dw-wizard" labelledBy={labelled}>
            <div className="cy-modal__body">
                <Tabs tabs={[{ key: 'new', label: 'New import' }, { key: 'past', label: 'Past imports' }]} value={tab} onChange={setTab} idPrefix="dw-imp" label="Import" />
                <div role="tabpanel" id={`dw-imp-panel-${tab}`} aria-labelledby={`dw-imp-tab-${tab}`} className="dw-panel">
                    {tab === 'past' && <Batches canReverse={can.import_reverse} onChanged={onDone} />}
                    {tab === 'new' && (
                        <>
                            <ol className="dw-steps" aria-label="Steps"><li aria-current={step === 'upload' ? 'step' : undefined}>1 Choose file</li><li aria-current={step === 'review' ? 'step' : undefined}>2 Review rows</li><li aria-current={step === 'done' ? 'step' : undefined}>3 Result</li></ol>
                            {error && <Alert tone="danger" onDismiss={() => setError(null)}>{error}</Alert>}
                            {step === 'upload' && (
                                <>
                                    <p>One sheet per day. Columns: date, RFI number, type, description, location, road side, layer, time. Nothing is saved until you confirm on the next step.</p>
                                    <FormField label="Excel or CSV file" htmlFor="dw-file" hint="Up to 10 MB. Excel .xlsx or .xls, or a .csv.">
                                        <input id="dw-file" className="cy-input" type="file" accept=".xlsx,.xls,.csv" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
                                    </FormField>
                                    <div className="dw-actions"><Button loading={busy} disabled={!file} onClick={analyse}>Read the file</Button><Button as="a" variant="outline" color="secondary" href={route('dailyWorks.downloadTemplate')}><Icon name="download" /> Download the template</Button></div>
                                </>
                            )}
                            {step === 'review' && preview && (
                                <>
                                    <Review preview={preview} overrides={overrides} setOverrides={setOverrides} />
                                    {needsPartial && <Alert tone="warning" title={`${c.error} rows have errors`}><Checkbox label={`Import the ${writable} good rows now and leave the ${c.error} rows with errors out (you get a report of them)`} checked={partial} onCheckedChange={setPartial} /></Alert>}
                                    <div className="dw-actions">
                                        <Button variant="outline" color="secondary" onClick={() => { setStep('upload'); setPreview(null); }}>Choose another file</Button>
                                        <Button loading={busy} disabled={writable === 0 || (needsPartial && !partial)} onClick={commit}>Import {writable} row{writable === 1 ? '' : 's'}</Button>
                                    </div>
                                    {writable === 0 && <p className="dw-muted">Nothing here can be imported: every row is rejected or already recorded.</p>}
                                </>
                            )}
                            {step === 'done' && result && (
                                <>
                                    <Alert tone="success" title="Import finished">{result.batch.rows_created} added, {result.batch.rows_resubmitted} resubmitted, {result.batch.rows_skipped} already recorded, {result.batch.rows_rejected} rejected. Batch #{result.batch.id}.</Alert>
                                    <div className="dw-actions">
                                        {result.batch.rows_rejected > 0 && <Button variant="outline" color="secondary" onClick={() => download(route('dailyWorks.imports.errors', result.batch.id), {}, `import-${result.batch.id}-rejected-rows.csv`)}><Icon name="download" /> Rejected rows (CSV)</Button>}
                                        <Button onClick={onClose}>Close</Button>
                                    </div>
                                </>
                            )}
                        </>
                    )}
                </div>
            </div>
        </Dialog>
    );
}
