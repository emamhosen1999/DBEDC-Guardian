/**
 * LeaveSettingsPanel.jsx
 * Leave types CRUD — pure Radix UI.
 * * UX Improvements added:
 * - Optimistic Updates: Deletions and edits update the table instantly. If the API fails, it rolls back.
 * - Sticky Table Headers & ScrollAreas: Prevents the page from breaking on mobile.
 * - Responsive Grid Layouts: Forms and switches elegantly stack into single columns on small screens.
 */
import React, { useState, useCallback, useMemo, useEffect } from 'react';
import {
    Box, Flex, Text, Button, TextField, Switch,
    Table, Badge, Tooltip, IconButton, Separator,
    ScrollArea, Spinner, Card, Grid,
} from '@radix-ui/themes';
import {
    PlusIcon, Pencil1Icon, TrashIcon, Cross2Icon,
    ReloadIcon, MagnifyingGlassIcon, CheckCircledIcon,
    CrossCircledIcon, InfoCircledIcon, ClockIcon,
    CalendarIcon, PersonIcon, GearIcon,
} from '@radix-ui/react-icons';
import { showToast } from '@/utils/toastUtils';
import { router } from '@inertiajs/react';
import axios from 'axios';

/* ── Confirm Modal ───────────────────────── */
const ConfirmModal = ({ open, onClose, onConfirm, loading, candidate }) => {
    if (!open) return null;
    return (
        <Box
            style={{
                position: 'fixed', inset: 0, zIndex: 1000,
                background: 'rgba(0,0,0,0.5)', backdropFilter: 'blur(2px)',
                display: 'flex', alignItems: 'center', justifyContent: 'center',
                padding: 16,
            }}
            onClick={e => { if (e.target === e.currentTarget && !loading) onClose(); }}
        >
            <Box
                style={{
                    background: 'var(--color-panel-solid)',
                    border: '1px solid var(--gray-a5)',
                    borderRadius: 'var(--radius-4)',
                    boxShadow: 'var(--shadow-5)',
                    width: '100%', maxWidth: 420,
                    overflow: 'hidden',
                }}
            >
                <Flex align="center" gap="3" px="4" py="3" style={{ borderBottom: '1px solid var(--gray-a4)' }}>
                    <Box style={{ width: 36, height: 36, borderRadius: 'var(--radius-2)', background: 'var(--red-a3)', display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 }}>
                        <TrashIcon style={{ color: 'var(--red-9)', width: 18, height: 18 }} />
                    </Box>
                    <Box>
                        <Text size="3" weight="bold">Delete Leave Type</Text>
                        <Text size="1" color="gray" as="div">This action cannot be undone</Text>
                    </Box>
                </Flex>

                <Box px="4" py="4">
                    <Text size="2">
                        Are you sure you want to delete{' '}
                        <Text size="2" weight="bold" color="red">"{candidate?.type}"</Text>?
                        This will permanently remove this leave type and its associated policies.
                    </Text>
                </Box>

                <Flex gap="3" justify="end" px="4" py="3" style={{ borderTop: '1px solid var(--gray-a4)', backgroundColor: 'var(--gray-a2)' }}>
                    <Button variant="soft" color="gray" onClick={onClose} disabled={loading} style={{ cursor: 'pointer' }}>
                        Cancel
                    </Button>
                    <Button color="red" onClick={onConfirm} disabled={loading} style={{ cursor: 'pointer' }}>
                        {loading ? <Spinner size="1" /> : <TrashIcon />}
                        Delete Leave Type
                    </Button>
                </Flex>
            </Box>
        </Box>
    );
};

