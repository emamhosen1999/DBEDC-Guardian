import React from 'react';
import { Box, Flex, Text, Tooltip } from '@radix-ui/themes';
import dayjs from 'dayjs';

/**
 * Presentational employees × days roster grid.
 * roster: { [userId]: { name, days: { 'YYYY-MM-DD': { code, color, off } } } }
 */
export default function RosterCalendar({ roster = {}, days = [], onCellClick }) {
    const rows = Object.entries(roster);

    if (rows.length === 0) {
        return (
            <Flex direction="column" align="center" py="9" gap="3">
                <Text size="2" color="gray">No roster data for this range</Text>
            </Flex>
        );
    }

    return (
        <Box style={{ overflowX: 'auto' }}>
            <Flex>
                <Box
                    p="2"
                    style={{
                        minWidth: 160, position: 'sticky', left: 0, zIndex: 2,
                        background: 'var(--color-panel)',
                    }}
                >
                    <Text size="2" weight="bold">Employee</Text>
                </Box>
                {days.map(d => (
                    <Box key={d} style={{ minWidth: 40, textAlign: 'center' }}>
                        <Text size="1" color="gray">{dayjs(d).format('D')}</Text>
                    </Box>
                ))}
            </Flex>

            {rows.map(([userId, row]) => (
                <Flex key={userId} align="center">
                    <Box
                        p="2"
                        style={{
                            minWidth: 160, position: 'sticky', left: 0, zIndex: 1,
                            background: 'var(--color-panel)',
                        }}
                    >
                        <Text size="2">{row.name || 'Unknown'}</Text>
                    </Box>
                    {days.map(d => {
                        const cell = row.days?.[d];
                        return (
                            <Box
                                key={d}
                                onClick={() => onCellClick?.(userId, d, cell)}
                                style={{
                                    minWidth: 40, height: 32, cursor: onCellClick ? 'pointer' : 'default',
                                    display: 'flex', alignItems: 'center', justifyContent: 'center',
                                    background: cell?.off ? 'transparent' : (cell?.color || 'var(--gray-a3)'),
                                    color: cell && !cell.off ? '#fff' : 'var(--gray-9)',
                                    borderRadius: 4, margin: 1,
                                }}
                            >
                                <Tooltip content={cell ? (cell.off ? 'Off' : (cell.code || 'Assigned')) : 'No assignment'}>
                                    <Text size="1">{cell ? (cell.off ? '—' : (cell.code || '·')) : ''}</Text>
                                </Tooltip>
                            </Box>
                        );
                    })}
                </Flex>
            ))}
        </Box>
    );
}
