import React, { useState, useEffect, useMemo } from 'react';
import { Dialog, AlertDialog, Flex, Box, TextField, Select, Switch, Button, Text } from '@radix-ui/themes';
import { requestJson } from '@/api/client';
import { showToast } from '@/utils/toastUtils';
import DateTimePicker from '@/Components/DateTimePicker';

const DEFAULT_FORM = {
    name: '', code: '', type: 'fixed', start_time: '09:00', end_time: '17:30',
    crosses_midnight: false, grace_in_minutes: 15, grace_out_minutes: 0,
    full_day_minutes: 480, half_day_minutes: 240, min_present_minutes: 0,
    break_minutes: 0, color: '#3b82f6', is_active: true,
};

// DB returns TIME columns as "HH:mm:ss" but Laravel validates "HH:mm"
const normalizeTime = (t) => (typeof t === 'string' && t.length > 5 ? t.slice(0, 5) : t);

const todayStr = () => new Date().toISOString().slice(0, 10);

// Columns that change HOW a day is scored — mirrors ShiftController::TIME_BEHAVIOR_FIELDS.
// Editing any of these on an EXISTING shift is versioned server-side so past
// attendance is never silently re-scored against the new definition.
const TIME_BEHAVIOR_FIELDS = [
    'start_time', 'end_time', 'crosses_midnight', 'grace_in_minutes',
    'grace_out_minutes', 'full_day_minutes', 'half_day_minutes',
    'min_present_minutes', 'break_minutes',
];

export default function ShiftForm({ open, onOpenChange, onSaved, initial = null }) {
    const [form, setForm] = useState(DEFAULT_FORM);
    const [baseline, setBaseline] = useState(null); // snapshot of the loaded shift, for change detection
    const [effectiveFrom, setEffectiveFrom] = useState(todayStr());
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [saving, setSaving] = useState(false);
    const isEdit = Boolean(initial?.id);

    useEffect(() => {
        if (open) {
            if (initial) {
                const normalized = { ...DEFAULT_FORM, ...initial };
                normalized.start_time = normalizeTime(normalized.start_time);
                normalized.end_time = normalizeTime(normalized.end_time);
                normalized.core_start_time = normalizeTime(normalized.core_start_time);
                normalized.core_end_time = normalizeTime(normalized.core_end_time);
                setForm(normalized);
                setBaseline(normalized);
            } else {
                setForm(DEFAULT_FORM);
                setBaseline(null);
            }
            setEffectiveFrom(todayStr());
            setConfirmOpen(false);
        }
    }, [open, initial]);

    const set = (k, v) => setForm(f => ({ ...f, [k]: v }));

    // Only meaningful when editing an existing shift — create flow has no "before" state.
    const hasTimeBehaviorChange = useMemo(() => {
        if (!isEdit || !baseline) return false;
        return TIME_BEHAVIOR_FIELDS.some((field) => {
            const a = form[field];
            const b = baseline[field];
            if (field === 'crosses_midnight') return Boolean(a) !== Boolean(b);
            if (field === 'start_time' || field === 'end_time') return normalizeTime(a) !== normalizeTime(b);
            return Number(a ?? 0) !== Number(b ?? 0);
        });
    }, [form, baseline, isEdit]);

    const performSave = async () => {
        setSaving(true);
        try {
            if (isEdit) {
                const payload = hasTimeBehaviorChange ? { ...form, effective_from: effectiveFrom } : form;
                const res = await requestJson('put', `/attendance/shifts/${initial.id}`, { data: payload });
                const versionNote = hasTimeBehaviorChange && res?.versions_count
                    ? ` New version ${res.versions_count} effective ${effectiveFrom}.`
                    : '';
                showToast.success(`Shift updated.${versionNote}`);
            } else {
                await requestJson('post', '/attendance/shifts', { data: form });
                showToast.success('Shift created.');
            }
            onSaved?.();
            onOpenChange(false);
        } catch (err) {
            showToast.error(err?.message || 'Failed to save shift.');
        } finally {
            setSaving(false);
            setConfirmOpen(false);
        }
    };

    // Time-behavior edits on an existing shift require confirming the version
    // impact before submitting; everything else saves immediately.
    const handleSaveClick = () => {
        if (isEdit && hasTimeBehaviorChange) {
            setConfirmOpen(true);
        } else {
            performSave();
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
                        <Box style={{ flex: 1 }}>
                            <DateTimePicker
                                mode="time"
                                label="Start"
                                value={form.start_time}
                                onChange={v => set('start_time', v)}
                            />
                        </Box>
                        <Box style={{ flex: 1 }}>
                            <DateTimePicker
                                mode="time"
                                label="End"
                                value={form.end_time}
                                onChange={v => set('end_time', v)}
                            />
                        </Box>
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

                    {isEdit && hasTimeBehaviorChange && (
                        <Box>
                            <DateTimePicker
                                mode="date"
                                label="Effective from"
                                value={effectiveFrom}
                                onChange={setEffectiveFrom}
                            />
                            <Text size="1" color="gray" mt="1" as="div">
                                Changes apply from this date; earlier days keep the old times.
                            </Text>
                        </Box>
                    )}

                    <Flex justify="end" gap="2" mt="2">
                        <Button variant="soft" color="gray" onClick={() => onOpenChange(false)} disabled={saving}>Cancel</Button>
                        <Button onClick={handleSaveClick} disabled={saving || !form.name || !form.code}>
                            {saving ? 'Saving…' : 'Save'}
                        </Button>
                    </Flex>
                </Flex>
            </Dialog.Content>

            <AlertDialog.Root open={confirmOpen} onOpenChange={setConfirmOpen}>
                <AlertDialog.Content maxWidth="440px">
                    <AlertDialog.Title>Confirm shift time change</AlertDialog.Title>
                    <AlertDialog.Description size="2" color="gray">
                        This changes the shift's times from {effectiveFrom}. Past attendance keeps the previous times (version history is kept).
                    </AlertDialog.Description>
                    <Flex gap="3" mt="4" justify="end">
                        <AlertDialog.Cancel>
                            <Button variant="soft" color="gray" disabled={saving}>Cancel</Button>
                        </AlertDialog.Cancel>
                        <Button onClick={performSave} disabled={saving}>
                            {saving ? 'Saving…' : 'Confirm & save'}
                        </Button>
                    </Flex>
                </AlertDialog.Content>
            </AlertDialog.Root>
        </Dialog.Root>
    );
}
