/**
 * AdminLeavesPanel.jsx
 * "All Leaves" tab — admin view: full filter set, stats row,
 * paginated table (reuses existing LeaveEmployeeTable + LeaveForm modals).
 * Pure Radix UI shell — no HeroUI, no Tailwind.
 *
 * Fix: BulkDeleteModal prop names corrected:
 *   isOpen       → open
 *   selectedIds  → selectedLeaves  (full leave objects, resolved from leaves state)
 *   allUsers     added (required by BulkDeleteModal for name display)
 *
 * Fix: handleBulkDelete now resolves IDs → full leave objects before storing.
 */
import React, {
    useState, useEffect, useCallback, useMemo, useRef,
} from 'react';
import { usePage } from '@inertiajs/react';
import dayjs from 'dayjs';
import axios from 'axios';
import {
    Badge, Box, Button, Callout, Card, Flex, Grid,
    IconButton, Select, Separator, Spinner, Text, TextField,
} from '@radix-ui/themes';
import {
    CalendarIcon, CheckCircledIcon, ClockIcon, Cross2Icon,
    CrossCircledIcon, DownloadIcon, ExclamationTriangleIcon,
    MagnifyingGlassIcon, MixerHorizontalIcon, PlusIcon,
    ReloadIcon, TableIcon,
} from '@radix-ui/react-icons';
import { showToast } from '@/utils/toastUtils';
import LeaveEmployeeTable from '@/Tables/LeaveEmployeeTable.jsx';
import LeaveForm          from '@/Forms/LeaveForm.jsx';
import DeleteLeaveForm    from '@/Forms/DeleteLeaveForm.jsx';
import BulkLeaveModal     from '@/Components/BulkLeave/BulkLeaveModal.jsx';
import BulkDeleteModal    from '@/Components/BulkDelete/BulkDeleteModal.jsx';

