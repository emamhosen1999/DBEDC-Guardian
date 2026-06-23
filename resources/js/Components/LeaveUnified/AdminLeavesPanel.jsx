/**
 * AdminLeavesPanel.jsx
 * "All Leaves" tab — admin view.
 * * UX Improvements added:
 * - Optimistic CRUD: Instant UI updates for approve/reject actions with automatic rollback on API failure.
 * - Skeletons: Layout-preserving loading states instead of layout-shifting spinners.
 * - Responsive Filters: Grid layouts that gracefully collapse to single columns on mobile.
 */
import React, { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import { usePage } from '@inertiajs/react';
import dayjs from 'dayjs';
import axios from 'axios';
import {
    Badge, Box, Button, Callout, Card, Flex, Grid,
    IconButton, Select, Separator, Spinner, Text, TextField,
    Skeleton, ScrollArea
} from '@radix-ui/themes';
import {
    CalendarIcon, CheckCircledIcon, ClockIcon, Cross2Icon,
    CrossCircledIcon, ExclamationTriangleIcon,
    MagnifyingGlassIcon, MixerHorizontalIcon, PlusIcon,
    ReloadIcon, TableIcon, LayersIcon
} from '@radix-ui/react-icons';
import { showToast } from '@/utils/toastUtils';
import LeaveEmployeeTable from '@/Tables/LeaveEmployeeTable.jsx';
import LeaveForm          from '@/Forms/LeaveForm.jsx';
import DeleteLeaveForm    from '@/Forms/DeleteLeaveForm.jsx';
import BulkLeaveModal           from '@/Components/BulkLeave/BulkLeaveModal.jsx';
import BulkDeleteModal          from '@/Components/BulkDelete/BulkDeleteModal.jsx';
import BulkStatusUpdateModal    from '@/Components/LeaveUnified/BulkStatusUpdateModal.jsx';

/* ── Responsive Stat Pill ── */
function StatPill({ label, value, color = 'gray', icon: Icon, loading = false }) {
    return (
        <Card size="1" style={{ minWidth: '130px', flex: '1 1 auto' }}>
            <Flex align="center" gap="3" p="1">
                <Box p="2" style={{ backgroundColor: `var(--${color}-a3)`, borderRadius: 'var(--radius-2)' }}>
                    {Icon ? <Icon style={{ color: `var(--${color}-9)` }} /> : <LayersIcon style={{ color: `var(--${color}-9)` }} />}
                </Box>
                <Box>
                    <Skeleton loading={loading}>
                        <Text size="4" weight="bold" style={{ display: 'block', lineHeight: 1 }}>{value}</Text>
                    </Skeleton>
                    <Text size="1" color="gray">{label}</Text>
                </Box>
            </Flex>
        </Card>
    );
}

export default function AdminLeavesPanel({
    allUsers = [], isMobile, isActive, onCountChange, onSetHeaderActions,
}) {
    const { auth } = usePage().props;

    const canApprove = auth.permissions?.includes('leaves.approve') || false;
    const canCreate  = auth.permissions?.includes('leaves.create')  || false;
    const canEdit    = auth.permissions?.includes('leaves.update')  || false;
    const canDelete  = auth.permissions?.includes('leaves.delete')  || false;

    /* ── State ── */
    const [leaves,      setLeaves]      = useState([]);
    const [leavesData,  setLeavesData]  = useState({ leaveTypes: [] });
    const [departments, setDepartments] = useState([]);
    const [totalRows,   setTotalRows]   = useState(0);
    const [lastPage,    setLastPage]    = useState(1);
    const [loading,     setLoading]     = useState(true);
    const [error,       setError]       = useState('');
    const [leaveStats,  setLeaveStats]  = useState({
        total: 0, pending: 0, approved: 0, rejected: 0, thisMonth: 0, thisWeek: 0,
    });

    /* ── Pagination / Filters ── */
    const [pagination, setPagination] = useState({ currentPage: 1, perPage: 30 });
    const [filters, setFilters] = useState({
        employee:      '',
        selectedMonth: dayjs().format('YYYY-MM'),
        status:        [],
        leaveType:     [],
        department:    [],
    });
    const [showFilters, setShowFilters] = useState(false);

    /* ── Modal States ── */
    const [modalStates, setModalStates] = useState({
        addEditLeave:      false,
        deleteLeave:       false,
        bulkLeave:         false,
        bulkDelete:        false,
        bulkStatusUpdate:  false,
    });
    const [currentLeave,            setCurrentLeave]            = useState(null);
    const [selectedForBulkDelete,   setSelectedForBulkDelete]   = useState([]);
    const [selectedForBulkStatus,   setSelectedForBulkStatus]   = useState([]);
    const [bulkStatusTarget,        setBulkStatusTarget]        = useState('approved');
    const leaveTableRef = useRef(null);

    /* ── Helpers ── */
    const openModal  = useCallback(type => setModalStates(p => ({ ...p, [type]: true  })), []);
    const closeModal = useCallback(type => setModalStates(p => ({ ...p, [type]: false })), []);

    const handleClickOpen = useCallback((leaveId, modalType) => {
        setCurrentLeave({ id: leaveId });
        openModal(modalType);
    }, [openModal]);

    const handleFilterChange = useCallback((key, val) => {
        setFilters(p => ({ ...p, [key]: val }));
        setPagination(p => ({ ...p, currentPage: 1 }));
    }, []);

    /* ── Fetching Logic ── */
    const fetchLeaves = useCallback(async (isSilent = false) => {
        if (!isSilent) setLoading(true);
        try {
            const { data } = await axios.get(route('leaves.paginate'), {
                params: {
                    page:       pagination.currentPage,
                    perPage:    pagination.perPage,
                    employee:   filters.employee || undefined,
                    month:      filters.selectedMonth,
                    status:     filters.status.length     ? filters.status     : undefined,
                    leave_type: filters.leaveType.length  ? filters.leaveType  : undefined,
                    department: filters.department.length ? filters.department : undefined,
                    admin_view: true,
                    view_all:   true,
                },
            });
            const { leaves: lv, leavesData: ld, departments: depts } = data;
            const list = lv?.data ?? lv ?? [];
            setLeaves(list);
            setTotalRows(lv?.total ?? list.length);
            setLastPage(lv?.last_page ?? 1);
            setLeavesData(ld || { leaveTypes: [] });
            setDepartments(depts || []);
            onCountChange?.(lv?.total ?? list.length);
            setError('');
        } catch (e) {
            setError(e.response?.data?.message || 'Failed to load leaves.');
            if (!isSilent) setLeaves([]);
        } finally {
            if (!isSilent) setLoading(false);
        }
    }, [pagination, filters, onCountChange]);

    const fetchStats = useCallback(async () => {
        try {
            const { data } = await axios.get(route('leaves.stats'), {
                params: { month: filters.selectedMonth, admin_view: true, view_all: true },
            });
            if (data.stats) setLeaveStats(data.stats);
        } catch { /* silent */ }
    }, [filters.selectedMonth]);

    useEffect(() => {
        if (isActive) { fetchLeaves(); fetchStats(); }
    }, [fetchLeaves, fetchStats, isActive]);

    /* ── Header Actions ── */
    useEffect(() => {
        if (!isActive) return;
        onSetHeaderActions?.(
            <Flex gap="2" wrap="wrap" style={{ width: '100%', justifyContent: isMobile ? 'space-between' : 'flex-end' }}>
                <Flex gap="2">
                    {canCreate && (
                        <Button size="2" color="indigo" onClick={() => { setCurrentLeave(null); openModal('addEditLeave'); }}>
                            <PlusIcon /> {!isMobile && 'Add Leave'}
                        </Button>
                    )}
                    <Button size="2" variant="soft" color="violet" onClick={() => openModal('bulkLeave')}>
                        <LayersIcon /> {!isMobile && 'Bulk Leave'}
                    </Button>
                </Flex>
                <IconButton size="2" variant="soft" color="gray" onClick={() => { fetchLeaves(); fetchStats(); }} aria-label="Refresh">
                    <ReloadIcon />
                </IconButton>
            </Flex>
        );
    }, [isActive, isMobile, canCreate, fetchLeaves, fetchStats]);

    /* ── Optimistic Bulk Handlers ── */
    const handleBulkApprove = useCallback(async ids => {
        // Optimistic UI Update
        const previousLeaves = [...leaves];
        setLeaves(prev => prev.map(l => ids.includes(l.id) ? { ...l, status: 'Approved' } : l));
        
        try {
            await axios.post(route('leaves.bulk-approve'), { leave_ids: ids });
            showToast.success(`${ids.length} leave(s) approved.`);
            fetchStats(); // Update stats in background
        } catch {
            showToast.error('Bulk approve failed. Rolling back.');
            setLeaves(previousLeaves); // Rollback
        }
    }, [leaves, fetchStats]);

    const handleBulkReject = useCallback(async ids => {
        // Optimistic UI Update
        const previousLeaves = [...leaves];
        setLeaves(prev => prev.map(l => ids.includes(l.id) ? { ...l, status: 'Declined' } : l));
        
        try {
            await axios.post(route('leaves.bulk-reject'), { leave_ids: ids });
            showToast.success(`${ids.length} leave(s) rejected.`);
            fetchStats(); // Update stats in background
        } catch {
            showToast.error('Bulk reject failed. Rolling back.');
            setLeaves(previousLeaves); // Rollback
        }
    }, [leaves, fetchStats]);

    const handleBulkDelete = useCallback(fullLeaves => {
        setSelectedForBulkDelete(fullLeaves);
        openModal('bulkDelete');
    }, [openModal]);

    const handleBulkStatusUpdate = useCallback((fullLeaves, targetStatus) => {
        setSelectedForBulkStatus(fullLeaves);
        setBulkStatusTarget(targetStatus);
        openModal('bulkStatusUpdate');
    }, [openModal]);

    /* ── Leave Type Options ── */
    const leaveTypeOptions = useMemo(() => {
        const base = [{ value: 'all', label: 'All Types' }];
        return [...base, ...(leavesData.leaveTypes || []).map(t => ({
            value: t.type.toLowerCase(), label: t.type,
        }))];
    }, [leavesData.leaveTypes]);

    const hasActiveFilters = !!(filters.employee ||
        filters.status.length || filters.leaveType.length || filters.department.length);

    /* ── Render ── */
    return (
        <Box>
            {/* ── Stats Row ── */}
            <ScrollArea type="auto" scrollbars="horizontal" style={{ width: '100%', marginBottom: '16px' }}>
                <Flex gap="3" style={{ minWidth: '100%', paddingBottom: '4px' }}>
                    <StatPill label="Total"      value={leaveStats.total}     color="blue"   icon={TableIcon} loading={loading} />
                    <StatPill label="Pending"    value={leaveStats.pending}   color="amber"  icon={ClockIcon} loading={loading} />
                    <StatPill label="Approved"   value={leaveStats.approved}  color="green"  icon={CheckCircledIcon} loading={loading} />
                    <StatPill label="Rejected"   value={leaveStats.rejected}  color="red"    icon={CrossCircledIcon} loading={loading} />
                    <StatPill label="This Month" value={leaveStats.thisMonth} color="violet" icon={CalendarIcon} loading={loading} />
                </Flex>
            </ScrollArea>

            {/* ── Toolbar ── */}
            <Flex
                direction={{ initial: 'column', sm: 'row' }}
                gap="3" align={{ initial: 'stretch', sm: 'center' }} mb="4"
            >
                <Box style={{ flex: 1 }}>
                    <TextField.Root
                        placeholder="Search by employee name…"
                        size="2"
                        value={filters.employee}
                        onChange={e => handleFilterChange('employee', e.target.value)}
                    >
                        <TextField.Slot><MagnifyingGlassIcon /></TextField.Slot>
                        {filters.employee && (
                            <TextField.Slot side="right">
                                <IconButton size="1" variant="ghost" onClick={() => handleFilterChange('employee', '')}>
                                    <Cross2Icon />
                                </IconButton>
                            </TextField.Slot>
                        )}
                    </TextField.Root>
                </Box>

                <Flex gap="3">
                    <TextField.Root
                        type="month"
                        size="2"
                        value={filters.selectedMonth}
                        onChange={e => handleFilterChange('selectedMonth', e.target.value)}
                        style={{ flex: isMobile ? 1 : '0 0 160px' }}
                    />

                    <Button
                        size="2"
                        variant={showFilters ? 'solid' : 'surface'}
                        color={showFilters ? 'indigo' : 'gray'}
                        onClick={() => setShowFilters(v => !v)}
                        style={{ flexShrink: 0 }}
                    >
                        <MixerHorizontalIcon /> {!isMobile && 'Filters'}
                        {hasActiveFilters && !showFilters && (
                            <Box style={{ width: 8, height: 8, borderRadius: '50%', backgroundColor: 'var(--accent-9)', marginLeft: 4 }} />
                        )}
                    </Button>
                </Flex>
            </Flex>

            {/* ── Advanced Filter Panel ── */}
            {showFilters && (
                <Card size="2" variant="surface" mb="4">
                    <Grid columns={{ initial: '1', sm: '2', lg: '4' }} gap="4">
                        <Box>
                            <Text size="2" color="gray" weight="medium" as="div" mb="2">Status</Text>
                            <Select.Root size="2"
                                value={filters.status[0] || 'all'}
                                onValueChange={v => handleFilterChange('status', v === 'all' ? [] : [v])}>
                                <Select.Trigger style={{ width: '100%' }} />
                                <Select.Content>
                                    <Select.Item value="all">All Status</Select.Item>
                                    <Select.Item value="pending">Pending</Select.Item>
                                    <Select.Item value="approved">Approved</Select.Item>
                                    <Select.Item value="rejected">Rejected</Select.Item>
                                </Select.Content>
                            </Select.Root>
                        </Box>

                        <Box>
                            <Text size="2" color="gray" weight="medium" as="div" mb="2">Leave Type</Text>
                            <Select.Root size="2"
                                value={filters.leaveType[0] || 'all'}
                                onValueChange={v => handleFilterChange('leaveType', v === 'all' ? [] : [v])}>
                                <Select.Trigger style={{ width: '100%' }} />
                                <Select.Content>
                                    {leaveTypeOptions.map(o => (
                                        <Select.Item key={o.value} value={o.value}>{o.label}</Select.Item>
                                    ))}
                                </Select.Content>
                            </Select.Root>
                        </Box>

                        <Box>
                            <Text size="2" color="gray" weight="medium" as="div" mb="2">Department</Text>
                            <Select.Root size="2"
                                value={filters.department[0] || 'all'}
                                onValueChange={v => handleFilterChange('department', v === 'all' ? [] : [v])}>
                                <Select.Trigger style={{ width: '100%' }} />
                                <Select.Content>
                                    <Select.Item value="all">All Departments</Select.Item>
                                    {departments.map(d => (
                                        <Select.Item key={d.id} value={String(d.id)}>{d.name}</Select.Item>
                                    ))}
                                </Select.Content>
                            </Select.Root>
                        </Box>

                        <Flex align="end" style={{ height: '100%' }}>
                            <Button size="2" variant="soft" color="red"
                                disabled={!hasActiveFilters}
                                onClick={() => setFilters({
                                    employee: '', selectedMonth: dayjs().format('YYYY-MM'),
                                    status: [], leaveType: [], department: [],
                                })}
                                style={{ width: '100%' }}>
                                <Cross2Icon /> Clear Filters
                            </Button>
                        </Flex>
                    </Grid>

                    {/* Filter Tags */}
                    {hasActiveFilters && (
                        <Flex gap="2" wrap="wrap" mt="4" pt="3" style={{ borderTop: '1px solid var(--gray-a4)' }}>
                            {filters.status.map(s => (
                                <Badge key={s} color="violet" style={{ cursor: 'pointer' }} onClick={() => handleFilterChange('status', filters.status.filter(x => x !== s))}>
                                    Status: {s} <Cross2Icon style={{ marginLeft: 4 }} />
                                </Badge>
                            ))}
                            {filters.leaveType.map(t => (
                                <Badge key={t} color="amber" style={{ cursor: 'pointer' }} onClick={() => handleFilterChange('leaveType', filters.leaveType.filter(x => x !== t))}>
                                    Type: {t} <Cross2Icon style={{ marginLeft: 4 }} />
                                </Badge>
                            ))}
                            {filters.department.map(dId => {
                                const dept = departments.find(d => String(d.id) === String(dId));
                                return (
                                    <Badge key={dId} color="green" style={{ cursor: 'pointer' }} onClick={() => handleFilterChange('department', filters.department.filter(x => x !== dId))}>
                                        Dept: {dept?.name || dId} <Cross2Icon style={{ marginLeft: 4 }} />
                                    </Badge>
                                );
                            })}
                        </Flex>
                    )}
                </Card>
            )}

            {/* ── Content ── */}
            {loading ? (
                // SKELETON LOADING STATE
                <Box>
                    <Skeleton height="40px" mb="2" />
                    <Skeleton height="60px" mb="2" />
                    <Skeleton height="60px" mb="2" />
                    <Skeleton height="60px" mb="2" />
                    <Skeleton height="60px" mb="2" />
                </Box>
            ) : error ? (
                <Callout.Root color="orange">
                    <Callout.Icon><ExclamationTriangleIcon /></Callout.Icon>
                    <Callout.Text>{error}</Callout.Text>
                </Callout.Root>
            ) : leaves?.length > 0 ? (
                <LeaveEmployeeTable
                    ref={leaveTableRef}
                    leaves={leaves}
                    allUsers={allUsers}
                    totalRows={totalRows}
                    lastPage={lastPage}
                    currentPage={pagination.currentPage}
                    perPage={pagination.perPage}
                    setCurrentPage={page => setPagination(p => ({ ...p, currentPage: page }))}
                    onRowsPerPageChange={n => setPagination({ currentPage: 1, perPage: n })}
                    handleClickOpen={handleClickOpen}
                    setCurrentLeave={setCurrentLeave}
                    openModal={openModal}
                    setLeaves={setLeaves}
                    employee={filters.employee}
                    selectedMonth={filters.selectedMonth}
                    isAdminView={true}
                    canApproveLeaves={canApprove}
                    canEditLeaves={canEdit}
                    canDeleteLeaves={canDelete}
                    onBulkApprove={handleBulkApprove}
                    onBulkReject={handleBulkReject}
                    onBulkDelete={handleBulkDelete}
                    onBulkStatusUpdate={handleBulkStatusUpdate}
                    fetchLeavesStats={() => fetchStats()}
                    onLeaveUpdated={() => fetchLeaves(true)}
                />
            ) : (
                <Flex direction="column" align="center" py="9" gap="3" style={{ border: '1px dashed var(--gray-a6)', borderRadius: 'var(--radius-3)' }}>
                    <CalendarIcon style={{ width: 48, height: 48, color: 'var(--gray-8)' }} />
                    <Text size="4" weight="bold">No Leave Records Found</Text>
                    <Text size="2" color="gray">Try adjusting your filters or search terms.</Text>
                </Flex>
            )}

            {/* ── Modals ── */}
            {modalStates.addEditLeave && (
                <LeaveForm
                    open={modalStates.addEditLeave}
                    closeModal={() => closeModal('addEditLeave')}
                    leavesData={leavesData}
                    setLeavesData={setLeavesData}
                    currentLeave={currentLeave}
                    allUsers={allUsers}
                    departments={departments}
                    setLeaves={setLeaves}
                    setTotalRows={setTotalRows}
                    setLastPage={setLastPage}
                    selectedMonth={filters.selectedMonth}
                    fetchLeavesStats={fetchStats}
                    addLeaveOptimized={leave => {
                        setLeaves(prev => [leave, ...prev]);
                        setTotalRows(n => n + 1);
                    }}
                    updateLeaveOptimized={leave => {
                        setLeaves(prev => prev.map(l => l.id === leave.id ? leave : l));
                    }}
                />
            )}

            {modalStates.deleteLeave && (
                <DeleteLeaveForm
                    open={modalStates.deleteLeave}
                    closeModal={() => closeModal('deleteLeave')}
                    leaveId={currentLeave?.id}
                    setLeaves={setLeaves}
                    setTotalRows={setTotalRows}
                    fetchLeavesStats={fetchStats}
                    deleteLeaveOptimized={id => setLeaves(prev => prev.filter(l => l.id !== id))}
                />
            )}

            {modalStates.bulkLeave && (
                <BulkLeaveModal
                    open={modalStates.bulkLeave}
                    onClose={() => closeModal('bulkLeave')}
                    allUsers={allUsers}
                    departments={departments}
                    onSuccess={() => { fetchLeaves(true); fetchStats(); }}
                    isAdmin={true}
                />
            )}

            {modalStates.bulkDelete && (
                <BulkDeleteModal
                    open={modalStates.bulkDelete}
                    onClose={() => closeModal('bulkDelete')}
                    selectedLeaves={selectedForBulkDelete}
                    allUsers={allUsers}
                    onSuccess={() => { 
                        // Optimistically remove deleted items
                        const deletedIds = selectedForBulkDelete.map(l => l.id);
                        setLeaves(prev => prev.filter(l => !deletedIds.includes(l.id)));
                        fetchStats(); 
                        closeModal('bulkDelete'); 
                    }}
                />
            )}

            {modalStates.bulkStatusUpdate && (
                <BulkStatusUpdateModal
                    open={modalStates.bulkStatusUpdate}
                    onClose={() => closeModal('bulkStatusUpdate')}
                    selectedLeaves={selectedForBulkStatus}
                    targetStatus={bulkStatusTarget}
                    onSuccess={(targetStatus) => {
                        // Optimistic UI: map the API status to display status
                        const displayStatus = targetStatus === 'approved' ? 'Approved'
                            : targetStatus === 'declined' ? 'Declined'
                            : targetStatus === 'pending' ? 'Pending' : 'New';
                        const ids = selectedForBulkStatus.map(l => l.id);
                        setLeaves(prev => prev.map(l =>
                            ids.includes(l.id) ? { ...l, status: displayStatus } : l
                        ));
                        fetchStats();
                    }}
                />
            )}
        </Box>
    );
}