import React, { useState } from 'react';
import Dialog from '@/Components/Cyber/Map/Dialog.jsx';
import { Badge, Button, MediaFrame } from '@/Components/Cyber';

/**
 * Opens an application's documents inline (image, or the browser's PDF viewer) next to the decision buttons, in a Cyber dialog.
 * `attachments` is the server's present() payload: { files: [{ id, name, mime_type, url, superseded }], history, returned_for_reupload }.
 * `actions` renders the decision buttons in the footer (Approve / Reject / Request re-upload).
 */
export default function AttachmentViewer({ title = 'Documents', attachments, onClose, actions }) {
    const files = attachments?.files ?? [];
    const current = files.filter((f) => !f.superseded);
    const old = files.filter((f) => f.superseded);
    const [active, setActive] = useState((current[0] ?? old[0])?.id);
    const file = files.find((f) => f.id === active);

    return (
        <Dialog title={title} onClose={onClose} className="cy-modal cy-modal--wide">
            <div className="cy-modal__section cy-viewer">
                <div className="cy-viewer__side">
                    {[...current, ...old].map((f) => (
                        <Button key={f.id} size="sm" color="default" variant="outline" aria-pressed={f.id === active} onClick={() => setActive(f.id)}>
                            {f.name}{f.superseded ? ' (replaced)' : ''}
                        </Button>
                    ))}
                    {attachments?.returned_for_reupload && <Badge soft color="warning">Returned for re-upload</Badge>}
                    {(attachments?.history ?? []).map((h, i) => (
                        <p key={i} className="cy-row__sub">{h.action === 'reupload_requested' ? `Re-upload requested: ${h.reason}` : 'Re-uploaded by the employee'}</p>
                    ))}
                </div>
                <div className="cy-viewer__main">
                    {!file && <p className="cy-row__sub">No document attached.</p>}
                    {file && !file.url && <p className="cy-row__sub">You are not allowed to open this document.</p>}
                    {file?.url && <MediaFrame src={file.url} mime={file.mime_type} title={file.name} />}
                </div>
            </div>
            <div className="cy-modal__foot">
                {actions}
                <Button color="default" variant="outline" size="sm" onClick={onClose}>Close</Button>
            </div>
        </Dialog>
    );
}
