import React, { useState } from 'react';
import { Dialog, Flex, Box, Text, Button, TextField, Select, Table, IconButton } from '@radix-ui/themes';
import { TrashIcon } from '@radix-ui/react-icons';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { usePage } from '@inertiajs/react';
import { requestJson } from '@/api/client';
import { showToast } from '@/utils/toastUtils';

const WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

export default function CoverageRequirementsDialog({ open, onOpenChange }) {
    const qc = useQueryClient();
    const { designations = [] } = usePage().props;

    const { data: reqData } = useQuery({ queryKey: ['coverage-requirements'], queryFn: () => requestJson('get', '/attendance/coverage-requirements'), enabled: open });
    const { data: locData } = useQuery({ queryKey: ['work-locations'], queryFn: () => requestJson('get', '/attendance/work-locations'), enabled: open });
    const { data: shiftData } = useQuery({ queryKey: ['shifts'], queryFn: () => requestJson('get', '/attendance/shifts'), enabled: open });

    const requirements = reqData?.requirements || [];
    const locations = locData?.work_locations || [];
    const shifts = shiftData?.shifts || [];

    const [form, setForm] = useState({ work_location_id: '', shift_id: '', designation_id: '', required_headcount: '1', weekday: '', date: '' });
    const set = (k, v) => setForm(f => ({ ...f, [k]: v }));

    const invalidate = () => { qc.invalidateQueries({ queryKey: ['coverage-requirements'] }); qc.invalidateQueries({ queryKey: ['coverage'] }); };

    const create = useMutation({
        mutationFn: () => requestJson('post', '/attendance/coverage-requirements', { data: {
            work_location_id: Number(form.work_location_id),
            shift_id: Number(form.shift_id),
            designation_id: form.designation_id ? Number(form.designation_id) : null,
            required_headcount: Number(form.required_headcount),
            weekday: form.weekday === '' ? null : Number(form.weekday),
            date: form.date || null,
        } }),
        onSuccess: () => { showToast.success('Requirement added.'); invalidate(); },
        onError: (e) => showToast.error(e?.message || 'Failed to add requirement.'),
    });

    const remove = useMutation({
        mutationFn: (id) => requestJson('delete', `/attendance/coverage-requirements/${id}`),
        onSuccess: () => { showToast.success('Removed.'); invalidate(); },
        onError: (e) => showToast.error(e?.message || 'Failed to remove.'),
    });

    const canAdd = form.work_location_id && form.shift_id && form.required_headcount !== '';

    return (
        <Dialog.Root open={open} onOpenChange={onOpenChange}>
            <Dialog.Content maxWidth="720px">
                <Dialog.Title>Coverage requirements</Dialog.Title>
                <Dialog.Description size="2" color="gray" mb="3">
                    Required headcount per post &amp; shift. Leave weekday/date empty for an all-days rule; set weekday for a recurring day, or date for a one-off override. Role optional.
                </Dialog.Description>

                <Flex gap="2" wrap="wrap" align="end" mb="3">
                    <Box><Text size="1" color="gray">Location</Text>
                        <Select.Root value={form.work_location_id} onValueChange={v => set('work_location_id', v)}>
                            <Select.Trigger placeholder="Location" />
                            <Select.Content>{locations.map(l => <Select.Item key={l.id} value={String(l.id)}>{l.name}</Select.Item>)}</Select.Content>
                        </Select.Root>
                    </Box>
                    <Box><Text size="1" color="gray">Shift</Text>
                        <Select.Root value={form.shift_id} onValueChange={v => set('shift_id', v)}>
                            <Select.Trigger placeholder="Shift" />
                            <Select.Content>{shifts.map(s => <Select.Item key={s.id} value={String(s.id)}>{s.code}</Select.Item>)}</Select.Content>
                        </Select.Root>
                    </Box>
                    <Box><Text size="1" color="gray">Role (opt)</Text>
                        <Select.Root value={form.designation_id || 'any'} onValueChange={v => set('designation_id', v === 'any' ? '' : v)}>
                            <Select.Trigger placeholder="Any" />
                            <Select.Content><Select.Item value="any">Any (total)</Select.Item>{designations.map(d => <Select.Item key={d.id} value={String(d.id)}>{d.title}</Select.Item>)}</Select.Content>
                        </Select.Root>
                    </Box>
                    <Box style={{ width: 70 }}><Text size="1" color="gray">Count</Text>
                        <TextField.Root type="number" min="0" value={form.required_headcount} onChange={e => set('required_headcount', e.target.value)} />
                    </Box>
                    <Box><Text size="1" color="gray">Weekday (opt)</Text>
                        <Select.Root value={form.weekday === '' ? 'all' : form.weekday} onValueChange={v => set('weekday', v === 'all' ? '' : v)}>
                            <Select.Trigger placeholder="All" />
                            <Select.Content><Select.Item value="all">All days</Select.Item>{WEEKDAYS.map((w, i) => <Select.Item key={i} value={String(i)}>{w}</Select.Item>)}</Select.Content>
                        </Select.Root>
                    </Box>
                    <Box><Text size="1" color="gray">Date (opt)</Text>
                        <TextField.Root type="date" value={form.date} onChange={e => set('date', e.target.value)} />
                    </Box>
                    <Button disabled={!canAdd || create.isPending} onClick={() => create.mutate()}>Add</Button>
                </Flex>

                <Table.Root size="1" variant="surface">
                    <Table.Header>
                        <Table.Row>
                            <Table.ColumnHeaderCell>Location</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Shift</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Role</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Count</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>When</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell></Table.ColumnHeaderCell>
                        </Table.Row>
                    </Table.Header>
                    <Table.Body>
                        {requirements.map(r => (
                            <Table.Row key={r.id}>
                                <Table.Cell>{r.work_location?.name || `#${r.work_location_id}`}</Table.Cell>
                                <Table.Cell>{r.shift?.code || `#${r.shift_id}`}</Table.Cell>
                                <Table.Cell>{r.designation?.title || 'Any'}</Table.Cell>
                                <Table.Cell>{r.required_headcount}</Table.Cell>
                                <Table.Cell>{r.date ? r.date : (r.weekday === null || r.weekday === undefined ? 'All days' : WEEKDAYS[r.weekday])}</Table.Cell>
                                <Table.Cell><IconButton size="1" color="red" variant="ghost" onClick={() => remove.mutate(r.id)}><TrashIcon /></IconButton></Table.Cell>
                            </Table.Row>
                        ))}
                    </Table.Body>
                </Table.Root>

                <Flex justify="end" mt="3"><Dialog.Close><Button variant="soft" color="gray">Close</Button></Dialog.Close></Flex>
            </Dialog.Content>
        </Dialog.Root>
    );
}
