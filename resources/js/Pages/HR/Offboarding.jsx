import React, { useState, useMemo } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import App from '@/Layouts/App.jsx';
import { Panel } from '@/Components/ui/Panel';
import {
    Box,
    Flex,
    Text,
    Heading,
    Badge,
    Button,
    Tabs,
    TextField,
    Select,
    Dialog,
    TextArea,
    Table,
    Avatar,
    IconButton,
    Tooltip,
    Separator,
    Card,
    Progress,
} from '@radix-ui/themes';
import {
    MagnifyingGlassIcon,
    PlusIcon,
    CheckCircledIcon,
    CrossCircledIcon,
    ExclamationTriangleIcon,
    FileTextIcon,
    PersonIcon,
    CalendarIcon,
    ClockIcon,
    CheckIcon,
    ReloadIcon,
    DownloadIcon,
    Share2Icon,
} from '@radix-ui/react-icons';
import axios from 'axios';

const REASON_LABELS = {
    resignation: 'Resignation',
    resignation_without_notice: 'Resignation (No Notice)',
    absconded: 'Job Abandonment / Absconded',
    termination: 'Termination',
    retirement: 'Retirement',
    end_contract: 'End of Contract',
    other: 'Other',
};

const REASON_COLORS = {
    resignation: 'blue',
    resignation_without_notice: 'orange',
    absconded: 'red',
    termination: 'ruby',
    retirement: 'purple',
    end_contract: 'cyan',
    other: 'gray',
};

const STATUS_COLORS = {
    pending: 'amber',
    in_progress: 'indigo',
    completed: 'green',
    cancelled: 'gray',
};

const STAGE_COLORS = {
    monitoring: 'gray',
    notice_sent: 'amber',
    show_cause: 'orange',
    deemed_resignation: 'ruby',
    absconded: 'red',
    returned: 'green',
};

