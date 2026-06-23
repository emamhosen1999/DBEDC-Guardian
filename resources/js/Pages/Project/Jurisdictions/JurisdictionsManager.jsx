import React, { useState, useEffect, useMemo } from 'react';
import {
    Box, Flex, Text, Card, Table, Badge, Button, IconButton, Tooltip,
    Dialog, TextField, Select, Grid, Spinner, AlertDialog, TextField as RTextField
} from '@radix-ui/themes';
import {
    PlusIcon, Pencil1Icon, TrashIcon, SewingPinIcon, MagnifyingGlassIcon
} from '@radix-ui/react-icons';
import axios from 'axios';
import { showToast } from '@/utils/toastUtils';

/**
 * Jurisdiction (project chainage segment) management.
 * Lives inside Unified Daily Works — jurisdictions govern DWR/RFI chainage,
 * distinct from HR Work Locations (which govern attendance).
 */
const emptyForm = { id: null, location: '', start_chainage: '', end_chainage: '', incharge: '' };

const JurisdictionsManager = ({ jurisdictions: initial = [], users = [], canManage = false }) => {
    const [rows, setRows] = useState(initial);
    const [search, setSearch] = useState('');
    const [modalOpen, setModalOpen] = useState(false);
    const [modalType, setModalType] = useState('add'); // 'add' | 'edit'
    const [form, setForm] = useState(emptyForm);
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);
    const [deleteRow, setDeleteRow] = useState(null);
    const [deleting, setDeleting] = useState(false);

    useEffect(() => { setRows(initial); }, [initial]);

    const inchargeName = (row) => row.incharge_user?.name || users.find(u => String(u.id) === String(row.incharge))?.name || '—';

    const filtered = useMemo(() => {
        const q = search.trim().toLowerCase();
        if (!q) return rows;
        return rows.filter(r =>
            r.location?.toLowerCase().includes(q) ||
            inchargeName(r).toLowerCase().includes(q) ||
            String(r.start_chainage).includes(q) ||
            String(r.end_chainage).includes(q)
        );
    }, [rows, search, users]);

    const openAdd = () => { setModalType('add'); setForm(emptyForm); setErrors({}); setModalOpen(true); };
    const openEdit = (row) => {
        setModalType('edit');
        setForm({
            id: row.id,
            location: row.location || '',
            start_chainage: row.start_chainage ?? '',
            end_chainage: row.end_chainage ?? '',
            incharge: row.incharge ? String(row.incharge) : '',
        });
        setErrors({});
        setModalOpen(true);
    };

    const handleChange = (field, value) => setForm(prev => ({ ...prev, [field]: value }));

    const handleSubmit = async (e) => {
        e?.preventDefault();
        setErrors({});
        setSaving(true);
        try {
            const payload = {
                location: form.location,
                start_chainage: form.start_chainage,
                end_chainage: form.end_chainage,
                incharge: form.incharge || null,
            };
            const url = modalType === 'edit' ? route('updateJurisdiction') : route('addJurisdiction');
            if (modalType === 'edit') payload.id = form.id;
            const { data } = await axios.post(url, payload);
            setRows(data.jurisdictions || []);
            showToast.success(data.message || `Jurisdiction ${modalType === 'edit' ? 'updated' : 'added'}`);
            setModalOpen(false);
        } catch (error) {
            if (error.response?.status === 422) {
                setErrors(error.response.data.error || {});
                showToast.error('Please correct the highlighted fields.');
            } else {
                showToast.error(error.response?.data?.error || error.response?.data?.message || 'An error occurred');
            }
        } finally {
            setSaving(false);
        }
    };

    const confirmDelete = async () => {
        if (!deleteRow) return;
        setDeleting(true);
        try {
            const { data } = await axios.post(route('deleteJurisdiction'), { id: deleteRow.id });
            setRows(data.jurisdictions || []);
            showToast.success(data.message || 'Jurisdiction deleted');
            setDeleteRow(null);
        } catch (error) {
            showToast.error(error.response?.data?.error || 'Failed to delete jurisdiction');
        } finally {
            setDeleting(false);
        }
    };

    const err = (f) => errors?.[f]?.[0];

    return (
        <Box>
            <Flex justify="between" align="center" mb="4" gap="3" wrap="wrap">
                <Box>
                    <Text size="4" weight="bold" as="div">Jurisdictions</Text>
                    <Text size="2" color="gray">Project chainage segments used for daily work reports & RFIs.</Text>
                </Box>
                <Flex gap="3" align="center">
                    <TextField.Root placeholder="Search jurisdictions…" value={search} onChange={e => setSearch(e.target.value)} style={{ minWidth: 220 }}>
                        <TextField.Slot><MagnifyingGlassIcon /></TextField.Slot>
                    </TextField.Root>
                    {canManage && (
                        <Button onClick={openAdd}><PlusIcon /> Add Jurisdiction</Button>
                    )}
                </Flex>
            </Flex>

            <Card variant="surface">
                <Box style={{ overflowX: 'auto' }}>
                    <Table.Root variant="ghost">
                        <Table.Header>
                            <Table.Row>
                                <Table.ColumnHeaderCell>Location</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Start Chainage</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>End Chainage</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Incharge</Table.ColumnHeaderCell>
                                {canManage && <Table.ColumnHeaderCell justify="end">Actions</Table.ColumnHeaderCell>}
                            </Table.Row>
                        </Table.Header>
                        <Table.Body>
                            {filtered.length === 0 ? (
                                <Table.Row>
                                    <Table.Cell colSpan={canManage ? 5 : 4}>
                                        <Flex direction="column" align="center" justify="center" py="8" gap="2">
                                            <SewingPinIcon style={{ width: 36, height: 36, color: 'var(--gray-8)' }} />
                                            <Text size="2" color="gray">No jurisdictions found.</Text>
                                        </Flex>
                                    </Table.Cell>
                                </Table.Row>
                            ) : filtered.map(row => (
                                <Table.Row key={row.id} align="center">
                                    <Table.Cell>
                                        <Flex align="center" gap="2">
                                            <Box p="1" style={{ background: 'var(--blue-a3)', borderRadius: 'var(--radius-2)' }}>
                                                <SewingPinIcon style={{ color: 'var(--blue-9)' }} />
                                            </Box>
                                            <Text weight="medium" size="2">{row.location}</Text>
                                        </Flex>
                                    </Table.Cell>
                                    <Table.Cell><Badge color="gray" variant="soft">{row.start_chainage}</Badge></Table.Cell>
                                    <Table.Cell><Badge color="gray" variant="soft">{row.end_chainage}</Badge></Table.Cell>
                                    <Table.Cell><Text size="2">{inchargeName(row)}</Text></Table.Cell>
                                    {canManage && (
                                        <Table.Cell justify="end">
                                            <Flex gap="3" justify="end">
                                                <Tooltip content="Edit">
                                                    <IconButton size="1" variant="ghost" color="gray" onClick={() => openEdit(row)}><Pencil1Icon /></IconButton>
                                                </Tooltip>
                                                <Tooltip content="Delete">
                                                    <IconButton size="1" variant="ghost" color="red" onClick={() => setDeleteRow(row)}><TrashIcon /></IconButton>
                                                </Tooltip>
                                            </Flex>
                                        </Table.Cell>
                                    )}
                                </Table.Row>
                            ))}
                        </Table.Body>
                    </Table.Root>
                </Box>
            </Card>

            {/* Add / Edit modal */}
            <Dialog.Root open={modalOpen} onOpenChange={(v) => { if (!v && !saving) setModalOpen(false); }}>
                <Dialog.Content style={{ maxWidth: 480 }}>
                    <Dialog.Title>{modalType === 'edit' ? 'Edit Jurisdiction' : 'Add Jurisdiction'}</Dialog.Title>
                    <Dialog.Description size="2" color="gray" mb="3">Define a project chainage segment and its responsible incharge.</Dialog.Description>
                    <form onSubmit={handleSubmit}>
                        <Grid columns="1" gap="3">
                            <Box>
                                <Text size="2" weight="medium" mb="1" as="div">Location Name *</Text>
                                <TextField.Root value={form.location} onChange={e => handleChange('location', e.target.value)} placeholder="e.g. Package 2 — Main Carriageway" disabled={saving} />
                                {err('location') && <Text size="1" color="red">{err('location')}</Text>}
                            </Box>
                            <Grid columns="2" gap="3">
                                <Box>
                                    <Text size="2" weight="medium" mb="1" as="div">Start Chainage *</Text>
                                    <TextField.Root value={form.start_chainage} onChange={e => handleChange('start_chainage', e.target.value)} placeholder="e.g. 12+500" disabled={saving} />
                                    {err('start_chainage') && <Text size="1" color="red">{err('start_chainage')}</Text>}
                                </Box>
                                <Box>
                                    <Text size="2" weight="medium" mb="1" as="div">End Chainage *</Text>
                                    <TextField.Root value={form.end_chainage} onChange={e => handleChange('end_chainage', e.target.value)} placeholder="e.g. 14+200" disabled={saving} />
                                    {err('end_chainage') && <Text size="1" color="red">{err('end_chainage')}</Text>}
                                </Box>
                            </Grid>
                            <Box>
                                <Text size="2" weight="medium" mb="1" as="div">Incharge *</Text>
                                <Select.Root value={form.incharge || undefined} onValueChange={v => handleChange('incharge', v)} disabled={saving}>
                                    <Select.Trigger placeholder="Select incharge" style={{ width: '100%' }} />
                                    <Select.Content>
                                        {users.map(u => (
                                            <Select.Item key={u.id} value={String(u.id)}>{u.name}{u.employee_id ? ` (${u.employee_id})` : ''}</Select.Item>
                                        ))}
                                    </Select.Content>
                                </Select.Root>
                                {err('incharge') && <Text size="1" color="red">{err('incharge')}</Text>}
                            </Box>
                        </Grid>
                        <Flex justify="end" gap="3" mt="4">
                            <Button type="button" variant="soft" color="gray" onClick={() => setModalOpen(false)} disabled={saving}>Cancel</Button>
                            <Button type="submit" disabled={saving}>{saving ? <Spinner size="1" /> : (modalType === 'edit' ? 'Update' : 'Create')}</Button>
                        </Flex>
                    </form>
                </Dialog.Content>
            </Dialog.Root>

            {/* Delete confirm */}
            <AlertDialog.Root open={!!deleteRow} onOpenChange={(v) => { if (!v && !deleting) setDeleteRow(null); }}>
                <AlertDialog.Content style={{ maxWidth: 420 }}>
                    <AlertDialog.Title>Delete jurisdiction</AlertDialog.Title>
                    <AlertDialog.Description size="2">
                        Are you sure you want to delete <strong>{deleteRow?.location}</strong>? This may affect daily work reports referencing this chainage.
                    </AlertDialog.Description>
                    <Flex justify="end" gap="3" mt="4">
                        <Button variant="soft" color="gray" onClick={() => setDeleteRow(null)} disabled={deleting}>Cancel</Button>
                        <Button color="red" onClick={confirmDelete} disabled={deleting}>{deleting ? <Spinner size="1" /> : 'Delete'}</Button>
                    </Flex>
                </AlertDialog.Content>
            </AlertDialog.Root>
        </Box>
    );
};

export default JurisdictionsManager;
