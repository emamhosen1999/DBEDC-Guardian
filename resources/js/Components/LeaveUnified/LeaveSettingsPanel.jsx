/**
 * LeaveSettingsPanel.jsx
 * Leave types CRUD — full Radix UI port of LeaveSettings.jsx (HeroUI removed).
 * Embedded as a tab panel inside LeavesUnified — no App layout wrapper here.
 *
 * Props:
 *   leaveTypes  – array from Inertia page props (passed down from LeavesUnified)
 *   isMobile    – bool
 *   isActive    – bool
 *   onSetHeaderActions – fn(ReactNode)
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
import axios from 'axios';

/* ── simple confirm modal (pure Radix, no HeroUI) ───────── */
const ConfirmModal = ({ open, onClose, onConfirm, loading, candidate }) => {
    if (!open) return null;
    return (
        <Box
            style={{
                position: 'fixed', inset: 0, zIndex: 1000,
                background: 'rgba(0,0,0,0.5)',
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
                    width: '100%', maxWidth: 420,
                    overflow: 'hidden',
                }}
            >
                {/* header */}
                <Flex
                    align="center" gap="3" px="4" py="3"
                    style={{ borderBottom: '1px solid var(--gray-a4)' }}
                >
                    <Box
                        style={{
                            width: 36, height: 36, borderRadius: 'var(--radius-2)',
                            background: 'var(--red-a3)',
                            display: 'flex', alignItems: 'center', justifyContent: 'center',
                            flexShrink: 0,
                        }}
                    >
                        <TrashIcon style={{ color: 'var(--red-9)', width: 16, height: 16 }} />
                    </Box>
                    <Box>
                        <Text size="3" weight="bold">Delete Leave Type</Text>
                        <Text size="1" color="gray" as="div">This action cannot be undone</Text>
                    </Box>
                </Flex>

                {/* body */}
                <Box px="4" py="4">
                    <Text size="2">
                        Are you sure you want to delete{' '}
                        <Text size="2" weight="bold" color="red">"{candidate?.type}"</Text>?
                        This will permanently remove this leave type and all associated policies.
                    </Text>
                </Box>

                {/* footer */}
                <Flex
                    gap="3" justify="end" px="4" py="3"
                    style={{ borderTop: '1px solid var(--gray-a4)' }}
                >
                    <Button variant="soft" color="gray" onClick={onClose} disabled={loading}>
                        Cancel
                    </Button>
                    <Button color="red" onClick={onConfirm} disabled={loading}>
                        {loading ? <Spinner size="1" /> : <TrashIcon />}
                        Delete Leave Type
                    </Button>
                </Flex>
            </Box>
        </Box>
    );
};

/* ── switch row helper ───────────────────────────────────── */
const SwitchRow = ({ icon: Icon, label, description, checked, onCheckedChange, color, disabled }) => (
    <Box
        p="3"
        style={{
            borderRadius: 'var(--radius-3)',
            border: '1px solid var(--gray-a4)',
            background: 'var(--gray-a1)',
        }}
    >
        <Flex align="center" justify="between" gap="3">
            <Flex align="center" gap="2" style={{ minWidth: 0 }}>
                <Icon style={{ color: 'var(--gray-9)', width: 15, height: 15, flexShrink: 0 }} />
                <Box style={{ minWidth: 0 }}>
                    <Text size="2" weight="medium" as="div">{label}</Text>
                    <Text size="1" color="gray" as="div">{description}</Text>
                </Box>
            </Flex>
            <Switch
                checked={checked}
                onCheckedChange={onCheckedChange}
                disabled={disabled}
                size="2"
                color={color}
                style={{ flexShrink: 0 }}
            />
        </Flex>
    </Box>
);

