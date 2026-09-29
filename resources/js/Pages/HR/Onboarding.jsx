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
    FileTextIcon,
    PersonIcon,
    CalendarIcon,
    ClockIcon,
    CheckIcon,
    ReloadIcon,
} from '@radix-ui/react-icons';
import axios from 'axios';

const STATUS_COLORS = {
    pending: 'amber',
    in_progress: 'indigo',
    completed: 'green',
    cancelled: 'gray',
};

const OnboardingPage = ({
    title = 'Employee Onboarding',
    onboardings = { data: [] },
    stats = {},
    filters = {},
}) => {
    const { auth } = usePage().props;
    const canCreate = auth?.permissions?.includes('hr.onboarding.create') || auth?.roles?.includes('Super Administrator');
    const canUpdate = auth?.permissions?.includes('hr.onboarding.update') || auth?.roles?.includes('Super Administrator');

    const [searchTerm, setSearchTerm] = useState(filters.search || '');
    const [statusFilter, setStatusFilter] = useState(filters.status || 'all');

    // Initiate modal state
    const [isCreateOpen, setIsCreateOpen] = useState(false);
    const [eligibleEmployees, setEligibleEmployees] = useState([]);
    const [isLoadingEligible, setIsLoadingEligible] = useState(false);
    const [formData, setFormData] = useState({
        employee_id: '',
        start_date: new Date().toISOString().split('T')[0],
        expected_completion_date: new Date(Date.now() + 14 * 86400000).toISOString().split('T')[0],
        notes: '',
    });
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [formError, setFormError] = useState('');

    // Detail / Checklist dialog
    const [selectedOnboarding, setSelectedOnboarding] = useState(null);
    const [isDetailOpen, setIsDetailOpen] = useState(false);
    const [updatingTaskId, setUpdatingTaskId] = useState(null);
    const [isSyncingBiometric, setIsSyncingBiometric] = useState(false);
    const [biometricSyncMsg, setBiometricSyncMsg] = useState('');

    // Fetch eligible employees when opening the creation modal
    const handleOpenCreate = async () => {
        setIsCreateOpen(true);
        setFormError('');
        setIsLoadingEligible(true);
        try {
            const res = await axios.get('/hr/onboarding/eligible-employees');
            setEligibleEmployees(res.data || []);
            if (res.data && res.data.length > 0) {
                setFormData(prev => ({ ...prev, employee_id: res.data[0].employee_id }));
            }
        } catch (err) {
            console.error('Failed to fetch eligible employees', err);
        } finally {
            setIsLoadingEligible(false);
        }
    };

    // Submit new onboarding process
    const handleSubmitCreate = async (e) => {
        e.preventDefault();
        setFormError('');
        if (!formData.employee_id) {
            setFormError('Please select an employee.');
            return;
        }

        setIsSubmitting(true);
        try {
            await axios.post('/hr/onboarding', formData);
            setIsCreateOpen(false);
            router.reload({ only: ['onboardings', 'stats'] });
        } catch (err) {
            setFormError(err.response?.data?.message || 'Failed to initiate onboarding process.');
        } finally {
            setIsSubmitting(false);
        }
    };

    // Toggle task status
    const handleToggleTask = async (task) => {
        if (!canUpdate || !selectedOnboarding) return;
        const newStatus = task.status === 'completed' ? 'pending' : 'completed';
        setUpdatingTaskId(task.id);

        try {
            const res = await axios.patch(`/hr/onboarding/${selectedOnboarding.id}/tasks/${task.id}`, {
                status: newStatus,
            });
            // Update local dialog state
            setSelectedOnboarding(res.data.onboarding);
            // Refresh table data
            router.reload({ only: ['onboardings', 'stats'] });
        } catch (err) {
            console.error('Failed to update task', err);
        } finally {
            setUpdatingTaskId(null);
        }
    };

    // Manually push biometric ADD_USER command to hardware
    const handleSyncBiometric = async (onboardingId) => {
        setIsSyncingBiometric(true);
        setBiometricSyncMsg('');
        try {
            const res = await axios.post(`/hr/onboarding/${onboardingId}/sync-biometric`);
            setBiometricSyncMsg(res.data.message || 'Biometric command sent.');
            setTimeout(() => setBiometricSyncMsg(''), 4000);
        } catch (err) {
            setBiometricSyncMsg(err.response?.data?.message || 'Failed to sync biometric devices.');
        } finally {
            setIsSyncingBiometric(false);
        }
    };

    // Client-side filtering
    const filteredOnboardings = useMemo(() => {
        return (onboardings.data || []).filter(item => {
            if (statusFilter !== 'all' && item.status !== statusFilter) return false;
            if (searchTerm) {
                const term = searchTerm.toLowerCase();
                const nameMatch = item.employee?.name?.toLowerCase().includes(term);
                const idMatch = item.employee_id?.toLowerCase().includes(term);
                if (!nameMatch && !idMatch) return false;
            }
            return true;
        });
    }, [onboardings.data, statusFilter, searchTerm]);

    return (
        <Panel title={title}>
            <Head title="Employee Onboarding & Induction" />

            <Box p="4">
                {/* PAGE HEADER */}
                <Flex justify="between" align="center" mb="4" wrap="wrap" gap="3">
                    <Box>
                        <Heading size="6" weight="bold">Employee Onboarding & Induction</Heading>
                        <Text size="2" color="gray">
                            Structured induction, statutory BLA compliance, IT provisioning, and hardware biometric enrollment.
                        </Text>
                    </Box>
                    {canCreate && (
                        <Button color="indigo" onClick={handleOpenCreate}>
                            <PlusIcon /> Initiate Onboarding
                        </Button>
                    )}
                </Flex>

                {/* STATS CARDS */}
                <Flex gap="3" mb="5" wrap="wrap">
                    <Card style={{ flex: '1 1 200px', minWidth: 180 }}>
                        <Text size="1" color="gray" weight="medium">Total Onboarding</Text>
                        <Heading size="6" mt="1">{stats.total || 0}</Heading>
                    </Card>
                    <Card style={{ flex: '1 1 200px', minWidth: 180 }}>
                        <Text size="1" color="gray" weight="medium">In Progress</Text>
                        <Heading size="6" mt="1" color="indigo">{stats.in_progress || 0}</Heading>
                    </Card>
                    <Card style={{ flex: '1 1 200px', minWidth: 180 }}>
                        <Text size="1" color="gray" weight="medium">Pending Start</Text>
                        <Heading size="6" mt="1" color="amber">{stats.pending || 0}</Heading>
                    </Card>
                    <Card style={{ flex: '1 1 200px', minWidth: 180 }}>
                        <Text size="1" color="gray" weight="medium">Completed Induction</Text>
                        <Heading size="6" mt="1" color="green">{stats.completed || 0}</Heading>
                    </Card>
                </Flex>

                {/* FILTER CONTROLS */}
                <Card mb="4">
                    <Flex gap="3" align="center" wrap="wrap">
                        <Box style={{ flex: 1, minWidth: 260 }}>
                            <TextField.Root
                                placeholder="Search by employee name or ID..."
                                value={searchTerm}
                                onChange={(e) => setSearchTerm(e.target.value)}
                            >
                                <TextField.Slot>
                                    <MagnifyingGlassIcon />
                                </TextField.Slot>
                            </TextField.Root>
                        </Box>

                        <Select.Root value={statusFilter} onValueChange={setStatusFilter}>
                            <Select.Trigger style={{ minWidth: 150 }} placeholder="All Statuses" />
                            <Select.Content>
                                <Select.Item value="all">All Statuses</Select.Item>
                                <Select.Item value="pending">Pending</Select.Item>
                                <Select.Item value="in_progress">In Progress</Select.Item>
                                <Select.Item value="completed">Completed</Select.Item>
                                <Select.Item value="cancelled">Cancelled</Select.Item>
                            </Select.Content>
                        </Select.Root>
                    </Flex>
                </Card>

                {/* ONBOARDING RECORDS TABLE */}
                <Card>
                    <Table.Root variant="surface">
                        <Table.Header>
                            <Table.Row>
                                <Table.ColumnHeaderCell>EMPLOYEE</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>START DATE</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>TARGET DATE</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>INDUCTION PROGRESS</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>STATUS</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell style={{ textAlign: 'right' }}>ACTIONS</Table.ColumnHeaderCell>
                            </Table.Row>
                        </Table.Header>

                        <Table.Body>
                            {filteredOnboardings.length === 0 ? (
                                <Table.Row>
                                    <Table.Cell colSpan={6} style={{ textAlign: 'center', padding: '3rem 1rem' }}>
                                        <Text color="gray" size="2">No onboarding records found.</Text>
                                    </Table.Cell>
                                </Table.Row>
                            ) : (
                                filteredOnboardings.map((item) => {
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
                                                        <Text size="2" weight="bold" style={{ display: 'block' }}>
                                                            {item.employee?.name || `Employee #${item.employee_id}`}
                                                        </Text>
                                                        <Text size="1" color="gray">
                                                            {item.employee?.employee_id || item.employee_id} · {item.employee?.designation?.title ? `${item.employee.designation.title} · ` : ''}{item.employee?.department?.name || 'General'}
                                                        </Text>
                                                    </Box>
                                                </Flex>
                                            </Table.Cell>

                                            <Table.Cell>
                                                <Text size="2" weight="medium">
                                                    {item.start_date ? new Date(item.start_date).toLocaleDateString() : 'Not Set'}
                                                </Text>
                                            </Table.Cell>

                                            <Table.Cell>
                                                <Text size="2" weight="medium">
                                                    {item.expected_completion_date ? new Date(item.expected_completion_date).toLocaleDateString() : 'Not Set'}
                                                </Text>
                                            </Table.Cell>

                                            <Table.Cell style={{ minWidth: 160 }}>
                                                <Flex direction="column" gap="1">
                                                    <Flex justify="between" align="center">
                                                        <Text size="1" color="gray">{doneTasks}/{totalTasks} Tasks</Text>
                                                        <Text size="1" weight="bold" color={pct === 100 ? 'green' : 'indigo'}>
                                                            {pct}%
                                                        </Text>
                                                    </Flex>
                                                    <Progress value={pct} color={pct === 100 ? 'green' : 'indigo'} size="1" />
                                                </Flex>
                                            </Table.Cell>

                                            <Table.Cell>
                                                <Badge color={STATUS_COLORS[item.status] || 'gray'} size="2" variant="solid">
                                                    {item.status.replace('_', ' ').toUpperCase()}
                                                </Badge>
                                            </Table.Cell>

                                            <Table.Cell style={{ textAlign: 'right' }}>
                                                <Flex justify="end" gap="2">
                                                    <Tooltip content="Sync Biometric Terminal">
                                                        <IconButton
                                                            size="1"
                                                            variant="soft"
                                                            color="blue"
                                                            onClick={() => handleSyncBiometric(item.id)}
                                                        >
                                                            <ReloadIcon />
                                                        </IconButton>
                                                    </Tooltip>
                                                    <Button
                                                        size="1"
                                                        variant="soft"
                                                        color="indigo"
                                                        onClick={() => {
                                                            setSelectedOnboarding(item);
                                                            setIsDetailOpen(true);
                                                        }}
                                                    >
                                                        Checklist
                                                    </Button>
                                                </Flex>
                                            </Table.Cell>
                                        </Table.Row>
                                    );
                                })
                            )}
                        </Table.Body>
                    </Table.Root>
                </Card>
            </Box>

            {/* INITIATE ONBOARDING MODAL */}
            <Dialog.Root open={isCreateOpen} onOpenChange={setIsCreateOpen}>
                <Dialog.Content style={{ maxWidth: 520 }}>
                    <Dialog.Title>Initiate Employee Onboarding</Dialog.Title>
                    <Dialog.Description size="2" mb="4">
                        Initialize induction tasks and queue biometric terminal hardware credentials.
                    </Dialog.Description>

                    {formError && (
                        <Box mb="3" p="2" style={{ background: 'var(--red-3)', borderRadius: 6 }}>
                            <Text size="2" color="red">{formError}</Text>
                        </Box>
                    )}

                    <form onSubmit={handleSubmitCreate}>
                        <Flex direction="column" gap="3">
                            <Box>
                                <Text as="label" size="2" weight="bold" mb="1" style={{ display: 'block' }}>
                                    Employee <span style={{ color: 'var(--red-9)' }}>*</span>
                                </Text>
                                {isLoadingEligible ? (
                                    <Text size="2" color="gray">Loading un-onboarded employees...</Text>
                                ) : eligibleEmployees.length === 0 ? (
                                    <Text size="2" color="gray">No employees pending onboarding.</Text>
                                ) : (
                                    <Select.Root
                                        value={formData.employee_id}
                                        onValueChange={(val) => setFormData(prev => ({ ...prev, employee_id: val }))}
                                    >
                                        <Select.Trigger style={{ width: '100%' }} />
                                        <Select.Content>
                                            {eligibleEmployees.map(emp => (
                                                <Select.Item key={emp.employee_id} value={emp.employee_id}>
                                                    {emp.name} ({emp.employee_id}) — {emp.department?.name || 'General'}
                                                </Select.Item>
                                            ))}
                                        </Select.Content>
                                    </Select.Root>
                                )}
                            </Box>

                            <Flex gap="3">
                                <Box style={{ flex: 1 }}>
                                    <Text as="label" size="2" weight="bold" mb="1" style={{ display: 'block' }}>
                                        Joining / Start Date <span style={{ color: 'var(--red-9)' }}>*</span>
                                    </Text>
                                    <TextField.Root
                                        type="date"
                                        value={formData.start_date}
                                        onChange={(e) => setFormData(prev => ({ ...prev, start_date: e.target.value }))}
                                        required
                                    />
                                </Box>
                                <Box style={{ flex: 1 }}>
                                    <Text as="label" size="2" weight="bold" mb="1" style={{ display: 'block' }}>
                                        Target Completion Date <span style={{ color: 'var(--red-9)' }}>*</span>
                                    </Text>
                                    <TextField.Root
                                        type="date"
                                        value={formData.expected_completion_date}
                                        onChange={(e) => setFormData(prev => ({ ...prev, expected_completion_date: e.target.value }))}
                                        required
                                    />
                                </Box>
                            </Flex>

                            <Box>
                                <Text as="label" size="2" weight="bold" mb="1" style={{ display: 'block' }}>
                                    Notes & Instructions
                                </Text>
                                <TextArea
                                    placeholder="Special requirements, toll plaza assignment, or IT instructions..."
                                    value={formData.notes}
                                    onChange={(e) => setFormData(prev => ({ ...prev, notes: e.target.value }))}
                                    rows={3}
                                />
                            </Box>

                            <Box p="3" style={{ background: 'var(--indigo-2)', borderRadius: 8 }}>
                                <Text size="1" color="indigo" weight="medium">
                                    💡 Upon creation, standard 8-point induction checklist (Form 7 compliance, ID card, IT hardware, safety gear, biometric sync, and roster assignment) will be auto-generated.
                                </Text>
                            </Box>

                            <Flex justify="end" gap="3" mt="3">
                                <Dialog.Close>
                                    <Button variant="soft" color="gray" type="button">Cancel</Button>
                                </Dialog.Close>
                                <Button color="indigo" type="submit" disabled={isSubmitting || eligibleEmployees.length === 0}>
                                    {isSubmitting ? 'Initiating...' : 'Confirm Onboarding'}
                                </Button>
                            </Flex>
                        </Flex>
                    </form>
                </Dialog.Content>
            </Dialog.Root>

            {/* DETAIL & INDUCTION CHECKLIST DIALOG */}
            <Dialog.Root open={isDetailOpen} onOpenChange={setIsDetailOpen}>
                <Dialog.Content style={{ maxWidth: 650 }}>
                    {selectedOnboarding && (
                        <>
                            <Flex justify="between" align="center" mb="2">
                                <Dialog.Title style={{ marginBottom: 0 }}>
                                    Induction Checklist — {selectedOnboarding.employee?.name || `Employee #${selectedOnboarding.employee_id}`}
                                </Dialog.Title>
                                <Badge color={STATUS_COLORS[selectedOnboarding.status] || 'gray'} size="2">
                                    {selectedOnboarding.status.replace('_', ' ').toUpperCase()}
                                </Badge>
                            </Flex>

                            <Dialog.Description size="2" mb="4">
                                Complete departmental onboarding, verify compliance, and track hardware provisioning.
                            </Dialog.Description>

                            {/* PROGRESS & HARDWARE RE-SYNC BANNER */}
                            <Box mb="4" p="3" style={{ background: 'var(--gray-2)', borderRadius: 8 }}>
                                <Flex justify="between" align="center" mb="2">
                                    <Box>
                                        <Text size="1" color="gray">Employee ID: </Text>
                                        <Text size="2" weight="bold">{selectedOnboarding.employee?.employee_id || selectedOnboarding.employee_id}</Text>
                                        <Text size="1" color="gray" ml="3">Department: </Text>
                                        <Text size="2" weight="bold">{selectedOnboarding.employee?.department?.name || 'General'}</Text>
                                    </Box>
                                    <Button
                                        size="1"
                                        variant="surface"
                                        color="blue"
                                        loading={isSyncingBiometric}
                                        onClick={() => handleSyncBiometric(selectedOnboarding.id)}
                                    >
                                        <ReloadIcon /> Push Biometrics
                                    </Button>
                                </Flex>

                                {biometricSyncMsg && (
                                    <Text size="1" color="blue" weight="medium" style={{ display: 'block', marginBottom: 8 }}>
                                        {biometricSyncMsg}
                                    </Text>
                                )}

                                <Box mt="2">
                                    {(() => {
                                        const tasks = selectedOnboarding.tasks || [];
                                        const done = tasks.filter(t => t.status === 'completed' || t.status === 'not-applicable').length;
                                        const pct = tasks.length > 0 ? Math.round((done / tasks.length) * 100) : 0;
                                        return (
                                            <>
                                                <Flex justify="between" align="center" mb="1">
                                                    <Text size="1" color="gray">Checklist Progress ({done}/{tasks.length})</Text>
                                                    <Text size="1" weight="bold" color={pct === 100 ? 'green' : 'indigo'}>{pct}%</Text>
                                                </Flex>
                                                <Progress value={pct} color={pct === 100 ? 'green' : 'indigo'} size="2" />
                                            </>
                                        );
                                    })()}
                                </Box>
                            </Box>

                            {/* TASK LIST */}
                            <Flex direction="column" gap="2" style={{ maxHeight: 380, overflowY: 'auto' }}>
                                {(selectedOnboarding.tasks || []).map((t) => {
                                    const isDone = t.status === 'completed';
                                    const isBusy = updatingTaskId === t.id;

                                    return (
                                        <Box
                                            key={t.id}
                                            p="3"
                                            style={{
                                                background: isDone ? 'var(--green-2)' : 'var(--gray-2)',
                                                border: `1px solid ${isDone ? 'var(--green-6)' : 'var(--gray-5)'}`,
                                                borderRadius: 8,
                                                cursor: canUpdate ? 'pointer' : 'default',
                                                opacity: isBusy ? 0.6 : 1,
                                                transition: 'all 0.15s ease',
                                            }}
                                            onClick={() => handleToggleTask(t)}
                                        >
                                            <Flex justify="between" align="start" gap="2">
                                                <Flex gap="2" align="start">
                                                    <Box mt="1">
                                                        {isDone ? (
                                                            <CheckCircledIcon width={18} height={18} color="var(--green-11)" />
                                                        ) : (
                                                            <ClockIcon width={18} height={18} color="var(--amber-11)" />
                                                        )}
                                                    </Box>
                                                    <Box>
                                                        <Text
                                                            size="2"
                                                            weight="bold"
                                                            style={{
                                                                textDecoration: isDone ? 'line-through' : 'none',
                                                                color: isDone ? 'var(--gray-10)' : 'var(--gray-12)',
                                                            }}
                                                        >
                                                            {t.task}
                                                        </Text>
                                                        {t.description && (
                                                            <Text size="1" color="gray" style={{ display: 'block', marginTop: 2 }}>
                                                                {t.description}
                                                            </Text>
                                                        )}
                                                        <Flex gap="3" align="center" mt="2">
                                                            {t.due_date && (
                                                                <Text size="1" color="gray">
                                                                    Due: {new Date(t.due_date).toLocaleDateString()}
                                                                </Text>
                                                            )}
                                                            {t.completed_date && (
                                                                <Text size="1" color="green">
                                                                    Done: {new Date(t.completed_date).toLocaleDateString()}
                                                                </Text>
                                                            )}
                                                        </Flex>
                                                    </Box>
                                                </Flex>

                                                <Badge color={isDone ? 'green' : 'amber'} size="1" variant="surface">
                                                    {t.status.toUpperCase()}
                                                </Badge>
                                            </Flex>
                                        </Box>
                                    );
                                })}
                            </Flex>

                            <Flex justify="end" mt="4">
                                <Dialog.Close>
                                    <Button variant="soft" color="gray">Close</Button>
                                </Dialog.Close>
                            </Flex>
                        </>
                    )}
                </Dialog.Content>
            </Dialog.Root>
        </Panel>
    );
};

OnboardingPage.layout = (page) => <App>{page}</App>;

export default OnboardingPage;
