import React, { useMemo } from 'react';
import { Box, Flex, Text, Card, Tooltip, Badge } from '@radix-ui/themes';
import { useQuery } from '@tanstack/react-query';
import { requestJson } from '@/api/client';
import { resolveCoverageCellDisplay } from '../coverageCellDisplay';

/**
 * Location × Shift coverage matrix for [from,to], aggregated across the range
 * by worst-status per cell, plus an understaffed-posts list.
 */
export default function CoveragePanel({ from, to, isActive = true }) {
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
    const { matrix, gaps } = useMemo(() => {
        const rank = { understaffed: 3, overstaffed: 2, met: 1, untracked: 0, null: 0 };
        const m = {}; // [loc][shift] = {required, assigned, status}
        const g = []; // understaffed rows
        Object.entries(coverage).forEach(([date, locs]) => {
            Object.entries(locs).forEach(([locId, shiftsObj]) => {
                Object.entries(shiftsObj).forEach(([shiftId, cell]) => {
                    const t = cell.total;
                    const key = `${locId}:${shiftId}`;
                    const prev = m[key];
                    const status = t.status || 'untracked';
                    if (!prev || (rank[status] ?? 0) > (rank[prev.status] ?? 0)) {
                        m[key] = { required: t.required, assigned: t.assigned, status };
                    }
                    if (status === 'understaffed') {
                        g.push({ date, locId, shiftId, required: t.required, assigned: t.assigned });
                    }
                });
            });
        });
        return { matrix: m, gaps: g };
    }, [coverage]);

    const locName = (id) => locations.find(l => String(l.id) === String(id))?.name || `#${id}`;
    const shiftCode = (id) => shifts.find(s => String(s.id) === String(id))?.code || `#${id}`;

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
                <Flex>
                    <Box style={{ minWidth: 160, width: 160 }} />
                    {shifts.map(s => (
                        <Box key={s.id} style={{ width: 72, textAlign: 'center' }}>
                            <Text size="1" weight="medium">{s.code}</Text>
                        </Box>
                    ))}
                </Flex>
                {locations.map(loc => (
                    <Flex key={loc.id} align="center" style={{ borderTop: '1px solid var(--gray-a4)' }}>
                        <Box style={{ minWidth: 160, width: 160, padding: '6px 4px' }}>
                            <Text size="1">{loc.name}</Text>
                        </Box>
                        {shifts.map(s => {
                            const cell = matrix[`${loc.id}:${s.id}`];
                            if (!cell) {
                                return <Box key={s.id} style={{ width: 72, textAlign: 'center', color: 'var(--gray-6)' }}><Text size="1">–</Text></Box>;
                            }
                            const disp = resolveCoverageCellDisplay(cell);
                            return (
                                <Box key={s.id} style={{ width: 72, padding: 4 }}>
                                    <Tooltip content={`${loc.name} · ${s.code}: ${disp.status}`}>
                                        <Box style={{
                                            borderRadius: 4, textAlign: 'center', padding: '2px 0',
                                            background: disp.color, color: '#fff', fontSize: 11, fontWeight: 700,
                                        }}>
                                            {disp.label}
                                        </Box>
                                    </Tooltip>
                                </Box>
                            );
                        })}
                    </Flex>
                ))}
            </Box>

            {gaps.length > 0 && (
                <Box mt="3">
                    <Text size="1" color="gray">Understaffed: {gaps.slice(0, 12).map(g => `${g.date} ${locName(g.locId)}/${shiftCode(g.shiftId)} (${g.assigned}/${g.required})`).join(' · ')}{gaps.length > 12 ? ' …' : ''}</Text>
                </Box>
            )}
        </Card>
    );
}
