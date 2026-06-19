import React, { useState, useMemo } from 'react';
import { Dialog, Flex, Box, Select, TextField, Button, Text } from '@radix-ui/themes';
import { useQuery } from '@tanstack/react-query';
import { requestJson } from '@/api/client';
import { showToast } from '@/utils/toastUtils';

export default function ShiftAssignmentForm({ open, onOpenChange, onSaved, employees = [], departments = [], designations = [] }) {
    const empty = { scope_type: 'org', scope_id: '', shift_id: '', anchor_date: '', effective_from: '', effective_to: '', priority: 0 };
    const [form, setForm] = useState(empty);
    const [error, setError] = useState('');
    const [saving, setSaving] = useState(false);
    const set = (k, v) => setForm(f => ({ ...f, [k]: v }));

    const { data: shiftsData } = useQuery({
        queryKey: ['shifts'],
        queryFn: () => requestJson('get', '/attendance/shifts'),
        enabled: open,
    });
    const shifts = shiftsData?.shifts || [];

    const scopeOptions = useMemo(() => {
        if (form.scope_type === 'department') return departments.map(d => ({ id: d.id, label: d.name }));
        if (form.scope_type === 'designation') return designations.map(d => ({ id: d.id, label: d.title }));
        if (form.scope_type === 'user') return employees.map(e => ({ id: e.id, label: e.name }));
        return [];
    }, [form.scope_type, departments, designations, employees]);

    const save = async () => {
        setError('');
        setSaving(true);
        try {
            const payload = {
                ...form,
                scope_id: form.scope_type === 'org' ? null : Number(form.scope_id) || null,
                shift_id: Number(form.shift_id),
                priority: Number(form.priority) || 0,
                effective_to: form.effective_to || null,
            };
            await requestJson('post', '/attendance/shift-assignments', { data: payload });
            showToast.success('Shift assignment saved.');
            onSaved?.();
            onOpenChange(false);
            setForm(empty);
        } catch (e) {
            const msg = e?.message || 'Failed to save assignment.';
            setError(msg);
            showToast.error(msg);
        } finally {
            setSaving(false);
        }
    };

    return (
        <Dialog.Root open={open} onOpenChange={v => { if (!v) { setError(''); setForm(empty); } onOpenChange(v); }}>
            <Dialog.Content maxWidth="500px">
                <Dialog.Title>Assign Shift</Dialog.Title>
                <Flex direction="column" gap="3">
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
                        <Select.Root value={String(form.scope_id)} onValueChange={v => set('scope_id', v)}>
                            <Select.Trigger placeholder={`Select ${form.scope_type}`} />
                            <Select.Content>
                                {scopeOptions.map(o => (
                                    <Select.Item key={o.id} value={String(o.id)}>{o.label}</Select.Item>
                                ))}
                            </Select.Content>
                        </Select.Root>
                    )}

                    <Select.Root value={String(form.shift_id)} onValueChange={v => set('shift_id', v)}>
                        <Select.Trigger placeholder="Select shift" />
                        <Select.Content>
                            {shifts.map(s => (
                                <Select.Item key={s.id} value={String(s.id)}>{s.name} ({s.code})</Select.Item>
                            ))}
                        </Select.Content>
                    </Select.Root>

                    <Flex gap="3" wrap="wrap">
                        <Box style={{ flex: '1 1 130px' }}>
                            <Text size="1" color="gray" as="div" mb="1">Anchor date</Text>
                            <TextField.Root type="date" value={form.anchor_date} onChange={e => set('anchor_date', e.target.value)} />
                        </Box>
                        <Box style={{ flex: '1 1 130px' }}>
                            <Text size="1" color="gray" as="div" mb="1">Effective from *</Text>
                            <TextField.Root type="date" value={form.effective_from} onChange={e => set('effective_from', e.target.value)} />
                        </Box>
                        <Box style={{ flex: '1 1 130px' }}>
                            <Text size="1" color="gray" as="div" mb="1">Effective to</Text>
                            <TextField.Root type="date" value={form.effective_to} onChange={e => set('effective_to', e.target.value)} />
                        </Box>
                    </Flex>

                    <Box>
                        <Text size="1" color="gray" as="div" mb="1">Priority</Text>
                        <TextField.Root
                            type="number"
                            placeholder="Priority (0 = default)"
                            value={form.priority}
                            onChange={e => set('priority', e.target.value)}
                            style={{ width: 160 }}
                        />
                    </Box>

                    {error && <Text color="red" size="2">{error}</Text>}

                    <Flex justify="end" gap="2" mt="2">
                        <Button variant="soft" color="gray" onClick={() => onOpenChange(false)} disabled={saving}>
                            Cancel
                        </Button>
                        <Button onClick={save} disabled={saving || !form.shift_id || !form.effective_from}>
                            {saving ? 'Saving…' : 'Save'}
                        </Button>
                    </Flex>
                </Flex>
            </Dialog.Content>
        </Dialog.Root>
    );
}
