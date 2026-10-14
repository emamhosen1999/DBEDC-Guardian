import React from 'react';
import { useQuery } from '@tanstack/react-query';

import { Alert, Badge, Button, Icon, Timeline } from '@/Components/Cyber';

import { formatDay, getJson } from './jurisdictionUtils.js';

const KIND = {
    boundary: { icon: 'signpost-split', tone: 'info', label: 'Boundary' },
    in_charge: { icon: 'person-check', tone: 'theme', label: 'In-charge' },
    member: { icon: 'people', tone: 'secondary', label: 'Team' },
    handover: { icon: 'arrow-left-right', tone: 'warning', label: 'Handover' },
};

/** Boundary versions, in-charge tenures, member changes and handovers of one jurisdiction, newest first. */
export default function JurisdictionHistory({ jurisdiction, canManageTeam, onTransfer }) {
    const q = useQuery({
        queryKey: ['jurisdictions', 'timeline', jurisdiction.id],
        queryFn: () => getJson(route('jurisdictions.timeline', jurisdiction.id)),
        staleTime: 15_000,
    });

    if (q.isError) return <Alert tone="danger">The history could not be loaded. <Button size="sm" variant="outline" color="secondary" onClick={() => q.refetch()}>Retry</Button></Alert>;
    if (q.isLoading) return <p className="jr-empty" role="status">Loading the history…</p>;

    const events = q.data.events.map((e) => ({
        id: e.id,
        date: e.date,
        title: e.title,
        icon: KIND[e.kind]?.icon,
        tone: e.correction ? 'danger' : KIND[e.kind]?.tone,
        badge: (
            <>
                <Badge soft color={KIND[e.kind]?.tone === 'theme' ? 'theme' : KIND[e.kind]?.tone ?? 'secondary'}>{KIND[e.kind]?.label}</Badge>
                {e.correction && <Badge soft color="danger">Back-dated correction</Badge>}
            </>
        ),
        detail: (
            <>
                {[e.designation, e.detail].filter(Boolean).join(', ')}
                {e.reason ? <> · {e.reason}</> : null}
                {e.kind === 'handover' && (
                    <span className="jr-detail__actions">
                        <Button as="a" size="sm" variant="outline" color="secondary" href={route('jurisdictions.handover.report', [jurisdiction.id, e.handover_id])} target="_blank" rel="noopener"><Icon name="file-earmark-pdf" /> Handover report</Button>
                        {canManageTeam && <Button size="sm" variant="outline" color="secondary" onClick={() => onTransfer(e.handover_id)}><Icon name="arrow-left-right" /> Move open works</Button>}
                    </span>
                )}
            </>
        ),
    }));

    return <Timeline events={events.map((e) => ({ ...e, date: e.date ? formatDay(e.date) : null }))} label={`History of ${jurisdiction.name}`} empty="No history is recorded for this jurisdiction." />;
}
