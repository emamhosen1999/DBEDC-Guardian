import React, { useId, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import dayjs from 'dayjs';
import { requestJson } from '@/api/client';
import { Badge, StatTile } from '@/Components/Cyber';
import Tabs, { panelId, tabId } from '@/Components/Cyber/Tabs.jsx';

/**
 * The employee's own requests (regularizations, overtime, shift swaps) and comp-off balance, in Cyber's widget
 * composition: .row-grid figures, nav-tabs-v2 with counts, list rows with a status badge. Sections are direct
 * children so a flush Card divides them with one line each (border model).
 */
const STATUS_TONE = { pending: 'warning', approved: 'success', rejected: 'danger', cancelled: 'secondary' };
const toneOf = (status) => STATUS_TONE[status] ?? 'secondary';

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
                        <Badge color={r.tone} soft>{r.status}</Badge>
                    </div>
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
    const [tab, setTab] = useState('reg');
    const prefix = useId();

    const regs = regQ.data?.requests ?? [];
    const ots = otQ.data?.requests ?? [];
    const swaps = swapQ.data?.swaps ?? [];
    const minutes = coQ.data?.balance_minutes ?? 0;
    const pending = (list) => list.filter((r) => r.status === 'pending').length;

    const lists = {
        reg: {
            loading: regQ.isLoading, empty: 'No regularization requests yet.',
            rows: regs.map((r) => ({ key: r.id, title: dayjs(r.date).format('DD MMM YYYY'), sub: r.type?.replace(/_/g, ' '), status: r.status, tone: toneOf(r.status) })),
        },
        ot: {
            loading: otQ.isLoading, empty: 'No overtime requests yet.',
            rows: ots.map((r) => ({ key: r.id, title: dayjs(r.date).format('DD MMM YYYY'), sub: `${r.requested_minutes} min requested`, status: r.status, tone: toneOf(r.status) })),
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
        { key: 'swap', label: 'Shift swaps', count: swaps.length },
    ];

    return (
        <>
            <div className="dl-tiles">
                <StatTile label="Comp-off balance" value={coQ.isLoading ? '…' : `${Math.floor(minutes / 60)}h ${minutes % 60}m`} tone="theme" />
                <StatTile label="Pending requests" value={regQ.isLoading || otQ.isLoading ? '…' : pending(regs) + pending(ots) + swaps.filter((s) => s.status === 'pending').length} tone={pending(regs) + pending(ots) > 0 ? 'warn' : 'neutral'} />
            </div>
            <Tabs tabs={tabs} value={tab} onChange={setTab} idPrefix={prefix} label="My requests" />
            <div role="tabpanel" id={panelId(prefix, tab)} aria-labelledby={tabId(prefix, tab)}>
                <Rows {...lists[tab]} />
            </div>
        </>
    );
}
