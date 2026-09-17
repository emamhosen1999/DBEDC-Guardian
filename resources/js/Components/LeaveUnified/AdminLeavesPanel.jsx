import { Panel } from '@/Components/ui/Panel';
/**
 * AdminLeavesPanel.jsx
 * "All Leaves" tab — admin view.
 * * UX Improvements added:
 * - Optimistic CRUD: Instant UI updates for approve/reject actions with automatic rollback on API failure.
 * - Skeletons: Layout-preserving loading states instead of layout-shifting spinners.
 * - Responsive Filters: Grid layouts that gracefully collapse to single columns on mobile.
 */
import React, { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import { useQueryFilters } from '@/Hooks/useQueryFilters';
import { usePersistentPageState } from '@/Hooks/usePersistentPageState';
import { usePage } from '@inertiajs/react';
import dayjs from 'dayjs';
import axios from 'axios';
import { Badge, Box, Button, Callout, Flex, Grid, IconButton, Select, Separator, Spinner, Text, TextField, Skeleton, ScrollArea } from '@radix-ui/themes';
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
import StatsCards from '@/Components/StatsCards';
import SearchFilterBar from '@/Components/SearchFilterBar';
import PageToolbar from '@/Components/PageToolbar';
import { useRealtimeSignals } from '@/api/useRealtimeSignals';


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
    /* Every filter and the page live in the URL under an `al_` prefix, so the
       leave list comes back as it was left and a link can be shared. The fetch
       effect follows the URL. Whether the filter panel is open is remembered. */
    const f = useQueryFilters({
        mode: 'client',
        pageKey: 'al_page',
        debounceKeys: ['al_q'],
        defaults: {
            al_q: '',
            al_month: dayjs().format('YYYY-MM'),
            al_status: [],
            al_type: [],
            al_dept: [],
            al_page: 1,
            al_per: 30,
        },
    });
    const filters = useMemo(() => ({
        employee: f.values.al_q,
        selectedMonth: f.values.al_month,
        status: f.values.al_status,
        leaveType: f.values.al_type,
        department: f.values.al_dept,
    }), [f.values.al_q, f.values.al_month, f.values.al_status, f.values.al_type, f.values.al_dept]);
    const pagination = useMemo(
        () => ({ currentPage: f.values.al_page, perPage: f.values.al_per }),
        [f.values.al_page, f.values.al_per],
    );
    const FILTER_KEYS = { employee: 'al_q', selectedMonth: 'al_month', status: 'al_status', leaveType: 'al_type', department: 'al_dept' };

    const [ui, setUi] = usePersistentPageState('Leaves/AdminPanel', { showFilters: false });
    const showFilters = ui.showFilters;
    const setShowFilters = (u) => setUi((prev) => ({ showFilters: typeof u === 'function' ? u(prev.showFilters) : u }));

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

    // The employee search goes through the debounced draft; everything else
    // commits at once. The hook returns to page 1 on any filter change.
    const { set: setFilter, setDraft, setMany: setFilterValues } = f;
    const handleFilterChange = useCallback((key, val) => {
        if (key === 'employee') setDraft('al_q', val);
        else setFilter(FILTER_KEYS[key], val);
    }, [setFilter, setDraft]); // eslint-disable-line react-hooks/exhaustive-deps

    const toggleStatusFilter = useCallback((statusVal) => {
        const current = f.values.al_status[0];
        setFilter('al_status', current === statusVal ? [] : [statusVal]);
    }, [setFilter, f.values.al_status]);

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

    // Live updates: when ANOTHER user approves/rejects/changes a leave, refetch this
    // list + stats silently (~1s). The actor's own change is filtered out.
    useRealtimeSignals({
        path: isActive ? 'leave/all' : null,
        selfActorId: auth?.user?.id ?? null,
        onSignal: () => { fetchLeaves(true); fetchStats(); },
    });

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

    const statCards = [
        { key: 'total', title: 'Total', value: leaveStats.total, color: 'blue', icon: TableIcon, isLoading: loading, active: filters.status.length === 0, onClick: () => setFilter('al_status', []) },
        { key: 'pending', title: 'Pending', value: leaveStats.pending, color: 'amber', icon: ClockIcon, isLoading: loading, active: filters.status.includes('pending'), onClick: () => toggleStatusFilter('pending') },
        { key: 'approved', title: 'Approved', value: leaveStats.approved, color: 'green', icon: CheckCircledIcon, isLoading: loading, active: filters.status.includes('approved'), onClick: () => toggleStatusFilter('approved') },
        { key: 'rejected', title: 'Rejected', value: leaveStats.rejected, color: 'red', icon: CrossCircledIcon, isLoading: loading, active: filters.status.includes('rejected'), onClick: () => toggleStatusFilter('rejected') },
        { key: 'this_month', title: 'This Month', value: leaveStats.thisMonth, color: 'violet', icon: CalendarIcon, isLoading: loading },
    ];

    const activeFilterChips = useMemo(() => {
        const chips = [];
        if (filters.employee) chips.push({ label: 'Employee', value: filters.employee, onRemove: () => handleFilterChange('employee', '') });
        filters.status.forEach(s => {
            chips.push({ key: `status-${s}`, label: 'Status', value: s, onRemove: () => handleFilterChange('status', filters.status.filter(x => x !== s)) });
        });
        filters.leaveType.forEach(t => {
            chips.push({ key: `type-${t}`, label: 'Type', value: t, onRemove: () => handleFilterChange('leaveType', filters.leaveType.filter(x => x !== t)) });
        });
        filters.department.forEach(dId => {
            const dept = departments.find(d => String(d.id) === String(dId));
            chips.push({ key: `dept-${dId}`, label: 'Dept', value: dept?.name || dId, onRemove: () => handleFilterChange('department', filters.department.filter(x => x !== dId)) });
        });
        return chips;
    }, [filters, departments]);

    /* ── Render ── */
    return (
        <Box>
            {/* ── Stats Row ── */}
            <StatsCards stats={statCards} columns={{ initial: '1', sm: '2', md: '5' }} mb="4" />

            {/* ── Toolbar & Filter Bar ── */}
            <PageToolbar
                leftSlot={
                    <SearchFilterBar
                        searchValue={f.draft.al_q}
                        onSearchChange={val => handleFilterChange('employee', val)}
                        searchPlaceholder="Search by employee name..."
                        showFilterToggle
                        showFilters={showFilters}
                        onToggleFilters={() => setShowFilters(v => !v)}
                        activeFiltersCount={activeFilterChips.length}
                        activeFilterChips={activeFilterChips}
                        onClearFilters={hasActiveFilters ? f.reset : null}
                        mb="0"
                        extraActions={
                            <TextField.Root
                                type="month"
                                size="2"
                                value={filters.selectedMonth}
                                onChange={e => handleFilterChange('selectedMonth', e.target.value)}
                                style={{ flex: isMobile ? 1 : '0 0 160px', borderRadius: 10 }}
                            />
                        }
                    >
                        <Grid columns={{ initial: '1', sm: '2', lg: '3' }} gap="4">
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
                        </Grid>
                    </SearchFilterBar>
                }
            />

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
                    setCurrentPage={f.setPage}
                    onRowsPerPageChange={n => setFilter('al_per', n)}
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