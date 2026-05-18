/**
 * MyLeavesPanel.jsx
 * "My Leaves" tab — employee view of their own leave requests.
 * Pure Radix UI shell.
 */
import React, {
    useState, useEffect, useCallback, useMemo,
} from 'react';
import { usePage } from '@inertiajs/react';
import dayjs from 'dayjs';
import axios from 'axios';
import {
    Badge, Box, Button, Callout, Card, Flex,
    IconButton, Separator, Spinner, Text, TextField,
} from '@radix-ui/themes';
import {
    CalendarIcon, CheckCircledIcon, ClockIcon, CrossCircledIcon,
    ExclamationTriangleIcon, PlusIcon, ReloadIcon, TableIcon,
} from '@radix-ui/react-icons';
import { showToast } from '@/utils/toastUtils';
import LeaveEmployeeTable from '@/Tables/LeaveEmployeeTable.jsx';
import LeaveForm          from '@/Forms/LeaveForm.jsx';
import DeleteLeaveForm    from '@/Forms/DeleteLeaveForm.jsx';
import BulkLeaveModal     from '@/Components/BulkLeave/BulkLeaveModal.jsx';
import BulkDeleteModal    from '@/Components/BulkDelete/BulkDeleteModal.jsx';

function StatPill({ label, value, color = 'gray' }) {
    return (
        <Badge size="2" variant="soft" color={color} radius="full">
            <Text weight="bold">{value}</Text>
            <Text style={{ opacity: 0.7 }}> {label}</Text>
        </Badge>
    );
}

