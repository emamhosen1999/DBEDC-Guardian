import React, { useCallback, useEffect, useRef, useState } from 'react';
import Dialog from '@/Components/Cyber/Map/Dialog.jsx';
import { Alert, Button, CornerEditor, MediaFrame, Segmented } from '@/Components/Cyber';
import { adjustScan, discardStaged, finalizeScan, scanPhoto } from './attachmentsApi';

const MODES = [{ value: 'color', label: 'Colour' }, { value: 'grey', label: 'Grey' }, { value: 'bw', label: 'Black and white' }];
/**
 * "Straighten and clean" for a photographed document (same server pipeline as the mobile scanner): the server finds the page,
 * flattens, crops and enhances it; the user can switch the colour mode, drag the corners, add pages, and finish with ONE PDF.
 * `onDone(item)` receives the staged PDF ({ token, name, mime_type, preview_url, pages }).
 */
export default function ScanDialog({ file, onClose, onDone }) {
    const [pages, setPages] = useState([]);         // processed pages, each a staged scan_page
    const [current, setCurrent] = useState(null);   // the page being reviewed
    const [corners, setCorners] = useState(null);
    const [adjusting, setAdjusting] = useState(false);
    const [mode, setMode] = useState('color');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [nextFile, setNextFile] = useState(file);
    const input = useRef(null);

    const process = useCallback(async (f, m) => {
        setBusy(true); setError('');
        try {
            const row = await scanPhoto(f, m);
            setCurrent(row); setCorners(row.corners); setMode(row.mode);
            setAdjusting(!!row.needs_adjust); // low confidence opens "Adjust corners" automatically
        } catch (e) {
            setError(e.message || 'Could not process this photo.');
        } finally {
            setBusy(false); setNextFile(null);
        }
    }, []);

    useEffect(() => { if (nextFile) process(nextFile, mode); /* once per chosen photo */ }, [nextFile]); // eslint-disable-line react-hooks/exhaustive-deps

    const reflatten = async (opts) => {
        setBusy(true); setError('');
        try {
            const row = await adjustScan(current.token, opts);
            setCurrent(row); setCorners(row.corners); setMode(row.mode);
        } catch (e) { setError(e.message || 'Could not update the page.'); } finally { setBusy(false); }
    };

    const accept = async (more) => {
        const all = [...pages, current];
        if (more) { setPages(all); setCurrent(null); setAdjusting(false); input.current?.click(); return; }
        setBusy(true); setError('');
        try {
            const pdf = await finalizeScan(all.map((p) => p.token));
            onDone({ token: pdf.token, name: `Scanned document (${pdf.pages} page${pdf.pages > 1 ? 's' : ''}).pdf`, mime_type: 'application/pdf', preview_url: all[0].preview_url, pages: pdf.pages, passed: true });
        } catch (e) { setError(e.message || 'Could not build the document.'); setBusy(false); }
    };

    const retake = async () => {
        if (current) await discardStaged(current.token).catch(() => {});
        setCurrent(null); setCorners(null); setAdjusting(false); setError('');
        input.current?.click();
    };

    const verdict = current?.clarity;
    const ok = !!current?.passed;

    return (
        <Dialog title="Straighten and clean" onClose={onClose} className="cy-modal cy-modal--wide">
            <div className="cy-modal__section cy-fieldstack">
                <input ref={input} type="file" accept="image/*" hidden onChange={(e) => { const f = e.target.files?.[0]; e.target.value = ''; if (f) setNextFile(f); }} />
                {busy && <Alert tone="info">Processing the page on the server…</Alert>}
                {error && <Alert tone="danger">{error}</Alert>}
                {current && !busy && (verdict && !ok
                    ? <Alert tone="danger" title="This page is not clear enough">{verdict.message}</Alert>
                    : <Alert tone="success">The page is clear.</Alert>)}
                {current && (adjusting && corners
                    ? <CornerEditor src={current.original_url} corners={corners} onChange={setCorners} alt="Original photo of the document" />
                    : <MediaFrame src={`${current.preview_url}?v=${current.confidence}-${current.mode}-${current.size}`} mime="image/jpeg" title="The processed page" />)}
                {current && (
                    <div className="cy-actions">
                        <Segmented label="Colour mode" value={mode} options={MODES} onChange={(m) => { setMode(m); reflatten({ mode: m }); }} />
                        {adjusting
                            ? <Button size="sm" disabled={busy} onClick={async () => { await reflatten({ corners, mode }); setAdjusting(false); }}>Done adjusting</Button>
                            : <Button size="sm" color="default" variant="outline" disabled={busy} onClick={() => setAdjusting(true)}>Adjust corners</Button>}
                    </div>
                )}
                {pages.length > 0 && <p className="cy-row__sub">{pages.length} page{pages.length > 1 ? 's' : ''} already added.</p>}
            </div>
            <div className="cy-modal__foot">
                <Button color="default" variant="outline" size="sm" onClick={onClose}>Cancel</Button>
                <Button color="default" variant="outline" size="sm" disabled={busy} onClick={retake}>Retake</Button>
                <Button color="default" variant="outline" size="sm" disabled={busy || !ok || pages.length + 1 >= 10} onClick={() => accept(true)}>Add page</Button>
                <Button size="sm" disabled={busy || !ok} loading={busy} onClick={() => accept(false)}>Use this</Button>
            </div>
        </Dialog>
    );
}
