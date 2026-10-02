import React, { useState, useEffect } from 'react';
import { Popover, Flex, Box, Button, Text, Callout, Select, Checkbox } from '@radix-ui/themes';
import { toggleShiftId } from '../rosterShiftSelection';

const MAX_SHIFTS = 3;

/**
 * shifts: [{id,code,name,color}]; selectedShiftIds: number[] (the cell's
 * current assignment — derive with rosterShiftSelection.deriveSelectedShiftIds).
 * leave: {type,status,fraction,session}|null — the leave the grid overlay found for this cell's date.
 * onPick(shiftIds: number[], workLocationIdOrNull) — fires on Confirm.
 * violations: Violation[]|null — working-time compliance results for the last write
 *   attempt on this cell (either a 200-with-warnings or a 422-blocked response).
 * violationsBlocked: true when the write was rejected (422) and did not apply.
 */
export default function RosterCellPopover({ open, onOpenChange, anchor, shifts = [], selectedShiftIds = [], notice = null, violations = null, violationsBlocked = false, workLocations = [], selectedLocationId = null, leave = null, cellDate = null, onPick, onReset = null, canReset = false, finalized = false }) {
    const [locId, setLocId] = useState(selectedLocationId ? String(selectedLocationId) : 'home');
    const [selectedIds, setSelectedIds] = useState(selectedShiftIds);

    useEffect(() => { setLocId(selectedLocationId ? String(selectedLocationId) : 'home'); }, [selectedLocationId, open]);
    // eslint-disable-next-line react-hooks/exhaustive-deps -- resync only when the popover (re)opens for a (possibly different) cell
    useEffect(() => { setSelectedIds(selectedShiftIds); }, [open, JSON.stringify(selectedShiftIds)]);

    const isOff = selectedIds.length === 0;
    const atCap = selectedIds.length >= MAX_SHIFTS;

    const toggleShift = (shiftId) => setSelectedIds(prev => toggleShiftId(prev, shiftId, MAX_SHIFTS));
    const clearToOff = () => setSelectedIds([]);

    const approvedLeave = leave && String(leave.status).toLowerCase() === 'approved' ? leave : null;
    const pendingLeave = leave && !approvedLeave ? leave : null;
    // A working shift on an approved-leave date never counts as absence: the leave wins. Say so BEFORE
    // someone locks a shift there that suggests otherwise.
    const warnsLeaveConflict = Boolean(approvedLeave) && selectedIds.length > 0;

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
                {approvedLeave && (
                    <Callout.Root color={warnsLeaveConflict ? 'amber' : 'blue'} size="1" mb="2" data-testid="roster-leave-warning">
                        <Callout.Text>
                            Approved leave{approvedLeave.type ? ` (${approvedLeave.type})` : ''} on this date.
                            {warnsLeaveConflict ? ' The leave takes precedence over a working shift: this person will show On Leave, not Absent.' : ''}
                        </Callout.Text>
                    </Callout.Root>
                )}
                {pendingLeave && (
                    <Callout.Root color="gray" size="1" mb="2">
                        <Callout.Text>A leave request is pending for this date.</Callout.Text>
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
                            Off day (clears the shift)
                        </Flex>
                    </Text>
                </Flex>
                {!leave && (
                    <Box mt="2">
                        <Button asChild size="1" variant="ghost" data-testid="roster-request-leave">
                            <a href={`/leaves-employee${cellDate ? `?date=${cellDate}` : ''}`}>On leave? Create a leave request</a>
                        </Button>
                    </Box>
                )}
                {finalized && (
                    <Text size="1" color="gray" as="div" mt="2" data-testid="roster-finalized">Finalized by HR</Text>
                )}
                {onReset && canReset && (
                    <Box mt="2">
                        <Button size="1" variant="ghost" color="gray" onClick={onReset} data-testid="roster-reset-to-pattern">
                            Reset to pattern
                        </Button>
                    </Box>
                )}
                <Flex gap="2" justify="end" mt="3">
                    <Button size="1" variant="soft" color="gray" onClick={() => onOpenChange?.(false)}>
                        Cancel
                    </Button>
                    <Button size="1" onClick={confirm}>
                        {warnsLeaveConflict ? 'Assign anyway' : 'Confirm'}
                    </Button>
                </Flex>
            </Popover.Content>
        </Popover.Root>
    );
}
