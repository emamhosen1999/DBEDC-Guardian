import React, { useMemo } from 'react';
import { Box, Flex, Text, Card, Tooltip, Badge } from '@radix-ui/themes';
import { useQuery } from '@tanstack/react-query';
import { usePage } from '@inertiajs/react';
import { requestJson } from '@/api/client';
import { resolveCoverageCellDisplay } from '../coverageCellDisplay';

const parseTimeToHours = (timeStr) => {
    if (!timeStr) return 0;
    const parts = timeStr.split(':');
    const h = parseInt(parts[0] || 0, 10);
    const m = parseInt(parts[1] || 0, 10);
    return h + m / 60;
};

const intervalsOverlap = (s1, e1, s2, e2) => {
    return Math.max(s1, s2) < Math.min(e1, e2);
};

const assignLanes = (shiftsList) => {
    const sorted = [...shiftsList].sort((a, b) => parseTimeToHours(a.start_time) - parseTimeToHours(b.start_time));
    const lanes = [];
    
    sorted.forEach(s => {
        let placed = false;
        const hStart = parseTimeToHours(s.start_time);
        const hEnd = parseTimeToHours(s.end_time);
        const crosses = s.crosses_midnight || hEnd < hStart;
        
        const parts1 = crosses ? [[hStart, 24], [0, hEnd]] : [[hStart, hEnd]];

        for (let i = 0; i < lanes.length; i++) {
            const hasOverlap = lanes[i].some(existing => {
                const exStart = parseTimeToHours(existing.start_time);
                const exEnd = parseTimeToHours(existing.end_time);
                const exCrosses = existing.crosses_midnight || exEnd < exStart;
                const parts2 = exCrosses ? [[exStart, 24], [0, exEnd]] : [[exStart, exEnd]];

                for (const [s1, e1] of parts1) {
                    for (const [s2, e2] of parts2) {
                        if (intervalsOverlap(s1, e1, s2, e2)) return true;
                    }
                }
                return false;
            });

            if (!hasOverlap) {
                lanes[i].push(s);
                s.lane = i;
                placed = true;
                break;
            }
        }

        if (!placed) {
            lanes.push([s]);
            s.lane = lanes.length - 1;
        }
    });
    return lanes.length;
};

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

    const { matrix, gaps } = useMemo(() => {
        const rank = { understaffed: 3, overstaffed: 2, met: 1, untracked: 0, null: 0 };
        const m = {};
        const g = [];
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

    const { shiftsWithLanes, totalLanes } = useMemo(() => {
        const list = shifts.map(s => ({ ...s }));
        const numLanes = assignLanes(list);
        return { shiftsWithLanes: list, totalLanes: numLanes };
    }, [shifts]);

    const locName = (id) => locations.find(l => String(l.id) === String(id))?.name || `#${id}`;
    const shiftCode = (id) => shifts.find(s => String(s.id) === String(id))?.code || `#${id}`;
    const roleName = (id) => designations.find(d => String(d.id) === String(id))?.title || `role #${id}`;

    if (locations.length === 0 || shifts.length === 0) {
        return null;
    }

    return (
        <Card mb="4">
            <Flex justify="between" align="center" mb="3">
                <Text size="2" weight="bold">Coverage — {from} → {to}</Text>
                {gaps.length > 0 && <Badge color="red">{gaps.length} understaffed</Badge>}
            </Flex>

            <Box style={{ overflowX: 'auto' }}>
                <Box style={{ minWidth: 640 }}>
                    {/* Header timeline axis */}
                    <Flex align="center" style={{ marginBottom: 8 }}>
                        <Box style={{ minWidth: 160, width: 160 }}>
                            <Text size="1" color="gray" weight="bold">LOCATION</Text>
                        </Box>
                        <Box style={{ flex: 1, position: 'relative', height: 20 }}>
                            {[0, 4, 8, 12, 16, 20, 24].map(h => (
                                <Box
                                    key={h}
                                    style={{
                                        position: 'absolute',
                                        left: `${(h / 24) * 100}%`,
                                        transform: 'translateX(-50%)',
                                        whiteSpace: 'nowrap'
                                    }}
                                >
                                    <Text size="1" color="gray" weight="bold">
                                        {h === 24 ? '24:00' : `${String(h).padStart(2, '0')}:00`}
                                    </Text>
                                </Box>
                            ))}
                        </Box>
                    </Flex>

                    {/* Locations tracks */}
                    {locations.map(loc => (
                        <Flex key={loc.id} align="center" style={{ borderTop: '1px solid var(--gray-a4)', padding: '6px 0' }}>
                            <Box style={{ minWidth: 160, width: 160, paddingRight: 8 }}>
                                <Text size="1" weight="medium">{loc.name}</Text>
                            </Box>
                            
                            <Box style={{ flex: 1, position: 'relative', height: totalLanes * 28 + 6, background: 'var(--gray-a2)', borderRadius: 4, overflow: 'hidden' }}>
                                {/* Background Gridlines */}
                                {[0, 4, 8, 12, 16, 20, 24].map(h => (
                                    <Box
                                        key={h}
                                        style={{
                                            position: 'absolute',
                                            left: `${(h / 24) * 100}%`,
                                            top: 0,
                                            bottom: 0,
                                            width: 1,
                                            borderLeft: h === 12 ? '1px dashed var(--gray-a6)' : '1px dashed var(--gray-a4)',
                                            pointerEvents: 'none',
                                            zIndex: 0
                                        }}
                                    />
                                ))}

                                {/* Shift Chips */}
                                {shiftsWithLanes.map(s => {
                                    const cell = matrix[`${loc.id}:${s.id}`];
                                    if (!cell) return null;
                                    const disp = resolveCoverageCellDisplay(cell);
                                    
                                    const hStart = parseTimeToHours(s.start_time);
                                    const hEnd = parseTimeToHours(s.end_time);
                                    const crosses = s.crosses_midnight || hEnd < hStart;

                                    const renderChip = (leftPct, widthPct, segmentKey) => (
                                        <Tooltip key={`${s.id}-${segmentKey}`} content={`${loc.name} · ${s.code} (${s.start_time.substring(0,5)}-${s.end_time.substring(0,5)}): ${disp.status}`}>
                                            <Box
                                                style={{
                                                    position: 'absolute',
                                                    left: `${leftPct}%`,
                                                    width: `${widthPct}%`,
                                                    top: s.lane * 28 + 4,
                                                    height: 22,
                                                    background: disp.color,
                                                    color: '#fff',
                                                    fontSize: 10,
                                                    fontWeight: 700,
                                                    borderRadius: 4,
                                                    display: 'flex',
                                                    alignItems: 'center',
                                                    justifyContent: 'center',
                                                    cursor: 'pointer',
                                                    zIndex: 1,
                                                    padding: '0 4px',
                                                    boxShadow: 'var(--shadow-1)',
                                                    overflow: 'hidden',
                                                    textOverflow: 'ellipsis',
                                                    whiteSpace: 'nowrap'
                                                }}
                                            >
                                                {s.code} ({disp.label})
                                            </Box>
                                        </Tooltip>
                                    );

                                    if (crosses) {
                                        const p1Width = 24 - hStart;
                                        return (
                                            <React.Fragment key={s.id}>
                                                {p1Width > 0 && renderChip((hStart / 24) * 100, (p1Width / 24) * 100, 'part1')}
                                                {hEnd > 0 && renderChip(0, (hEnd / 24) * 100, 'part2')}
                                            </React.Fragment>
                                        );
                                    } else {
                                        return renderChip((hStart / 24) * 100, ((hEnd - hStart) / 24) * 100, 'full');
                                    }
                                })}
                            </Box>
                        </Flex>
                    ))}
                </Box>
            </Box>

            {gaps.length > 0 && (
                <Box mt="3">
                    <Text size="1" color="gray">Understaffed: {gaps.slice(0, 12).map(g => `${g.date} ${locName(g.locId)}/${shiftCode(g.shiftId)}${g.role ? ` [${roleName(g.role)}]` : ''} (${g.assigned}/${g.required})`).join(' · ')}{gaps.length > 12 ? ' …' : ''}</Text>
                </Box>
            )}
        </Card>
    );
}
