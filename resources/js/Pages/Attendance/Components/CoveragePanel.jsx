import React, { useMemo } from 'react';
import { Badge, Card } from '@/Components/Cyber';
import { useQuery } from '@tanstack/react-query';
import { usePage } from '@inertiajs/react';
import { requestJson } from '@/api/client';
import { resolveCoverageCellDisplay } from '../coverageCellDisplay';

/**
 * Location × Shift coverage matrix for [from,to], aggregated across the range
 * by worst-status per cell (total AND role requirements), plus an
 * understaffed-posts list that includes role-level gaps.
 */
export default function CoveragePanel({ from, to, isActive = true }) {
    const { designations = [] } = usePage().props;

    const { data: covData } = useQuery({
        queryKey: ['coverage', from, to],
        queryFn: () => requestJson('get', '/attendance/coverage', { params: { from, to } }),
        enabled: isActive,
    });
    const { data: locData } = useQuery({
        queryKey: ['work-locations'],
        queryFn: () => requestJson('get', '/attendance/work-locations'),
        enabled: isActive,
    });
    const { data: shiftData } = useQuery({
        queryKey: ['shifts'],
        queryFn: () => requestJson('get', '/attendance/shifts'),
        enabled: isActive,
    });

    const coverage = covData?.coverage || {};
    const locations = locData?.work_locations || [];
    const shifts = shiftData?.shifts || [];

    // Reduce the date-keyed payload to a worst-status-per (location,shift) summary + gaps list.
    // A cell's status is the worst of its total requirement AND any role requirement, so a
    // met total with a missing role still surfaces as understaffed.
    const { matrix, gaps } = useMemo(() => {
        const rank = { understaffed: 3, overstaffed: 2, met: 1, untracked: 0, null: 0 };
        const m = {}; // [loc:shift] = {required, assigned, status}
        const g = []; // understaffed rows (total + role)
        Object.entries(coverage).forEach(([date, locs]) => {
            Object.entries(locs).forEach(([locId, shiftsObj]) => {
                Object.entries(shiftsObj).forEach(([shiftId, cell]) => {
                    const t = cell.total || {};
                    const roles = cell.roles || {};
                    const key = `${locId}:${shiftId}`;

                    let worst = t.status || 'untracked';
                    Object.values(roles).forEach(r => {
                        if ((rank[r.status] ?? 0) > (rank[worst] ?? 0)) worst = r.status;
                    });

                    const prev = m[key];
                    if (!prev || (rank[worst] ?? 0) > (rank[prev.status] ?? 0)) {
                        m[key] = { required: t.required, assigned: t.assigned, status: worst };
                    }

                    if (t.status === 'understaffed') {
                        g.push({ date, locId, shiftId, role: null, required: t.required, assigned: t.assigned });
                    }
                    Object.entries(roles).forEach(([desigId, r]) => {
                        if (r.status === 'understaffed') {
                            g.push({ date, locId, shiftId, role: desigId, required: r.required, assigned: r.assigned });
                        }
                    });
                });
            });
        });
        return { matrix: m, gaps: g };
    }, [coverage]);

    const locName = (id) => locations.find(l => String(l.id) === String(id))?.name || `#${id}`;
    const shiftCode = (id) => shifts.find(s => String(s.id) === String(id))?.code || `#${id}`;
    const roleName = (id) => designations.find(d => String(d.id) === String(id))?.title || `role #${id}`;

    if (locations.length === 0 || shifts.length === 0) {
        return null;
    }

    return (
        <div className="dl-row">
            <div className="dl-col dl-col--12">
                <Card
                    id="attendance-coverage"
                    title={`Coverage: ${from} to ${to}`}
                    flush
                    actions={gaps.length > 0 ? <Badge soft color="danger">{gaps.length} understaffed</Badge> : null}
                    footer={gaps.length > 0 ? (
                        <span className="cy-fresh">Understaffed: {gaps.slice(0, 12).map(g => `${g.date} ${locName(g.locId)}/${shiftCode(g.shiftId)}${g.role ? ` [${roleName(g.role)}]` : ''} (${g.assigned}/${g.required})`).join(' · ')}{gaps.length > 12 ? ' …' : ''}</span>
                    ) : null}
                >
                    <div className="cy-dt cy-coverage" tabIndex={0} role="region" aria-label="Coverage by post and shift, scrollable">
                        <table className="cy-table">
                            <thead>
                                <tr>
                                    <th scope="col">Post</th>
                                    {shifts.map(s => <th key={s.id} scope="col" className="cy-num">{s.code}</th>)}
                                </tr>
                            </thead>
                            <tbody>
                                {locations.map(loc => (
                                    <tr key={loc.id}>
                                        <th scope="row" className="cy-coverage__post">{loc.name}</th>
                                        {shifts.map(s => {
                                            const cell = matrix[`${loc.id}:${s.id}`];
                                            if (!cell) return <td key={s.id} className="cy-num cy-muted" aria-label={`${loc.name}, ${s.code}: no requirement`}>-</td>;
                                            const disp = resolveCoverageCellDisplay(cell);
                                            return (
                                                <td key={s.id} className="cy-num">
                                                    <span className="cy-coverage__cell" style={{ background: disp.color }} title={`${loc.name} · ${s.code}: ${disp.status}`} aria-label={`${loc.name}, ${s.code}: ${disp.status}, ${disp.label}`}>{disp.label}</span>
                                                </td>
                                            );
                                        })}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Card>
            </div>
        </div>
    );
}
