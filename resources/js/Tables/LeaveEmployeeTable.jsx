import React, { useState, useCallback } from "react";
import { useMediaQuery } from '@/Hooks/useMediaQuery.js';
import { usePage, router } from "@inertiajs/react";
import { showToast } from '@/utils/toastUtils';
import {
    Avatar, Badge, Box, Button, Card,
    DropdownMenu, Flex, IconButton, ScrollArea,
    Separator, Spinner, Table, Text, Tooltip,
} from '@radix-ui/themes';
import {
    CalendarIcon, CheckCircledIcon, ChevronLeftIcon, ChevronRightIcon,
    ClockIcon, CrossCircledIcon, DotsVerticalIcon,
    ExclamationTriangleIcon, FileTextIcon,
    Pencil1Icon, PersonIcon, TrashIcon,
} from '@radix-ui/react-icons';
import axios from 'axios';
import ApprovalActions from '@/Components/Leave/ApprovalActions.jsx';




/* ── helpers ─────────────────────────────────────────────────────────────── */
const TablePagination = ({ currentPage, lastPage, totalRows, onChange }) => (
    <Flex align="center" justify="between" mt="3" px="1">
        <Text size="1" color="gray">
            Page {currentPage} of {lastPage} &mdash; {totalRows} records
        </Text>
        <Flex align="center" gap="1">
            <Button size="1" variant="soft" disabled={currentPage <= 1} onClick={() => onChange(currentPage - 1)}>
                <ChevronLeftIcon /> Prev
            </Button>
            <Button size="1" variant="soft" disabled={currentPage >= lastPage} onClick={() => onChange(currentPage + 1)}>
                Next <ChevronRightIcon />
            </Button>
        </Flex>
    </Flex>
);

const UserCell = ({ user }) => (
    <Flex align="center" gap="2">
        <Avatar
            size="1"
            src={user?.profile_image_url || user?.profile_image}
            fallback={(user?.name?.[0] || '?').toUpperCase()}
            radius="full"
        />
        <Box>
            <Text size="2" weight="medium" style={{ display: 'block' }}>
                {user?.name || 'Unknown User'}
            </Text>
            {user?.phone
                ? <Text size="1" color="blue" as="a" href={`tel:${user.phone}`}>{user.phone}</Text>
                : <Text size="1" color="gray">No Phone</Text>
            }
        </Box>
    </Flex>
);

