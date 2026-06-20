import React, { useState, useEffect } from 'react';
import { Dialog, Flex, Box, Select, TextField, Button, Text, IconButton, Separator } from '@radix-ui/themes';
import { PlusIcon, TrashIcon } from '@radix-ui/react-icons';
import { requestJson } from '@/api/client';
import { showToast } from '@/utils/toastUtils';

const emptyGraceTier = () => ({ upto_minutes: 15, outcome: 'late' });

export default function PolicyForm({ open, onOpenChange, onSaved, policy = null }) {
    const empty = {
        name: '',
        scope_type: 'org',
        scope_id: '',
        effective_from: '',
        effective_to: '',
        punch_strictness: 'warn',
        outside_window_minutes: 120,
    };
    const [form, setForm] = useState(empty);
    const [graceTiers, setGraceTiers] = useState([]);
    const [rounding, setRounding] = useState({ strategy: 'none', unit_minutes: 15, direction: 'nearest' });
    const [error, setError] = useState('');
    const [saving, setSaving] = useState(false);
    const set = (k, v) => setForm(f => ({ ...f, [k]: v }));

    const isEdit = !!policy?.id;

    useEffect(() => {
        if (open) {
            if (policy) {
                setForm({
                    name: policy.name || '',
                    scope_type: policy.scope_type || 'org',
                    scope_id: policy.scope_id != null ? String(policy.scope_id) : '',
                    effective_from: policy.effective_from || '',
                    effective_to: policy.effective_to || '',
                    punch_strictness: policy.punch_strictness || 'warn',
                    outside_window_minutes: policy.outside_window_minutes ?? 120,
                });
                setGraceTiers(policy.grace_tiers?.late || []);
                setRounding(policy.rounding || { strategy: 'none', unit_minutes: 15, direction: 'nearest' });
            } else {
                setForm(empty);
                setGraceTiers([]);
                setRounding({ strategy: 'none', unit_minutes: 15, direction: 'nearest' });
            }
            setError('');
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, policy]);

    const addGraceTier = () => setGraceTiers(t => [...t, emptyGraceTier()]);
    const removeGraceTier = (idx) => setGraceTiers(t => t.filter((_, i) => i !== idx));
    const updateGraceTier = (idx, key, val) => setGraceTiers(t => t.map((row, i) => i === idx ? { ...row, [key]: val } : row));

    const save = async () => {
        setError('');
        setSaving(true);
        try {
            const payload = {
                name: form.name,
                scope_type: form.scope_type,
                scope_id: form.scope_type === 'org' ? null : Number(form.scope_id) || null,
                effective_from: form.effective_from,
                effective_to: form.effective_to || null,
                punch_strictness: form.punch_strictness,
                outside_window_minutes: Number(form.outside_window_minutes) || 0,
                grace_tiers: graceTiers.length > 0
                    ? { late: graceTiers.map(t => ({ upto_minutes: Number(t.upto_minutes) || 0, outcome: t.outcome })) }
                    : null,
                rounding: rounding.strategy !== 'none'
                    ? { strategy: rounding.strategy, unit_minutes: Number(rounding.unit_minutes) || 15, direction: rounding.direction }
                    : null,
            };

            if (isEdit) {
                await requestJson('put', `/attendance/policies/${policy.id}`, { data: payload });
                showToast.success('Policy updated.');
            } else {
                await requestJson('post', '/attendance/policies', { data: payload });
                showToast.success('Policy created.');
            }
            onSaved?.();
            onOpenChange(false);
        } catch (e) {
            const msg = e?.message || 'Failed to save policy.';
            setError(msg);
            showToast.error(msg);
        } finally {
            setSaving(false);
        }
    };

    return (
        <Dialog.Root open={open} onOpenChange={v => { if (!v) setError(''); onOpenChange(v); }}>
            <Dialog.Content maxWidth="560px">
                <Dialog.Title>{isEdit ? 'Edit Policy' : 'New Policy'}</Dialog.Title>
                <Flex direction="column" gap="3">
                    <Box>
                        <Text size="1" color="gray" as="div" mb="1">Name *</Text>
                        <TextField.Root placeholder="Policy name" value={form.name} onChange={e => set('name', e.target.value)} />
                    </Box>

                    <Select.Root value={form.scope_type} onValueChange={v => { set('scope_type', v); set('scope_id', ''); }}>
                        <Select.Trigger placeholder="Scope" />
                        <Select.Content>
                            <Select.Item value="org">Whole organization</Select.Item>
                            <Select.Item value="department">Department</Select.Item>
                            <Select.Item value="designation">Designation</Select.Item>
                            <Select.Item value="user">Employee</Select.Item>
                        </Select.Content>
                    </Select.Root>

                    {form.scope_type !== 'org' && (
                        <Box>
                            <Text size="1" color="gray" as="div" mb="1">Scope ID ({form.scope_type})</Text>
                            <TextField.Root
                                type="number"
                                placeholder={`${form.scope_type} id`}
                                value={form.scope_id}
                                onChange={e => set('scope_id', e.target.value)}
                            />
                        </Box>
                    )}

                    <Flex gap="3" wrap="wrap">
                        <Box style={{ flex: '1 1 160px' }}>
                            <Text size="1" color="gray" as="div" mb="1">Effective from *</Text>
                            <TextField.Root type="date" value={form.effective_from} onChange={e => set('effective_from', e.target.value)} />
                        </Box>
                        <Box style={{ flex: '1 1 160px' }}>
                            <Text size="1" color="gray" as="div" mb="1">Effective to</Text>
                            <TextField.Root type="date" value={form.effective_to} onChange={e => set('effective_to', e.target.value)} />
                        </Box>
                    </Flex>

                    <Flex gap="3" wrap="wrap">
                        <Box style={{ flex: '1 1 200px' }}>
                            <Text size="1" color="gray" as="div" mb="1">Punch strictness</Text>
                            <Select.Root value={form.punch_strictness} onValueChange={v => set('punch_strictness', v)}>
                                <Select.Trigger />
                                <Select.Content>
                                    <Select.Item value="warn">Warn</Select.Item>
                                    <Select.Item value="flag">Flag</Select.Item>
                                    <Select.Item value="restrict">Restrict</Select.Item>
                                </Select.Content>
                            </Select.Root>
                        </Box>
                        <Box style={{ flex: '1 1 200px' }}>
                            <Text size="1" color="gray" as="div" mb="1">Outside window (minutes)</Text>
                            <TextField.Root
                                type="number"
                                min="0"
                                max="1440"
                                value={form.outside_window_minutes}
                                onChange={e => set('outside_window_minutes', e.target.value)}
                            />
                        </Box>
                    </Flex>

                    <Separator size="4" />

                    <Box>
                        <Flex justify="between" align="center" mb="2">
                            <Text size="2" weight="medium">Grace tiers (late arrival)</Text>
                            <Button size="1" variant="soft" onClick={addGraceTier}>
                                <PlusIcon /> Add tier
                            </Button>
                        </Flex>
                        {graceTiers.length === 0 && (
                            <Text size="1" color="gray">No grace tiers configured.</Text>
                        )}
                        <Flex direction="column" gap="2">
                            {graceTiers.map((tier, idx) => (
                                <Flex key={idx} gap="2" align="center">
                                    <TextField.Root
                                        size="1"
                                        type="number"
                                        min="0"
                                        value={tier.upto_minutes}
                                        onChange={e => updateGraceTier(idx, 'upto_minutes', e.target.value)}
                                        style={{ flex: '1 1 120px' }}
                                    >
                                        <TextField.Slot><Text size="1" color="gray">Up to (min)</Text></TextField.Slot>
                                    </TextField.Root>
                                    <Select.Root value={tier.outcome} onValueChange={v => updateGraceTier(idx, 'outcome', v)}>
                                        <Select.Trigger />
                                        <Select.Content>
                                            <Select.Item value="present">Present</Select.Item>
                                            <Select.Item value="late">Late</Select.Item>
                                            <Select.Item value="half_day">Half day</Select.Item>
                                        </Select.Content>
                                    </Select.Root>
                                    <IconButton size="1" variant="ghost" color="red" onClick={() => removeGraceTier(idx)}>
                                        <TrashIcon />
                                    </IconButton>
                                </Flex>
                            ))}
                        </Flex>
                    </Box>

                    <Separator size="4" />

                    <Box>
                        <Text size="2" weight="medium" as="div" mb="2">Rounding</Text>
                        <Flex gap="3" wrap="wrap">
                            <Box style={{ flex: '1 1 160px' }}>
                                <Text size="1" color="gray" as="div" mb="1">Strategy</Text>
                                <Select.Root value={rounding.strategy} onValueChange={v => setRounding(r => ({ ...r, strategy: v }))}>
                                    <Select.Trigger />
                                    <Select.Content>
                                        <Select.Item value="none">None</Select.Item>
                                        <Select.Item value="nearest">Nearest</Select.Item>
                                        <Select.Item value="quarter_hour">Quarter hour</Select.Item>
                                        <Select.Item value="seven_minute">Seven minute</Select.Item>
                                    </Select.Content>
                                </Select.Root>
                            </Box>
                            {rounding.strategy !== 'none' && (
                                <>
                                    <Box style={{ flex: '1 1 140px' }}>
                                        <Text size="1" color="gray" as="div" mb="1">Unit (minutes)</Text>
                                        <TextField.Root
                                            type="number"
                                            min="1"
                                            value={rounding.unit_minutes}
                                            onChange={e => setRounding(r => ({ ...r, unit_minutes: e.target.value }))}
                                        />
                                    </Box>
                                    <Box style={{ flex: '1 1 140px' }}>
                                        <Text size="1" color="gray" as="div" mb="1">Direction</Text>
                                        <Select.Root value={rounding.direction} onValueChange={v => setRounding(r => ({ ...r, direction: v }))}>
                                            <Select.Trigger />
                                            <Select.Content>
                                                <Select.Item value="nearest">Nearest</Select.Item>
                                                <Select.Item value="up">Up</Select.Item>
                                                <Select.Item value="down">Down</Select.Item>
                                            </Select.Content>
                                        </Select.Root>
                                    </Box>
                                </>
                            )}
                        </Flex>
                    </Box>

                    {error && <Text color="red" size="2">{error}</Text>}

                    <Flex justify="end" gap="2" mt="2">
                        <Button variant="soft" color="gray" onClick={() => onOpenChange(false)} disabled={saving}>
                            Cancel
                        </Button>
                        <Button onClick={save} disabled={saving || !form.name || !form.effective_from}>
                            {saving ? 'Saving…' : 'Save'}
                        </Button>
                    </Flex>
                </Flex>
            </Dialog.Content>
        </Dialog.Root>
    );
}
