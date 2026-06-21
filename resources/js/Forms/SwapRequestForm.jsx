import React, { useState, useEffect } from 'react';
import { Dialog, Flex, Box, Select, TextField, Button, Text, TextArea } from '@radix-ui/themes';
import { usePage } from '@inertiajs/react';
import { requestJson } from '@/api/client';
import { showToast } from '@/utils/toastUtils';
import DateTimePicker from '@/Components/DateTimePicker';

export default function SwapRequestForm({ open, onOpenChange, onSaved }) {
    const { employees = [] } = usePage().props;
    const [requesterDate, setRequesterDate] = useState('');
    const [counterpartyId, setCounterpartyId] = useState('');
    const [counterpartyDate, setCounterpartyDate] = useState('');
    const [reason, setReason] = useState('');
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        if (open) {
            setRequesterDate('');
            setCounterpartyId('');
            setCounterpartyDate('');
            setReason('');
        }
    }, [open]);

    const save = async () => {
        setSaving(true);
        try {
            await requestJson('post', '/attendance/swaps', {
                data: {
                    requester_date: requesterDate,
                    counterparty_id: counterpartyId ? Number(counterpartyId) : null,
                    counterparty_date: counterpartyDate || null,
                    reason: reason || null,
                },
            });
            showToast.success('Swap request submitted.');
            onSaved?.();
            onOpenChange(false);
        } catch (err) {
            showToast.error(err?.message || 'Failed to submit swap request.');
        } finally {
            setSaving(false);
        }
    };

    return (
        <Dialog.Root open={open} onOpenChange={onOpenChange}>
            <Dialog.Content maxWidth="440px">
                <Dialog.Title>Request Shift Swap</Dialog.Title>
                <Flex direction="column" gap="3">
                    <DateTimePicker
                        mode="date"
                        label="Your date"
                        value={requesterDate}
                        onChange={v => setRequesterDate(v)}
                    />

                    <Box>
                        <Text size="1" color="gray" as="div" mb="1">Counterparty (optional)</Text>
                        <Select.Root
                            value={counterpartyId || 'none'}
                            onValueChange={v => setCounterpartyId(v === 'none' ? '' : v)}
                        >
                            <Select.Trigger placeholder="— None —" style={{ width: '100%' }} />
                            <Select.Content>
                                <Select.Item value="none">— None —</Select.Item>
                                {employees.map(emp => (
                                    <Select.Item key={emp.id} value={String(emp.id)}>{emp.name}</Select.Item>
                                ))}
                            </Select.Content>
                        </Select.Root>
                    </Box>

                    <DateTimePicker
                        mode="date"
                        label="Counterparty date"
                        value={counterpartyDate}
                        onChange={v => setCounterpartyDate(v)}
                    />

                    <TextArea
                        placeholder="Reason (optional)"
                        value={reason}
                        onChange={e => setReason(e.target.value)}
                        rows={3}
                    />

                    <Flex justify="end" gap="2" mt="2">
                        <Button variant="soft" color="gray" onClick={() => onOpenChange(false)} disabled={saving}>Cancel</Button>
                        <Button onClick={save} disabled={saving || !requesterDate}>
                            {saving ? 'Submitting…' : 'Submit'}
                        </Button>
                    </Flex>
                </Flex>
            </Dialog.Content>
        </Dialog.Root>
    );
}
