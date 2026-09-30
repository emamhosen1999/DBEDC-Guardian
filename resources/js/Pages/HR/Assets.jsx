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
    Card,
    Separator,
    IconButton,
    Tooltip,
} from '@radix-ui/themes';
import {
    MagnifyingGlassIcon,
    PlusIcon,
    CheckCircledIcon,
    Cross2Icon,
    Pencil1Icon,
    PersonIcon,
    ReloadIcon,
} from '@radix-ui/react-icons';
import axios from 'axios';

const CATEGORY_LABELS = {
    it_hardware: 'IT Hardware',
    sim_card: 'SIM Card',
    access_card: 'Access Card',
    safety_gear: 'Safety Gear',
    keys: 'Keys',
    vehicle: 'Vehicle',
    other: 'Other',
};

const STATUS_COLORS = {
    available: 'green',
    assigned: 'indigo',
    returned: 'cyan',
    damaged: 'red',
    disposed: 'gray',
};

const AssetsPage = ({
    title = 'Company Asset Management',
    assets = { data: [] },
    stats = {},
    filters = {},
}) => {
    const { auth } = usePage().props;
    const canEdit = auth?.permissions?.includes('employees.view') || auth?.roles?.includes('Super Administrator');

    const [searchTerm, setSearchTerm] = useState(filters.search || '');
    const [catFilter, setCatFilter] = useState(filters.category || 'all');
    const [statusFilter, setStatusFilter] = useState(filters.status || 'all');

    // Create modal
    const [isCreateOpen, setIsCreateOpen] = useState(false);
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [formError, setFormError] = useState('');
    const [createForm, setCreateForm] = useState({
        asset_code: '',
        name: '',
        category: 'it_hardware',
        serial_number: '',
        condition_on_issue: 'good',
        notes: '',
    });

    // Assign modal
    const [isAssignOpen, setIsAssignOpen] = useState(false);
    const [assigningAsset, setAssigningAsset] = useState(null);
    const [assignEmployeeId, setAssignEmployeeId] = useState('');
    const [isAssigning, setIsAssigning] = useState(false);

    // Return modal
    const [isReturnOpen, setIsReturnOpen] = useState(false);
    const [returningAsset, setReturningAsset] = useState(null);
    const [returnCondition, setReturnCondition] = useState('good');
    const [returnNotes, setReturnNotes] = useState('');
    const [isReturning, setIsReturning] = useState(false);

    const handleSearch = () => {
        router.get('/hr/assets', {
            search: searchTerm || undefined,
            category: catFilter !== 'all' ? catFilter : undefined,
            status: statusFilter !== 'all' ? statusFilter : undefined,
        }, { preserveState: true, only: ['assets', 'stats'] });
    };

    const handleCreateSubmit = async (e) => {
        e.preventDefault();
        setFormError('');
        if (!createForm.asset_code || !createForm.name) {
            setFormError('Asset code and name are required.');
            return;
        }
        setIsSubmitting(true);
        try {
            await axios.post('/hr/assets', createForm);
            setIsCreateOpen(false);
            setCreateForm({ asset_code: '', name: '', category: 'it_hardware', serial_number: '', condition_on_issue: 'good', notes: '' });
            router.reload({ only: ['assets', 'stats'] });
        } catch (err) {
            const msg = err.response?.data?.errors
                ? Object.values(err.response.data.errors).flat().join(' ')
                : (err.response?.data?.message || 'Failed to register asset.');
            setFormError(msg);
        } finally {
            setIsSubmitting(false);
        }
    };

    const handleAssign = async () => {
        if (!assigningAsset || !assignEmployeeId) return;
        setIsAssigning(true);
        try {
            await axios.post(`/hr/assets/${assigningAsset.id}/assign`, { employee_id: assignEmployeeId });
            setIsAssignOpen(false);
            setAssignEmployeeId('');
            router.reload({ only: ['assets', 'stats'] });
        } catch (e) {
            alert(e.response?.data?.message || 'Failed to assign asset.');
        } finally {
            setIsAssigning(false);
        }
    };

    const handleReturn = async () => {
        if (!returningAsset) return;
        setIsReturning(true);
        try {
            await axios.post(`/hr/assets/${returningAsset.id}/return`, {
                condition_on_return: returnCondition,
                notes: returnNotes || null,
            });
            setIsReturnOpen(false);
            setReturnCondition('good');
            setReturnNotes('');
            router.reload({ only: ['assets', 'stats'] });
        } catch (e) {
            alert(e.response?.data?.message || 'Failed to return asset.');
        } finally {
            setIsReturning(false);
        }
    };

    const handleDelete = async (id) => {
        if (!confirm('Are you sure you want to delete this asset?')) return;
        try {
            await axios.delete(`/hr/assets/${id}`);
            router.reload({ only: ['assets', 'stats'] });
        } catch (e) {
            alert(e.response?.data?.message || 'Failed to delete asset.');
        }
    };

    return (
        <>
            <Head title={title} />

            <Flex justify="center" p={{ initial: '2', sm: '4' }}>
                <Box style={{ width: '100%', maxWidth: 1600 }}>
                    {/* Header */}
                    <Flex justify="between" align={{ initial: 'start', sm: 'center' }} direction={{ initial: 'column', sm: 'row' }} gap="3" mb="4">
                        <Box>
                            <Heading size="6" weight="bold">Company Asset Management</Heading>
                            <Text size="2" color="gray">
                                Track IT hardware, SIM cards, access cards, keys, and all company property assigned to employees.
                            </Text>
                        </Box>
                        {canEdit && (
                            <Button size="3" variant="solid" onClick={() => setIsCreateOpen(true)} style={{ cursor: 'pointer' }}>
                                <PlusIcon /> Register New Asset
                            </Button>
                        )}
                    </Flex>

                    {/* KPI Stats */}
                    <Flex gap="3" wrap="wrap" mb="4">
                        <Card style={{ flex: '1 1 180px', minWidth: 160 }}>
                            <Text size="1" color="gray" weight="medium">Total Assets</Text>
                            <Heading size="6" mt="1">{stats.total || 0}</Heading>
                        </Card>
                        <Card style={{ flex: '1 1 180px', minWidth: 160 }}>
                            <Text size="1" color="indigo" weight="medium">Assigned</Text>
                            <Heading size="6" mt="1" style={{ color: 'var(--indigo-11)' }}>{stats.assigned || 0}</Heading>
                        </Card>
                        <Card style={{ flex: '1 1 180px', minWidth: 160 }}>
                            <Text size="1" color="green" weight="medium">Available</Text>
                            <Heading size="6" mt="1" style={{ color: 'var(--green-11)' }}>{stats.available || 0}</Heading>
                        </Card>
                        <Card style={{ flex: '1 1 180px', minWidth: 160 }}>
                            <Text size="1" color="red" weight="medium">Damaged</Text>
                            <Heading size="6" mt="1" style={{ color: 'var(--red-11)' }}>{stats.damaged || 0}</Heading>
                        </Card>
                    </Flex>

                    {/* Filters */}
                    <Panel variant="surface" p="4">
                        <Flex gap="3" wrap="wrap" mb="4" align="center">
                            <Box style={{ flex: '1 1 260px' }}>
                                <TextField.Root
                                    placeholder="Search by name, asset code, serial number or employee..."
                                    value={searchTerm}
                                    onChange={(e) => setSearchTerm(e.target.value)}
                                    onKeyDown={(e) => e.key === 'Enter' && handleSearch()}
                                >
                                    <TextField.Slot>
                                        <MagnifyingGlassIcon height="16" width="16" />
                                    </TextField.Slot>
                                </TextField.Root>
                            </Box>

                            <Select.Root value={catFilter} onValueChange={(v) => { setCatFilter(v); }}>
                                <Select.Trigger placeholder="Category" />
                                <Select.Content>
                                    <Select.Item value="all">All Categories</Select.Item>
                                    {Object.entries(CATEGORY_LABELS).map(([k, v]) => (
                                        <Select.Item key={k} value={k}>{v}</Select.Item>
                                    ))}
                                </Select.Content>
                            </Select.Root>

                            <Select.Root value={statusFilter} onValueChange={(v) => { setStatusFilter(v); }}>
                                <Select.Trigger placeholder="Status" />
                                <Select.Content>
                                    <Select.Item value="all">All Statuses</Select.Item>
                                    <Select.Item value="available">Available</Select.Item>
                                    <Select.Item value="assigned">Assigned</Select.Item>
                                    <Select.Item value="returned">Returned</Select.Item>
                                    <Select.Item value="damaged">Damaged</Select.Item>
                                    <Select.Item value="disposed">Disposed</Select.Item>
                                </Select.Content>
                            </Select.Root>

                            <Button variant="soft" onClick={handleSearch} style={{ cursor: 'pointer' }}>
                                <MagnifyingGlassIcon /> Search
                            </Button>
                        </Flex>

                        {/* Table */}
                        <Table.Root variant="surface">
                            <Table.Header>
                                <Table.Row>
                                    <Table.ColumnHeaderCell>Asset Code</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell>Name</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell>Category</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell>Serial Number</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell>Assigned To</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell>Status</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell>Condition</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell align="right">Actions</Table.ColumnHeaderCell>
                                </Table.Row>
                            </Table.Header>

                            <Table.Body>
                                {(assets.data || []).length === 0 ? (
                                    <Table.Row>
                                        <Table.Cell colSpan={8} align="center">
                                            <Text color="gray" size="2">No assets found.</Text>
                                        </Table.Cell>
                                    </Table.Row>
                                ) : (
                                    (assets.data || []).map((asset) => (
                                        <Table.Row key={asset.id}>
                                            <Table.Cell>
                                                <Text size="2" weight="bold">{asset.asset_code}</Text>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Text size="2">{asset.name}</Text>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Badge variant="soft" color="gray" size="1">
                                                    {CATEGORY_LABELS[asset.category] || asset.category}
                                                </Badge>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Text size="2" color="gray">{asset.serial_number || '—'}</Text>
                                            </Table.Cell>
                                            <Table.Cell>
                                                {asset.assignee ? (
                                                    <Box>
                                                        <Text size="2" weight="bold" style={{ display: 'block' }}>{asset.assignee.name}</Text>
                                                        <Text size="1" color="gray">{asset.assignee_id}</Text>
                                                    </Box>
                                                ) : (
                                                    <Text size="2" color="gray">Unassigned</Text>
                                                )}
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Badge color={STATUS_COLORS[asset.status] || 'gray'} variant="solid" size="1">
                                                    {asset.status?.toUpperCase()}
                                                </Badge>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Text size="2">{asset.condition_on_issue || '—'}</Text>
                                            </Table.Cell>
                                            <Table.Cell align="right">
                                                {canEdit && (
                                                    <Flex gap="2" justify="end" wrap="wrap">
                                                        {asset.status === 'available' && (
                                                            <Button
                                                                size="1"
                                                                variant="surface"
                                                                color="indigo"
                                                                onClick={() => {
                                                                    setAssigningAsset(asset);
                                                                    setIsAssignOpen(true);
                                                                }}
                                                                style={{ cursor: 'pointer' }}
                                                            >
                                                                <PersonIcon /> Assign
                                                            </Button>
                                                        )}
                                                        {asset.status === 'assigned' && (
                                                            <Button
                                                                size="1"
                                                                variant="surface"
                                                                color="green"
                                                                onClick={() => {
                                                                    setReturningAsset(asset);
                                                                    setIsReturnOpen(true);
                                                                }}
                                                                style={{ cursor: 'pointer' }}
                                                            >
                                                                <ReloadIcon /> Return
                                                            </Button>
                                                        )}
                                                        <Button
                                                            size="1"
                                                            variant="soft"
                                                            color="red"
                                                            onClick={() => handleDelete(asset.id)}
                                                            style={{ cursor: 'pointer' }}
                                                        >
                                                            <Cross2Icon />
                                                        </Button>
                                                    </Flex>
                                                )}
                                            </Table.Cell>
                                        </Table.Row>
                                    ))
                                )}
                            </Table.Body>
                        </Table.Root>
                    </Panel>
                </Box>
            </Flex>

            {/* CREATE ASSET MODAL */}
            <Dialog.Root open={isCreateOpen} onOpenChange={setIsCreateOpen}>
                <Dialog.Content style={{ maxWidth: 520 }}>
                    <Dialog.Title>Register New Company Asset</Dialog.Title>
                    <Dialog.Description size="2" mb="4">
                        Enter asset details. It will be registered as 'Available' in the inventory.
                    </Dialog.Description>

                    {formError && (
                        <Box mb="3" p="2" style={{ background: 'var(--red-3)', border: '1px solid var(--red-6)', borderRadius: 6 }}>
                            <Text size="2" color="red">{formError}</Text>
                        </Box>
                    )}

                    <form onSubmit={handleCreateSubmit}>
                        <Flex direction="column" gap="3">
                            <Flex gap="3">
                                <Box style={{ flex: 1 }}>
                                    <Text as="label" size="2" weight="bold" mb="1" style={{ display: 'block' }}>Asset Code *</Text>
                                    <TextField.Root
                                        placeholder="e.g. DBEDC-LP-001"
                                        value={createForm.asset_code}
                                        onChange={(e) => setCreateForm({ ...createForm, asset_code: e.target.value })}
                                    />
                                </Box>
                                <Box style={{ flex: 1 }}>
                                    <Text as="label" size="2" weight="bold" mb="1" style={{ display: 'block' }}>Category *</Text>
                                    <Select.Root value={createForm.category} onValueChange={(v) => setCreateForm({ ...createForm, category: v })}>
                                        <Select.Trigger style={{ width: '100%' }} />
                                        <Select.Content>
                                            {Object.entries(CATEGORY_LABELS).map(([k, v]) => (
                                                <Select.Item key={k} value={k}>{v}</Select.Item>
                                            ))}
                                        </Select.Content>
                                    </Select.Root>
                                </Box>
                            </Flex>

                            <Box>
                                <Text as="label" size="2" weight="bold" mb="1" style={{ display: 'block' }}>Asset Name *</Text>
                                <TextField.Root
                                    placeholder="e.g. Dell Latitude 5540 Laptop"
                                    value={createForm.name}
                                    onChange={(e) => setCreateForm({ ...createForm, name: e.target.value })}
                                />
                            </Box>

                            <Box>
                                <Text as="label" size="2" weight="bold" mb="1" style={{ display: 'block' }}>Serial Number</Text>
                                <TextField.Root
                                    placeholder="Manufacturer serial (optional)"
                                    value={createForm.serial_number}
                                    onChange={(e) => setCreateForm({ ...createForm, serial_number: e.target.value })}
                                />
                            </Box>

                            <Flex gap="3">
                                <Box style={{ flex: 1 }}>
                                    <Text as="label" size="2" weight="bold" mb="1" style={{ display: 'block' }}>Condition</Text>
                                    <Select.Root value={createForm.condition_on_issue} onValueChange={(v) => setCreateForm({ ...createForm, condition_on_issue: v })}>
                                        <Select.Trigger style={{ width: '100%' }} />
                                        <Select.Content>
                                            <Select.Item value="new">New</Select.Item>
                                            <Select.Item value="good">Good</Select.Item>
                                            <Select.Item value="fair">Fair</Select.Item>
                                        </Select.Content>
                                    </Select.Root>
                                </Box>
                            </Flex>

                            <Box>
                                <Text as="label" size="2" weight="bold" mb="1" style={{ display: 'block' }}>Notes</Text>
                                <TextArea
                                    placeholder="Additional details..."
                                    value={createForm.notes}
                                    onChange={(e) => setCreateForm({ ...createForm, notes: e.target.value })}
                                />
                            </Box>
                        </Flex>

                        <Flex justify="end" gap="3" mt="5">
                            <Button type="button" variant="soft" color="gray" onClick={() => setIsCreateOpen(false)}>Cancel</Button>
                            <Button type="submit" variant="solid" disabled={isSubmitting}>
                                {isSubmitting ? 'Registering...' : 'Register Asset'}
                            </Button>
                        </Flex>
                    </form>
                </Dialog.Content>
            </Dialog.Root>

            {/* ASSIGN MODAL */}
            <Dialog.Root open={isAssignOpen} onOpenChange={setIsAssignOpen}>
                <Dialog.Content style={{ maxWidth: 420 }}>
                    <Dialog.Title>Assign Asset to Employee</Dialog.Title>
                    <Dialog.Description size="2" mb="4">
                        Assign <Text weight="bold">{assigningAsset?.name}</Text> ({assigningAsset?.asset_code}) to an employee.
                    </Dialog.Description>

                    <Box mb="3">
                        <Text as="label" size="2" weight="bold" mb="1" style={{ display: 'block' }}>Employee ID *</Text>
                        <TextField.Root
                            placeholder="Enter employee ID (e.g. EMP-001)"
                            value={assignEmployeeId}
                            onChange={(e) => setAssignEmployeeId(e.target.value)}
                        />
                    </Box>

                    <Flex justify="end" gap="3">
                        <Button variant="soft" color="gray" onClick={() => setIsAssignOpen(false)}>Cancel</Button>
                        <Button
                            variant="solid"
                            color="indigo"
                            disabled={isAssigning || !assignEmployeeId}
                            onClick={handleAssign}
                            style={{ cursor: 'pointer' }}
                        >
                            {isAssigning ? 'Assigning...' : 'Confirm Assignment'}
                        </Button>
                    </Flex>
                </Dialog.Content>
            </Dialog.Root>

            {/* RETURN MODAL */}
            <Dialog.Root open={isReturnOpen} onOpenChange={setIsReturnOpen}>
                <Dialog.Content style={{ maxWidth: 420 }}>
                    <Dialog.Title>Return Asset</Dialog.Title>
                    <Dialog.Description size="2" mb="4">
                        Record the return of <Text weight="bold">{returningAsset?.name}</Text> ({returningAsset?.asset_code}).
                    </Dialog.Description>

                    <Box mb="3">
                        <Text as="label" size="2" weight="bold" mb="1" style={{ display: 'block' }}>Condition on Return *</Text>
                        <Select.Root value={returnCondition} onValueChange={setReturnCondition}>
                            <Select.Trigger style={{ width: '100%' }} />
                            <Select.Content>
                                <Select.Item value="good">Good</Select.Item>
                                <Select.Item value="fair">Fair</Select.Item>
                                <Select.Item value="damaged">Damaged</Select.Item>
                                <Select.Item value="lost">Lost</Select.Item>
                            </Select.Content>
                        </Select.Root>
                    </Box>

                    <Box mb="3">
                        <Text as="label" size="2" weight="bold" mb="1" style={{ display: 'block' }}>Return Notes</Text>
                        <TextArea
                            placeholder="Any damage description or notes..."
                            value={returnNotes}
                            onChange={(e) => setReturnNotes(e.target.value)}
                        />
                    </Box>

                    <Flex justify="end" gap="3">
                        <Button variant="soft" color="gray" onClick={() => setIsReturnOpen(false)}>Cancel</Button>
                        <Button
                            variant="solid"
                            color="green"
                            disabled={isReturning}
                            onClick={handleReturn}
                            style={{ cursor: 'pointer' }}
                        >
                            {isReturning ? 'Processing...' : 'Confirm Return'}
                        </Button>
                    </Flex>
                </Dialog.Content>
            </Dialog.Root>
        </>
    );
};

AssetsPage.layout = (page) => <App>{page}</App>;

export default AssetsPage;
