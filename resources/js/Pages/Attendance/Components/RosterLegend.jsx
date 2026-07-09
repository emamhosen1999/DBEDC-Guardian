import React from 'react';
import { Flex, Box, Text } from '@radix-ui/themes';

/**
 * Shared shift legend for the roster views (grid + per-employee).
 * Maps each shift's colour to its code + name, plus Off / Holiday markers,
 * so the colour coding is readable in every view.
 */
export default function RosterLegend({ shifts = [], showHoliday = false }) {
    if (!shifts.length) return null;
    return (
        <Flex align="center" gap="4" wrap="wrap" mb="3" px="1">
            <Text size="1" color="gray" weight="medium">Legend</Text>
            {shifts.map(s => (
                <Flex key={s.id} align="center" gap="1">
                    <Box style={{ width: 12, height: 12, borderRadius: 3, background: s.color || 'var(--accent-9)', flexShrink: 0 }} />
                    <Text size="1" weight="bold">{s.code}</Text>
                    <Text size="1" color="gray">{s.name}</Text>
                </Flex>
            ))}
            <Flex align="center" gap="1">
                <Box style={{ width: 12, height: 12, borderRadius: 3, border: '1px dashed var(--gray-a7)', flexShrink: 0 }} />
                <Text size="1" color="gray">Off</Text>
            </Flex>
            {showHoliday && (
                <Flex align="center" gap="1">
                    <Box style={{ width: 12, height: 12, borderRadius: 3, background: 'var(--amber-9)', flexShrink: 0 }} />
                    <Text size="1" color="gray">Holiday</Text>
                </Flex>
            )}
        </Flex>
    );
}