const OffboardingPage = ({
    title = 'Employee Offboarding',
    offboardings = { data: [] },
    stats = {},
    absenceCases = [],
    filters = {},
}) => {
    const { auth } = usePage().props;
    const canCreate = auth?.permissions?.includes('hr.offboarding.create') || auth?.roles?.includes('Super Administrator');
    const canUpdate = auth?.permissions?.includes('hr.offboarding.update') || auth?.roles?.includes('Super Administrator');

    const [activeTab, setActiveTab] = useState('offboardings');
    const [searchTerm, setSearchTerm] = useState(filters.search || '');
    const [statusFilter, setStatusFilter] = useState(filters.status || 'all');
    const [reasonFilter, setReasonFilter] = useState(filters.reason || 'all');

    // Initiate modal state
    const [isCreateOpen, setIsCreateOpen] = useState(false);
    const [eligibleEmployees, setEligibleEmployees] = useState([]);
    const [isLoadingEligible, setIsLoadingEligible] = useState(false);
    const [formData, setFormData] = useState({
        employee_id: '',
        reason: 'resignation',
        initiation_date: new Date().toISOString().split('T')[0],
        last_working_date: new Date().toISOString().split('T')[0],
        resignation_received_at: '',
        notice_days_required: 30,
        notice_shortfall_days: 0,
        notes: '',
    });
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [formError, setFormError] = useState('');

    // Detail/Clearance checklist dialog
    const [selectedOffboarding, setSelectedOffboarding] = useState(null);
    const [isDetailOpen, setIsDetailOpen] = useState(false);
    const [updatingTaskId, setUpdatingTaskId] = useState(null);

    // Notice letter dialog state
    const [noticeLetter, setNoticeLetter] = useState(null);
    const [isNoticeOpen, setIsNoticeOpen] = useState(false);
    const [isLoadingNotice, setIsLoadingNotice] = useState(false);

    // Case resolution dialog state
    const [resolvingCase, setResolvingCase] = useState(null);
    const [isResolveOpen, setIsResolveOpen] = useState(false);
    const [resolveAction, setResolveAction] = useState('regularize');
    const [resolveNotes, setResolveNotes] = useState('');
    const [isResolving, setIsResolving] = useState(false);

    // F&F Settlement modal state
    const [isSettlementOpen, setIsSettlementOpen] = useState(false);
    const [settlementData, setSettlementData] = useState(null);
    const [isLoadingSettlement, setIsLoadingSettlement] = useState(false);
    const [isSavingSettlement, setIsSavingSettlement] = useState(false);
    const [settlementError, setSettlementError] = useState('');

    // Assets in clearance
    const [employeeAssets, setEmployeeAssets] = useState([]);
    const [isLoadingAssets, setIsLoadingAssets] = useState(false);
    const [returningAssetId, setReturningAssetId] = useState(null);

    // Certificate modal state
    const [isCertificateOpen, setIsCertificateOpen] = useState(false);
    const [certificateData, setCertificateData] = useState(null);
    const [isLoadingCertificate, setIsLoadingCertificate] = useState(false);
    const [certificateType, setCertificateType] = useState('experience');

    const openCreateModal = async () => {
        setIsCreateOpen(true);
        setIsLoadingEligible(true);
        setFormError('');
        try {
            const res = await axios.get('/hr/offboarding/eligible-employees');
            setEligibleEmployees(res.data || []);
        } catch (e) {
            console.error('Failed to load eligible employees', e);
        } finally {
            setIsLoadingEligible(false);
        }
    };

    const handleCreateSubmit = async (e) => {
        e.preventDefault();
        setFormError('');
        if (!formData.employee_id) {
            setFormError('Please select an employee.');
            return;
        }
        if (!formData.last_working_date) {
            setFormError('Please specify the last working date.');
            return;
        }

        setIsSubmitting(true);
        try {
            const payload = {
                ...formData,
                initiation_date: formData.initiation_date || new Date().toISOString().split('T')[0],
            };
            await axios.post('/hr/offboarding', payload);
            setIsCreateOpen(false);
            router.reload({ only: ['offboardings', 'stats'] });
        } catch (err) {
            const errorMsg = err.response?.data?.errors
                ? Object.values(err.response.data.errors).flat().join(' ')
                : (err.response?.data?.message || 'Failed to initiate offboarding.');
            setFormError(errorMsg);
        } finally {
            setIsSubmitting(false);
        }
    };

    const handleToggleTask = async (task) => {
        if (!canUpdate || !selectedOffboarding) return;
        const newStatus = task.status === 'completed' ? 'pending' : 'completed';
        setUpdatingTaskId(task.id);
        try {
            const res = await axios.patch(`/hr/offboarding/${selectedOffboarding.id}/tasks/${task.id}`, {
                status: newStatus,
            });
            if (res.data?.offboarding) {
                setSelectedOffboarding(res.data.offboarding);
                router.reload({ only: ['offboardings', 'stats'] });
            }
        } catch (e) {
            console.error('Failed to update task', e);
        } finally {
            setUpdatingTaskId(null);
        }
    };

    const handleViewNotice = async (caseItem, type) => {
        setIsLoadingNotice(true);
        setIsNoticeOpen(true);
        setNoticeLetter(null);
        try {
            const res = await axios.get(`/hr/absence-cases/${caseItem.id}/notice/${type}`);
            setNoticeLetter(res.data);
        } catch (e) {
            console.error('Failed to generate notice', e);
        } finally {
            setIsLoadingNotice(false);
        }
    };

    const handleResolveCaseSubmit = async () => {
        if (!resolvingCase) return;
        setIsResolving(true);
        try {
            await axios.post(`/hr/absence-cases/${resolvingCase.id}/resolve`, {
                action: resolveAction,
                notes: resolveNotes,
            });
            setIsResolveOpen(false);
            setResolvingCase(null);
            router.reload({ only: ['absenceCases', 'offboardings', 'stats'] });
        } catch (e) {
            console.error('Failed to resolve case', e);
        } finally {
            setIsResolving(false);
        }
    };

    // ── F&F Settlement Handlers ──────────────────────────────────────────
    const openSettlementModal = async (item) => {
        setIsSettlementOpen(true);
        setIsLoadingSettlement(true);
        setSettlementError('');
        setSettlementData(null);
        try {
            const res = await axios.get(`/hr/offboarding/${item.id}/settlement/calculate`);
            setSettlementData(res.data);
        } catch (e) {
            setSettlementError(e.response?.data?.message || 'Failed to calculate settlement.');
        } finally {
            setIsLoadingSettlement(false);
        }
    };

    const handleSaveSettlement = async () => {
        if (!settlementData) return;
        setIsSavingSettlement(true);
        setSettlementError('');
        try {
            await axios.post('/hr/offboarding/settlement', settlementData);
            setSettlementError('');
            // Refresh calculation to show saved status
            const res = await axios.get(`/hr/offboarding/${settlementData.offboarding_id}/settlement/calculate`);
            setSettlementData(res.data);
        } catch (e) {
            setSettlementError(e.response?.data?.message || 'Failed to save settlement.');
        } finally {
            setIsSavingSettlement(false);
        }
    };

    const handleApproveSettlement = async () => {
        if (!settlementData?.existing_settlement?.id) return;
        try {
            await axios.post(`/hr/offboarding/settlement/${settlementData.existing_settlement.id}/approve`);
            const res = await axios.get(`/hr/offboarding/${settlementData.offboarding_id}/settlement/calculate`);
            setSettlementData(res.data);
        } catch (e) {
            setSettlementError(e.response?.data?.message || 'Failed to approve.');
        }
    };

    const handleDisburseSettlement = async (method) => {
        if (!settlementData?.existing_settlement?.id) return;
        try {
            await axios.post(`/hr/offboarding/settlement/${settlementData.existing_settlement.id}/disburse`, {
                payment_method: method,
            });
            const res = await axios.get(`/hr/offboarding/${settlementData.offboarding_id}/settlement/calculate`);
            setSettlementData(res.data);
        } catch (e) {
            setSettlementError(e.response?.data?.message || 'Failed to disburse.');
        }
    };

    // ── Asset Handlers ───────────────────────────────────────────────────
    const fetchEmployeeAssets = async (employeeId) => {
        setIsLoadingAssets(true);
        try {
            const res = await axios.get(`/hr/assets/by-employee/${employeeId}`);
            setEmployeeAssets(res.data || []);
        } catch (e) {
            console.error('Failed to fetch assets', e);
            setEmployeeAssets([]);
        } finally {
            setIsLoadingAssets(false);
        }
    };

    const handleReturnAsset = async (assetId, condition = 'good') => {
        setReturningAssetId(assetId);
        try {
            await axios.post(`/hr/assets/${assetId}/return`, { condition_on_return: condition });
            // Refresh the list
            if (selectedOffboarding?.employee_id) {
                const res = await axios.get(`/hr/assets/by-employee/${selectedOffboarding.employee_id}`);
                setEmployeeAssets(res.data || []);
            }
        } catch (e) {
            console.error('Failed to return asset', e);
        } finally {
            setReturningAssetId(null);
        }
    };

    // ── Certificate Handlers ─────────────────────────────────────────────
    const openCertificateModal = async (item, type = 'experience') => {
        setCertificateType(type);
        setIsCertificateOpen(true);
        setIsLoadingCertificate(true);
        setCertificateData(null);
        try {
            const res = await axios.get(`/hr/offboarding/${item.id}/certificate/${type}`);
            setCertificateData(res.data);
        } catch (e) {
            console.error('Failed to fetch certificate', e);
        } finally {
            setIsLoadingCertificate(false);
        }
    };

    const filteredOffboardings = useMemo(() => {
        return (offboardings.data || []).filter((item) => {
            const matchesSearch = !searchTerm ||
                item.employee?.name?.toLowerCase().includes(searchTerm.toLowerCase()) ||
                item.employee?.employee_id?.toLowerCase().includes(searchTerm.toLowerCase());
            const matchesStatus = statusFilter === 'all' || item.status === statusFilter;
            const matchesReason = reasonFilter === 'all' || item.reason === reasonFilter;
            return matchesSearch && matchesStatus && matchesReason;
        });
    }, [offboardings.data, searchTerm, statusFilter, reasonFilter]);

    return (
        <>
            <Head title={title} />

            <Flex justify="center" p={{ initial: '2', sm: '4' }}>
                <Box style={{ width: '100%', maxWidth: 1600 }}>
                    {/* Page Header */}
                    <Flex justify="between" align={{ initial: 'start', sm: 'center' }} direction={{ initial: 'column', sm: 'row' }} gap="3" mb="4">
                        <Box>
                            <Heading size="6" weight="bold">Employee Separation & Offboarding</Heading>
                            <Text size="2" color="gray">
                                Structured exit clearance, access revocation, notice compliance, and no-show escalation.
                            </Text>
                        </Box>
                        {canCreate && (
                            <Button size="3" variant="solid" onClick={openCreateModal} style={{ cursor: 'pointer' }}>
                                <PlusIcon /> Initiate Offboarding
                            </Button>
                        )}
                    </Flex>

                    {/* Stats KPI Ribbon */}
                    <Flex gap="3" wrap="wrap" mb="4">
                        <Card style={{ flex: '1 1 200px', minWidth: 180 }}>
                            <Text size="1" color="gray" weight="medium">Total Separations</Text>
                            <Heading size="6" mt="1">{stats.total || 0}</Heading>
                        </Card>
                        <Card style={{ flex: '1 1 200px', minWidth: 180 }}>
                            <Text size="1" color="indigo" weight="medium">In Progress</Text>
                            <Heading size="6" mt="1" style={{ color: 'var(--indigo-11)' }}>{stats.in_progress || 0}</Heading>
                        </Card>
                        <Card style={{ flex: '1 1 200px', minWidth: 180 }}>
                            <Text size="1" color="red" weight="medium">Absconded / Abandoned</Text>
                            <Heading size="6" mt="1" style={{ color: 'var(--red-11)' }}>{stats.absconded || 0}</Heading>
                        </Card>
                        <Card style={{ flex: '1 1 200px', minWidth: 180 }}>
                            <Text size="1" color="amber" weight="medium">Active Absence Cases</Text>
                            <Heading size="6" mt="1" style={{ color: 'var(--amber-11)' }}>{stats.active_cases || 0}</Heading>
                        </Card>
                        <Card style={{ flex: '1 1 200px', minWidth: 180 }}>
                            <Text size="1" color="green" weight="medium">Completed</Text>
                            <Heading size="6" mt="1" style={{ color: 'var(--green-11)' }}>{stats.completed || 0}</Heading>
                        </Card>
                    </Flex>

                    <Panel variant="surface" p="4">
                        {/* Tabs */}
                        <Tabs.Root value={activeTab} onValueChange={setActiveTab}>
                            <Tabs.List mb="4">
                                <Tabs.Trigger value="offboardings">
                                    Separation Records ({offboardings.data?.length || 0})
                                </Tabs.Trigger>
                                <Tabs.Trigger value="absence_cases">
                                    <Flex align="center" gap="2">
                                        Absence Streaks & Escalation
                                        {absenceCases.length > 0 && (
                                            <Badge color="red" variant="solid" size="1">
                                                {absenceCases.length}
                                            </Badge>
                                        )}
                                    </Flex>
                                </Tabs.Trigger>
                            </Tabs.List>

                            {/* TAB 1: OFFBOARDINGS */}
                            <Tabs.Content value="offboardings">
                                {/* Filters */}
                                <Flex gap="3" wrap="wrap" mb="4" align="center">
                                    <Box style={{ flex: '1 1 260px' }}>
                                        <TextField.Root
                                            placeholder="Search by employee name or ID..."
                                            value={searchTerm}
                                            onChange={(e) => setSearchTerm(e.target.value)}
                                        >
                                            <TextField.Slot>
                                                <MagnifyingGlassIcon height="16" width="16" />
                                            </TextField.Slot>
                                        </TextField.Root>
                                    </Box>

                                    <Select.Root value={statusFilter} onValueChange={setStatusFilter}>
                                        <Select.Trigger placeholder="Status" />
                                        <Select.Content>
                                            <Select.Item value="all">All Statuses</Select.Item>
                                            <Select.Item value="pending">Pending</Select.Item>
                                            <Select.Item value="in_progress">In Progress</Select.Item>
                                            <Select.Item value="completed">Completed</Select.Item>
                                            <Select.Item value="cancelled">Cancelled</Select.Item>
                                        </Select.Content>
                                    </Select.Root>

                                    <Select.Root value={reasonFilter} onValueChange={setReasonFilter}>
                                        <Select.Trigger placeholder="Reason" />
                                        <Select.Content>
                                            <Select.Item value="all">All Reasons</Select.Item>
                                            <Select.Item value="resignation">Resignation</Select.Item>
                                            <Select.Item value="resignation_without_notice">Resignation (No Notice)</Select.Item>
                                            <Select.Item value="absconded">Absconded</Select.Item>
                                            <Select.Item value="termination">Termination</Select.Item>
                                            <Select.Item value="retirement">Retirement</Select.Item>
                                            <Select.Item value="end_contract">End of Contract</Select.Item>
                                        </Select.Content>
                                    </Select.Root>
                                </Flex>

                                {/* Table */}
                                <Table.Root variant="surface">
                                    <Table.Header>
                                        <Table.Row>
                                            <Table.ColumnHeaderCell>Employee</Table.ColumnHeaderCell>
                                            <Table.ColumnHeaderCell>Reason</Table.ColumnHeaderCell>
                                            <Table.ColumnHeaderCell>Last Working Date</Table.ColumnHeaderCell>
                                            <Table.ColumnHeaderCell>Notice Shortfall</Table.ColumnHeaderCell>
                                            <Table.ColumnHeaderCell>Clearance</Table.ColumnHeaderCell>
                                            <Table.ColumnHeaderCell>Status</Table.ColumnHeaderCell>
                                            <Table.ColumnHeaderCell align="right">Actions</Table.ColumnHeaderCell>
                                        </Table.Row>
                                    </Table.Header>

                                    <Table.Body>
                                        {filteredOffboardings.length === 0 ? (
                                            <Table.Row>
                                                <Table.Cell colSpan={7} align="center">
                                                    <Text color="gray" size="2">No offboarding records found.</Text>
                                                </Table.Cell>
                                            </Table.Row>
                                        ) : (
                                            filteredOffboardings.map((item) => {
                                                const totalTasks = item.tasks?.length || 0;
                                                const doneTasks = (item.tasks || []).filter(t => t.status === 'completed' || t.status === 'not-applicable').length;
                                                const pct = totalTasks > 0 ? Math.round((doneTasks / totalTasks) * 100) : 0;

                                                return (
                                                    <Table.Row key={item.id}>
                                                        <Table.Cell>
                                                            <Flex align="center" gap="2">
                                                                <Avatar
                                                                    fallback={(item.employee?.name || item.employee_id || '?').charAt(0).toUpperCase()}
                                                                    size="2"
                                                                    radius="full"
                                                                />
                                                                <Box>
                                                                    <Flex align="center" gap="2">
                                                                        <Text size="2" weight="bold" style={{ display: 'block' }}>
                                                                            {item.employee?.name || `Employee #${item.employee_id}`}
                                                                        </Text>
                                                                        {item.status === 'completed' && (
                                                                            <Badge color="gray" variant="surface" size="1">Ex-Employee</Badge>
                                                                        )}
                                                                    </Flex>
                                                                    <Text size="1" color="gray">
                                                                        {item.employee?.employee_id || item.employee_id} · {item.employee?.designation?.title ? `${item.employee.designation.title} · ` : ''}{item.employee?.department?.name || 'General'}
                                                                    </Text>
                                                                </Box>
                                                            </Flex>
                                                        </Table.Cell>

                                                        <Table.Cell>
                                                            <Badge color={REASON_COLORS[item.reason] || 'gray'} variant="soft" size="2">
                                                                {REASON_LABELS[item.reason] || item.reason}
                                                            </Badge>
                                                        </Table.Cell>

                                                        <Table.Cell>
                                                            <Text size="2" weight="medium">
                                                                {item.last_working_date ? new Date(item.last_working_date).toLocaleDateString() : 'Not Set'}
                                                            </Text>
                                                        </Table.Cell>

                                                        <Table.Cell>
                                                            {item.notice_shortfall_days > 0 ? (
                                                                <Badge color="red" variant="surface" size="1">
                                                                    {item.notice_shortfall_days} days shortfall
                                                                </Badge>
                                                            ) : (
                                                                <Text size="2" color="gray">None</Text>
                                                            )}
                                                        </Table.Cell>

                                                        <Table.Cell style={{ minWidth: 120 }}>
                                                            <Flex direction="column" gap="1">
                                                                <Flex justify="between" align="center">
                                                                    <Text size="1" color="gray">{doneTasks}/{totalTasks}</Text>
                                                                    <Text size="1" weight="bold">{pct}%</Text>
                                                                </Flex>
                                                                <Progress value={pct} size="1" color={pct === 100 ? 'green' : 'indigo'} />
                                                            </Flex>
                                                        </Table.Cell>

                                                        <Table.Cell>
                                                            <Badge color={STATUS_COLORS[item.status] || 'gray'} variant="solid" size="1">
                                                                {item.status.replace('_', ' ').toUpperCase()}
                                                            </Badge>
                                                        </Table.Cell>

                                                        <Table.Cell align="right">
                                                            <Flex gap="2" wrap="wrap" justify="end">
                                                                <Button
                                                                    size="1"
                                                                    variant="soft"
                                                                    onClick={() => {
                                                                        setSelectedOffboarding(item);
                                                                        setIsDetailOpen(true);
                                                                        fetchEmployeeAssets(item.employee_id);
                                                                    }}
                                                                    style={{ cursor: 'pointer' }}
                                                                >
                                                                    <CheckCircledIcon /> Clearance
                                                                </Button>
                                                                {canUpdate && (
                                                                    <Button
                                                                        size="1"
                                                                        variant="surface"
                                                                        color="green"
                                                                        onClick={() => openSettlementModal(item)}
                                                                        style={{ cursor: 'pointer' }}
                                                                    >
                                                                        <DownloadIcon /> F&F Settlement
                                                                    </Button>
                                                                )}
                                                                {item.status === 'completed' && (
                                                                    <Button
                                                                        size="1"
                                                                        variant="surface"
                                                                        color="purple"
                                                                        onClick={() => openCertificateModal(item, 'experience')}
                                                                        style={{ cursor: 'pointer' }}
                                                                    >
                                                                        <FileTextIcon /> Certificate
                                                                    </Button>
                                                                )}
                                                            </Flex>
                                                        </Table.Cell>
                                                    </Table.Row>
                                                );
                                            })
                                        )}
                                    </Table.Body>
                                </Table.Root>
                            </Tabs.Content>

                            {/* TAB 2: ABSENCE CASES & ESCALATION */}
                            <Tabs.Content value="absence_cases">
                                <Box mb="3">
                                    <Text size="2" color="gray">
                                        Employees with multi-day unauthorized absence computed by daily streak evaluation. Bangladesh Labour Act s.27(3A) compliant.
                                    </Text>
                                </Box>

                                <Table.Root variant="surface">
                                    <Table.Header>
                                        <Table.Row>
                                            <Table.ColumnHeaderCell>Employee</Table.ColumnHeaderCell>
                                            <Table.ColumnHeaderCell>Streak (Working Days)</Table.ColumnHeaderCell>
                                            <Table.ColumnHeaderCell>First Absent Date</Table.ColumnHeaderCell>
                                            <Table.ColumnHeaderCell>Escalation Stage</Table.ColumnHeaderCell>
                                            <Table.ColumnHeaderCell>Notices</Table.ColumnHeaderCell>
                                            <Table.ColumnHeaderCell align="right">Legal Actions</Table.ColumnHeaderCell>
                                        </Table.Row>
                                    </Table.Header>

                                    <Table.Body>
                                        {absenceCases.length === 0 ? (
                                            <Table.Row>
                                                <Table.Cell colSpan={6} align="center">
                                                    <Text color="gray" size="2">No consecutive absence cases currently open.</Text>
                                                </Table.Cell>
                                            </Table.Row>
                                        ) : (
                                            absenceCases.map((c) => (
                                                <Table.Row key={c.id}>
                                                    <Table.Cell>
                                                        <Flex align="center" gap="2">
                                                            <Avatar
                                                                fallback={(c.employee?.name || c.user_id || '?').charAt(0).toUpperCase()}
                                                                size="2"
                                                                radius="full"
                                                            />
                                                            <Box>
                                                                <Text size="2" weight="bold" style={{ display: 'block' }}>
                                                                    {c.employee?.name || `Employee #${c.user_id}`}
                                                                </Text>
                                                                <Text size="1" color="gray">
                                                                    {c.user_id} · {c.employee?.designation?.title ? `${c.employee.designation.title} · ` : ''}{c.employee?.department?.name || 'General'}
                                                                </Text>
                                                            </Box>
                                                        </Flex>
                                                    </Table.Cell>

                                                    <Table.Cell>
                                                        <Badge color={c.streak_days >= 10 ? 'red' : c.streak_days >= 3 ? 'orange' : 'amber'} variant="solid" size="2">
                                                            {c.streak_days} days absent
                                                        </Badge>
                                                    </Table.Cell>

                                                    <Table.Cell>
                                                        <Text size="2">
                                                            {c.first_absent_date ? new Date(c.first_absent_date).toLocaleDateString() : 'N/A'}
                                                        </Text>
                                                    </Table.Cell>

                                                    <Table.Cell>
                                                        <Badge color={STAGE_COLORS[c.stage] || 'gray'} variant="soft" size="2">
                                                            {c.stage.replace('_', ' ').toUpperCase()}
                                                        </Badge>
                                                    </Table.Cell>

                                                    <Table.Cell>
                                                        <Text size="2">{c.notices_sent || 0} sent</Text>
                                                    </Table.Cell>

                                                    <Table.Cell align="right">
                                                        <Flex justify="end" gap="2">
                                                            <Button
                                                                size="1"
                                                                variant="surface"
                                                                color="amber"
                                                                onClick={() => handleViewNotice(c, 'return_to_work')}
                                                                style={{ cursor: 'pointer' }}
                                                            >
                                                                <FileTextIcon /> Notice
                                                            </Button>

                                                            {c.streak_days >= 10 && (
                                                                <Button
                                                                    size="1"
                                                                    variant="surface"
                                                                    color="red"
                                                                    onClick={() => handleViewNotice(c, 'show_cause')}
                                                                    style={{ cursor: 'pointer' }}
                                                                >
                                                                    Show Cause
                                                                </Button>
                                                            )}

                                                            <Button
                                                                size="1"
                                                                variant="solid"
                                                                color="indigo"
                                                                onClick={() => {
                                                                    setResolvingCase(c);
                                                                    setIsResolveOpen(true);
                                                                }}
                                                                style={{ cursor: 'pointer' }}
                                                            >
                                                                Resolve
                                                            </Button>
                                                        </Flex>
                                                    </Table.Cell>
                                                </Table.Row>
                                            ))
                                        )}
                                    </Table.Body>
                                </Table.Root>
                            </Tabs.Content>
                        </Tabs.Root>
                    </Panel>
                </Box>
            </Flex>

            {/* INITIATE MODAL */}
            <Dialog.Root open={isCreateOpen} onOpenChange={setIsCreateOpen}>
                <Dialog.Content style={{ maxWidth: 540 }}>
                    <Dialog.Title>Initiate Employee Offboarding</Dialog.Title>
                    <Dialog.Description size="2" mb="4">
                        Record separation details. System access and future roster days will be adjusted per Last Working Date.
                    </Dialog.Description>

                    {formError && (
                        <Box mb="3" p="2" style={{ background: 'var(--red-3)', border: '1px solid var(--red-6)', borderRadius: 6 }}>
                            <Text size="2" color="red">{formError}</Text>
                        </Box>
                    )}

                    <form onSubmit={handleCreateSubmit}>
                        <Flex direction="column" gap="3">
                            <Box>
                                <Text as="label" size="2" weight="bold" mb="1" style={{ display: 'block' }}>
                                    Employee *
                                </Text>
                                <Select.Root
                                    value={formData.employee_id}
                                    onValueChange={(val) => setFormData({ ...formData, employee_id: val })}
                                    disabled={isLoadingEligible}
                                >
                                    <Select.Trigger placeholder={isLoadingEligible ? 'Loading employees...' : 'Select employee'} style={{ width: '100%' }} />
                                    <Select.Content>
                                        {eligibleEmployees.map((emp) => (
                                            <Select.Item key={emp.employee_id} value={emp.employee_id}>
                                                {emp.name} ({emp.employee_id}) - {emp.department?.name || 'No Dept'}
                                            </Select.Item>
                                        ))}
                                    </Select.Content>
                                </Select.Root>
                            </Box>

                            <Box>
                                <Text as="label" size="2" weight="bold" mb="1" style={{ display: 'block' }}>
                                    Separation Reason *
                                </Text>
                                <Select.Root
                                    value={formData.reason}
                                    onValueChange={(val) => setFormData({ ...formData, reason: val })}
                                >
                                    <Select.Trigger style={{ width: '100%' }} />
                                    <Select.Content>
                                        <Select.Item value="resignation">Resignation</Select.Item>
                                        <Select.Item value="resignation_without_notice">Resignation Without Notice</Select.Item>
                                        <Select.Item value="absconded">Job Abandonment (Absconded)</Select.Item>
                                        <Select.Item value="termination">Termination by Employer</Select.Item>
                                        <Select.Item value="retirement">Retirement</Select.Item>
                                        <Select.Item value="end_contract">End of Contract</Select.Item>
                                        <Select.Item value="other">Other</Select.Item>
                                    </Select.Content>
                                </Select.Root>
                            </Box>

                            <Flex gap="3">
                                <Box style={{ flex: 1 }}>
                                    <Text as="label" size="2" weight="bold" mb="1" style={{ display: 'block' }}>
                                        Last Working Date *
                                    </Text>
                                    <TextField.Root
                                        type="date"
                                        value={formData.last_working_date}
                                        onChange={(e) => setFormData({ ...formData, last_working_date: e.target.value })}
                                    />
                                </Box>
                                <Box style={{ flex: 1 }}>
                                    <Text as="label" size="2" weight="bold" mb="1" style={{ display: 'block' }}>
                                        Resignation Received Date
                                    </Text>
                                    <TextField.Root
                                        type="date"
                                        value={formData.resignation_received_at}
                                        onChange={(e) => setFormData({ ...formData, resignation_received_at: e.target.value })}
                                    />
                                </Box>
                            </Flex>

                            <Flex gap="3">
                                <Box style={{ flex: 1 }}>
                                    <Text as="label" size="2" weight="bold" mb="1" style={{ display: 'block' }}>
                                        Notice Required (Days)
                                    </Text>
                                    <TextField.Root
                                        type="number"
                                        value={formData.notice_days_required}
                                        onChange={(e) => setFormData({ ...formData, notice_days_required: parseInt(e.target.value) || 0 })}
                                    />
                                </Box>
                                <Box style={{ flex: 1 }}>
                                    <Text as="label" size="2" weight="bold" mb="1" style={{ display: 'block' }}>
                                        Notice Shortfall (Days)
                                    </Text>
                                    <TextField.Root
                                        type="number"
                                        value={formData.notice_shortfall_days}
                                        onChange={(e) => setFormData({ ...formData, notice_shortfall_days: parseInt(e.target.value) || 0 })}
                                    />
                                </Box>
                            </Flex>

                            <Box>
                                <Text as="label" size="2" weight="bold" mb="1" style={{ display: 'block' }}>
                                    Notes & Remarks
                                </Text>
                                <TextArea
                                    placeholder="Circumstances of separation..."
                                    value={formData.notes}
                                    onChange={(e) => setFormData({ ...formData, notes: e.target.value })}
                                />
                            </Box>
                        </Flex>

                        <Flex justify="end" gap="3" mt="5">
                            <Button type="button" variant="soft" color="gray" onClick={() => setIsCreateOpen(false)}>
                                Cancel
                            </Button>
                            <Button type="submit" variant="solid" disabled={isSubmitting}>
                                {isSubmitting ? 'Initiating...' : 'Confirm Offboarding'}
                            </Button>
                        </Flex>
                    </form>
                </Dialog.Content>
            </Dialog.Root>

            {/* DETAIL & CHECKLIST DIALOG */}
            <Dialog.Root open={isDetailOpen} onOpenChange={setIsDetailOpen}>
                <Dialog.Content style={{ maxWidth: 640 }}>
                    {selectedOffboarding && (
                        <>
                            <Dialog.Title>
                                Clearance Checklist — {selectedOffboarding.employee?.name || `Employee #${selectedOffboarding.employee_id}`}
                            </Dialog.Title>
                            <Dialog.Description size="2" mb="4">
                                Track departmental clearance and asset handover. Click a task to mark completed.
                            </Dialog.Description>

                            <Box mb="4" p="3" style={{ background: 'var(--gray-2)', borderRadius: 8 }}>
                                <Flex justify="between" wrap="wrap" gap="2">
                                    <Box>
                                        <Text size="1" color="gray">Last Working Date</Text>
                                        <Text size="2" weight="bold">
                                            {selectedOffboarding.last_working_date ? new Date(selectedOffboarding.last_working_date).toLocaleDateString() : 'N/A'}
                                        </Text>
                                    </Box>
                                    <Box>
                                        <Text size="1" color="gray">Reason</Text>
                                        <Badge color={REASON_COLORS[selectedOffboarding.reason] || 'gray'} size="1">
                                            {REASON_LABELS[selectedOffboarding.reason] || selectedOffboarding.reason}
                                        </Badge>
                                    </Box>
                                    <Box>
                                        <Text size="1" color="gray">Notice Shortfall</Text>
                                        <Text size="2" weight="bold">{selectedOffboarding.notice_shortfall_days || 0} days</Text>
                                    </Box>
                                </Flex>
                            </Box>

                            <Flex direction="column" gap="2">
                                {(selectedOffboarding.tasks || []).map((t) => {
                                    const isDone = t.status === 'completed';
                                    return (
                                        <Flex
                                            key={t.id}
                                            align="center"
                                            justify="between"
                                            p="3"
                                            style={{
                                                background: isDone ? 'var(--green-2)' : 'var(--color-surface)',
                                                border: `1px solid ${isDone ? 'var(--green-6)' : 'var(--gray-a4)'}`,
                                                borderRadius: 8,
                                                cursor: canUpdate ? 'pointer' : 'default',
                                            }}
                                            onClick={() => handleToggleTask(t)}
                                        >
                                            <Flex align="center" gap="3">
                                                <IconButton
                                                    size="1"
                                                    variant={isDone ? 'solid' : 'soft'}
                                                    color={isDone ? 'green' : 'gray'}
                                                    disabled={updatingTaskId === t.id}
                                                >
                                                    {isDone ? <CheckIcon /> : <ClockIcon />}
                                                </IconButton>
                                                <Box>
                                                    <Text
                                                        size="2"
                                                        weight={isDone ? 'normal' : 'bold'}
                                                        style={{ textDecoration: isDone ? 'line-through' : 'none' }}
                                                    >
                                                        {t.task}
                                                    </Text>
                                                    {t.due_date && (
                                                        <Text size="1" color="gray" style={{ display: 'block' }}>
                                                            Due: {new Date(t.due_date).toLocaleDateString()}
                                                        </Text>
                                                    )}
                                                </Box>
                                            </Flex>
                                            <Badge color={isDone ? 'green' : 'amber'} variant="soft" size="1">
                                                {t.status.toUpperCase()}
                                            </Badge>
                                        </Flex>
                                    );
                                })}
                            </Flex>

                            {/* ── Assigned Assets Section ── */}
                            <Separator my="4" />
                            <Heading size="3" mb="2">Assigned Company Assets</Heading>
                            {isLoadingAssets ? (
                                <Text size="2" color="gray">Loading assigned assets...</Text>
                            ) : employeeAssets.length === 0 ? (
                                <Box p="3" style={{ background: 'var(--green-2)', borderRadius: 8, border: '1px solid var(--green-6)' }}>
                                    <Text size="2" color="green">✓ No company assets currently assigned to this employee.</Text>
                                </Box>
                            ) : (
                                <Flex direction="column" gap="2">
                                    {employeeAssets.map((asset) => (
                                        <Flex
                                            key={asset.id}
                                            align="center"
                                            justify="between"
                                            p="3"
                                            style={{
                                                background: 'var(--amber-2)',
                                                border: '1px solid var(--amber-6)',
                                                borderRadius: 8,
                                            }}
                                        >
                                            <Box>
                                                <Text size="2" weight="bold">{asset.name}</Text>
                                                <Text size="1" color="gray" style={{ display: 'block' }}>
                                                    {asset.asset_code} · {asset.category?.replace('_', ' ')} {asset.serial_number ? `· S/N: ${asset.serial_number}` : ''}
                                                </Text>
                                            </Box>
                                            {canUpdate && (
                                                <Flex gap="2">
                                                    <Button
                                                        size="1"
                                                        variant="solid"
                                                        color="green"
                                                        disabled={returningAssetId === asset.id}
                                                        onClick={() => handleReturnAsset(asset.id, 'good')}
                                                        style={{ cursor: 'pointer' }}
                                                    >
                                                        {returningAssetId === asset.id ? 'Returning...' : '✓ Return (Good)'}
                                                    </Button>
                                                    <Button
                                                        size="1"
                                                        variant="surface"
                                                        color="red"
                                                        disabled={returningAssetId === asset.id}
                                                        onClick={() => handleReturnAsset(asset.id, 'damaged')}
                                                        style={{ cursor: 'pointer' }}
                                                    >
                                                        Return (Damaged)
                                                    </Button>
                                                </Flex>
                                            )}
                                        </Flex>
                                    ))}
                                </Flex>
                            )}

                            <Flex justify="end" mt="5">
                                <Button variant="soft" color="gray" onClick={() => setIsDetailOpen(false)}>
                                    Close
                                </Button>
                            </Flex>
                        </>
                    )}
                </Dialog.Content>
            </Dialog.Root>

            {/* NOTICE LETTER MODAL */}
            <Dialog.Root open={isNoticeOpen} onOpenChange={setIsNoticeOpen}>
                <Dialog.Content style={{ maxWidth: 680 }}>
                    <Dialog.Title>Official Notice Preview</Dialog.Title>
                    <Dialog.Description size="2" mb="3">
                        Drafted in compliance with Bangladesh Labour Act 2006 s.27(3A). Ready to copy or print.
                    </Dialog.Description>

                    {isLoadingNotice ? (
                        <Flex justify="center" p="6">
                            <Text color="gray">Generating notice...</Text>
                        </Flex>
                    ) : noticeLetter ? (
                        <Box>
                            <Box
                                p="4"
                                style={{
                                    background: 'var(--gray-1)',
                                    border: '1px solid var(--gray-a4)',
                                    borderRadius: 8,
                                    maxHeight: 400,
                                    overflowY: 'auto',
                                    whiteSpace: 'pre-wrap',
                                    fontFamily: 'monospace',
                                    fontSize: 12,
                                    lineHeight: 1.5,
                                }}
                            >
                                {noticeLetter.body}
                            </Box>
                            <Flex justify="end" gap="3" mt="4">
                                <Button
                                    variant="surface"
                                    color="gray"
                                    onClick={() => {
                                        navigator.clipboard.writeText(noticeLetter.body);
                                        alert('Notice text copied to clipboard.');
                                    }}
                                >
                                    Copy Text
                                </Button>
                                <Button
                                    variant="solid"
                                    onClick={() => {
                                        const printWin = window.open('', '', 'width=800,height=600');
                                        printWin.document.write(`<pre style="font-family: Arial; white-space: pre-wrap; padding: 40px;">${noticeLetter.body}</pre>`);
                                        printWin.document.close();
                                        printWin.print();
                                    }}
                                >
                                    Print Notice
                                </Button>
                                <Button variant="soft" color="gray" onClick={() => setIsNoticeOpen(false)}>
                                    Close
                                </Button>
                            </Flex>
                        </Box>
                    ) : (
                        <Text color="red">Could not generate notice.</Text>
                    )}
                </Dialog.Content>
            </Dialog.Root>

            {/* RESOLVE ABSENCE CASE MODAL */}
            <Dialog.Root open={isResolveOpen} onOpenChange={setIsResolveOpen}>
                <Dialog.Content style={{ maxWidth: 480 }}>
                    <Dialog.Title>Resolve Absence Case</Dialog.Title>
                    <Dialog.Description size="2" mb="3">
                        Determine outcome for employee {resolvingCase?.employee?.name || resolvingCase?.user_id}.
                    </Dialog.Description>

                    <Flex direction="column" gap="3">
                        <Box>
                            <Text as="label" size="2" weight="bold" mb="1" style={{ display: 'block' }}>
                                Outcome Action *
                            </Text>
                            <Select.Root value={resolveAction} onValueChange={setResolveAction}>
                                <Select.Trigger style={{ width: '100%' }} />
                                <Select.Content>
                                    <Select.Item value="regularize">Returned to Work — Regularize Attendance</Select.Item>
                                    <Select.Item value="lwp">Returned to Work — Leave Without Pay (LWP)</Select.Item>
                                    <Select.Item value="abscond">Deemed Resignation / Convert to Absconded Offboarding</Select.Item>
                                </Select.Content>
                            </Select.Root>
                        </Box>

                        <Box>
                            <Text as="label" size="2" weight="bold" mb="1" style={{ display: 'block' }}>
                                Resolution Notes
                            </Text>
                            <TextArea
                                placeholder="Summary of explanation or decision..."
                                value={resolveNotes}
                                onChange={(e) => setResolveNotes(e.target.value)}
                            />
                        </Box>
                    </Flex>

                    <Flex justify="end" gap="3" mt="5">
                        <Button variant="soft" color="gray" onClick={() => setIsResolveOpen(false)}>
                            Cancel
                        </Button>
                        <Button
                            variant="solid"
                            color={resolveAction === 'abscond' ? 'red' : 'indigo'}
                            disabled={isResolving}
                            onClick={handleResolveCaseSubmit}
                        >
                            {isResolving ? 'Resolving...' : 'Confirm Resolution'}
                        </Button>
                    </Flex>
                </Dialog.Content>
            </Dialog.Root>

            {/* ══════════ F&F SETTLEMENT MODAL ══════════ */}
            <Dialog.Root open={isSettlementOpen} onOpenChange={setIsSettlementOpen}>
                <Dialog.Content style={{ maxWidth: 720 }}>
                    <Dialog.Title>Full & Final Settlement Voucher</Dialog.Title>
                    <Dialog.Description size="2" mb="4">
                        Itemized calculation per Bangladesh Labour Act 2006 — earned salary, leave encashment (s.117), gratuity (s.27), notice recovery, and loan deductions.
                    </Dialog.Description>

                    {settlementError && (
                        <Box mb="3" p="2" style={{ background: 'var(--red-3)', border: '1px solid var(--red-6)', borderRadius: 6 }}>
                            <Text size="2" color="red">{settlementError}</Text>
                        </Box>
                    )}

                    {isLoadingSettlement ? (
                        <Flex justify="center" p="6"><Text color="gray">Calculating settlement...</Text></Flex>
                    ) : settlementData ? (
                        <>
                            {/* Employee Info */}
                            <Box mb="4" p="3" style={{ background: 'var(--gray-2)', borderRadius: 8 }}>
                                <Flex justify="between" wrap="wrap" gap="2">
                                    <Box>
                                        <Text size="1" color="gray">Employee</Text>
                                        <Text size="2" weight="bold" style={{ display: 'block' }}>
                                            {settlementData.employee?.name} ({settlementData.employee?.employee_id})
                                        </Text>
                                    </Box>
                                    <Box>
                                        <Text size="1" color="gray">Designation / Dept</Text>
                                        <Text size="2" weight="bold" style={{ display: 'block' }}>
                                            {settlementData.employee?.designation} · {settlementData.employee?.department}
                                        </Text>
                                    </Box>
                                    <Box>
                                        <Text size="1" color="gray">LWD</Text>
                                        <Text size="2" weight="bold" style={{ display: 'block' }}>{settlementData.last_working_date}</Text>
                                    </Box>
                                    <Box>
                                        <Text size="1" color="gray">Monthly Gross</Text>
                                        <Text size="2" weight="bold" style={{ display: 'block' }}>৳{Number(settlementData.monthly_gross_salary).toLocaleString()}</Text>
                                    </Box>
                                </Flex>
                            </Box>

                            {/* Earnings */}
                            <Heading size="3" mb="2" color="green">Earnings</Heading>
                            <Table.Root variant="surface" mb="3">
                                <Table.Body>
                                    <Table.Row>
                                        <Table.Cell>Pro-rated Salary ({settlementData.payable_working_days} days × ৳{Number(settlementData.daily_rate).toLocaleString()})</Table.Cell>
                                        <Table.Cell align="right"><Text weight="bold">৳{Number(settlementData.earned_salary).toLocaleString()}</Text></Table.Cell>
                                    </Table.Row>
                                    <Table.Row>
                                        <Table.Cell>Leave Encashment ({settlementData.unavailed_leave_days} days)</Table.Cell>
                                        <Table.Cell align="right"><Text weight="bold">৳{Number(settlementData.leave_encashment_amount).toLocaleString()}</Text></Table.Cell>
                                    </Table.Row>
                                    <Table.Row>
                                        <Table.Cell>Service Gratuity (BLA s.27)</Table.Cell>
                                        <Table.Cell align="right"><Text weight="bold">৳{Number(settlementData.gratuity_amount).toLocaleString()}</Text></Table.Cell>
                                    </Table.Row>
                                    <Table.Row style={{ background: 'var(--green-2)' }}>
                                        <Table.Cell><Text weight="bold">Total Earnings</Text></Table.Cell>
                                        <Table.Cell align="right"><Text weight="bold" color="green">৳{Number(settlementData.total_earnings).toLocaleString()}</Text></Table.Cell>
                                    </Table.Row>
                                </Table.Body>
                            </Table.Root>

                            {/* Deductions */}
                            <Heading size="3" mb="2" color="red">Deductions</Heading>
                            <Table.Root variant="surface" mb="3">
                                <Table.Body>
                                    <Table.Row>
                                        <Table.Cell>Notice Shortfall Recovery ({settlementData.notice_shortfall_days} days)</Table.Cell>
                                        <Table.Cell align="right"><Text weight="bold">৳{Number(settlementData.notice_shortfall_deduction).toLocaleString()}</Text></Table.Cell>
                                    </Table.Row>
                                    <Table.Row>
                                        <Table.Cell>Petty Cash Loan Recovery</Table.Cell>
                                        <Table.Cell align="right"><Text weight="bold">৳{Number(settlementData.loan_recovery_amount).toLocaleString()}</Text></Table.Cell>
                                    </Table.Row>
                                    <Table.Row>
                                        <Table.Cell>Asset Damage Deduction</Table.Cell>
                                        <Table.Cell align="right"><Text weight="bold">৳{Number(settlementData.asset_damage_deduction).toLocaleString()}</Text></Table.Cell>
                                    </Table.Row>
                                    <Table.Row style={{ background: 'var(--red-2)' }}>
                                        <Table.Cell><Text weight="bold">Total Deductions</Text></Table.Cell>
                                        <Table.Cell align="right"><Text weight="bold" color="red">৳{Number(settlementData.total_deductions).toLocaleString()}</Text></Table.Cell>
                                    </Table.Row>
                                </Table.Body>
                            </Table.Root>

                            {/* Net Payable */}
                            <Box p="3" mb="3" style={{ background: 'var(--indigo-3)', borderRadius: 8, border: '1px solid var(--indigo-6)' }}>
                                <Flex justify="between" align="center">
                                    <Heading size="4">Net Payable Amount</Heading>
                                    <Heading size="5" color="indigo">৳{Number(settlementData.net_payable).toLocaleString()}</Heading>
                                </Flex>
                            </Box>

                            {/* Status Badge */}
                            {settlementData.existing_settlement && (
                                <Box mb="3">
                                    <Badge
                                        size="2"
                                        variant="solid"
                                        color={settlementData.existing_settlement.status === 'paid' ? 'green' : settlementData.existing_settlement.status === 'approved' ? 'indigo' : 'amber'}
                                    >
                                        Settlement Status: {settlementData.existing_settlement.status?.toUpperCase()}
                                    </Badge>
                                </Box>
                            )}

                            {/* Action Buttons */}
                            <Flex justify="end" gap="3" mt="4">
                                <Button variant="soft" color="gray" onClick={() => setIsSettlementOpen(false)}>Close</Button>

                                {(!settlementData.existing_settlement || settlementData.existing_settlement.status === 'draft') && canUpdate && (
                                    <Button
                                        variant="solid"
                                        color="indigo"
                                        disabled={isSavingSettlement}
                                        onClick={handleSaveSettlement}
                                        style={{ cursor: 'pointer' }}
                                    >
                                        {isSavingSettlement ? 'Saving...' : (settlementData.existing_settlement ? 'Update Draft' : 'Save Voucher')}
                                    </Button>
                                )}

                                {settlementData.existing_settlement?.status === 'draft' && canUpdate && (
                                    <Button variant="solid" color="green" onClick={handleApproveSettlement} style={{ cursor: 'pointer' }}>
                                        Approve
                                    </Button>
                                )}

                                {settlementData.existing_settlement?.status === 'approved' && canUpdate && (
                                    <Button variant="solid" color="cyan" onClick={() => handleDisburseSettlement('bank_transfer')} style={{ cursor: 'pointer' }}>
                                        Disburse (Bank Transfer)
                                    </Button>
                                )}
                            </Flex>
                        </>
                    ) : (
                        <Text color="red">Could not load settlement data.</Text>
                    )}
                </Dialog.Content>
            </Dialog.Root>

            {/* ══════════ CERTIFICATE MODAL ══════════ */}
            <Dialog.Root open={isCertificateOpen} onOpenChange={setIsCertificateOpen}>
                <Dialog.Content style={{ maxWidth: 680 }}>
                    <Dialog.Title>
                        {certificateData?.title || 'Employee Certificate'}
                    </Dialog.Title>
                    <Dialog.Description size="2" mb="3">
                        Official certificate per Bangladesh Labour Act s.31. Ready to print or download.
                    </Dialog.Description>

                    {isLoadingCertificate ? (
                        <Flex justify="center" p="6"><Text color="gray">Generating certificate...</Text></Flex>
                    ) : certificateData ? (
                        <Box>
                            {/* Certificate Type Toggle */}
                            <Flex gap="2" mb="4">
                                <Button
                                    size="1"
                                    variant={certificateType === 'experience' ? 'solid' : 'soft'}
                                    color="purple"
                                    onClick={() => {
                                        if (certificateType !== 'experience' && certificateData) {
                                            openCertificateModal({ id: settlementData?.offboarding_id || selectedOffboarding?.id }, 'experience');
                                        }
                                    }}
                                    style={{ cursor: 'pointer' }}
                                >
                                    Experience Certificate
                                </Button>
                                <Button
                                    size="1"
                                    variant={certificateType === 'release' ? 'solid' : 'soft'}
                                    color="cyan"
                                    onClick={() => {
                                        if (certificateType !== 'release' && certificateData) {
                                            openCertificateModal({ id: settlementData?.offboarding_id || selectedOffboarding?.id }, 'release');
                                        }
                                    }}
                                    style={{ cursor: 'pointer' }}
                                >
                                    Release Certificate
                                </Button>
                            </Flex>

                            {/* Certificate Header */}
                            <Box p="4" mb="3" style={{ background: 'var(--gray-1)', border: '2px solid var(--gray-6)', borderRadius: 8 }}>
                                <Flex justify="center" mb="3">
                                    <Heading size="4" align="center" style={{ textTransform: 'uppercase', letterSpacing: '2px' }}>
                                        {certificateData.company_name}
                                    </Heading>
                                </Flex>
                                <Separator mb="3" />
                                <Flex justify="center" mb="3">
                                    <Heading size="3" color="indigo">{certificateData.title}</Heading>
                                </Flex>
                                <Flex justify="between" mb="3">
                                    <Text size="1">Ref: {certificateData.reference_no}</Text>
                                    <Text size="1">Date: {certificateData.issue_date}</Text>
                                </Flex>
                                <Separator mb="3" />

                                <Box mb="3">
                                    <Text size="2" style={{ lineHeight: 1.8 }}>
                                        {certificateData.body}
                                    </Text>
                                </Box>

                                <Box mt="5">
                                    <Flex justify="between">
                                        <Box>
                                            <Text size="1" color="gray">Employee ID: {certificateData.employee?.employee_id}</Text>
                                        </Box>
                                        <Box style={{ textAlign: 'right' }}>
                                            <Text size="2" weight="bold" style={{ display: 'block' }}>______________________</Text>
                                            <Text size="1" color="gray">Authorized Signatory</Text>
                                            <Text size="1" color="gray" style={{ display: 'block' }}>HR & Administration</Text>
                                        </Box>
                                    </Flex>
                                </Box>
                            </Box>

                            <Flex justify="end" gap="3" mt="4">
                                <Button
                                    variant="surface"
                                    color="gray"
                                    onClick={() => {
                                        navigator.clipboard.writeText(certificateData.body);
                                        alert('Certificate text copied to clipboard.');
                                    }}
                                    style={{ cursor: 'pointer' }}
                                >
                                    Copy Text
                                </Button>
                                <Button
                                    variant="solid"
                                    onClick={() => {
                                        const printWin = window.open('', '', 'width=800,height=700');
                                        printWin.document.write(`
                                            <html><head><title>${certificateData.title}</title>
                                            <style>
                                                body { font-family: 'Georgia', serif; padding: 60px; line-height: 2; }
                                                h1 { text-align: center; font-size: 18px; letter-spacing: 3px; margin-bottom: 5px; }
                                                h2 { text-align: center; font-size: 16px; margin-bottom: 20px; color: #333; text-decoration: underline; }
                                                .ref { display: flex; justify-content: space-between; font-size: 12px; color: #666; margin-bottom: 20px; }
                                                .body { font-size: 14px; text-align: justify; }
                                                .sig { margin-top: 80px; text-align: right; font-size: 12px; }
                                                hr { border: 1px solid #333; margin: 15px 0; }
                                            </style></head><body>
                                            <h1>${certificateData.company_name}</h1><hr/>
                                            <h2>${certificateData.title}</h2>
                                            <div class="ref"><span>Ref: ${certificateData.reference_no}</span><span>Date: ${certificateData.issue_date}</span></div>
                                            <div class="body">${certificateData.body}</div>
                                            <div class="sig"><p>______________________</p><p>Authorized Signatory</p><p>HR & Administration</p></div>
                                            </body></html>
                                        `);
                                        printWin.document.close();
                                        printWin.print();
                                    }}
                                    style={{ cursor: 'pointer' }}
                                >
                                    Print Certificate
                                </Button>
                                <Button variant="soft" color="gray" onClick={() => setIsCertificateOpen(false)}>Close</Button>
                            </Flex>
                        </Box>
                    ) : (
                        <Text color="red">Could not generate certificate.</Text>
                    )}
                </Dialog.Content>
            </Dialog.Root>
        </>
    );
};

OffboardingPage.layout = (page) => <App>{page}</App>;

export default OffboardingPage;
