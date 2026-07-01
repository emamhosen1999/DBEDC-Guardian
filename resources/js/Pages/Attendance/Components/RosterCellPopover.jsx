import React from 'react';
import { Popover, Flex, Button, Text, Callout } from '@radix-ui/themes';

/** shifts: [{id,code,name,color}]; onPick(shiftIdOrNull) */
export default function RosterCellPopover({ open, onOpenChange, anchor, shifts = [], notice = null, onPick }) {
    return (
        <Popover.Root open={open} onOpenChange={onOpenChange}>
            <Popover.Trigger>{anchor}</Popover.Trigger>
            <Popover.Content width="220px">
                {notice && (
                    <Callout.Root color="amber" size="1" mb="2">
                        <Callout.Text>{notice}</Callout.Text>
                    </Callout.Root>
                )}
                <Text size="1" color="gray">Assign shift</Text>
                <Flex direction="column" gap="1" mt="2">
                    {shifts.map(s => (
                        <Button
                            key={s.id}
                            size="1"
                            variant="soft"
                            style={{ justifyContent: 'flex-start' }}
                            onClick={() => onPick(s.id)}
                        >
                            <span style={{
                                width: 10, height: 10, borderRadius: 2,
                                background: s.color || 'var(--gray-9)',
                                display: 'inline-block', marginRight: 6, flexShrink: 0,
                            }} />
                            {s.name} ({s.code})
                        </Button>
                    ))}
                    <Button size="1" variant="soft" color="gray" onClick={() => onPick(null)}>
                        Off (clear)
                    </Button>
                </Flex>
            </Popover.Content>
        </Popover.Root>
    );
}