/* ── policy badge ────────────────────────────────────────── */
const PolicyBadge = ({ active, label, tooltip }) => (
    <Tooltip content={tooltip}>
        <Badge
            color={active ? 'green' : 'gray'}
            variant="soft"
            size="1"
            style={{ cursor: 'default', userSelect: 'none' }}
        >
            {active
                ? <CheckCircledIcon style={{ width: 10 }} />
                : <CrossCircledIcon style={{ width: 10 }} />
            }
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

    /* keep local list fresh if parent re-renders with new props */
    useEffect(() => { setLeaveTypes(initialTypes); }, [initialTypes]);

    /* header actions — refresh button */
    useEffect(() => {
        if (!isActive) return;
        onSetHeaderActions?.(
            <Button size="2" variant="soft" color="gray" onClick={() => window.location.reload()}>
                <ReloadIcon /> Refresh
            </Button>
        );
        return () => onSetHeaderActions?.(null);
    }, [isActive, onSetHeaderActions]);

    /* derived */
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

    /* handlers */
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

    const handleSubmit = async () => {
        if (!isFormValid) { showToast.error('Leave type name and days are required.'); return; }
        setLoading(true);
        const promise = isEditing
            ? axios.put(`/update-leave-type/${form.id}`, form).then(r => {
                setLeaveTypes(p => p.map(lt => lt.id === form.id ? { ...form } : lt));
                resetForm();
                return 'Leave type updated successfully.';
              })
            : axios.post('/add-leave-type', form).then(r => {
                setLeaveTypes(p => [...p, { ...form, id: r.data.id }]);
                resetForm();
                return 'Leave type added successfully.';
              });

        showToast.promise(promise.finally(() => setLoading(false)), {
            loading: isEditing ? 'Updating...' : 'Adding...',
            success: msg => msg,
            error:   e   => e.response?.data?.message || 'Failed to save leave type.',
        });
    };

    const confirmDelete = async () => {
        const candidate = deleteModal.candidate;
        if (!candidate) return;
        setDeleteLoading(true);
        const promise = axios.delete(`/delete-leave-type/${candidate.id}`).then(r => {
            setLeaveTypes(p => p.filter(lt => lt.id !== candidate.id));
            setDeleteModal({ open: false, candidate: null });
            return r.data?.message || 'Leave type deleted.';
        });
        showToast.promise(promise.finally(() => setDeleteLoading(false)), {
            loading: 'Deleting...',
            success: msg => msg,
            error:   e   => e.response?.data?.message || 'Failed to delete.',
        });
    };

    /* ── render ─────────────────────────────────────────────── */
    return (
        <>
            {/* ── Add / Edit Form ──────────────────────────────── */}
            <Box mb="5">
                <Flex align="center" gap="2" mb="4">
                    <Box
                        style={{
                            width: 32, height: 32, borderRadius: 'var(--radius-2)',
                            background: 'var(--accent-a3)',
                            display: 'flex', alignItems: 'center', justifyContent: 'center',
                        }}
                    >
                        {isEditing
                            ? <Pencil1Icon style={{ color: 'var(--accent-9)', width: 15 }} />
                            : <PlusIcon    style={{ color: 'var(--accent-9)', width: 15 }} />
                        }
                    </Box>
                    <Box>
                        <Text size="3" weight="bold">
                            {isEditing ? 'Edit Leave Type' : 'Add New Leave Type'}
                        </Text>
                        <Text size="1" color="gray" as="div">
                            {isEditing
                                ? 'Update the leave type details below'
                                : 'Configure a new leave type with policies and rules'
                            }
                        </Text>
                    </Box>
                </Flex>

                <Card size="2">
                    <Flex direction="column" gap="4">
                        {/* text fields row */}
                        <Grid columns={{ initial: '1', sm: '2', lg: '3' }} gap="3">
                            <Box>
                                <Text size="2" weight="medium" as="div" mb="1">
                                    Leave Type <Text color="red">*</Text>
                                </Text>
                                <TextField.Root
                                    size="2"
                                    placeholder="e.g. Annual Leave, Sick Leave"
                                    value={form.type}
                                    onChange={e => setField('type', e.target.value)}
                                >
                                    <TextField.Slot><ClockIcon /></TextField.Slot>
                                </TextField.Root>
                            </Box>

                            <Box>
                                <Text size="2" weight="medium" as="div" mb="1">
                                    Number of Days <Text color="red">*</Text>
                                </Text>
                                <TextField.Root
                                    size="2"
                                    type="number"
                                    min="0"
                                    placeholder="e.g. 21"
                                    value={form.days}
                                    onChange={e => setField('days', e.target.value)}
                                >
                                    <TextField.Slot><CalendarIcon /></TextField.Slot>
                                </TextField.Root>
                            </Box>

                            <Box>
                                <Text size="2" weight="medium" as="div" mb="1">Eligibility Criteria</Text>
                                <TextField.Root
                                    size="2"
                                    placeholder="e.g. After 1 year of service"
                                    value={form.eligibility}
                                    onChange={e => setField('eligibility', e.target.value)}
                                >
                                    <TextField.Slot><PersonIcon /></TextField.Slot>
                                </TextField.Root>
                            </Box>

                            <Box style={{ gridColumn: isMobile ? '1' : 'span 2' }}>
                                <Text size="2" weight="medium" as="div" mb="1">Special Conditions</Text>
                                <TextField.Root
                                    size="2"
                                    placeholder="e.g. Medical certificate required"
                                    value={form.special_conditions}
                                    onChange={e => setField('special_conditions', e.target.value)}
                                >
                                    <TextField.Slot><InfoCircledIcon /></TextField.Slot>
                                </TextField.Root>
                            </Box>
                        </Grid>

                        {/* policy toggles */}
                        <Grid columns={{ initial: '1', sm: '2', lg: '4' }} gap="3">
                            <SwitchRow
                                icon={ReloadIcon}
                                label="Carry Forward"
                                description="Allow unused days to next year"
                                checked={form.carry_forward}
                                onCheckedChange={v => setField('carry_forward', v)}
                                color="orange"
                            />
                            <SwitchRow
                                icon={ClockIcon}
                                label="Earned Leave"
                                description="Accumulated over time"
                                checked={form.earned_leave}
                                onCheckedChange={v => setField('earned_leave', v)}
                                color="green"
                            />
                            <SwitchRow
                                icon={CheckCircledIcon}
                                label="Requires Approval"
                                description="Manager approval needed"
                                checked={form.requires_approval}
                                onCheckedChange={v => {
                                    setField('requires_approval', v);
                                    if (!v) setField('auto_approve', false);
                                }}
                                color="blue"
                            />
                            <SwitchRow
                                icon={GearIcon}
                                label="Auto Approve"
                                description="Skip approval workflow"
                                checked={form.auto_approve}
                                onCheckedChange={v => setField('auto_approve', v)}
                                color="green"
                                disabled={!form.requires_approval}
                            />
                        </Grid>

                        {/* action buttons */}
                        <Flex gap="2" justify="end" wrap="wrap">
                            {isEditing && (
                                <Button variant="soft" color="gray" onClick={resetForm} disabled={loading}>
                                    <Cross2Icon /> Cancel
                                </Button>
                            )}
                            <Button
                                color={isEditing ? 'amber' : 'accent'}
                                onClick={handleSubmit}
                                disabled={!isFormValid || loading}
                            >
                                {loading
                                    ? <Spinner size="1" />
                                    : isEditing ? <Pencil1Icon /> : <PlusIcon />
                                }
                                {isEditing ? 'Update Leave Type' : 'Add Leave Type'}
                            </Button>
                        </Flex>
                    </Flex>
                </Card>
            </Box>

            <Separator size="4" mb="5" />

            {/* ── Leave Types Table ─────────────────────────────── */}
            <Box>
                <Flex align="center" justify="between" gap="3" mb="4" wrap="wrap">
                    <Flex align="center" gap="2">
                        <Text size="3" weight="bold">Leave Types Overview</Text>
                        <Badge color="blue" variant="soft" size="1">{leaveTypes.length} types</Badge>
                    </Flex>
                    <TextField.Root
                        size="2"
                        placeholder="Search leave types…"
                        value={search}
                        onChange={e => setSearch(e.target.value)}
                        style={{ width: 220 }}
                    >
                        <TextField.Slot><MagnifyingGlassIcon /></TextField.Slot>
                    </TextField.Root>
                </Flex>

                <ScrollArea scrollbars="both" style={{ maxHeight: 520 }}>
                    <Table.Root size="2" variant="surface" style={{ minWidth: 780 }}>
                        <Table.Header>
                            <Table.Row>
                                <Table.ColumnHeaderCell style={{ minWidth: 160 }}>
                                    <Flex align="center" gap="1">
                                        <ClockIcon style={{ width: 13 }} />
                                        <Text size="2">Leave Type</Text>
                                    </Flex>
                                </Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell style={{ width: 80, textAlign: 'center' }}>
                                    <Flex align="center" justify="center" gap="1">
                                        <CalendarIcon style={{ width: 13 }} />
                                        <Text size="2">Days</Text>
                                    </Flex>
                                </Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell style={{ minWidth: 160 }}>
                                    <Flex align="center" gap="1">
                                        <PersonIcon style={{ width: 13 }} />
                                        <Text size="2">Eligibility</Text>
                                    </Flex>
                                </Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell style={{ minWidth: 260 }}>
                                    <Flex align="center" gap="1">
                                        <CheckCircledIcon style={{ width: 13 }} />
                                        <Text size="2">Policies</Text>
                                    </Flex>
                                </Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell style={{ minWidth: 180 }}>
                                    <Flex align="center" gap="1">
                                        <InfoCircledIcon style={{ width: 13 }} />
                                        <Text size="2">Special Conditions</Text>
                                    </Flex>
                                </Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell style={{ width: 80, textAlign: 'center' }}>
                                    <Text size="2">Actions</Text>
                                </Table.ColumnHeaderCell>
                            </Table.Row>
                        </Table.Header>

                        <Table.Body>
                            {filtered.length === 0 ? (
                                <Table.Row>
                                    <Table.Cell colSpan={6}>
                                        <Flex direction="column" align="center" py="8" gap="2">
                                            <CalendarIcon style={{ color: 'var(--gray-7)', width: 32, height: 32 }} />
                                            <Text size="2" color="gray">
                                                {search ? 'No leave types match your search.' : 'No leave types configured yet.'}
                                            </Text>
                                        </Flex>
                                    </Table.Cell>
                                </Table.Row>
                            ) : (
                                filtered.map(lt => (
                                    <Table.Row key={lt.id}>
                                        {/* name */}
                                        <Table.Cell>
                                            <Flex align="center" gap="2">
                                                <Box
                                                    style={{
                                                        width: 30, height: 30, borderRadius: 'var(--radius-2)',
                                                        background: 'var(--accent-a3)',
                                                        display: 'flex', alignItems: 'center', justifyContent: 'center',
                                                        flexShrink: 0,
                                                    }}
                                                >
                                                    <ClockIcon style={{ color: 'var(--accent-9)', width: 14 }} />
                                                </Box>
                                                <Box>
                                                    <Text size="2" weight="medium">{lt.type}</Text>
                                                    <Text size="1" color="gray">Leave Type</Text>
                                                </Box>
                                            </Flex>
                                        </Table.Cell>

                                        {/* days */}
                                        <Table.Cell style={{ textAlign: 'center' }}>
                                            <Badge color="blue" variant="soft" size="2">
                                                {lt.days} days
                                            </Badge>
                                        </Table.Cell>

                                        {/* eligibility */}
                                        <Table.Cell>
                                            <Text size="2" color={lt.eligibility ? undefined : 'gray'}>
                                                {lt.eligibility || 'No specific criteria'}
                                            </Text>
                                        </Table.Cell>

                                        {/* policies */}
                                        <Table.Cell>
                                            <Flex gap="1" wrap="wrap">
                                                <PolicyBadge
                                                    active={lt.carry_forward}
                                                    label="Carry Fwd"
                                                    tooltip="Carry Forward Policy"
                                                />
                                                <PolicyBadge
                                                    active={lt.earned_leave}
                                                    label="Earned"
                                                    tooltip="Earned Leave Policy"
                                                />
                                                <PolicyBadge
                                                    active={lt.requires_approval ?? true}
                                                    label="Approval"
                                                    tooltip="Requires Manager Approval"
                                                />
                                                {lt.auto_approve && (
                                                    <Badge color="green" variant="soft" size="1">
                                                        <CheckCircledIcon style={{ width: 10 }} /> Auto
                                                    </Badge>
                                                )}
                                            </Flex>
                                        </Table.Cell>

                                        {/* special conditions */}
                                        <Table.Cell>
                                            {lt.special_conditions ? (
                                                <Flex align="center" gap="1">
                                                    <InfoCircledIcon style={{ color: 'var(--amber-9)', width: 13, flexShrink: 0 }} />
                                                    <Text size="2">{lt.special_conditions}</Text>
                                                </Flex>
                                            ) : (
                                                <Text size="2" color="gray" style={{ fontStyle: 'italic' }}>
                                                    None
                                                </Text>
                                            )}
                                        </Table.Cell>

                                        {/* actions */}
                                        <Table.Cell>
                                            <Flex gap="1" justify="center">
                                                <Tooltip content="Edit">
                                                    <IconButton
                                                        size="1" variant="ghost" color="blue"
                                                        onClick={() => handleEdit(lt)}
                                                    >
                                                        <Pencil1Icon />
                                                    </IconButton>
                                                </Tooltip>
                                                <Tooltip content="Delete">
                                                    <IconButton
                                                        size="1" variant="ghost" color="red"
                                                        onClick={() => setDeleteModal({ open: true, candidate: lt })}
                                                    >
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
            </Box>

            {/* ── Delete Confirm Modal ──────────────────────────── */}
            <ConfirmModal
                open={deleteModal.open}
                onClose={() => { if (!deleteLoading) setDeleteModal({ open: false, candidate: null }); }}
                onConfirm={confirmDelete}
                loading={deleteLoading}
                candidate={deleteModal.candidate}
            />
        </>
    );
}
