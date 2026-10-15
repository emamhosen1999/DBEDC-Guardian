import React, { useId, useState } from 'react';

import { Alert, Button, DateInput, FormField, SelectInput } from '@/Components/Cyber';
import Dialog from '@/Components/Cyber/Map/Dialog.jsx';
import { showToast } from '@/utils/toastUtils';
import axios from 'axios';

import { STATUSES, errorsOf, todayDhaka } from './dwUtils.js';

const TITLE = { status: 'Change status', incharge: 'Change in-charge', completion: 'Set completion date', delete: 'Delete works' };
const ROUTE = { status: 'dailyWorks.bulkUpdateStatus', incharge: 'dailyWorks.bulkUpdateIncharge', completion: 'dailyWorks.bulkUpdateCompletionDate', delete: 'dailyWorks.bulkDelete' };

/** One bulk action over the selected works. Each work is checked by its own policy and version on the server; the answer says how many changed. */
export default function BulkDialog({ action, works, people, onClose, onDone }) {
    const labelled = useId();
    const [value, setValue] = useState(action === 'status' ? 'in-progress' : action === 'completion' ? todayDhaka() : '');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);

    const run = async () => {
        setBusy(true); setError(null);
        const body = { work_ids: works.map((w) => w.id), versions: Object.fromEntries(works.map((w) => [w.id, w.lock_version])) };
        if (action === 'status') body.status = value;
        if (action === 'incharge') body.incharge_id = value;
        if (action === 'completion') body.completion_date = value;
        try {
            const res = await axios.post(route(ROUTE[action]), body);
            showToast.success(res.data.message);
            onDone();
        } catch (e) { setError(errorsOf(e).message); } finally { setBusy(false); }
    };

    return (
        <Dialog title={`${TITLE[action]} (${works.length})`} onClose={onClose} labelledBy={labelled}>
            <div className="cy-modal__body">
                {error && <Alert tone="danger" onDismiss={() => setError(null)}>{error}</Alert>}
                {action === 'status' && <FormField label="New status" htmlFor="dw-b-s"><SelectInput id="dw-b-s" value={value} onChange={setValue} options={STATUSES.filter((s) => s.value !== 'completed').map((s) => ({ value: s.value, label: s.label }))} /></FormField>}
                {action === 'incharge' && <FormField label="New in-charge" htmlFor="dw-b-i" required><SelectInput id="dw-b-i" value={value} onChange={setValue} options={[{ value: '', label: 'Choose a person' }, ...people.map((p) => ({ value: p.employee_id, label: `${p.name} (${p.employee_id})` }))]} /></FormField>}
                {action === 'completion' && <FormField label="Completion date" htmlFor="dw-b-c"><DateInput id="dw-b-c" label="Completion date" value={value} onChange={setValue} /></FormField>}
                {action === 'delete' && <Alert tone="warning" title="These works move to deleted works">They can be restored by someone with the restore permission, and the history keeps a record of the deletion.</Alert>}
                <p>Applies to RFI {works.slice(0, 6).map((w) => w.number).join(', ')}{works.length > 6 ? ` and ${works.length - 6} more` : ''}. A move the status rules do not allow is refused for that work.</p>
            </div>
            <div className="cy-modal__foot">
                <Button variant="outline" color="secondary" onClick={onClose}>Cancel</Button>
                <Button loading={busy} disabled={action === 'incharge' && !value} onClick={run}>{action === 'delete' ? 'Delete' : 'Apply'}</Button>
            </div>
        </Dialog>
    );
}
