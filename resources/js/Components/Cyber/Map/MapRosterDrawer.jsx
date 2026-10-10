import React, { useMemo, useState } from 'react';

import Badge from '../Badge.jsx';
import Icon from '../Icon.jsx';
import { Avatar } from './Avatar.jsx';
import { clockOf } from './util.js';

/** Who is where today: searchable and filterable by status; choosing a person flies the map to them. */
export default function MapRosterDrawer({ people, selectedId, onSelect, onClose }) {
    const [q, setQ] = useState('');
    const [status, setStatus] = useState('all');
    const rows = useMemo(() => {
        const needle = q.trim().toLowerCase();
        return people
            .filter((f) => (status === 'all' || f.person.status === status)
                && (!needle || `${f.person.name} ${f.person.employee_id} ${f.person.designation ?? ''}`.toLowerCase().includes(needle)))
            .sort((a, b) => a.person.name.localeCompare(b.person.name));
    }, [people, q, status]);

    return (
        <aside className="cy-drawer" aria-label="Roster">
            <div className="cy-drawer__head">
                <span>Roster · {rows.length}</span>
                <button type="button" className="cy-pop__close" onClick={onClose} aria-label="Close roster"><Icon name="x-lg" /></button>
            </div>
            <div className="cy-drawer__search">
                <label className="cy-field">Search people
                    <input type="search" value={q} onChange={(e) => setQ(e.target.value)} placeholder="Name, ID or designation" />
                </label>
                <div className="cy-map-side__presets" role="group" aria-label="Status filter">
                    {[['all', 'All'], ['active', 'Active'], ['completed', 'Done']].map(([k, label]) => (
                        <button key={k} type="button" className="cy-map__btn" aria-pressed={status === k} onClick={() => setStatus(k)}>{label}</button>
                    ))}
                </div>
            </div>
            <ul className="cy-drawer__list">
                {rows.length === 0 && <li className="cy-pop__sub" style={{ padding: '0.75rem' }}>Nobody matches.</li>}
                {rows.map((f) => (
                    <li key={f.id}>
                        <button type="button" className="cy-drawer__item" aria-current={selectedId === f.id} onClick={() => onSelect(f)}>
                            <Avatar name={f.person.name} photo={f.person.photo} />
                            <span className="cy-drawer__text">
                                <span className="cy-pop__name" style={{ display: 'block' }}>{f.person.name}</span>
                                <span className="cy-pop__sub" style={{ display: 'block' }}>{clockOf(f.person.punch_in?.time) ?? '--:--'} to {clockOf(f.person.punch_out?.time) ?? 'now'}</span>
                            </span>
                            <Badge color={f.person.status === 'completed' ? 'info' : 'success'}>{f.person.status === 'completed' ? 'Done' : 'Active'}</Badge>
                        </button>
                    </li>
                ))}
            </ul>
        </aside>
    );
}
