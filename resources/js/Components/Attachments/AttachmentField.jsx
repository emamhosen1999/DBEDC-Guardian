import React, { useCallback, useEffect, useId, useState } from 'react';
import { Alert, Badge, Button, FileInput, FormField, Progress, Thumbnail } from '@/Components/Cyber';
import { precheckImageFile } from './documentPrecheck';
import { discardStaged, stageFile } from './attachmentsApi';
import ScanDialog from './ScanDialog';

/**
 * Supporting documents for an application, built only from the Cyber library: a file field (a drop also works), an instant
 * clarity pre-check (the server stays authoritative), upload progress, a list of attached documents with Remove, and clear errors.
 * Photos can be straightened and cleaned by the server-side scanner; PDFs skip that and keep the clarity check.
 *
 * value / onChange: the list of staged documents [{ token, name, mime_type, preview_url, pages }]; the form submits their tokens.
 */
export default function AttachmentField({ value, onChange, required = false, max = 3, label = 'Supporting document', hint, error, disabled = false, id }) {
    const auto = useId();
    const inputId = id ?? auto;
    const [busy, setBusy] = useState(false);
    const [progress, setProgress] = useState(0);
    const [problem, setProblem] = useState('');
    const [pending, setPending] = useState(null); // a chosen photo waiting for "Use this photo" / "Straighten and clean"
    const [scanning, setScanning] = useState(null);
    const items = value ?? [];
    const full = items.length >= max;

    useEffect(() => () => { if (pending?.url) URL.revokeObjectURL(pending.url); }, [pending]);

    const upload = useCallback(async (file) => {
        setBusy(true); setProgress(0); setProblem('');
        try {
            const row = await stageFile(file, setProgress);
            onChange([...items, { token: row.token, name: row.name || file.name, mime_type: row.mime_type, preview_url: row.preview_url, pages: row.pages, passed: row.passed }]);
            setPending(null);
        } catch (e) {
            setProblem(e.message || 'The upload failed.');
        } finally {
            setBusy(false);
        }
    }, [items, onChange]);

    const choose = useCallback(async (files) => {
        const file = files?.[0];
        if (!file || disabled || full) return;
        setProblem('');
        if (file.type === 'application/pdf') { upload(file); return; }
        const verdict = await precheckImageFile(file);
        setPending({ file, url: URL.createObjectURL(file), verdict });
    }, [disabled, full, upload]);

    const remove = (item) => {
        onChange(items.filter((i) => i.token !== item.token));
        discardStaged(item.token).catch(() => {});
    };

    return (
        <FormField label={label} htmlFor={inputId} required={required} hint={hint ?? (required ? 'A clear photo or PDF is required.' : 'Optional. A clear photo or PDF, up to 10 MB.')} error={error}>
            <div className="cy-fieldstack">
                {!full && !pending && (
                    <FileInput id={inputId} accept="application/pdf,image/jpeg,image/png" disabled={disabled || busy} onFiles={choose} aria-label={label} />
                )}

                {pending && (
                    <div className="cy-pending">
                        <Thumbnail src={pending.url} alt="The chosen photo" size="md" />
                        <div className="cy-pending__body">
                            {pending.verdict.passed
                                ? <Alert tone="success">This photo looks clear.</Alert>
                                : <Alert tone="danger" title="This photo may not be clear enough">{pending.verdict.message}</Alert>}
                            <div className="cy-actions">
                                <Button size="sm" disabled={busy || !pending.verdict.passed} loading={busy} onClick={() => upload(pending.file)}>Use this photo</Button>
                                <Button size="sm" color="default" variant="outline" disabled={busy} onClick={() => setScanning(pending.file)}>Straighten and clean</Button>
                                <Button size="sm" color="default" variant="outline" disabled={busy} onClick={() => setPending(null)}>Choose another</Button>
                            </div>
                        </div>
                    </div>
                )}

                {busy && <Progress value={progress} label="Upload progress" />}
                {problem && <Alert tone="danger" onDismiss={() => setProblem('')}>{problem}</Alert>}

                {items.length > 0 && (
                    <ul className="cy-docs" aria-label="Attached documents">
                        {items.map((item) => (
                            <li key={item.token} className="cy-docs__row">
                                <Thumbnail src={item.preview_url} alt={`Preview of ${item.name}`} size="sm" />
                                <span className="cy-docs__main">
                                    <span className="cy-docs__name" title={item.name}>{item.name}</span>
                                    <span className="cy-row__sub">{item.pages > 1 ? `${item.pages} pages` : '1 page'}</span>
                                </span>
                                <Badge soft color="success">Clear</Badge>
                                <Button size="sm" color="default" variant="outline" disabled={disabled} onClick={() => remove(item)} aria-label={`Remove ${item.name}`}>Remove</Button>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
            {scanning && (
                <ScanDialog file={scanning} onClose={() => setScanning(null)}
                    onDone={(item) => { onChange([...items, item]); setScanning(null); setPending(null); }} />
            )}
        </FormField>
    );
}
