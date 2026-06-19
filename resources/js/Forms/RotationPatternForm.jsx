import React, { useState, useEffect } from 'react';
import { Dialog, Flex, TextField, Select, Button, Text, Box, IconButton } from '@radix-ui/themes';
import { TrashIcon } from '@radix-ui/react-icons';
import { useQuery } from '@tanstack/react-query';
import { requestJson } from '@/api/client';
import { showToast } from '@/utils/toastUtils';

const OFF = 'off';

export default function RotationPatternForm({ open, onOpenChange, onSaved }) {
    const [name, setName] = useState('');
    const [code, setCode] = useState('');
    const [cycleLength, setCycleLength] = useState(7);
    const [definition, setDefinition] = useState(Array(7).fill(OFF));
    const [saving, setSaving] = useState(false);

    const { data } = useQuery({
        queryKey: ['shifts'],
        queryFn: () => requestJson('get', '/attendance/shifts'),
        enabled: open,
    });
    const shifts = data?.shifts || [];

    useEffect(() => {
        if (open) {
            setName('');
            setCode('');
            setCycleLength(7);
            setDefinition(Array(7).fill(OFF));
        }
    }, [open]);

    const setCycle = (n) => {
        const len = Math.max(1, n);
        setCycleLength(len);
        setDefinition(prev => {
            const next = prev.slice(0, len);
            while (next.length < len) next.push(OFF);
            return next;
        });
    };

    const setDay = (idx, val) => {
        setDefinition(prev => prev.map((d, i) => i === idx ? val : d));
    };

    const save = async () => {
        setSaving(true);
        try {
            await requestJson('post', '/attendance/rotation-patterns', {
                data: {
                    name,
                    code,
                    cycle_length_days: cycleLength,
                    definition: definition.map(d => (d === OFF ? null : Number(d))),
                    is_active: true,
                },
            });
            showToast.success('Pattern created.');
            onSaved?.();
            onOpenChange(false);
        } catch (err) {
            showToast.error(err?.message || 'Failed to save pattern.');
        } finally {
            setSaving(false);
        }
    };

    return (
        <Dialog.Root open={open} onOpenChange={onOpenChange}>
            <Dialog.Content maxWidth="520px">
                <Dialog.Title>New Rotation Pattern</Dialog.Title>
                <Flex direction="column" gap="3">
                    <TextField.Root placeholder="Name" value={name} onChange={e => setName(e.target.value)} />
                    <TextField.Root placeholder="Code" value={code} onChange={e => setCode(e.target.value)} />
                    <TextField.Root
                        type="number" min="1" max="60"
                        value={cycleLength}
                        onChange={e => setCycle(+e.target.value)}
                    >
                        <TextField.Slot><Text size="1" color="gray">Cycle length (days)</Text></TextField.Slot>
                    </TextField.Root>

                    <Box>
                        <Text size="2" weight="medium" as="div" mb="2">Day sequence</Text>
                        <Flex direction="column" gap="2" style={{ maxHeight: 280, overflowY: 'auto' }}>
                            {definition.map((day, idx) => (
                                <Flex key={idx} align="center" gap="2">
                                    <Text size="1" color="gray" style={{ width: 48 }}>Day {idx + 1}</Text>
                                    <Select.Root value={String(day)} onValueChange={v => setDay(idx, v)}>
                                        <Select.Trigger style={{ flex: 1 }} />
                                        <Select.Content>
                                            <Select.Item value={OFF}>Off</Select.Item>
                                            {shifts.map(s => (
                                                <Select.Item key={s.id} value={String(s.id)}>{s.name} ({s.code})</Select.Item>
                                            ))}
                                        </Select.Content>
                                    </Select.Root>
                                </Flex>
                            ))}
                        </Flex>
                    </Box>

                    <Flex justify="end" gap="2" mt="2">
                        <Button variant="soft" color="gray" onClick={() => onOpenChange(false)} disabled={saving}>Cancel</Button>
                        <Button onClick={save} disabled={saving || !name || !code}>
                            {saving ? 'Saving…' : 'Save'}
                        </Button>
                    </Flex>
                </Flex>
            </Dialog.Content>
        </Dialog.Root>
    );
}