export default function MyLeavesPanel({
    allUsers = [], isMobile, isActive, onCountChange, onSetHeaderActions,
}) {
    const { auth } = usePage().props;

    const canCreate = auth.permissions?.includes('leaves.create') || true; // employees can always apply
    const canEdit   = auth.permissions?.includes('leaves.update') || false;
    const canDelete = auth.permissions?.includes('leaves.delete') || false;

    /* ── data ── */
    const [leaves,     setLeaves]     = useState([]);
    const [leavesData, setLeavesData] = useState({ leaveTypes: [], leaveCountsByUser: {} });
    const [totalRows,  setTotalRows]  = useState(0);
    const [lastPage,   setLastPage]   = useState(1);
    const [loading,    setLoading]    = useState(false);
    const [error,      setError]      = useState('');
    const [leaveStats, setLeaveStats] = useState({
        total: 0, pending: 0, approved: 0, rejected: 0,
    });

    /* ── pagination / filters ── */
    const [pagination, setPagination] = useState({ page: 1, perPage: 30 });
    const [filters,    setFilters]    = useState({
        year: new Date().getFullYear(),
        selectedMonth: dayjs().format('YYYY-MM'),
    });

    /* ── modals ── */
    const [modalStates, setModalStates] = useState({
        addEditLeave: false, deleteLeave: false,
        bulkLeave: false,    bulkDelete:  false,
    });
    const [currentLeave, setCurrentLeave] = useState(null);

    const openModal  = useCallback(type => setModalStates(p => ({ ...p, [type]: true  })), []);
    const closeModal = useCallback(type => setModalStates(p => ({ ...p, [type]: false })), []);

    const handleClickOpen = useCallback((leaveId, modalType) => {
        setCurrentLeave({ id: leaveId });
        openModal(modalType);
    }, [openModal]);

    /* ── fetch ── */
    const fetchLeaves = useCallback(async () => {
        setLoading(true);
        try {
            const { data } = await axios.get(route('leaves.paginate'), {
                params: {
                    page:    pagination.page,
                    perPage: pagination.perPage,
                    year:    filters.year,
                    user_id: auth.user.id,
                },
                timeout: 10000,
            });
            const { leaves: lv, leavesData: ld } = data;
            const list = lv?.data ?? lv ?? [];
            setLeaves(list);
            setTotalRows(lv?.total ?? list.length);
            setLastPage(lv?.last_page ?? 1);
            setLeavesData(ld || { leaveTypes: [], leaveCountsByUser: {} });
            onCountChange?.(lv?.total ?? list.length);

            // derive quick stats from the list
            const pending  = list.filter(l => l.status === 'pending').length;
            const approved = list.filter(l => l.status === 'approved').length;
            const rejected = list.filter(l => l.status === 'rejected').length;
            setLeaveStats({ total: list.length, pending, approved, rejected });
            setError('');
        } catch (e) {
            setError(e.response?.data?.message || 'Failed to load your leaves.');
            setLeaves([]);
        } finally {
            setLoading(false);
        }
    }, [pagination, filters, auth.user.id, onCountChange]);

    useEffect(() => {
        if (isActive) fetchLeaves();
    }, [fetchLeaves, isActive]);

    /* ── header actions ── */
    useEffect(() => {
        if (!isActive) return;
        onSetHeaderActions?.(
            <Flex gap="2" wrap="wrap">
                <Button size="2" onClick={() => { setCurrentLeave(null); openModal('addEditLeave'); }}>
                    <PlusIcon /> {!isMobile && 'Apply Leave'}
                </Button>
                <Button size="2" variant="soft" color="violet"
                    onClick={() => openModal('bulkLeave')}>
                    {!isMobile && 'Bulk Apply'}
                </Button>
                <IconButton size="2" variant="soft" color="gray" onClick={fetchLeaves} aria-label="Refresh">
                    <ReloadIcon />
                </IconButton>
            </Flex>
        );
    }, [isActive, isMobile]);

    /* ── leave balance cards ── */
    const leaveCountsByUser = leavesData?.leaveCountsByUser?.[auth.user.id] || {};
    const leaveTypes = leavesData?.leaveTypes || [];

    /* ── render ── */
    return (
        <Box>
            {/* ── Stats ── */}
            <Flex wrap="wrap" gap="2" mb="4">
                <StatPill label="Total"    value={leaveStats.total}    color="blue"  />
                <StatPill label="Pending"  value={leaveStats.pending}  color="amber" />
                <StatPill label="Approved" value={leaveStats.approved} color="green" />
                <StatPill label="Rejected" value={leaveStats.rejected} color="red"   />
            </Flex>

            {/* ── Leave Balance Cards ── */}
            {leaveTypes.length > 0 && (
                <Box mb="4">
                    <Text size="2" weight="medium" color="gray" as="div" mb="2">Leave Balance</Text>
                    <Flex gap="2" wrap="wrap">
                        {leaveTypes.map(lt => {
                            const counts  = leaveCountsByUser[lt.type] || {};
                            const used    = counts.used    ?? 0;
                            const allowed = counts.allowed ?? lt.max_days ?? '—';
                            const remain  = typeof allowed === 'number' ? allowed - used : '—';
                            return (
                                <Card key={lt.id} size="1" variant="surface" style={{ minWidth: 120 }}>
                                    <Text size="1" color="gray" as="div">{lt.type}</Text>
                                    <Flex align="center" gap="1" mt="1">
                                        <Text size="3" weight="bold">{remain}</Text>
                                        <Text size="1" color="gray">/ {allowed} left</Text>
                                    </Flex>
                                    <Text size="1" color="gray">{used} used</Text>
                                </Card>
                            );
                        })}
                    </Flex>
                </Box>
            )}

            {/* ── Year filter ── */}
            <Flex gap="3" align="center" mb="3" wrap="wrap">
                <Box>
                    <Text size="2" color="gray" weight="medium" as="div" mb="1">Year</Text>
                    <select
                        value={filters.year}
                        onChange={e => {
                            setFilters(p => ({ ...p, year: Number(e.target.value) }));
                            setPagination(p => ({ ...p, page: 1 }));
                        }}
                        style={{
                            padding: '6px 10px',
                            background: 'var(--color-surface)',
                            border: '1px solid var(--gray-a7)',
                            borderRadius: 'var(--radius-2)',
                            color: 'var(--gray-12)', fontSize: 14,
                        }}
                    >
                        {Array.from({ length: 5 }, (_, i) => new Date().getFullYear() - i).map(y => (
                            <option key={y} value={y}>{y}</option>
                        ))}
                    </select>
                </Box>
            </Flex>

            {/* ── Section header ── */}
            <Flex align="center" justify="between" mb="3">
                <Flex align="center" gap="2">
                    <TableIcon style={{ width: 16, height: 16 }} />
                    <Text size="3" weight="medium">My Leave Requests</Text>
                    {!loading && <Badge size="1" variant="soft" color="gray" radius="full">{totalRows}</Badge>}
                </Flex>
            </Flex>

            {/* ── Table / States ── */}
            {loading ? (
                <Flex direction="column" align="center" py="9" gap="3">
                    <Spinner size="3" /><Text color="gray" size="2">Loading your leaves…</Text>
                </Flex>
            ) : error ? (
                <Callout.Root color="orange">
                    <Callout.Icon><ExclamationTriangleIcon /></Callout.Icon>
                    <Callout.Text>{error}</Callout.Text>
                </Callout.Root>
            ) : leaves.length > 0 ? (
                <LeaveEmployeeTable
                    leaves={leaves}
                    allUsers={allUsers}
                    totalRows={totalRows}
                    lastPage={lastPage}
                    currentPage={pagination.page}
                    perPage={pagination.perPage}
                    setCurrentPage={page => setPagination(p => ({ ...p, page }))}
                    setPerPage={n => setPagination({ page: 1, perPage: n })}
                    handleClickOpen={handleClickOpen}
                    setCurrentLeave={setCurrentLeave}
                    openModal={openModal}
                    setLeaves={setLeaves}
                    employee={auth.user.id}
                    selectedMonth={filters.selectedMonth}
                    isAdminView={false}
                    canApproveLeaves={false}
                    canEditLeaves={canEdit}
                    canDeleteLeaves={canDelete}
                    fetchLeavesStats={fetchLeaves}
                />
            ) : (
                <Flex direction="column" align="center" py="9" gap="2">
                    <CalendarIcon style={{ width: 40, height: 40, color: 'var(--gray-9)' }} />
                    <Text size="3" weight="medium">No Leaves Found</Text>
                    <Text size="2" color="gray">You have no leave requests for {filters.year}.</Text>
                    <Button size="2" mt="2" onClick={() => { setCurrentLeave(null); openModal('addEditLeave'); }}>
                        <PlusIcon /> Apply for Leave
                    </Button>
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
                    setLeaves={setLeaves}
                    setTotalRows={setTotalRows}
                    setLastPage={setLastPage}
                    selectedMonth={filters.selectedMonth}
                    fetchLeavesStats={fetchLeaves}
                />
            )}

            {modalStates.deleteLeave && (
                <DeleteLeaveForm
                    open={modalStates.deleteLeave}
                    handleClose={() => closeModal('deleteLeave')}
                    leave={currentLeave}
                    setLeaves={setLeaves}
                    fetchLeavesStats={fetchLeaves}
                />
            )}

            {modalStates.bulkLeave && (
                <BulkLeaveModal
                    isOpen={modalStates.bulkLeave}
                    onClose={() => closeModal('bulkLeave')}
                    allUsers={allUsers}
                    onSuccess={fetchLeaves}
                />
            )}

            {modalStates.bulkDelete && (
                <BulkDeleteModal
                    isOpen={modalStates.bulkDelete}
                    onClose={() => closeModal('bulkDelete')}
                    onSuccess={() => { fetchLeaves(); closeModal('bulkDelete'); }}
                />
            )}
        </Box>
    );
}
