import React, { useState, useEffect } from 'react';
import { Popover, Flex, Box, Button, Text, Callout, Select } from '@radix-ui/themes';

/** shifts: [{id,code,name,color}]; onPick(shiftIdOrNull, workLocationIdOrNull) */
export default function RosterCellPopover({ open, onOpenChange, anchor, shifts = [], notice = null, workLocations = [], selectedLocationId = null, onPick }) {
    const [locId, setLocId] = useState(selectedLocationId ? String(selectedLocationId) : 'home');
    useEffect(() => { setLocId(selectedLocationId ? String(selectedLocationId) : 'home'); }, [selectedLocationId, open]);

    const pick = (shiftId) => onPick(shiftId, locId === 'home' ? null : Number(locId));

    return (
        <Popover.Root open={open} onOpenChange={onOpenChange}>
            <Popover.Trigger>{anchor}</Popover.Trigger>
            <Popover.Content width="220px">
                {notice && (
                    <Callout.Root color="amber" size="1" mb="2">
                        <Callout.Text>{notice}</Callout.Text>
                    </Callout.Root>
                )}
                {workLocations.length > 0 && (
                    <Box mb="2">
                        <Text size="1" color="gray">Post</Text>
                        <Select.Root value={locId} onValueChange={setLocId}>
                            <Select.Trigger placeholder="Home location" />
                            <Select.Content>
                                <Select.Item value="home">Home location</Select.Item>
                                {workLocations.map(l => <Select.Item key={l.id} value={String(l.id)}>{l.name}</Select.Item>)}
                            </Select.Content>
                        </Select.Root>
                    </Box>
                )}
                <Text size="1" color="gray">Assign shift</Text>
                <Flex direction="column" gap="1" mt="2">
                    {shifts.map(s => (
                        <Button
                            key={s.id}
                            size="1"
                            variant="soft"
                            style={{ justifyContent: 'flex-start' }}
                            onClick={() => pick(s.id)}
                        >
                            <span style={{
                                width: 10, height: 10, borderRadius: 2,
                                background: s.color || 'var(--gray-9)',
                                display: 'inline-block', marginRight: 6, flexShrink: 0,
                            }} />
                            {s.name} ({s.code})
                        </Button>
                    ))}
                    <Button size="1" variant="soft" color="gray" onClick={() => pick(null)}>
                        Off (clear)
                    </Button>
                </Flex>
            </Popover.Content>
        </Popover.Root>
    );
}
