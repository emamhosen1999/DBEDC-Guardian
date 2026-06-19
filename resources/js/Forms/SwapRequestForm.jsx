import React, { useState, useEffect } from 'react';
import { Dialog, Flex, TextField, Button, Text, TextArea } from '@radix-ui/themes';
import { requestJson } from '@/api/client';
import { showToast } from '@/utils/toastUtils';

export default function SwapRequestForm({ open, onOpenChange, onSaved }) {
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
                    <TextField.Root type="date" value={requesterDate} onChange={e => setRequesterDate(e.target.value)}>
                        <TextField.Slot><Text size="1" color="gray">Your date</Text></TextField.Slot>
                    </TextField.Root>

                    <TextField.Root
                        type="number" min="1" placeholder="Optional"
                        value={counterpartyId}
                        onChange={e => setCounterpartyId(e.target.value)}
                    >
                        <TextField.Slot><Text size="1" color="gray">Counterparty user ID</Text></TextField.Slot>
                    </TextField.Root>

                    <TextField.Root type="date" value={counterpartyDate} onChange={e => setCounterpartyDate(e.target.value)}>
                        <TextField.Slot><Text size="1" color="gray">Counterparty date</Text></TextField.Slot>
                    </TextField.Root>

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