/* ── Switch Row Helper ───────────────────────────────────── */
const SwitchRow = ({ icon: Icon, label, description, checked, onCheckedChange, color, disabled }) => (
    <Box p="3" style={{ borderRadius: 'var(--radius-3)', border: '1px solid var(--gray-a4)', background: disabled ? 'var(--gray-a2)' : 'var(--gray-a1)', opacity: disabled ? 0.7 : 1 }}>
        <Flex align="center" justify="between" gap="3">
            <Flex align="center" gap="3" style={{ minWidth: 0 }}>
                <Box p="2" style={{ backgroundColor: `var(--${color}-a3)`, borderRadius: 'var(--radius-2)' }}>
                    <Icon style={{ color: `var(--${color}-9)`, width: 16, height: 16, flexShrink: 0 }} />
                </Box>
                <Box style={{ minWidth: 0 }}>
                    <Text size="2" weight="bold" as="div">{label}</Text>
                    <Text size="1" color="gray" as="div">{description}</Text>
                </Box>
            </Flex>
            <Switch checked={checked} onCheckedChange={onCheckedChange} disabled={disabled} size="2" color={color} style={{ flexShrink: 0 }} />
        </Flex>
    </Box>
);

/* ── Policy Badge ────────────────────────────────────────── */
const PolicyBadge = ({ active, label, tooltip }) => (
    <Tooltip content={tooltip}>
        <Badge color={active ? 'green' : 'gray'} variant="soft" size="1" style={{ cursor: 'default', userSelect: 'none' }}>
            {active ? <CheckCircledIcon style={{ width: 12, height: 12 }} /> : <CrossCircledIcon style={{ width: 12, height: 12 }} />}
            {label}
        </Badge>
    </Tooltip>
);

/* ════════════════════════════════════════════════════════════
   MAIN COMPONENT
   ═══════════════════════════════════════════════════════════ */
const EMPTY_FORM = {
    type:               '',
    days:               '',
    eligibility:        '',
    carry_forward:      false,
    earned_leave:       false,
    requires_approval:  true,
    auto_approve:       false,
    special_conditions: '',
};

