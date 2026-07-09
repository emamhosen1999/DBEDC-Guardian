import React, { useMemo } from 'react';
import { Box, Flex, Text, Select } from '@radix-ui/themes';
import { PersonIcon } from '@radix-ui/react-icons';
import dayjs from 'dayjs';
import { resolveRosterCellDisplay } from '../rosterCellDisplay';

/**
 * Per-employee month calendar (Saturday-start week) — a readable single-person
 * view of the roster. Uses the same design tokens + cell display logic as the
 * grid so the two views stay visually consistent.
 *
 * roster: { [userId]: { name, days: { 'YYYY-MM-DD': { code, color, off, leave } } } }
 */
const WEEKDAYS = ['Sat', 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri'];
const colIndex = (d) => (dayjs(d).day() + 1) % 7; // Sat→0, Sun→1 … Fri→6
const LINE = '1px solid var(--gray-a5)';

export default function RosterEmployeeView({
    employees = [], roster = {}, days = [], holidays = {}, shifts = [],
    selectedUserId, onSelectUser, onCellClick,
}) {
    const row = roster?.[selectedUserId] ?? roster?.[String(selectedUserId)] ?? null;

    const shiftByCode = useMemo(() => {
        const m = {};
        shifts.forEach(s => { m[s.code] = s; });
        return m;
    }, [shifts]);

    // Summary: count per shift code + off + holiday across the visible month.
    const summary = useMemo(() => {
        const counts = {};
        let off = 0, holiday = 0, working = 0;
        days.forEach(d => {
            if (holidays[d]) { holiday++; return; }
            const cell = row?.days?.[d];
            if (!cell || cell.off) { off++; return; }
            const code = cell.code || '—';
            counts[code] = (counts[code] || 0) + 1;
            working++;
        });
        return { counts, off, holiday, working };
    }, [row, days, holidays]);

    // Calendar weeks (Saturday-start), padded to full weeks.
    const weeks = useMemo(() => {
        if (!days.length) return [];
        const cells = [...Array(colIndex(days[0])).fill(null), ...days];
        while (cells.length % 7 !== 0) cells.push(null);
        const out = [];
        for (let i = 0; i < cells.length; i += 7) out.push(cells.slice(i, i + 7));
        return out;
    }, [days]);

    const colorFor = (code) => shiftByCode[code]?.color || 'var(--accent-9)';

    return (
        <Box>
            {/* ── Person picker + summary ─────────────────────── */}
            <Flex justify="between" align="center" wrap="wrap" gap="3" mb="4">
                <Flex align="center" gap="2">
                    <Text size="2" color="gray">Person</Text>
                    <Select.Root value={selectedUserId ? String(selectedUserId) : undefined} onValueChange={(v) => onSelectUser?.(v)}>
                        <Select.Trigger placeholder="Select employee…" style={{ minWidth: 220 }}>
                            <Flex align="center" gap="2">
                                <PersonIcon />
                                {row?.name || 'Select employee…'}
                            </Flex>
                        </Select.Trigger>
                        <Select.Content>
                            {employees.map(e => (
                                <Select.Item key={e.id} value={String(e.id)}>{e.name}</Select.Item>
                            ))}
                        </Select.Content>
                    </Select.Root>
                </Flex>

                <Flex align="center" gap="2" wrap="wrap">
                    {Object.entries(summary.counts).map(([code, n]) => (
                        <Flex key={code} align="center" gap="1"
                            style={{ border: LINE, borderRadius: 999, padding: '2px 10px', background: 'var(--color-panel)' }}>
                            <Box style={{ width: 9, height: 9, borderRadius: 2, background: colorFor(code) }} />
                            <Text size="1" weight="medium">{code}</Text>
                            <Text size="1" weight="bold">{n}</Text>
                        </Flex>
                    ))}
                    <Flex align="center" gap="1"
                        style={{ border: LINE, borderRadius: 999, padding: '2px 10px' }}>
                        <Box style={{ width: 9, height: 9, borderRadius: 2, border: '1px dashed var(--gray-a7)' }} />
                        <Text size="1" weight="medium" color="gray">OFF</Text>
                        <Text size="1" weight="bold" color="gray">{summary.off}</Text>
                    </Flex>
                    {summary.holiday > 0 && (
                        <Flex align="center" gap="1"
                            style={{ border: LINE, borderRadius: 999, padding: '2px 10px' }}>
                            <Box style={{ width: 9, height: 9, borderRadius: 2, background: 'var(--amber-9)' }} />
                            <Text size="1" weight="medium" color="amber">Holiday</Text>
                            <Text size="1" weight="bold" color="amber">{summary.holiday}</Text>
                        </Flex>
                    )}
                </Flex>
            </Flex>

            {!row ? (
                <Flex align="center" justify="center" py="9">
                    <Text size="2" color="gray">Select an employee to view their schedule.</Text>
                </Flex>
            ) : (
                <Box style={{ border: LINE, borderRadius: 8, overflow: 'hidden' }}>
                    {/* Weekday header */}
                    <Box style={{ display: 'grid', gridTemplateColumns: 'repeat(7, 1fr)', background: 'var(--gray-a2)' }}>
                        {WEEKDAYS.map(w => (
                            <Box key={w} style={{ padding: '8px 0', textAlign: 'center', borderRight: LINE, borderBottom: LINE }}>
                                <Text size="2" weight="bold" color={w === 'Fri' || w === 'Sat' ? 'gray' : undefined}>{w}</Text>
                            </Box>
                        ))}
                    </Box>

                    {/* Weeks */}
                    {weeks.map((week, wi) => (
                        <Box key={wi} style={{ display: 'grid', gridTemplateColumns: 'repeat(7, 1fr)' }}>
                            {week.map((d, di) => {
                                if (!d) {
                                    return <Box key={di} style={{ minHeight: 74, borderRight: LINE, borderBottom: LINE, background: 'var(--gray-a1)' }} />;
                                }
                                const cell = row.days?.[d];
                                const disp = resolveRosterCellDisplay(cell, holidays[d]);
                                const isOff = disp.kind === 'off';
                                const isHoliday = disp.kind === 'holiday';
                                const chipColor = disp.color || colorFor(disp.label);
                                return (
                                    <Box
                                        key={d}
                                        onClick={() => onCellClick?.(String(selectedUserId), d, cell)}
                                        style={{
                                            minHeight: 74, borderRight: LINE, borderBottom: LINE,
                                            padding: 6, boxSizing: 'border-box',
                                            display: 'flex', flexDirection: 'column', gap: 4,
                                            cursor: onCellClick ? 'pointer' : 'default',
                                            background: isHoliday ? 'var(--amber-a2)' : 'transparent',
                                        }}
                                    >
                                        <Text size="1" color="gray" style={{ lineHeight: 1 }}>{dayjs(d).format('D')}</Text>
                                        <Flex align="center" justify="center" style={{ flex: 1 }}>
                                            {isHoliday ? (
                                                <Text size="1" weight="bold" color="amber">HOLIDAY</Text>
                                            ) : isOff ? (
                                                <Box style={{
                                                    width: '100%', textAlign: 'center', padding: '6px 0', borderRadius: 6,
                                                    border: '1px dashed var(--gray-a6)',
                                                }}>
                                                    <Text size="1" weight="bold" color="gray">OFF</Text>
                                                </Box>
                                            ) : (
                                                <Box style={{
                                                    width: '100%', textAlign: 'center', padding: '6px 0', borderRadius: 6,
                                                    background: chipColor,
                                                    border: disp.kind === 'pending' ? '1px dashed var(--amber-8)' : 'none',
                                                }}>
                                                    <Text size="1" weight="bold" style={{ color: '#fff' }}>{disp.label}</Text>
                                                </Box>
                                            )}
                                        </Flex>
                                    </Box>
                                );
                            })}
                        </Box>
                    ))}
                </Box>
            )}
        </Box>
    );
}
