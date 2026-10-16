import React from 'react';
import { useQuery } from '@tanstack/react-query';
import dayjs from 'dayjs';
import { Badge, Button } from '@/Components/Cyber';
import { requestJson } from '@/api/client';

const TYPE = { rest_day: 'Rest day', holiday: 'Holiday' };

/**
 * "Overtime worked, not claimed": ONLY rest-day and holiday work, with the eligible credit already worked out (never working-day
 * extra hours). A rest day or holiday with a punch-in but no punch-out is listed too: the claim then rides on a declared end time.
 * Each row has a one-tap "Apply" that opens the claim form prefilled with the date.
 */
export default function UnclaimedOvertime({ onApply }) {
    const q = useQuery({ queryKey: ['overtime-unclaimed'], queryFn: () => requestJson('get', '/attendance/overtime/unclaimed') });
    const rows = Array.isArray(q.data) ? q.data : [];
    if (q.isLoading) return <div className="dl-empty"><span className="cy-row__sub">Loading…</span></div>;
    if (!rows.length) return <div className="dl-empty"><span className="cy-row__sub">Nothing to claim: no unclaimed work on a rest day or holiday.</span></div>;

    return (
        <ul className="dl-list" aria-label="Overtime worked, not claimed">
            {rows.map((r) => (
                <li key={r.date} className="dl-list__row">
                    <div className="cy-row">
                        <div>
                            <span className="cy-row__title">{dayjs(r.date).format('ddd, DD MMM YYYY')}</span>
                            <span className="cy-row__sub">
                                {r.punch_out_missing
                                    ? `${TYPE[r.day_type]}, punch-out missing: claim ${r.day_type === 'holiday' ? 'overtime' : 'replacement leave'}`
                                    : `${TYPE[r.day_type]}${r.holiday_on_rest_day ? ' (holiday on your rest day)' : ''} · ${r.credit === 1 ? '1 day' : `${r.credit} day`} to claim`}
                            </span>
                        </div>
                        <span className="cy-actions">
                            {r.punch_out_missing && <Badge soft color="warning">No punch-out</Badge>}
                            <Button size="sm" onClick={() => onApply?.({ date: r.date })}>Apply for overtime</Button>
                        </span>
                    </div>
                </li>
            ))}
        </ul>
    );
}
