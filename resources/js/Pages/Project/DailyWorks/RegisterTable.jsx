import React from 'react';

import { Badge, Checkbox, Icon } from '@/Components/Cyber';

import { COLUMNS, RESULTS, formatDay, formatMoment, statusMeta } from './dwUtils.js';

export function StatusBadge({ status, result }) {
    const m = statusMeta(status);
    return (
        <span className="dw-status">
            <Badge soft color={m.tone}>{m.label}</Badge>
            {status === 'completed' && result && RESULTS[result] && <Badge soft color={RESULTS[result].tone}>{RESULTS[result].label}</Badge>}
        </span>
    );
}

/** The value of one register column for one work (the same for the table cell and the phone card). */
function cell(key, w, onOpen) {
    switch (key) {
        case 'date': return <span className="cy-nowrap">{w.date ? formatDay(w.date) : ''}</span>;
        case 'number': return <button type="button" className="dw-link" onClick={() => onOpen(w)} aria-label={`Open RFI ${w.number}`}>{w.number}{w.deleted_at && <Badge soft color="danger" className="dw-gap">Deleted</Badge>}</button>;
        case 'type': return w.type;
        case 'location': return <span className="dw-loc cy-nowrap">{w.location}{w.start_m == null && <Badge soft color="warning" className="dw-gap" title="This location could not be read as a chainage, so the work is not placed on the corridor.">Not placed</Badge>}</span>;
        case 'side': return w.side;
        case 'jurisdiction': return w.jurisdiction ? <Badge soft color="secondary" title={w.jurisdiction.name}>{w.jurisdiction.code}</Badge> : <span className="dw-muted">None</span>;
        case 'status': return <StatusBadge status={w.status} result={w.inspection_result} />;
        case 'incharge': return w.incharge_name ?? w.incharge ?? <span className="dw-muted">None</span>;
        case 'assigned': return w.assigned_name ?? w.assigned ?? <span className="dw-muted">None</span>;
        case 'description': return <span className="dw-desc">{w.description}</span>;
        case 'completion': return w.completion_time ? formatMoment(w.completion_time) : '';
        case 'resubmissions': return <span className="cy-num">{w.resubmission_count}</span>;
        case 'objections': return w.active_objections > 0 ? <Badge soft color="danger">{w.active_objections} active</Badge> : <span className="dw-muted">None</span>;
        case 'files': return <span className="cy-num">{w.files}</span>;
        case 'updated': return formatMoment(w.updated_at);
        default: return null;
    }
}

const ariaSort = (active, dir) => (active ? (dir === 'asc' ? 'ascending' : 'descending') : 'none');

/** The desktop table: sticky header, server-side sort buttons, row selection. */
export function WorksTable({ rows, columns, sort, dir, onSort, selected, onSelect, onSelectAll, onOpen, density, busy }) {
    const shown = COLUMNS.filter((c) => columns.includes(c.key));
    const all = rows.length > 0 && rows.every((r) => selected.has(r.id));
    return (
        <div className={`dw-tablewrap dw-density--${density}`} aria-busy={busy || undefined}>
            <table className="cy-table dw-table">
                <caption className="visually-hidden">Daily works register</caption>
                <thead>
                    <tr>
                        <th scope="col" className="dw-select"><Checkbox label={<span className="visually-hidden">Select all works on this page</span>} checked={all} indeterminate={!all && rows.some((r) => selected.has(r.id))} onCheckedChange={onSelectAll} /></th>
                        {shown.map((c) => (
                            <th key={c.key} scope="col" aria-sort={c.sort ? ariaSort(sort === c.sort, dir) : undefined}>
                                {c.sort ? (
                                    <button type="button" className="dw-sortbtn" onClick={() => onSort(c.sort)}>
                                        {c.label}
                                        <Icon name="chevron-down" className={sort === c.sort ? (dir === 'asc' ? 'dw-sort dw-sort--asc' : 'dw-sort') : 'dw-sort dw-sort--idle'} />
                                    </button>
                                ) : c.label}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {rows.map((w) => (
                        <tr key={w.id} className={[selected.has(w.id) ? 'is-selected' : '', w.deleted_at ? 'is-deleted' : ''].filter(Boolean).join(' ')}>
                            <td className="dw-select"><Checkbox label={<span className="visually-hidden">Select RFI {w.number}</span>} checked={selected.has(w.id)} onCheckedChange={(on) => onSelect(w.id, on)} /></td>
                            {shown.map((c) => <td key={c.key}>{cell(c.key, w, onOpen)}</td>)}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

/** The phone layout: one card per work with the facts a site engineer needs. */
export function WorksCards({ rows, selected, onSelect, onOpen, busy }) {
    return (
        <ul className="dw-cards" aria-label="Daily works" aria-busy={busy || undefined}>
            {rows.map((w) => (
                <li key={w.id} className={['dw-card', selected.has(w.id) ? 'is-selected' : '', w.deleted_at ? 'is-deleted' : ''].filter(Boolean).join(' ')}>
                    <div className="dw-card__head">
                        <Checkbox label={<span className="visually-hidden">Select RFI {w.number}</span>} checked={selected.has(w.id)} onCheckedChange={(on) => onSelect(w.id, on)} />
                        <button type="button" className="dw-link dw-card__title" onClick={() => onOpen(w)}>{w.number}</button>
                        <StatusBadge status={w.status} result={w.inspection_result} />
                    </div>
                    <dl className="dw-card__facts">
                        <div><dt>Date</dt><dd>{formatDay(w.date)}</dd></div>
                        <div><dt>Type</dt><dd>{w.type}</dd></div>
                        <div><dt>Location</dt><dd>{w.location}{w.start_m == null && <Badge soft color="warning" className="dw-gap">Not placed</Badge>}</dd></div>
                        <div><dt>Jurisdiction</dt><dd>{w.jurisdiction?.code ?? 'None'}</dd></div>
                        <div><dt>In-charge</dt><dd>{w.incharge_name ?? w.incharge ?? 'None'}</dd></div>
                        {w.active_objections > 0 && <div><dt>Objections</dt><dd><Badge soft color="danger">{w.active_objections} active</Badge></dd></div>}
                    </dl>
                </li>
            ))}
        </ul>
    );
}
