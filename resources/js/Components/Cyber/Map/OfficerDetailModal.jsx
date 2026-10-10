import React from 'react';
import { Link } from '@inertiajs/react';

import Badge from '../Badge.jsx';
import Dialog from './Dialog.jsx';
import { Avatar, PunchThumb } from './Avatar.jsx';
import { clockOf, placeOf } from './util.js';

function PhotoCell({ label, punch, name, onPhoto }) {
    if (!punch?.photo) return null;
    return (
        <div className="cy-modal__photo">
            <span>{label}</span>
            <PunchThumb url={punch.photo} label={`Open ${label.toLowerCase()} photo`} onOpen={() => onPhoto({ url: punch.photo, title: `${name} - ${label.toLowerCase()}`, caption: `${name} - ${label.toLowerCase()} ${clockOf(punch.time) ?? ''}` })} />
        </div>
    );
}

/** Officer detail: who, status, both punches with place and photo, and the way into the timesheet. */
export default function OfficerDetailModal({ feature, onClose, onPhoto, onLocate }) {
    const p = feature?.person;
    if (!p) return null;
    const done = p.status === 'completed';
    return (
        <Dialog title="Officer detail" onClose={onClose}>
            <div className="cy-pop__person cy-modal__section" style={{ borderBottom: '1px solid var(--cy-border-translucent)' }}>
                <Avatar name={p.name} photo={p.photo} />
                <div className="cy-pop__who">
                    <div className="cy-pop__name">{p.name}</div>
                    <div className="cy-pop__sub">{[p.designation, p.department].filter(Boolean).join(' · ')}</div>
                    <div className="cy-pop__sub">Employee {p.employee_id}{p.attendance_type ? ` · ${p.attendance_type}` : ''}</div>
                </div>
                <Badge color={done ? 'info' : 'success'}>{done ? 'Done' : 'Active'}</Badge>
            </div>
            <dl className="cy-pop__rows">
                <dt>Date</dt><dd>{p.date}</dd>
                <dt>Punch in</dt><dd>{clockOf(p.punch_in?.time) ?? 'Not recorded'}{placeOf(p.punch_in) ? ` · ${placeOf(p.punch_in)}` : ''}</dd>
                <dt>Punch out</dt><dd>{clockOf(p.punch_out?.time) ?? 'Not yet'}{placeOf(p.punch_out) ? ` · ${placeOf(p.punch_out)}` : ''}</dd>
                <dt>Cycles</dt><dd>{p.cycles}</dd>
                <dt>Photo proof</dt><dd>{p.requires_photo ? 'Required by attendance type' : 'Not required'}</dd>
            </dl>
            {(p.punch_in?.photo || p.punch_out?.photo) && (
                <div className="cy-modal__section cy-modal__photos">
                    <PhotoCell label="Punch in" punch={p.punch_in} name={p.name} onPhoto={onPhoto} />
                    <PhotoCell label="Punch out" punch={p.punch_out} name={p.name} onPhoto={onPhoto} />
                </div>
            )}
            <div className="cy-pop__foot">
                {onLocate && <button type="button" onClick={() => { onLocate(feature); onClose(); }}>Show on map</button>}
                {p.timesheet && <Link href={p.timesheet}>Open timesheet</Link>}
            </div>
        </Dialog>
    );
}
