import React, { useId, useState } from 'react';
import { useQuery } from '@tanstack/react-query';

import { Alert, Button, Checkbox, FormField, Icon } from '@/Components/Cyber';
import Dialog from '@/Components/Cyber/Map/Dialog.jsx';
import { showToast } from '@/utils/toastUtils';

import { download, errorsOf, getJson } from './dwUtils.js';

/** Exports exactly the current filtered view, within the viewer's scope. Says how many rows before downloading. */
export default function ExportDialog({ filters, onClose }) {
    const labelled = useId();
    const [format, setFormat] = useState('csv');
    const [columns, setColumns] = useState(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);
    const { sort, dir, page, per_page, ...rest } = filters;
    const info = useQuery({ queryKey: ['daily-works', 'export-count', rest], queryFn: () => getJson(route('dailyWorks.exportCount'), rest), staleTime: 10_000 });
    const d = info.data;
    const chosen = columns ?? d?.default ?? [];
    const toggle = (k) => setColumns(chosen.includes(k) ? chosen.filter((x) => x !== k) : [...chosen, k]);

    const go = async () => {
        setBusy(true); setError(null);
        try {
            await download(route('dailyWorks.exportFile'), { ...rest, sort, dir, format, columns: chosen }, `daily-works.${format}`);
            showToast.success('Export downloaded'); onClose();
        } catch (e) { setError(errorsOf(e).message); } finally { setBusy(false); }
    };

    return (
        <Dialog title="Export daily works" onClose={onClose} className="cy-modal cy-modal--wide" labelledBy={labelled}>
            <div className="cy-modal__body">
                {error && <Alert tone="danger" onDismiss={() => setError(null)}>{error}</Alert>}
                {info.isPending && <p role="status">Counting the rows…</p>}
                {d && (
                    <>
                        <p role="status"><strong>{d.rows.toLocaleString('en-GB')}</strong> work{d.rows === 1 ? '' : 's'} match the current filters{d.rows > d.max ? `; the file holds the first ${d.max.toLocaleString('en-GB')}` : ''}. Only works in your scope are included.</p>
                        <FormField label="Format" htmlFor="dw-ex-f">
                            <select id="dw-ex-f" className="cy-select cy-select--block" value={format} onChange={(e) => setFormat(e.target.value)}>
                                <option value="csv">CSV (opens in Excel)</option><option value="xls">Excel worksheet (.xls)</option>
                            </select>
                        </FormField>
                        <fieldset className="dw-fieldset"><legend>Columns</legend>
                            <div className="dw-colgrid">{Object.entries(d.columns).map(([k, label]) => <Checkbox key={k} label={label} checked={chosen.includes(k)} onCheckedChange={() => toggle(k)} />)}</div>
                        </fieldset>
                    </>
                )}
            </div>
            <div className="cy-modal__foot">
                <Button variant="outline" color="secondary" onClick={onClose}>Cancel</Button>
                <Button loading={busy} disabled={!d || d.rows === 0 || chosen.length === 0} onClick={go}><Icon name="download" /> Download</Button>
            </div>
        </Dialog>
    );
}
