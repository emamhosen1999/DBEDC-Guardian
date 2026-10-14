import React, { useMemo, useState } from 'react';

import { Badge, Tree } from '@/Components/Cyber';
import { Avatar } from '@/Components/Cyber/Map/Avatar.jsx';

import { ROLE_LABEL, chainageRange, lengthKm } from './jurisdictionUtils.js';

export const POSITION_LABEL = { in_charge: 'In-charge', reporting: 'Reports to the in-charge', assigned: 'Assigned to the jurisdiction' };
const ATTENDANCE_TONE = { present: 'success', on_leave: 'info', absent: 'danger', not_rostered: 'secondary', unknown: 'secondary' };
const positionText = (n) => [POSITION_LABEL[n.position], n.assigned_role && n.position !== 'assigned' ? `also assigned as ${ROLE_LABEL[n.assigned_role].toLowerCase()}` : (n.position === 'assigned' ? ROLE_LABEL[n.assigned_role]?.toLowerCase() : null)].filter(Boolean).join(', ');

/** One person in a tree: avatar, name, designation, department, position, works in the period, attendance, contact when allowed. */
function Person({ node, periodLabel }) {
    return (
        <span className="jr-person">
            <Avatar name={node.name} photo={node.photo} />
            <span className="jr-person__text">
                <strong>{node.name ?? node.employee_id} <small>{node.employee_id}</small></strong>
                <small>{[node.designation, node.department].filter(Boolean).join(', ') || 'Employee'}</small>
                <small>{positionText(node)}</small>
                {node.contact && (node.contact.phone || node.contact.email) && <small>{[node.contact.phone, node.contact.email].filter(Boolean).join(' · ')}</small>}
                {node.warnings?.map((w) => <small key={w}><Badge soft color="warning">Data quality</Badge> {w}</small>)}
            </span>
            <span className="jr-person__works">
                <Badge soft color={ATTENDANCE_TONE[node.attendance?.status] ?? 'secondary'} title="Attendance on the as-of date">{node.attendance?.label ?? 'No data'}</Badge>{' '}
                <Badge soft color={node.works ? 'theme' : 'secondary'} title={`Works in ${periodLabel}`}>{node.works} works</Badge>
            </span>
        </span>
    );
}

const toItems = (nodes, jid, periodLabel) => nodes.map((n) => ({
    id: `${jid}:${n.employee_id}`,
    content: <Person node={n} periodLabel={periodLabel} />,
    children: n.children?.length ? toItems(n.children, jid, periodLabel) : undefined,
    raw: n,
}));

/** The team of one jurisdiction: in-charge, then deputy in-charges, then members (WAI-ARIA tree). */
export function JurisdictionTeamTree({ jurisdiction, periodLabel, onSelectMember }) {
    const [selected, setSelected] = useState(null);
    const items = useMemo(() => toItems(jurisdiction.team, jurisdiction.id, periodLabel), [jurisdiction.team, jurisdiction.id, periodLabel]);
    if (!items.length) return <p className="jr-empty" role="status">No team is recorded for this jurisdiction on this date.</p>;
    return <Tree items={items} label={`Team of ${jurisdiction.name}`} selectedId={selected} onSelect={(item) => { setSelected(item.id); onSelectMember?.(item.raw); }} />;
}

/** Corridor, jurisdictions, teams: the whole picture as one tree. */
export function CorridorTree({ jurisdictions, coverage, periodLabel, selectedId, onSelect }) {
    const items = useMemo(() => [{
        id: 'corridor',
        content: <span className="file-text"><strong>Dhaka Bypass Expressway</strong> <small className="file-meta">K0+000 to K48+999, {coverage.coverage_pct}% covered</small></span>,
        children: jurisdictions.map((j) => ({
            id: `j:${j.id}`,
            content: (
                <span className="file-text">
                    <span className="jr-swatch" style={{ '--jr-c': `var(--jr-c-${j.colour_token})` }} aria-hidden="true" />
                    <strong>{j.code} {j.name}</strong> <small className="file-meta">{chainageRange({ start_m: j.start_m, end_m: j.end_m })}, {lengthKm(j.length_m)}</small>
                </span>
            ),
            children: toItems(j.team, j.id, periodLabel),
            jurisdictionId: j.id,
        })),
    }], [jurisdictions, coverage.coverage_pct, periodLabel]);
    return <Tree items={items} label="Corridor, jurisdictions and teams" selectedId={selectedId ? `j:${selectedId}` : undefined} onSelect={(item) => item.jurisdictionId && onSelect(item.jurisdictionId)} />;
}