/* ── main component ───────────────────────────────────────────────────────── */
const LeaveEmployeeTable = React.forwardRef(({
    leaves,
    allUsers,
    handleClickOpen,
    setCurrentLeave,
    openModal,
    setLeaves,
    setCurrentPage,
    currentPage,
    totalRows,
    lastPage,
    perPage,
    selectedMonth,
    employee,
    isAdminView = false,
    onBulkApprove,
    onBulkReject,
    onBulkDelete,
    canApproveLeaves = false,
    canEditLeaves = false,
    canDeleteLeaves = false,
    fetchLeavesStats,
}, ref) => {
    const { auth } = usePage().props;
    const isMobile = useMediaQuery('(max-width: 640px)');

    const [updatingLeave, setUpdatingLeave] = useState(null);
    const [selectedIds, setSelectedIds] = useState(new Set());

    /* ── status config ── */
    const statusConfig = {
        'New':      { color: 'blue',  icon: ExclamationTriangleIcon },
        'Pending':  { color: 'amber', icon: ClockIcon },
        'Approved': { color: 'green', icon: CheckCircledIcon },
        'Declined': { color: 'red',   icon: CrossCircledIcon },
    };

    /* ── helpers ── */
    const getLeaveTypeIcon = (type) => {
        switch (type?.toLowerCase()) {
            case 'casual':  return <FileTextIcon  style={{ width: 12, height: 12, color: 'var(--blue-9)'  }} />;
            case 'weekend': return <CalendarIcon  style={{ width: 12, height: 12, color: 'var(--amber-9)' }} />;
            case 'sick':    return <CrossCircledIcon style={{ width: 12, height: 12, color: 'var(--red-9)' }} />;
            case 'earned':  return <ClockIcon     style={{ width: 12, height: 12, color: 'var(--green-9)' }} />;
            default:        return <FileTextIcon  style={{ width: 12, height: 12, color: 'var(--accent-9)' }} />;
        }
    };

    const getLeaveDuration = (fromDate, toDate) => {
        const diff = Math.ceil(Math.abs(new Date(toDate) - new Date(fromDate)) / 86400000) + 1;
        return diff === 1 ? '1 day' : `${diff} days`;
    };

    const formatDate = (d) => new Date(d).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' });

    const getUserInfo = (userId) =>
        allUsers?.find(u => String(u.id) === String(userId)) || { name: 'Unknown User', phone: '' };

    const getStatusBadge = (status) => {
        const config = statusConfig[status] || statusConfig['New'];
        const StatusIcon = config.icon;
        return (
            <Badge color={config.color} variant="soft" radius="full">
                <StatusIcon style={{ width: 10, height: 10 }} />{status}
            </Badge>
        );
    };

    const handlePageChange = useCallback((page) => {
        if (setCurrentPage) setCurrentPage(page);
    }, [setCurrentPage]);

    const updateLeaveStatus = useCallback(async (leave, newStatus) => {
        if (leave.status === newStatus) {
            showToast.info(`Leave is already ${newStatus}.`);
            return;
        }
        const actionKey = `${leave.id}-${newStatus}`;
        if (updatingLeave === actionKey) return;
        setUpdatingLeave(actionKey);
        const promise = new Promise(async (resolve, reject) => {
            try {
                const response = await axios.post(route('leave-update-status'), { id: leave.id, status: newStatus });
                if (response.status === 200) {
                    setLeaves(prev => prev.map(l => l.id === leave.id ? { ...l, status: newStatus } : l));
                    fetchLeavesStats?.();
                    resolve(response.data.message || 'Leave status updated successfully');
                } else {
                    reject(response.data?.message || 'Failed to update leave status');
                }
            } catch (error) {
                reject(error.response?.data?.message || error.response?.statusText || 'Failed to update leave status');
            } finally {
                setUpdatingLeave(null);
            }
        });
        showToast.promise(promise, {
            loading: 'Updating leave status...',
            success: 'Leave status updated successfully!',
            error:   'Failed to update leave status',
        });
        return promise;
    }, [setLeaves, updatingLeave, fetchLeavesStats]);

    /* ── selection ── */
    const allSelected = leaves.length > 0 && selectedIds.size === leaves.length;
    const toggleAll   = () => setSelectedIds(allSelected ? new Set() : new Set(leaves.map(l => l.id)));
    const toggleOne   = useCallback((id) => setSelectedIds(prev => {
        const next = new Set(prev);
        next.has(id) ? next.delete(id) : next.add(id);
        return next;
    }), []);

    /* ── bulk bar ── */
    const bulkBar = isAdminView && onBulkDelete && selectedIds.size > 0 && (
        <Flex align="center" gap="2" mb="2">
            <Button size="1" color="red" variant="soft" onClick={() => {
                onBulkDelete(leaves.filter(l => selectedIds.has(l.id)));
                setSelectedIds(new Set());
            }}>
                <TrashIcon /> Delete {selectedIds.size} selected
            </Button>
            <Text size="1" color="gray">{selectedIds.size} of {leaves.length} selected</Text>
        </Flex>
    );

    /* ── mobile card ── */
    const MobileLeaveCard = ({ leave }) => {
        const user = getUserInfo(leave.user_id);
        return (
            <Card mb="2" variant="surface">
                <Flex direction="column" gap="2" p="3">
                    <Flex justify="between" align="start">
                        <Flex align="center" gap="2" style={{ flex: 1 }}>
                            {isAdminView && <UserCell user={user} />}
                        </Flex>
                        <Flex align="center" gap="1">
                            {getStatusBadge(leave.status)}
                            {(canEditLeaves || canDeleteLeaves) && (
                                <DropdownMenu.Root>
                                    <DropdownMenu.Trigger>
                                        <IconButton size="1" variant="ghost"><DotsVerticalIcon /></IconButton>
                                    </DropdownMenu.Trigger>
                                    <DropdownMenu.Content size="1">
                                        {canEditLeaves && (
                                            <DropdownMenu.Item onSelect={() => { setCurrentLeave(leave); openModal('edit_leave'); }}>
                                                <Pencil1Icon /> Edit Leave
                                            </DropdownMenu.Item>
                                        )}
                                        {canEditLeaves && canDeleteLeaves && <DropdownMenu.Separator />}
                                        {canDeleteLeaves && (
                                            <DropdownMenu.Item color="red" onSelect={() => handleClickOpen(leave.id, 'delete_leave')}>
                                                <TrashIcon /> Delete Leave
                                            </DropdownMenu.Item>
                                        )}
                                    </DropdownMenu.Content>
                                </DropdownMenu.Root>
                            )}
                        </Flex>
                    </Flex>

                    <Separator size="4" />

                    <Flex direction="column" gap="1">
                        <Flex align="center" gap="2">
                            <FileTextIcon style={{ width: 14, height: 14, color: 'var(--accent-9)' }} />
                            <Text size="2" weight="medium">{leave.leave_type}</Text>
                        </Flex>
                        <Flex align="center" gap="2" wrap="wrap">
                            <CalendarIcon style={{ width: 14, height: 14, color: 'var(--gray-9)' }} />
                            <Text size="2" color="gray">{formatDate(leave.from_date)} – {formatDate(leave.to_date)}</Text>
                            <Badge size="1" variant="outline">{getLeaveDuration(leave.from_date, leave.to_date)}</Badge>
                        </Flex>
                        {leave.reason && (
                            <Flex align="start" gap="2">
                                <ClockIcon style={{ width: 14, height: 14, marginTop: 2, color: 'var(--gray-9)' }} />
                                <Text size="2" color="gray">{leave.reason}</Text>
                            </Flex>
                        )}
                    </Flex>

                    {isAdminView && canApproveLeaves && (
                        <>
                            <Separator size="4" />
                            <Flex gap="2">
                                {['Approved', 'Declined'].map(status => {
                                    const config = statusConfig[status];
                                    const StatusIcon = config.icon;
                                    const isLoading = updatingLeave === `${leave.id}-${status}`;
                                    return (
                                        <Button
                                            key={status} size="1"
                                            variant={leave.status === status ? 'solid' : 'soft'}
                                            color={config.color}
                                            disabled={isLoading || !!updatingLeave?.startsWith(`${leave.id}-`)}
                                            onClick={() => updateLeaveStatus(leave, status)}
                                            style={{ flex: 1 }}
                                        >
                                            {isLoading ? <Spinner size="1" /> : <StatusIcon style={{ width: 12, height: 12 }} />}
                                            {status}
                                        </Button>
                                    );
                                })}
                            </Flex>
                        </>
                    )}
                </Flex>
            </Card>
        );
    };

    /* ── columns ── */
    const columns = [
        ...(isAdminView ? [{ key: 'select', label: '' }] : []),
        ...(isAdminView ? [{ key: 'employee', label: 'Employee', icon: PersonIcon }] : []),
        { key: 'leave_type', label: 'Leave Type', icon: FileTextIcon },
        { key: 'from_date',  label: 'From Date',  icon: CalendarIcon },
        { key: 'to_date',    label: 'To Date',    icon: CalendarIcon },
        { key: 'status',     label: 'Status',     icon: ClockIcon },
        { key: 'reason',     label: 'Reason',     icon: FileTextIcon },
        ...(isAdminView ? [{ key: 'actions', label: 'Actions' }] : []),
    ];

    /* ── cell renderer ── */
    const renderCellContent = useCallback((leave, key) => {
        const user = getUserInfo(leave.user_id);
        switch (key) {
            case 'select':
                return (
                    <input type="checkbox" checked={selectedIds.has(leave.id)}
                        onChange={() => toggleOne(leave.id)} style={{ cursor: 'pointer' }} />
                );
            case 'employee':
                return <UserCell user={user} />;
            case 'leave_type':
                return (
                    <Flex align="center" gap="1">
                        {getLeaveTypeIcon(leave.leave_type)}
                        <Text size="2" weight="medium" style={{ textTransform: 'capitalize' }}>{leave.leave_type}</Text>
                    </Flex>
                );
            case 'from_date':
            case 'to_date':
                return (
                    <Flex align="center" gap="1">
                        <CalendarIcon style={{ width: 12, height: 12, color: 'var(--gray-9)' }} />
                        <Box>
                            <Text size="2">{formatDate(leave[key])}</Text>
                            {key === 'from_date' && (
                                <Text size="1" color="gray" style={{ display: 'block' }}>
                                    {getLeaveDuration(leave.from_date, leave.to_date)}
                                </Text>
                            )}
                        </Box>
                    </Flex>
                );
            case 'status': {
                const canApproveThisLeave = leave.approval_chain &&
                    leave.status === 'pending' &&
                    leave.approval_chain.some(level =>
                        level.level === leave.current_approval_level &&
                        level.approver_id === auth.user.id &&
                        level.status === 'pending'
                    );
                return (
                    <Flex align="center" gap="1">
                        {getStatusBadge(leave.status)}
                        {canApproveThisLeave ? (
                            <ApprovalActions leave={leave} onApprovalComplete={() => { fetchLeavesStats?.(); router.reload(); }} />
                        ) : isAdminView && canApproveLeaves && (
                            <DropdownMenu.Root>
                                <DropdownMenu.Trigger>
                                    <IconButton size="1" variant="ghost"
                                        disabled={!!updatingLeave?.startsWith(`${leave.id}-`)}>
                                        <DotsVerticalIcon />
                                    </IconButton>
                                </DropdownMenu.Trigger>
                                <DropdownMenu.Content size="1">
                                    {Object.entries(statusConfig).map(([status, config]) => {
                                        const StatusIcon = config.icon;
                                        return (
                                            <DropdownMenu.Item key={status} color={config.color === 'amber' ? undefined : config.color}
                                                onSelect={() => updateLeaveStatus(leave, status)}>
                                                <StatusIcon style={{ width: 14, height: 14 }} />{status}
                                            </DropdownMenu.Item>
                                        );
                                    })}
                                </DropdownMenu.Content>
                            </DropdownMenu.Root>
                        )}
                    </Flex>
                );
            }
            case 'reason':
                return (
                    <Tooltip content={leave.reason || 'No reason provided'}>
                        <Text size="1" color="gray"
                            style={{ maxWidth: 200, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', display: 'block', cursor: 'help' }}>
                            {leave.reason || 'No reason provided'}
                        </Text>
                    </Tooltip>
                );
            case 'actions':
                return (
                    <Flex align="center" gap="1">
                        {canEditLeaves && (
                            <Tooltip content="Edit Leave">
                                <IconButton size="1" variant="ghost"
                                    disabled={!!updatingLeave?.startsWith(`${leave.id}-`)}
                                    onClick={() => { setCurrentLeave(leave); openModal('edit_leave'); }}>
                                    <Pencil1Icon />
                                </IconButton>
                            </Tooltip>
                        )}
                        {canDeleteLeaves && (
                            <Tooltip content="Delete Leave">
                                <IconButton size="1" variant="ghost" color="red"
                                    disabled={!!updatingLeave?.startsWith(`${leave.id}-`)}
                                    onClick={() => { handleClickOpen(leave.id, 'delete_leave'); }}>
                                    <TrashIcon />
                                </IconButton>
                            </Tooltip>
                        )}
                    </Flex>
                );
            default:
                return <Text size="2">{leave[key]}</Text>;
        }
    }, [isAdminView, canApproveLeaves, canEditLeaves, canDeleteLeaves, updatingLeave, selectedIds,
        setCurrentLeave, openModal, handleClickOpen, updateLeaveStatus, auth, fetchLeavesStats, toggleOne]);

    const emptyState = (
        <Flex direction="column" align="center" py="8" gap="2">
            <CalendarIcon style={{ width: 40, height: 40, color: 'var(--gray-9)' }} />
            <Text size="3" weight="medium">No leaves found</Text>
            <Text size="2" color="gray">
                {employee ? `No leaves for "${employee}"` : 'No leave requests for the selected period'}
            </Text>
        </Flex>
    );

    /* ── mobile view ── */
    if (isMobile) {
        return (
            <Box>
                {bulkBar}
                <ScrollArea style={{ maxHeight: '70vh' }}>
                    {leaves.length === 0 ? emptyState : leaves.map(leave => (
                        <MobileLeaveCard key={leave.id} leave={leave} />
                    ))}
                </ScrollArea>
                {totalRows > perPage && (
                    <TablePagination currentPage={currentPage} lastPage={lastPage}
                        totalRows={totalRows} perPage={perPage} onChange={handlePageChange} />
                )}
            </Box>
        );
    }

    /* ── desktop view ── */
    return (
        <Box>
            {bulkBar}
            <ScrollArea style={{ maxHeight: '70vh' }}>
                <Table.Root variant="surface" size="1">
                    <Table.Header>
                        <Table.Row>
                            {columns.map(col => (
                                <Table.ColumnHeaderCell key={col.key}>
                                    {col.key === 'select' ? (
                                        <input type="checkbox" checked={allSelected}
                                            onChange={toggleAll} style={{ cursor: 'pointer' }} />
                                    ) : (
                                        <Flex align="center" gap="1">
                                            {col.icon && React.createElement(col.icon, { style: { width: 12, height: 12 } })}
                                            <Text size="1" weight="medium">{col.label}</Text>
                                        </Flex>
                                    )}
                                </Table.ColumnHeaderCell>
                            ))}
                        </Table.Row>
                    </Table.Header>
                    <Table.Body>
                        {leaves.length === 0 ? (
                            <Table.Row>
                                <Table.Cell colSpan={columns.length}>{emptyState}</Table.Cell>
                            </Table.Row>
                        ) : leaves.map(leave => (
                            <Table.Row key={leave.id}
                                style={{ background: selectedIds.has(leave.id) ? 'var(--accent-a2)' : undefined }}>
                                {columns.map(col => (
                                    <Table.Cell key={col.key}>{renderCellContent(leave, col.key)}</Table.Cell>
                                ))}
                            </Table.Row>
                        ))}
                    </Table.Body>
                </Table.Root>
            </ScrollArea>
            {totalRows > perPage && (
                <TablePagination currentPage={currentPage} lastPage={lastPage}
                    totalRows={totalRows} perPage={perPage} onChange={handlePageChange} />
            )}
        </Box>
    );
});

LeaveEmployeeTable.displayName = 'LeaveEmployeeTable';

export default LeaveEmployeeTable;