export default function LeaveSettingsPanel({
    leaveTypes: initialTypes = [],
    isMobile = false,
    isActive = false,
    onSetHeaderActions,
}) {
    const [leaveTypes,    setLeaveTypes]    = useState(initialTypes);
    const [form,          setForm]          = useState(EMPTY_FORM);
    const [isEditing,     setIsEditing]     = useState(false);
    const [loading,       setLoading]       = useState(false);
    const [deleteLoading, setDeleteLoading] = useState(false);
    const [deleteModal,   setDeleteModal]   = useState({ open: false, candidate: null });
    const [search,        setSearch]        = useState('');
    const [isLoading,     setIsLoading]     = useState(false);

    useEffect(() => { setLeaveTypes(initialTypes); }, [initialTypes]);

    const refetch = useCallback(() => {
        setIsLoading(true);
        router.reload({
            only: ['leaveTypes'],
            onFinish: () => setIsLoading(false),
        });
    }, []);

    /* Header Actions */
    useEffect(() => {
        if (!isActive) return;
        onSetHeaderActions?.(
            <Button size="2" variant="soft" color="gray" onClick={() => refetch()} disabled={isLoading}>
                <ReloadIcon /> {!isMobile && (isLoading ? 'Loading...' : 'Refresh')}
            </Button>
        );
        return () => onSetHeaderActions?.(null);
    }, [isActive, onSetHeaderActions, isMobile, isLoading, refetch]);

    /* Derived State */
    const isFormValid = useMemo(() =>
        form.type.trim() && form.days && parseInt(form.days) > 0,
    [form.type, form.days]);

    const filtered = useMemo(() => {
        if (!search) return leaveTypes;
        const q = search.toLowerCase();
        return leaveTypes.filter(lt =>
            lt.type.toLowerCase().includes(q) ||
            (lt.eligibility || '').toLowerCase().includes(q)
        );
    }, [leaveTypes, search]);

    /* Handlers */
    const setField = (key, val) => setForm(p => ({ ...p, [key]: val }));

    const resetForm = useCallback(() => {
        setForm(EMPTY_FORM);
        setIsEditing(false);
    }, []);

    const handleEdit = useCallback((lt) => {
        setForm({
            ...lt,
            carry_forward:     Boolean(lt.carry_forward),
            earned_leave:      Boolean(lt.earned_leave),
            requires_approval: Boolean(lt.requires_approval ?? true),
            auto_approve:      Boolean(lt.auto_approve),
        });
        setIsEditing(true);
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }, []);

    /* Optimistic Submit */
    const handleSubmit = async () => {
        if (!isFormValid) { showToast.error('Leave type name and days are required.'); return; }
        setLoading(true);

        if (isEditing) {
            const previousState = [...leaveTypes];
            // Optimistic update
            setLeaveTypes(p => p.map(lt => lt.id === form.id ? { ...form } : lt));
            resetForm();

            try {
                await axios.put(route('update-leave-type', { id: form.id }), form);
                showToast.success('Leave type updated successfully.');
            } catch (e) {
                setLeaveTypes(previousState); // Rollback
                showToast.error(e.response?.data?.message || 'Failed to update. Rolling back.');
            } finally {
                setLoading(false);
            }
        } else {
            // Creation requires DB ID, cannot be fully optimistic
            try {
                const r = await axios.post(route('add-leave-type'), form);
                setLeaveTypes(p => [...p, { ...form, id: r.data.id }]);
                resetForm();
                showToast.success('Leave type added successfully.');
            } catch (e) {
                showToast.error(e.response?.data?.message || 'Failed to add leave type.');
            } finally {
                setLoading(false);
            }
        }
    };

    /* Optimistic Delete */
    const confirmDelete = async () => {
        const candidate = deleteModal.candidate;
        if (!candidate) return;
        
        setDeleteLoading(true);
        const previousState = [...leaveTypes];
        
        // Optimistic delete
        setLeaveTypes(p => p.filter(lt => lt.id !== candidate.id));
        setDeleteModal({ open: false, candidate: null });

        try {
            await axios.delete(route('delete-leave-type', { id: candidate.id }));
            showToast.success('Leave type deleted.');
        } catch (e) {
            setLeaveTypes(previousState); // Rollback
            showToast.error(e.response?.data?.message || 'Failed to delete. Rolling back.');
        } finally {
            setDeleteLoading(false);
        }
    };

    /* ── Render ─────────────────────────────────────────────── */
    return (
        <Box>
            {/* ── Add / Edit Form ── */}
            <Box mb="5">
                <Flex align="center" gap="3" mb="4">
                    <Box style={{ width: 40, height: 40, borderRadius: 'var(--radius-3)', background: 'var(--accent-a3)', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                        {isEditing ? <Pencil1Icon style={{ color: 'var(--accent-9)', width: 20, height: 20 }} /> : <PlusIcon style={{ color: 'var(--accent-9)', width: 20, height: 20 }} />}
                    </Box>
                    <Box>
                        <Text size="4" weight="bold">{isEditing ? 'Edit Leave Type' : 'Add New Leave Type'}</Text>
                        <Text size="2" color="gray" as="div">{isEditing ? 'Update the leave type details below' : 'Configure a new leave type with policies and rules'}</Text>
                    </Box>
                </Flex>

                <Card size="3" variant="surface">
                    <Flex direction="column" gap="5">
                        {/* Text Fields Grid */}
                        <Grid columns={{ initial: '1', sm: '2', lg: '3' }} gap="4">
                            <Box>
                                <Text size="2" weight="medium" as="div" mb="2">Leave Type <Text color="red">*</Text></Text>
                                <TextField.Root size="2" placeholder="e.g. Annual Leave, Sick Leave" value={form.type} onChange={e => setField('type', e.target.value)}>
                                    <TextField.Slot><ClockIcon /></TextField.Slot>
                                </TextField.Root>
                            </Box>

                            <Box>
                                <Text size="2" weight="medium" as="div" mb="2">Number of Days <Text color="red">*</Text></Text>
                                <TextField.Root size="2" type="number" min="0" placeholder="e.g. 21" value={form.days} onChange={e => setField('days', e.target.value)}>
                                    <TextField.Slot><CalendarIcon /></TextField.Slot>
                                </TextField.Root>
                            </Box>

                            <Box>
                                <Text size="2" weight="medium" as="div" mb="2">Eligibility Criteria</Text>
                                <TextField.Root size="2" placeholder="e.g. After 1 year of service" value={form.eligibility} onChange={e => setField('eligibility', e.target.value)}>
                                    <TextField.Slot><PersonIcon /></TextField.Slot>
                                </TextField.Root>
                            </Box>

                            <Box style={{ gridColumn: isMobile ? '1' : 'span 2' }}>
                                <Text size="2" weight="medium" as="div" mb="2">Special Conditions</Text>
                                <TextField.Root size="2" placeholder="e.g. Medical certificate required" value={form.special_conditions} onChange={e => setField('special_conditions', e.target.value)}>
                                    <TextField.Slot><InfoCircledIcon /></TextField.Slot>
                                </TextField.Root>
                            </Box>
                        </Grid>

                        {/* Policy Toggles */}
                        <Text size="3" weight="bold" mt="2">Policy Rules</Text>
                        <Grid columns={{ initial: '1', sm: '2', lg: '4' }} gap="3">
                            <SwitchRow icon={ReloadIcon} label="Carry Forward" description="Allow unused days to next year" checked={form.carry_forward} onCheckedChange={v => setField('carry_forward', v)} color="orange" />
                            <SwitchRow icon={ClockIcon} label="Earned Leave" description="Accumulated over time" checked={form.earned_leave} onCheckedChange={v => setField('earned_leave', v)} color="green" />
                            <SwitchRow icon={CheckCircledIcon} label="Approval Req." description="Manager approval needed" checked={form.requires_approval} onCheckedChange={v => { setField('requires_approval', v); if (!v) setField('auto_approve', false); }} color="blue" />
                            <SwitchRow icon={GearIcon} label="Auto Approve" description="Skip approval workflow" checked={form.auto_approve} onCheckedChange={v => setField('auto_approve', v)} color="teal" disabled={!form.requires_approval} />
                        </Grid>

                        {/* Action Buttons */}
                        <Separator size="4" />
                        <Flex gap="3" justify="end" wrap="wrap">
                            {isEditing && (
                                <Button variant="soft" color="gray" size="2" onClick={resetForm} disabled={loading} style={{ cursor: 'pointer' }}>
                                    <Cross2Icon /> Cancel
                                </Button>
                            )}
                            <Button size="2" color={isEditing ? 'amber' : 'indigo'} onClick={handleSubmit} disabled={!isFormValid || loading} style={{ cursor: isFormValid ? 'pointer' : 'not-allowed' }}>
                                {loading ? <Spinner size="1" /> : isEditing ? <Pencil1Icon /> : <PlusIcon />}
                                {isEditing ? 'Update Leave Type' : 'Add Leave Type'}
                            </Button>
                        </Flex>
                    </Flex>
                </Card>
            </Box>

            <Separator size="4" mb="5" />

            {/* ── Leave Types Table ── */}
            <Box>
                <Flex align="center" justify="between" gap="3" mb="4" wrap="wrap">
                    <Flex align="center" gap="2">
                        <Text size="4" weight="bold">Leave Types Overview</Text>
                        <Badge color="blue" variant="soft" size="2" radius="full">{leaveTypes.length}</Badge>
                    </Flex>
                    <Box style={{ flex: isMobile ? 1 : '0 0 250px' }}>
                        <TextField.Root size="2" placeholder="Search leave types…" value={search} onChange={e => setSearch(e.target.value)}>
                            <TextField.Slot><MagnifyingGlassIcon /></TextField.Slot>
                            {search && (
                                <TextField.Slot side="right">
                                    <IconButton size="1" variant="ghost" onClick={() => setSearch('')}><Cross2Icon /></IconButton>
                                </TextField.Slot>
                            )}
                        </TextField.Root>
                    </Box>
                </Flex>

                <Card variant="surface" p="0" style={{ overflow: 'hidden' }}>
                    <ScrollArea scrollbars="both" style={{ maxHeight: 600 }}>
                        <Table.Root size="2" variant="ghost">
                            <Table.Header style={{ backgroundColor: 'var(--gray-a2)', position: 'sticky', top: 0, zIndex: 1 }}>
                                <Table.Row>
                                    <Table.ColumnHeaderCell style={{ minWidth: 180 }}>Leave Type</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell style={{ width: 100, textAlign: 'center' }}>Days</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell style={{ minWidth: 160 }}>Eligibility</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell style={{ minWidth: 260 }}>Policies</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell style={{ minWidth: 200 }}>Special Conditions</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell style={{ width: 100, textAlign: 'center' }}>Actions</Table.ColumnHeaderCell>
                                </Table.Row>
                            </Table.Header>

                            <Table.Body>
                                {filtered.length === 0 ? (
                                    <Table.Row>
                                        <Table.Cell colSpan={6}>
                                            <Flex direction="column" align="center" py="9" gap="3">
                                                <CalendarIcon style={{ color: 'var(--gray-8)', width: 48, height: 48 }} />
                                                <Text size="3" weight="bold">
                                                    {search ? 'No matches found.' : 'No leave types configured yet.'}
                                                </Text>
                                            </Flex>
                                        </Table.Cell>
                                    </Table.Row>
                                ) : (
                                    filtered.map(lt => (
                                        <Table.Row key={lt.id}>
                                            <Table.Cell>
                                                <Flex align="center" gap="3">
                                                    <Box style={{ width: 32, height: 32, borderRadius: 'var(--radius-2)', background: 'var(--accent-a3)', display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 }}>
                                                        <ClockIcon style={{ color: 'var(--accent-9)', width: 16 }} />
                                                    </Box>
                                                    <Text size="2" weight="bold">{lt.type}</Text>
                                                </Flex>
                                            </Table.Cell>
                                            <Table.Cell style={{ textAlign: 'center' }}>
                                                <Badge color="blue" variant="solid" size="2">{lt.days} days</Badge>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Text size="2" color={lt.eligibility ? 'gray' : 'gray'} style={{ fontStyle: lt.eligibility ? 'normal' : 'italic' }}>
                                                    {lt.eligibility || 'None'}
                                                </Text>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Flex gap="2" wrap="wrap">
                                                    <PolicyBadge active={lt.carry_forward} label="Carry Fwd" tooltip="Carry Forward Policy" />
                                                    <PolicyBadge active={lt.earned_leave} label="Earned" tooltip="Earned Leave Policy" />
                                                    <PolicyBadge active={lt.requires_approval ?? true} label="Approval" tooltip="Requires Manager Approval" />
                                                    {lt.auto_approve && (
                                                        <Badge color="teal" variant="soft" size="1">
                                                            <CheckCircledIcon style={{ width: 12, height: 12 }} /> Auto
                                                        </Badge>
                                                    )}
                                                </Flex>
                                            </Table.Cell>
                                            <Table.Cell>
                                                {lt.special_conditions ? (
                                                    <Flex align="center" gap="2">
                                                        <InfoCircledIcon style={{ color: 'var(--amber-9)', width: 14, flexShrink: 0 }} />
                                                        <Text size="2" color="gray">{lt.special_conditions}</Text>
                                                    </Flex>
                                                ) : (
                                                    <Text size="2" color="gray" style={{ fontStyle: 'italic' }}>None</Text>
                                                )}
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Flex gap="2" justify="center">
                                                    <Tooltip content="Edit">
                                                        <IconButton size="1" variant="soft" color="blue" onClick={() => handleEdit(lt)} style={{ cursor: 'pointer' }}>
                                                            <Pencil1Icon />
                                                        </IconButton>
                                                    </Tooltip>
                                                    <Tooltip content="Delete">
                                                        <IconButton size="1" variant="soft" color="red" onClick={() => setDeleteModal({ open: true, candidate: lt })} style={{ cursor: 'pointer' }}>
                                                            <TrashIcon />
                                                        </IconButton>
                                                    </Tooltip>
                                                </Flex>
                                            </Table.Cell>
                                        </Table.Row>
                                    ))
                                )}
                            </Table.Body>
                        </Table.Root>
                    </ScrollArea>
                </Card>
            </Box>

            {/* ── Delete Confirm Modal ── */}
            <ConfirmModal
                open={deleteModal.open}
                onClose={() => { if (!deleteLoading) setDeleteModal({ open: false, candidate: null }); }}
                onConfirm={confirmDelete}
                loading={deleteLoading}
                candidate={deleteModal.candidate}
            />
        </Box>
    );
}