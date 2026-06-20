import React from 'react';
import { Box, Flex, Text, Tooltip } from '@radix-ui/themes';
import dayjs from 'dayjs';

/**
 * Presentational employees × days roster grid.
 * roster: { [userId]: { name, days: { 'YYYY-MM-DD': { code, color, off } } } }
 *
 * Fixed-width columns + light grid borders so the day-number header aligns
 * exactly with the cells in every row.
 */
const NAME_W = 168;
const CELL_W = 46;
const ROW_H = 36;
const LINE = '1px solid var(--gray-a5)';

export default function RosterCalendar({ roster = {}, days = [], onCellClick }) {
    const rows = Object.entries(roster);

    if (rows.length === 0) {
        return (
            <Flex direction="column" align="center" py="9" gap="3">
                <Text size="2" color="gray">No roster data for this range</Text>
            </Flex>
        );
    }

    const gridWidth = NAME_W + days.length * CELL_W;

    const nameCell = (children, { header = false, zIndex = 1 } = {}) => (
        <Box
            style={{
                minWidth: NAME_W, width: NAME_W, height: ROW_H,
                position: 'sticky', left: 0, zIndex,
                background: header ? 'var(--gray-a2)' : 'var(--color-panel)',
                borderRight: LINE, borderBottom: LINE,
                display: 'flex', alignItems: 'center', padding: '0 12px',
            }}
        >
            {children}
        </Box>
    );

    return (
        <Box style={{ overflowX: 'auto', border: LINE, borderRadius: 8 }}>
            <Box style={{ minWidth: gridWidth }}>
                {/* ── Day header ─────────────────────────────── */}
                <Flex style={{ background: 'var(--gray-a2)' }}>
                    {nameCell(<Text size="2" weight="bold">Employee</Text>, { header: true, zIndex: 3 })}
                    {days.map((d) => {
                        const wd = dayjs(d).day(); // 0 Sun … 6 Sat
                        const weekend = wd === 5 || wd === 6; // Fri/Sat (local week)
                        return (
                            <Box
                                key={d}
                                style={{
                                    width: CELL_W, minWidth: CELL_W, height: ROW_H,
                                    borderRight: LINE, borderBottom: LINE,
                                    background: weekend ? 'var(--gray-a3)' : 'transparent',
                                    display: 'flex', flexDirection: 'column',
                                    alignItems: 'center', justifyContent: 'center', lineHeight: 1.1,
                                }}
                            >
                                <Text size="1" weight="medium">{dayjs(d).format('D')}</Text>
                                <Text size="1" color="gray" style={{ fontSize: 9 }}>{dayjs(d).format('dd')}</Text>
                            </Box>
                        );
                    })}
                </Flex>

                {/* ── Employee rows ──────────────────────────── */}
                {rows.map(([userId, row], i) => (
                    <Flex key={userId} style={{ background: i % 2 ? 'var(--gray-a1)' : 'transparent' }}>
                        {nameCell(<Text size="2">{row.name || 'Unknown'}</Text>, { zIndex: 1 })}
                        {days.map((d) => {
                            const cell = row.days?.[d];
                            const isOff = cell?.off;
                            const assigned = cell && !isOff;
                            return (
                                <Box
                                    key={d}
                                    onClick={() => onCellClick?.(userId, d, cell)}
                                    style={{
                                        width: CELL_W, minWidth: CELL_W, height: ROW_H,
                                        borderRight: LINE, borderBottom: LINE, padding: 4,
                                        boxSizing: 'border-box',
                                        cursor: onCellClick ? 'pointer' : 'default',
                                    }}
                                >
                                    <Tooltip content={cell ? (isOff ? 'Off' : (cell.code || 'Assigned')) : 'No assignment'}>
                                        <Box
                                            style={{
                                                width: '100%', height: '100%', borderRadius: 4,
                                                display: 'flex', alignItems: 'center', justifyContent: 'center',
                                                background: assigned ? (cell.color || 'var(--accent-9)') : 'transparent',
                                                border: isOff ? '1px dashed var(--gray-a6)' : 'none',
                                                color: assigned ? '#fff' : 'var(--gray-8)',
                                                fontSize: 9, fontWeight: 700, letterSpacing: 0.2,
                                            }}
                                        >
                                            {assigned ? (cell.code || '·') : ''}
                                        </Box>
                                    </Tooltip>
                                </Box>
                            );
                        })}
                    </Flex>
                ))}
            </Box>
        </Box>
    );
}
