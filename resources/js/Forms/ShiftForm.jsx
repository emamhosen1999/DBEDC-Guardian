import React, { useState, useEffect } from 'react';
import { Dialog, Flex, TextField, Select, Switch, Button, Text } from '@radix-ui/themes';
import { requestJson } from '@/api/client';
import { showToast } from '@/utils/toastUtils';

const DEFAULT_FORM = {
    name: '', code: '', type: 'fixed', start_time: '09:00', end_time: '17:30',
    crosses_midnight: false, grace_in_minutes: 15, grace_out_minutes: 0,
    full_day_minutes: 480, half_day_minutes: 240, min_present_minutes: 0,
    color: '#3b82f6', is_active: true,
};

export default function ShiftForm({ open, onOpenChange, onSaved, initial = null }) {
    const [form, setForm] = useState(DEFAULT_FORM);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        if (open) {
            setForm(initial ? { ...DEFAULT_FORM, ...initial } : DEFAULT_FORM);
        }
    }, [open, initial]);

    const set = (k, v) => setForm(f => ({ ...f, [k]: v }));

    const save = async () => {
        setSaving(true);
        try {
            if (initial?.id) {
                await requestJson('put', `/attendance/shifts/${initial.id}`, { data: form });
            } else {
                await requestJson('post', '/attendance/shifts', { data: form });
            }
            showToast.success(initial?.id ? 'Shift updated.' : 'Shift created.');
            onSaved?.();
            onOpenChange(false);
        } catch (err) {
            showToast.error(err?.message || 'Failed to save shift.');
        } finally {
            setSaving(false);
        }
    };

    return (
        <Dialog.Root open={open} onOpenChange={onOpenChange}>
            <Dialog.Content maxWidth="520px">
                <Dialog.Title>{initial?.id ? 'Edit Shift' : 'New Shift'}</Dialog.Title>
                <Flex direction="column" gap="3">
                    <TextField.Root placeholder="Name" value={form.name} onChange={e => set('name', e.target.value)} />
                    <TextField.Root placeholder="Code" value={form.code} onChange={e => set('code', e.target.value)} />

                    <Select.Root value={form.type} onValueChange={v => set('type', v)}>
                        <Select.Trigger placeholder="Type" />
                        <Select.Content>
                            <Select.Item value="fixed">Fixed</Select.Item>
                            <Select.Item value="flexible">Flexible</Select.Item>
                            <Select.Item value="open">Open</Select.Item>
                        </Select.Content>
                    </Select.Root>

                    <Flex gap="3">
                        <TextField.Root type="time" value={form.start_time} onChange={e => set('start_time', e.target.value)} style={{ flex: 1 }}>
                            <TextField.Slot><Text size="1" color="gray">Start</Text></TextField.Slot>
                        </TextField.Root>
                        <TextField.Root type="time" value={form.end_time} onChange={e => set('end_time', e.target.value)} style={{ flex: 1 }}>
                            <TextField.Slot><Text size="1" color="gray">End</Text></TextField.Slot>
                        </TextField.Root>
                    </Flex>

                    <Flex align="center" gap="2">
                        <Switch checked={form.crosses_midnight} onCheckedChange={v => set('crosses_midnight', v)} />
                        <Text size="2">Crosses midnight (night shift)</Text>
                    </Flex>

                    <Flex gap="3">
                        <TextField.Root type="number" min="0" value={form.grace_in_minutes} onChange={e => set('grace_in_minutes', +e.target.value)} style={{ flex: 1 }}>
                            <TextField.Slot><Text size="1" color="gray">Grace in</Text></TextField.Slot>
                        </TextField.Root>
                        <TextField.Root type="number" min="0" value={form.grace_out_minutes} onChange={e => set('grace_out_minutes', +e.target.value)} style={{ flex: 1 }}>
                            <TextField.Slot><Text size="1" color="gray">Grace out</Text></TextField.Slot>
                        </TextField.Root>
                    </Flex>

                    <Flex gap="3">
                        <TextField.Root type="number" min="0" value={form.full_day_minutes} onChange={e => set('full_day_minutes', +e.target.value)} style={{ flex: 1 }}>
                            <TextField.Slot><Text size="1" color="gray">Full day min</Text></TextField.Slot>
                        </TextField.Root>
                        <TextField.Root type="number" min="0" value={form.half_day_minutes} onChange={e => set('half_day_minutes', +e.target.value)} style={{ flex: 1 }}>
                            <TextField.Slot><Text size="1" color="gray">Half day min</Text></TextField.Slot>
                        </TextField.Root>
                        <TextField.Root type="number" min="0" value={form.min_present_minutes} onChange={e => set('min_present_minutes', +e.target.value)} style={{ flex: 1 }}>
                            <TextField.Slot><Text size="1" color="gray">Min present</Text></TextField.Slot>
                        </TextField.Root>
                    </Flex>

                    <Flex align="center" gap="3">
                        <Text size="2" color="gray">Color</Text>
                        <TextField.Root type="color" value={form.color} onChange={e => set('color', e.target.value)} style={{ width: 80 }} />
                    </Flex>

                    <Flex align="center" gap="2">
                        <Switch checked={form.is_active} onCheckedChange={v => set('is_active', v)} />
                        <Text size="2">{form.is_active ? 'Active' : 'Inactive'}</Text>
                    </Flex>

                    <Flex justify="end" gap="2" mt="2">
                        <Button variant="soft" color="gray" onClick={() => onOpenChange(false)} disabled={saving}>Cancel</Button>
                        <Button onClick={save} disabled={saving || !form.name || !form.code}>
                            {saving ? 'Saving…' : 'Save'}
                        </Button>
                    </Flex>
                </Flex>
            </Dialog.Content>
        </Dialog.Root>
    );
}
