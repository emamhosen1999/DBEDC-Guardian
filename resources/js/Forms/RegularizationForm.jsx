import React, { useState, useEffect } from 'react';
import { Dialog, Flex, Box, Select, TextField, Button, Text, TextArea } from '@radix-ui/themes';
import { requestJson } from '@/api/client';
import { showToast } from '@/utils/toastUtils';

const TYPES = [
    { value: 'missing_punchin',  label: 'Missing punch-in' },
    { value: 'missing_punchout', label: 'Missing punch-out' },
    { value: 'wrong_time',       label: 'Wrong time recorded' },
    { value: 'missed_day',       label: 'Missed full day' },
    { value: 'other',            label: 'Other' },
];

export default function RegularizationForm({ open, onOpenChange, onSaved }) {
    const [date, setDate]               = useState('');
    const [type, setType]               = useState('');
    const [punchin, setPunchin]         = useState('');
    const [punchout, setPunchout]       = useState('');
    const [reason, setReason]           = useState('');
    const [saving, setSaving]           = useState(false);

    useEffect(() => {
        if (open) {
            setDate('');
            setType('');
            setPunchin('');
            setPunchout('');
            setReason('');
        }
    }, [open]);

    const save = async () => {
        setSaving(true);
        try {
            await requestJson('post', '/attendance/regularizations', {
                data: {
                    date,
                    type,
                    requested_punchin:  punchin  || null,
                    requested_punchout: punchout || null,
                    reason,
                },
            });
            showToast.success('Regularization request submitted.');
            onSaved?.();
            onOpenChange(false);
        } catch (err) {
            showToast.error(err?.message || 'Failed to submit request.');
        } finally {
            setSaving(false);
        }
    };

    const canSubmit = date && type && reason.trim();

    return (
        <Dialog.Root open={open} onOpenChange={onOpenChange}>
            <Dialog.Content maxWidth="480px">
                <Dialog.Title>Regularize Attendance</Dialog.Title>
                <Flex direction="column" gap="3">
                    <Box>
                        <Text size="1" color="gray" as="div" mb="1">Date</Text>
                        <TextField.Root
                            type="date"
                            value={date}
                            onChange={e => setDate(e.target.value)}
                        />
                    </Box>

                    <Box>
                        <Text size="1" color="gray" as="div" mb="1">Type</Text>
                        <Select.Root value={type || ''} onValueChange={setType}>
                            <Select.Trigger placeholder="— Select type —" style={{ width: '100%' }} />
                            <Select.Content>
                                {TYPES.map(t => (
                                    <Select.Item key={t.value} value={t.value}>{t.label}</Select.Item>
                                ))}
                            </Select.Content>
                        </Select.Root>
                    </Box>

                    <Box>
                        <Text size="1" color="gray" as="div" mb="1">Requested punch-in (optional)</Text>
                        <TextField.Root
                            type="datetime-local"
                            value={punchin}
                            onChange={e => setPunchin(e.target.value)}
                        />
                    </Box>

                    <Box>
                        <Text size="1" color="gray" as="div" mb="1">Requested punch-out (optional)</Text>
                        <TextField.Root
                            type="datetime-local"
                            value={punchout}
                            onChange={e => setPunchout(e.target.value)}
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
