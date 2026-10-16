import React, { useId, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import dayjs from 'dayjs';
import { requestJson } from '@/api/client';
import { Badge, StatTile } from '@/Components/Cyber';
import Tabs, { panelId, tabId } from '@/Components/Cyber/Tabs.jsx';
import { Button } from '@/Components/Cyber';
import ReuploadDialog from '@/Components/Attachments/ReuploadDialog';
import AttachmentViewer from '@/Components/Attachments/AttachmentViewer';

/**
 * The employee's own requests (regularizations, overtime, shift swaps) and comp-off balance, in Cyber's widget
 * composition: .row-grid figures, nav-tabs-v2 with counts, list rows with a status badge. Sections are direct
 * children so a flush Card divides them with one line each (border model).
 */
const STATUS_TONE = { pending: 'warning', approved: 'success', rejected: 'danger', cancelled: 'secondary' };
const toneOf = (status) => STATUS_TONE[status] ?? 'secondary';
const COMP = { payment: 'overtime pay', replacement: 'replacement leave' };
const TYPE = { rest_day: 'Rest day', holiday: 'Holiday' };
const days = (d) => `${d} day${d === 1 ? '' : 's'}`;

function swapStatus(s) {
    if (s.counterparty_status === 'pending') return { text: 'Awaiting coworker', tone: 'warning' };
    if (s.counterparty_status === 'declined') return { text: 'Declined by coworker', tone: 'danger' };
    if (s.counterparty_status === 'accepted' && s.status === 'pending') return { text: 'Awaiting manager', tone: 'warning' };
    if (s.status === 'approved') return { text: 'Approved', tone: 'success' };
    if (s.status === 'rejected') return { text: 'Rejected', tone: 'danger' };
    if (s.status === 'cancelled') return { text: 'Cancelled', tone: 'secondary' };
    return { text: s.status, tone: 'secondary' };
}

function Rows({ loading, rows, empty }) {
    if (loading) return <div className="dl-empty"><span className="cy-row__sub">Loading…</span></div>;
    if (!rows.length) return <div className="dl-empty"><span className="cy-row__sub">{empty}</span></div>;
    return (
        <ul className="dl-list">
            {rows.map((r) => (
                <li key={r.key} className="dl-list__row">
                    <div className="cy-row">
                        <div>
                            <span className="cy-row__title">{r.title}</span>
                            {r.sub && <span className="cy-row__sub">{r.sub}</span>}
                        </div>
                        <span className="cy-actions">
                            {r.flag && <Badge color="secondary" soft>{r.flag}</Badge>}
                            {r.attachments?.files?.length > 0 && <Button size="sm" color="default" variant="outline" onClick={() => r.onView?.(r)}>Documents</Button>}
                            {r.returned && <Button size="sm" color="default" onClick={() => r.onReupload?.(r)}>Re-upload</Button>}
                            <Badge color={r.tone} soft>{r.status}</Badge>
                        </span>
                    </div>
                    {r.returned && <div className="cy-returned"><strong>Returned for a clearer document</strong><span>{r.returned}</span></div>}
                </li>
            ))}
        </ul>
    );
}

export default function MyRequests() {
    const regQ = useQuery({ queryKey: ['my-regularizations'], queryFn: () => requestJson('get', '/attendance/regularizations/mine') });
    const otQ = useQuery({ queryKey: ['my-overtime'], queryFn: () => requestJson('get', '/attendance/overtime/mine') });
    const coQ = useQuery({ queryKey: ['my-comp-off'], queryFn: () => requestJson('get', '/attendance/comp-off/mine') });
    const swapQ = useQuery({ queryKey: ['my-swaps'], queryFn: () => requestJson('get', '/attendance/swaps/mine') });
    const repQ = useQuery({ queryKey: ['my-replacement'], queryFn: () => requestJson('get', '/attendance/replacement/mine') });
    const qc = useQueryClient();
    const [tab, setTab] = useState('reg');
    const [reupload, setReupload] = useState(null);
    const [viewing, setViewing] = useState(null);
    const refresh = () => { qc.invalidateQueries({ queryKey: ['my-overtime'] }); qc.invalidateQueries({ queryKey: ['my-replacement'] }); };
    const prefix = useId();

    const regs = regQ.data?.requests ?? [];
    const ots = otQ.data?.requests ?? [];
    const swaps = swapQ.data?.swaps ?? [];
    const reps = Array.isArray(repQ.data) ? repQ.data : [];
    const balanceDays = coQ.data?.balance_days ?? 0;
    const expiringDays = coQ.data?.expiring_days ?? 0;
    const attach = (type) => (r) => ({
        attachments: r.attachments,
        returned: r.attachments?.returned_for_reupload ? (r.attachments.reupload?.reason || 'Please upload a clearer document.') : null,
        onReupload: () => setReupload({ type, id: r.id, reason: r.attachments?.reupload?.reason }),
        onView: () => setViewing(r),
    });
    const pending = (list) => list.filter((r) => r.status === 'pending').length;

    const lists = {
        reg: {
            loading: regQ.isLoading, empty: 'No regularization requests yet.',
            rows: regs.map((r) => ({ key: r.id, title: dayjs(r.date).format('DD MMM YYYY'), sub: r.type?.replace(/_/g, ' '), status: r.status, tone: toneOf(r.status) })),
        },
        ot: {
            loading: otQ.isLoading, empty: 'No overtime requests yet.',
            rows: ots.map((r) => ({
                key: r.id, title: dayjs(r.date).format('DD MMM YYYY'),
                // Minute-based requests are read-only history ("legacy (hours)"); new claims are whole / half days.
                sub: r.legacy ? `${r.requested_minutes} min requested` : `${TYPE[r.claim_type] ?? 'Day'} · ${days(r.day_credit)} as ${COMP[r.compensation] ?? '—'}${r.end_time_declared ? ' · end time declared by you' : ''}`,
                flag: r.legacy ? 'legacy (hours)' : null,
                status: r.attachments?.returned_for_reupload ? 'returned' : r.status, tone: r.attachments?.returned_for_reupload ? 'warning' : toneOf(r.status),
                ...attach('overtime')(r),
            })),
        },
        rep: {
            loading: repQ.isLoading, empty: 'No replacement leave requests yet.',
            rows: reps.map((r) => ({
                key: r.id, title: dayjs(r.replacement_date).format('DD MMM YYYY'), sub: `${days(r.days)} · uses work on ${r.allocations?.map((a) => dayjs(a.work_date).format('DD MMM')).join(', ')}`,
                status: r.attachments?.returned_for_reupload ? 'returned' : r.status, tone: r.attachments?.returned_for_reupload ? 'warning' : toneOf(r.status),
                ...attach('replacement')(r),
            })),
        },
        swap: {
            loading: swapQ.isLoading, empty: 'No swap requests yet.',
            rows: swaps.map((s) => {
                const st = swapStatus(s);
                const who = s.counterparty?.name || 'coworker';
                const sub = s.type === 'swap'
                    ? `Give ${dayjs(s.requester_date).format('DD MMM')} ↔ take ${dayjs(s.counterparty_date).format('DD MMM')} · ${who}`
                    : `Cover ${dayjs(s.requester_date).format('DD MMM')} · ${who}`;
                return { key: s.id, title: s.type === 'swap' ? 'Shift swap' : 'Cover request', sub, status: st.text, tone: st.tone };
            }),
        },
    };
    const tabs = [
        { key: 'reg', label: 'Regularizations', count: regs.length },
        { key: 'ot', label: 'Overtime', count: ots.length },
        { key: 'rep', label: 'Replacement leave', count: reps.length },
        { key: 'swap', label: 'Shift swaps', count: swaps.length },
    ];

    return (
        <>
            <div className="dl-tiles">
                <StatTile label="Replacement leave" value={coQ.isLoading ? '…' : `${balanceDays} day${balanceDays === 1 ? '' : 's'}`} tone="theme" />
                <StatTile label="Expiring soon" value={coQ.isLoading ? '…' : `${expiringDays} day${expiringDays === 1 ? '' : 's'}`} tone={expiringDays > 0 ? 'warn' : 'neutral'} />
                <StatTile label="Pending requests" value={regQ.isLoading || otQ.isLoading ? '…' : pending(regs) + pending(ots) + swaps.filter((s) => s.status === 'pending').length} tone={pending(regs) + pending(ots) > 0 ? 'warn' : 'neutral'} />
            </div>
            <Tabs tabs={tabs} value={tab} onChange={setTab} idPrefix={prefix} label="My requests" />
            <div role="tabpanel" id={panelId(prefix, tab)} aria-labelledby={tabId(prefix, tab)}>
                <Rows {...lists[tab]} />
            </div>
            {reupload && <ReuploadDialog {...reupload} onClose={() => setReupload(null)} onDone={refresh} />}
            {viewing && <AttachmentViewer title="My documents" attachments={viewing.attachments} onClose={() => setViewing(null)} />}
        </>
    );
}
