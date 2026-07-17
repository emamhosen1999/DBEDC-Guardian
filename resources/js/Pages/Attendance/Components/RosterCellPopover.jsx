import React, { useState, useEffect } from 'react';
import { Popover, Flex, Box, Button, Text, Callout, Select, Checkbox } from '@radix-ui/themes';
import { toggleShiftId } from '../rosterShiftSelection';

const MAX_SHIFTS = 3;

/**
 * shifts: [{id,code,name,color}]; selectedShiftIds: number[] (the cell's
 * current assignment — derive with rosterShiftSelection.deriveSelectedShiftIds).
 * onPick(shiftIds: number[], workLocationIdOrNull) — fires on Confirm.
 * violations: Violation[]|null — working-time compliance results for the last write
 *   attempt on this cell (either a 200-with-warnings or a 422-blocked response).
 * violationsBlocked: true when the write was rejected (422) and did not apply.
 */
export default function RosterCellPopover({ open, onOpenChange, anchor, shifts = [], selectedShiftIds = [], notice = null, violations = null, violationsBlocked = false, workLocations = [], selectedLocationId = null, onPick }) {
    const [locId, setLocId] = useState(selectedLocationId ? String(selectedLocationId) : 'home');
    const [selectedIds, setSelectedIds] = useState(selectedShiftIds);

    useEffect(() => { setLocId(selectedLocationId ? String(selectedLocationId) : 'home'); }, [selectedLocationId, open]);
    // eslint-disable-next-line react-hooks/exhaustive-deps -- resync only when the popover (re)opens for a (possibly different) cell
    useEffect(() => { setSelectedIds(selectedShiftIds); }, [open, JSON.stringify(selectedShiftIds)]);

    const isOff = selectedIds.length === 0;
    const atCap = selectedIds.length >= MAX_SHIFTS;

    const toggleShift = (shiftId) => setSelectedIds(prev => toggleShiftId(prev, shiftId, MAX_SHIFTS));
    const clearToOff = () => setSelectedIds([]);

    const confirm = () => {
        onPick(selectedIds, locId === 'home' ? null : Number(locId));
    };

    return (
        <Popover.Root open={open} onOpenChange={onOpenChange}>
            <Popover.Trigger>{anchor}</Popover.Trigger>
            <Popover.Content width="260px">
                {notice && (
                    <Callout.Root color="amber" size="1" mb="2">
                        <Callout.Text>{notice}</Callout.Text>
                    </Callout.Root>
                )}
                {violations && violations.length > 0 && (
                    <Callout.Root color={violationsBlocked ? 'red' : 'amber'} size="1" mb="2">
                        <Callout.Text>
                            <Text weight="medium" as="div" mb="1">
                                {violationsBlocked ? 'Blocked by working-time rules:' : 'Compliance warning:'}
                            </Text>
                            {violations.map((v, i) => (
                                <Text key={i} as="div" size="1">{v.date} — {v.message}</Text>
                            ))}
                        </Callout.Text>
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
                <Flex justify="between" align="center">
                    <Text size="1" color="gray">Assign shift(s)</Text>
                    <Text size="1" color="gray">{selectedIds.length}/{MAX_SHIFTS}</Text>
                </Flex>
                <Flex direction="column" gap="1" mt="2">
                    {shifts.map(s => {
                        const checked = selectedIds.includes(s.id);
                        const disabled = !checked && atCap;
                        return (
                            <Text as="label" key={s.id} size="2">
                                <Flex gap="2" align="center">
                                    <Checkbox
                                        checked={checked}
                                        disabled={disabled}
                                        onCheckedChange={() => toggleShift(s.id)}
                                    />
                                    <span style={{
                                        width: 10, height: 10, borderRadius: 2,
                                        background: s.color || 'var(--gray-9)',
                                        display: 'inline-block', flexShrink: 0,
                                    }} />
                                    {s.name} ({s.code})
                                </Flex>
                            </Text>
                        );
                    })}
                    <Text as="label" size="2" color="gray">
                        <Flex gap="2" align="center">
                            <Checkbox checked={isOff} onCheckedChange={(checked) => { if (checked) clearToOff(); }} />
                            Off (clear)
                        </Flex>
                    </Text>
                </Flex>
                <Flex gap="2" justify="end" mt="3">
                    <Button size="1" variant="soft" color="gray" onClick={() => onOpenChange?.(false)}>
                        Cancel
                    </Button>
                    <Button size="1" onClick={confirm}>
                        Confirm
                    </Button>
                </Flex>
            </Popover.Content>
        </Popover.Root>
    );
}