/* ── tiny stat pill ── */
function StatPill({ label, value, color = 'gray' }) {
    return (
        <Badge size="2" variant="soft" color={color} radius="full">
            <Text weight="bold">{value}</Text>
            <Text style={{ opacity: 0.7 }}> {label}</Text>
        </Badge>
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

    /* ── data ── */
    const [leaves,      setLeaves]      = useState([]);
    const [leavesData,  setLeavesData]  = useState({ leaveTypes: [] });
    const [departments, setDepartments] = useState([]);
    const [totalRows,   setTotalRows]   = useState(0);
    const [lastPage,    setLastPage]    = useState(1);
    const [loading,     setLoading]     = useState(false);
    const [error,       setError]       = useState('');
    const [leaveStats,  setLeaveStats]  = useState({
        total: 0, pending: 0, approved: 0, rejected: 0, thisMonth: 0, thisWeek: 0,
    });

    /* ── pagination / filters ── */
    const [pagination, setPagination] = useState({ currentPage: 1, perPage: 30 });
    const [filters, setFilters] = useState({
        employee:      '',
        selectedMonth: dayjs().format('YYYY-MM'),
        status:        [],
        leaveType:     [],
        department:    [],
    });
    const [showFilters, setShowFilters] = useState(false);

    /* ── modal states ── */
    const [modalStates, setModalStates] = useState({
        addEditLeave: false,
        deleteLeave:  false,
        bulkLeave:    false,
        bulkDelete:   false,
    });
    const [currentLeave,          setCurrentLeave]          = useState(null);
    const [selectedForBulkDelete, setSelectedForBulkDelete] = useState([]); // full leave objects
    const leaveTableRef = useRef(null);

    /* ── helpers ── */
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

    /* ── fetch ── */
    const fetchLeaves = useCallback(async () => {
        setLoading(true);
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
            setLeaves([]);
        } finally {
            setLoading(false);
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

    /* ── header actions ── */
    useEffect(() => {
        if (!isActive) return;
        onSetHeaderActions?.(
            <Flex gap="2" wrap="wrap">
                {canCreate && (
                    <Button size="2" onClick={() => { setCurrentLeave(null); openModal('addEditLeave'); }}>
                        <PlusIcon /> {!isMobile && 'Add Leave'}
                    </Button>
                )}
                <Button size="2" variant="soft" color="violet"
                    onClick={() => openModal('bulkLeave')}>
                    {!isMobile && 'Bulk Leave'}
                </Button>
                <IconButton size="2" variant="soft" color="gray"
                    onClick={() => { fetchLeaves(); fetchStats(); }} aria-label="Refresh">
                    <ReloadIcon />
                </IconButton>
            </Flex>
        );
    }, [isActive, isMobile, canCreate]);

    /* ── bulk handlers ── */
    const handleBulkApprove = useCallback(async ids => {
        try {
            await axios.post(route('leaves.bulk-approve'), { leave_ids: ids });
            showToast.success('Leaves approved.');
            fetchLeaves(); fetchStats();
        } catch { showToast.error('Bulk approve failed.'); }
    }, [fetchLeaves, fetchStats]);

    const handleBulkReject = useCallback(async ids => {
        try {
            await axios.post(route('leaves.bulk-reject'), { leave_ids: ids });
            showToast.success('Leaves rejected.');
            fetchLeaves(); fetchStats();
        } catch { showToast.error('Bulk reject failed.'); }
    }, [fetchLeaves, fetchStats]);

    /**
     * LeaveEmployeeTable calls onBulkDelete with an array of IDs.
     * BulkDeleteModal needs full leave objects to display names/dates and
     * check approval status — so we resolve IDs against the current leaves list.
     */
    const handleBulkDelete = useCallback(ids => {
        const fullLeaves = ids
            .map(id => leaves.find(l => String(l.id) === String(id)))
            .filter(Boolean);
        setSelectedForBulkDelete(fullLeaves);
        openModal('bulkDelete');
    }, [openModal, leaves]);

    /* ── leave type options ── */
    const leaveTypeOptions = useMemo(() => {
        const base = [{ value: 'all', label: 'All Types' }];
        return [...base, ...(leavesData.leaveTypes || []).map(t => ({
            value: t.type.toLowerCase(), label: t.type,
        }))];
    }, [leavesData.leaveTypes]);

    const hasActiveFilters = filters.employee ||
        filters.status.length || filters.leaveType.length || filters.department.length;

    const memoizedLeaves = useMemo(() => leaves, [leaves]);

    /* ── render ── */
    return (
        <Box>
            {/* ── Stats row ── */}
            <Flex wrap="wrap" gap="2" mb="4">
                <StatPill label="Total"      value={leaveStats.total}     color="blue"   />
                <StatPill label="Pending"    value={leaveStats.pending}   color="amber"  />
                <StatPill label="Approved"   value={leaveStats.approved}  color="green"  />
                <StatPill label="Rejected"   value={leaveStats.rejected}  color="red"    />
                <StatPill label="This Month" value={leaveStats.thisMonth} color="violet" />
                <StatPill label="This Week"  value={leaveStats.thisWeek}  color="cyan"   />
            </Flex>

            {/* ── Toolbar ── */}
            <Flex
                direction={{ initial: 'column', sm: 'row' }}
                gap="3" align={{ initial: 'stretch', sm: 'center' }} mb="3"
            >
                <Box style={{ flex: 1, minWidth: 200 }}>
                    <TextField.Root
                        placeholder="Search by employee name…"
                        size="2"
                        onChange={e => handleFilterChange('employee', e.target.value.toLowerCase())}
                    >
                        <TextField.Slot><MagnifyingGlassIcon /></TextField.Slot>
                    </TextField.Root>
                </Box>

                <TextField.Root
                    type="month"
                    size="2"
                    value={filters.selectedMonth}
                    onChange={e => handleFilterChange('selectedMonth', e.target.value)}
                    style={{ maxWidth: 180 }}
                />

                <Button
                    size="2"
                    variant={showFilters ? 'solid' : 'surface'}
                    color={showFilters ? 'indigo' : 'gray'}
                    onClick={() => setShowFilters(v => !v)}
                >
                    <MixerHorizontalIcon /> {!isMobile && 'Filters'}
                </Button>
            </Flex>

            {/* ── Advanced Filter Panel ── */}
            {showFilters && (
                <Card size="2" variant="surface" mb="3">
                    <Grid columns={{ initial: '1', sm: '2', lg: '4' }} gap="4" align="end">
                        <Box>
                            <Text size="2" color="gray" weight="medium" as="div" mb="1">Status</Text>
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
                            <Text size="2" color="gray" weight="medium" as="div" mb="1">Leave Type</Text>
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
                            <Text size="2" color="gray" weight="medium" as="div" mb="1">Department</Text>
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

                        <Flex align="end">
                            <Button size="2" variant="soft" color="red"
                                disabled={!hasActiveFilters}
                                onClick={() => setFilters({
                                    employee: '', selectedMonth: dayjs().format('YYYY-MM'),
                                    status: [], leaveType: [], department: [],
                                })}
                                style={{ width: '100%' }}>
                                <Cross2Icon /> Clear
                            </Button>
                        </Flex>
                    </Grid>

                    {hasActiveFilters && (
                        <Flex gap="2" wrap="wrap" mt="3" pt="3" style={{ borderTop: '1px solid var(--gray-a4)' }}>
                            {filters.employee && (
                                <Badge color="blue" style={{ cursor: 'pointer' }}
                                    onClick={() => handleFilterChange('employee', '')}>
                                    Employee: {filters.employee} <Cross2Icon style={{ marginLeft: 4 }} />
                                </Badge>
                            )}
                            {filters.status.map(s => (
                                <Badge key={s} color="violet" style={{ cursor: 'pointer' }}
                                    onClick={() => handleFilterChange('status', filters.status.filter(x => x !== s))}>
                                    Status: {s} <Cross2Icon style={{ marginLeft: 4 }} />
                                </Badge>
                            ))}
                            {filters.leaveType.map(t => (
                                <Badge key={t} color="amber" style={{ cursor: 'pointer' }}
                                    onClick={() => handleFilterChange('leaveType', filters.leaveType.filter(x => x !== t))}>
                                    Type: {t} <Cross2Icon style={{ marginLeft: 4 }} />
                                </Badge>
                            ))}
                            {filters.department.map(dId => {
                                const dept = departments.find(d => String(d.id) === String(dId));
                                return (
                                    <Badge key={dId} color="green" style={{ cursor: 'pointer' }}
                                        onClick={() => handleFilterChange('department', filters.department.filter(x => x !== dId))}>
                                        Dept: {dept?.name || dId} <Cross2Icon style={{ marginLeft: 4 }} />
                                    </Badge>
                                );
                            })}
                        </Flex>
                    )}
                </Card>
            )}

            {/* ── Section header ── */}
            <Flex align="center" justify="between" mb="3">
                <Flex align="center" gap="2">
                    <TableIcon style={{ width: 16, height: 16 }} />
                    <Text size="3" weight="medium">Leave Requests</Text>
                    {!loading && <Badge size="1" variant="soft" color="gray" radius="full">{totalRows}</Badge>}
                </Flex>
            </Flex>

            {/* ── Content ── */}
            {loading ? (
                <Flex direction="column" align="center" py="9" gap="3">
                    <Spinner size="3" />
                    <Text color="gray" size="2">Loading leave data…</Text>
                </Flex>
            ) : error ? (
                <Callout.Root color="orange">
                    <Callout.Icon><ExclamationTriangleIcon /></Callout.Icon>
                    <Callout.Text>{error}</Callout.Text>
                </Callout.Root>
            ) : memoizedLeaves?.length > 0 ? (
                <LeaveEmployeeTable
                    ref={leaveTableRef}
                    leaves={memoizedLeaves}
                    allUsers={allUsers}
                    totalRows={totalRows}
                    lastPage={lastPage}
                    currentPage={pagination.currentPage}
                    perPage={pagination.perPage}
                    setCurrentPage={page => setPagination(p => ({ ...p, currentPage: page }))}
                    setPerPage={n => setPagination({ currentPage: 1, perPage: n })}
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
                    fetchLeavesStats={fetchStats}
                />
            ) : (
                <Flex direction="column" align="center" py="9" gap="2">
                    <CalendarIcon style={{ width: 40, height: 40, color: 'var(--gray-9)' }} />
                    <Text size="3" weight="medium">No Leave Records Found</Text>
                    <Text size="2" color="gray">Try adjusting your filters or month selection.</Text>
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
                />
            )}

            {modalStates.deleteLeave && (
                <DeleteLeaveForm
                    open={modalStates.deleteLeave}
                    handleClose={() => closeModal('deleteLeave')}
                    leave={currentLeave}
                    setLeaves={setLeaves}
                    fetchLeavesStats={fetchStats}
                />
            )}

            {modalStates.bulkLeave && (
                <BulkLeaveModal
                    isOpen={modalStates.bulkLeave}
                    onClose={() => closeModal('bulkLeave')}
                    allUsers={allUsers}
                    onSuccess={() => { fetchLeaves(); fetchStats(); }}
                />
            )}

            {/* ── BulkDeleteModal: corrected prop names ── */}
            {modalStates.bulkDelete && (
                <BulkDeleteModal
                    open={modalStates.bulkDelete}
                    onClose={() => closeModal('bulkDelete')}
                    selectedLeaves={selectedForBulkDelete}
                    allUsers={allUsers}
                    onSuccess={() => { fetchLeaves(); fetchStats(); closeModal('bulkDelete'); }}
                />
            )}
        </Box>
    );
}
