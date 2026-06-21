import React, { useState, useEffect } from 'react';
import { Dialog, Flex, Box, TextField, Button, Text, TextArea } from '@radix-ui/themes';
import { requestJson } from '@/api/client';
import { showToast } from '@/utils/toastUtils';
import DateTimePicker from '@/Components/DateTimePicker';

export default function OvertimeRequestForm({ open, onOpenChange, onSaved }) {
    const [date, setDate]       = useState('');
    const [minutes, setMinutes] = useState('');
    const [reason, setReason]   = useState('');
    const [saving, setSaving]   = useState(false);

    useEffect(() => {
        if (open) {
            setDate('');
            setMinutes('');
            setReason('');
        }
    }, [open]);

    const save = async () => {
        setSaving(true);
        try {
            await requestJson('post', '/attendance/overtime', {
                data: {
                    date,
                    requested_minutes: Number(minutes),
                    reason,
                },
            });
            showToast.success('Overtime request submitted.');
            onSaved?.();
            onOpenChange(false);
        } catch (err) {
            showToast.error(err?.message || 'Failed to submit request.');
        } finally {
            setSaving(false);
        }
    };

    const canSubmit = date && Number(minutes) >= 1 && reason.trim();

    return (
        <Dialog.Root open={open} onOpenChange={onOpenChange}>
            <Dialog.Content maxWidth="440px">
                <Dialog.Title>Request Overtime</Dialog.Title>
                <Flex direction="column" gap="3">
                    <Box>
                        <DateTimePicker
                            mode="date"
                            label="Date"
                            value={date}
                            onChange={v => setDate(v)}
                        />
                    </Box>

                    <Box>
                        <Text size="1" color="gray" as="div" mb="1">Overtime duration (minutes)</Text>
                        <TextField.Root
                            type="number"
                            min="1"
                            placeholder="e.g. 90"
                            value={minutes}
                            onChange={e => setMinutes(e.target.value)}
                        />
                    </Box>

                    <Box>
                        <Text size="1" color="gray" as="div" mb="1">Reason</Text>
                        <TextArea
                            placeholder="Describe the reason…"
                            value={reason}
                            onChange={e => setReason(e.target.value)}
                            rows={3}
                        />
                    </Box>

                    <Flex justify="end" gap="2" mt="2">
                        <Button variant="soft" color="gray" onClick={() => onOpenChange(false)} disabled={saving}>
                            Cancel
                        </Button>
                        <Button onClick={save} disabled={saving || !canSubmit}>
                            {saving ? 'Submitting…' : 'Submit'}
                        </Button>
                    </Flex>
                </Flex>
            </Dialog.Content>
        </Dialog.Root>
    );
}
