import React, { useState, useEffect } from 'react';
import { Head, router } from '@inertiajs/react';
import { Box, Flex, Text, Heading, Button, Badge, Table, TextField, Dialog, Select, TextArea, Card, Progress } from '@radix-ui/themes';
import {
    BriefcaseIcon,
    PlusIcon,
    BuildingOffice2Icon,
    StarIcon,
    ExclamationCircleIcon,
    PhoneIcon,
    EnvelopeIcon,
    CheckBadgeIcon,
    MagnifyingGlassIcon
} from '@heroicons/react/24/outline';
import App from '@/Layouts/App.jsx';
import { useQueryFilters } from '@/Hooks/useQueryFilters';
import { Panel } from '@/Components/ui/Panel';
import StatsCards from '@/Components/StatsCards';
import { useOperationsRealtimeRefresh } from '@/Hooks/useOperationsRealtimeRefresh';
import { showOperationMutationErrors } from './mutationFeedback';

export default function ContractorScorecards({ auth, contractors, stats, filters = {} }) {
    useOperationsRealtimeRefresh();

    const canManage = auth?.permissions?.includes('om.maintenance.manage') || auth?.roles?.includes('Super Administrator');
    const [showModal, setShowModal] = useState(false);
    const [editingContractor, setEditingContractor] = useState(null);

    // Filter states
    /* Applied filters live in the URL, so a refresh or a copied link reproduces
       this list. This page filters on an explicit "Filter" press rather than as
       you type, so the three controls below are an uncommitted draft until then. */
    const f = useQueryFilters({
        defaults: { search: '', status: 'all', trade_specialty: 'all', page: 1 },
        debounceKeys: [],
    });

    const appliedKey = JSON.stringify([f.values.search, f.values.status, f.values.trade_specialty]);
    const [search, setSearch] = useState(f.values.search);
    const [statusFilter, setStatusFilter] = useState(f.values.status);
    const [tradeFilter, setTradeFilter] = useState(f.values.trade_specialty);

    // Re-seed the controls when the applied set changes underneath them — Back,
    // Forward, or landing on a link someone shared.
    useEffect(() => {
        const [nextSearch, nextStatus, nextTrade] = JSON.parse(appliedKey);
        setSearch(nextSearch);
        setStatusFilter(nextStatus);
        setTradeFilter(nextTrade);
    }, [appliedKey]);

    // Form states
    const [companyName, setCompanyName] = useState('');
    const [tradeSpecialty, setTradeSpecialty] = useState('asphalt_paving');
    const [contactPerson, setContactPerson] = useState('');
    const [contactPhone, setContactPhone] = useState('');
    const [contactEmail, setContactEmail] = useState('');
    const [contractRef, setContractRef] = useState('');
    const [contractStartDate, setContractStartDate] = useState('');
    const [contractEndDate, setContractEndDate] = useState('');
    const [qualityScore, setQualityScore] = useState('85');
    const [status, setStatus] = useState('active');
    const [notes, setNotes] = useState('');

    const contractorList = contractors?.data || contractors || [];

    const statsData = [
        {
            title: 'Active Contractors',
            value: stats?.total_active ?? 0,
            icon: <BuildingOffice2Icon style={{ width: 22, height: 22 }} />,
            color: 'blue',
            description: 'Approved O&M maintenance vendors',
        },
        {
            title: 'Avg Quality Score',
            value: `${stats?.avg_quality_score ?? 0}%`,
            icon: <StarIcon style={{ width: 22, height: 22 }} />,
            color: 'green',
            description: 'Workmanship & QA compliance',
        },
        {
            title: 'High Performers (≥90%)',
            value: stats?.high_performers ?? 0,
            icon: <CheckBadgeIcon style={{ width: 22, height: 22 }} />,
            color: 'indigo',
            description: 'Eligible for priority work orders',
        },
        {
            title: 'Probation / Blacklisted',
            value: stats?.probation_or_blacklisted ?? 0,
            icon: <ExclamationCircleIcon style={{ width: 22, height: 22 }} />,
            color: 'red',
            description: 'Under review or restricted access',
        },
    ];

    const openCreateModal = () => {
        setEditingContractor(null);
        setCompanyName('');
        setTradeSpecialty('asphalt_paving');
        setContactPerson('');
        setContactPhone('');
        setContactEmail('');
        setContractRef('');
        setContractStartDate('');
        setContractEndDate('');
        setQualityScore('85');
        setStatus('active');
        setNotes('');
        setShowModal(true);
    };

    const openEditModal = (c) => {
        setEditingContractor(c);
        setCompanyName(c.company_name);
        setTradeSpecialty(c.trade_specialty);
        setContactPerson(c.contact_person || '');
        setContactPhone(c.contact_phone || '');
        setContactEmail(c.contact_email || '');
        setContractRef(c.contract_reference || '');
        setContractStartDate(c.contract_start_date ? c.contract_start_date.substring(0, 10) : '');
        setContractEndDate(c.contract_end_date ? c.contract_end_date.substring(0, 10) : '');
        setQualityScore(c.quality_score?.toString() || '85');
        setStatus(c.status);
        setNotes(c.notes || '');
        setShowModal(true);
    };

    const handleSave = (e) => {
        e.preventDefault();
        router.post('/om/contractors', {
            id: editingContractor?.id || null,
            company_name: companyName,
            trade_specialty: tradeSpecialty,
            contact_person: contactPerson || null,
            contact_phone: contactPhone || null,
            contact_email: contactEmail || null,
            contract_reference: contractRef || null,
            contract_start_date: contractStartDate || null,
            contract_end_date: contractEndDate || null,
            quality_score: parseFloat(qualityScore),
            status,
            notes: notes || null,
        }, {
            onSuccess: () => setShowModal(false),
            onError: showOperationMutationErrors,
        });
    };

    const handleFilter = () => f.setMany({
        search,
        status: statusFilter,
        trade_specialty: tradeFilter,
    });

    const statusBadgeColor = (s) => ({
        active: 'green',
        probation: 'amber',
        blacklisted: 'red',
        inactive: 'gray',
    }[s] || 'gray');

    const scoreColor = (score) => {
        if (score >= 90) return 'green';
        if (score >= 75) return 'blue';
        if (score >= 60) return 'amber';
        return 'red';
    };

    return (
        <App auth={auth}>
            <Head title="Contractor & Vendor Scorecards - DBEDC O&M" />

            <Box p="6">
                {/* Header */}
                <Flex justify="between" align="center" mb="5" wrap="wrap" gap="3">
                    <Box>
                        <Flex align="center" gap="2">
                            <BriefcaseIcon style={{ width: 28, height: 28, color: '#3b82f6' }} />
                            <Heading size="6">Contractor & Vendor Performance Scorecards</Heading>
                        </Flex>
                        <Text size="2" color="gray">
                            O&M vendor qualification, SLA performance metrics, and contract compliance
                        </Text>
                    </Box>

                    {canManage && (
                        <Button color="blue" onClick={openCreateModal}>
                            <PlusIcon style={{ width: 16, height: 16 }} />
                            Add Contractor
                        </Button>
                    )}
                </Flex>

                {/* Stats Cards */}
                <Box mb="5">
                    <StatsCards stats={statsData} />
                </Box>

                {/* Filters */}
                <Panel mb="4">
                    <Flex gap="3" align="center" wrap="wrap">
                        <Box style={{ flex: 1, minWidth: '220px' }}>
                            <TextField.Root
                                placeholder="Search by company or reference..."
                                value={search}
                                onChange={e => setSearch(e.target.value)}
                                onKeyDown={e => e.key === 'Enter' && handleFilter()}
                            >
                                <TextField.Slot>
                                    <MagnifyingGlassIcon style={{ width: 16, height: 16 }} />
                                </TextField.Slot>
                            </TextField.Root>
                        </Box>

                        <Select.Root value={statusFilter} onValueChange={setStatusFilter}>
                            <Select.Trigger placeholder="Status" />
                            <Select.Content>
                                <Select.Item value="all">All Statuses</Select.Item>
                                <Select.Item value="active">Active</Select.Item>
                                <Select.Item value="probation">Probation</Select.Item>
                                <Select.Item value="blacklisted">Blacklisted</Select.Item>
                                <Select.Item value="inactive">Inactive</Select.Item>
                            </Select.Content>
                        </Select.Root>

                        <Select.Root value={tradeFilter} onValueChange={setTradeFilter}>
                            <Select.Trigger placeholder="Specialty" />
                            <Select.Content>
                                <Select.Item value="all">All Specialties</Select.Item>
                                <Select.Item value="asphalt_paving">Asphalt Paving</Select.Item>
                                <Select.Item value="guardrail_repair">Guardrail Repair</Select.Item>
                                <Select.Item value="line_marking">Line Marking</Select.Item>
                                <Select.Item value="drainage_culvert">Drainage & Culverts</Select.Item>
                                <Select.Item value="bridge_expansion">Bridge & Joints</Select.Item>
                                <Select.Item value="lighting_electrical">Lighting & Electrical</Select.Item>
                                <Select.Item value="landscaping">Vegetation & Landscaping</Select.Item>
                            </Select.Content>
                        </Select.Root>

                        <Button variant="soft" color="gray" onClick={handleFilter}>
                            Filter
                        </Button>
                    </Flex>
                </Panel>

                {/* Table */}
                <Panel>
                    <Table.Root variant="surface">
                        <Table.Header>
                            <Table.Row>
                                <Table.ColumnHeaderCell>Company</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Specialty</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Contact</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Contract Term</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Quality Score</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Status</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell align="right">Actions</Table.ColumnHeaderCell>
                            </Table.Row>
                        </Table.Header>
                        <Table.Body>
                            {contractorList.map(c => (
                                <Table.Row key={c.id}>
                                    <Table.Cell>
                                        <Text weight="bold" size="2">{c.company_name}</Text>
                                        {c.contract_reference && (
                                            <Text size="1" color="gray" display="block">Ref: {c.contract_reference}</Text>
                                        )}
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Badge size="1" color="blue" variant="soft">
                                            {c.trade_specialty?.replace(/_/g, ' ')}
                                        </Badge>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Text size="2">{c.contact_person || '—'}</Text>
                                        <Flex gap="2" mt="1" align="center">
                                            {c.contact_phone && (
                                                <Flex align="center" gap="1">
                                                    <PhoneIcon style={{ width: 12, height: 12, color: 'var(--gray-9)' }} />
                                                    <Text size="1" color="gray">{c.contact_phone}</Text>
                                                </Flex>
                                            )}
                                            {c.contact_email && (
                                                <Flex align="center" gap="1">
                                                    <EnvelopeIcon style={{ width: 12, height: 12, color: 'var(--gray-9)' }} />
                                                    <Text size="1" color="gray">{c.contact_email}</Text>
                                                </Flex>
                                            )}
                                        </Flex>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Text size="1" color="gray">
                                            {c.contract_start_date?.substring(0, 10) || 'N/A'} ~ {c.contract_end_date?.substring(0, 10) || 'N/A'}
                                        </Text>
                                    </Table.Cell>
                                    <Table.Cell style={{ minWidth: 150 }}>
                                        <Flex align="center" gap="2">
                                            <Box style={{ flex: 1 }}>
                                                <Progress value={c.quality_score || 0} color={scoreColor(c.quality_score || 0)} size="2" />
                                            </Box>
                                            <Text size="2" weight="bold" color={scoreColor(c.quality_score || 0)}>
                                                {c.quality_score || 0}%
                                            </Text>
                                        </Flex>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Badge color={statusBadgeColor(c.status)}>
                                            {c.status?.toUpperCase()}
                                        </Badge>
                                    </Table.Cell>
                                    <Table.Cell align="right">
                                        {canManage && (
                                            <Button size="1" variant="ghost" color="blue" onClick={() => openEditModal(c)}>
                                                Edit
                                            </Button>
                                        )}
                                    </Table.Cell>
                                </Table.Row>
                            ))}
                            {contractorList.length === 0 && (
                                <Table.Row>
                                    <Table.Cell colSpan={7}>
                                        <Text align="center" color="gray" size="2" style={{ display: 'block', padding: '24px' }}>
                                            No contractors found matching current filters.
                                        </Text>
                                    </Table.Cell>
                                </Table.Row>
                            )}
                        </Table.Body>
                    </Table.Root>
                </Panel>

                {/* Add / Edit Contractor Modal */}
                <Dialog.Root open={showModal} onOpenChange={setShowModal}>
                    <Dialog.Content maxWidth="550px">
                        <Dialog.Title>{editingContractor ? 'Edit Contractor' : 'Register New O&M Contractor'}</Dialog.Title>
                        <Dialog.Description size="2" color="gray" mb="3">
                            Vendor profile, trade specialty, and quality performance baseline
                        </Dialog.Description>

                        <form onSubmit={handleSave}>
                            <Flex direction="column" gap="3">
                                <Box>
                                    <Text size="2" weight="bold">Company Name *</Text>
                                    <TextField.Root
                                        required
                                        placeholder="e.g., Summit Highway Infrastructures Ltd."
                                        value={companyName}
                                        onChange={e => setCompanyName(e.target.value)}
                                        mt="1"
                                    />
                                </Box>

                                <Flex gap="3">
                                    <Box style={{ flex: 1 }}>
                                        <Text size="2" weight="bold">Trade Specialty *</Text>
                                        <Select.Root value={tradeSpecialty} onValueChange={setTradeSpecialty}>
                                            <Select.Trigger style={{ width: '100%', marginTop: 4 }} />
                                            <Select.Content>
                                                <Select.Item value="asphalt_paving">Asphalt Paving</Select.Item>
                                                <Select.Item value="guardrail_repair">Guardrail Repair</Select.Item>
                                                <Select.Item value="line_marking">Line Marking</Select.Item>
                                                <Select.Item value="drainage_culvert">Drainage & Culverts</Select.Item>
                                                <Select.Item value="bridge_expansion">Bridge & Expansion Joints</Select.Item>
                                                <Select.Item value="lighting_electrical">Lighting & Electrical</Select.Item>
                                                <Select.Item value="landscaping">Vegetation & Landscaping</Select.Item>
                                            </Select.Content>
                                        </Select.Root>
                                    </Box>
                                    <Box style={{ flex: 1 }}>
                                        <Text size="2" weight="bold">Status *</Text>
                                        <Select.Root value={status} onValueChange={setStatus}>
                                            <Select.Trigger style={{ width: '100%', marginTop: 4 }} />
                                            <Select.Content>
                                                <Select.Item value="active">Active</Select.Item>
                                                <Select.Item value="probation">Probation</Select.Item>
                                                <Select.Item value="blacklisted">Blacklisted</Select.Item>
                                                <Select.Item value="inactive">Inactive</Select.Item>
                                            </Select.Content>
                                        </Select.Root>
                                    </Box>
                                </Flex>

                                <Flex gap="3">
                                    <Box style={{ flex: 1 }}>
                                        <Text size="2" weight="bold">Contact Person</Text>
                                        <TextField.Root
                                            placeholder="Engineer / PM Name"
                                            value={contactPerson}
                                            onChange={e => setContactPerson(e.target.value)}
                                            mt="1"
                                        />
                                    </Box>
                                    <Box style={{ flex: 1 }}>
                                        <Text size="2" weight="bold">Phone</Text>
                                        <TextField.Root
                                            placeholder="+880 1700-000000"
                                            value={contactPhone}
                                            onChange={e => setContactPhone(e.target.value)}
                                            mt="1"
                                        />
                                    </Box>
                                </Flex>

                                <Flex gap="3">
                                    <Box style={{ flex: 1 }}>
                                        <Text size="2" weight="bold">Email</Text>
                                        <TextField.Root
                                            type="email"
                                            placeholder="operations@contractor.com"
                                            value={contactEmail}
                                            onChange={e => setContactEmail(e.target.value)}
                                            mt="1"
                                        />
                                    </Box>
                                    <Box style={{ flex: 1 }}>
                                        <Text size="2" weight="bold">Contract Reference</Text>
                                        <TextField.Root
                                            placeholder="e.g., CNT-2026-OM-04"
                                            value={contractRef}
                                            onChange={e => setContractRef(e.target.value)}
                                            mt="1"
                                        />
                                    </Box>
                                </Flex>

                                <Flex gap="3">
                                    <Box style={{ flex: 1 }}>
                                        <Text size="2" weight="bold">Contract Start</Text>
                                        <TextField.Root
                                            type="date"
                                            value={contractStartDate}
                                            onChange={e => setContractStartDate(e.target.value)}
                                            mt="1"
                                        />
                                    </Box>
                                    <Box style={{ flex: 1 }}>
                                        <Text size="2" weight="bold">Contract End</Text>
                                        <TextField.Root
                                            type="date"
                                            value={contractEndDate}
                                            onChange={e => setContractEndDate(e.target.value)}
                                            mt="1"
                                        />
                                    </Box>
                                    <Box style={{ width: '100px' }}>
                                        <Text size="2" weight="bold">Score (0-100)</Text>
                                        <TextField.Root
                                            type="number"
                                            min="0"
                                            max="100"
                                            value={qualityScore}
                                            onChange={e => setQualityScore(e.target.value)}
                                            mt="1"
                                        />
                                    </Box>
                                </Flex>

                                <Box>
                                    <Text size="2" weight="bold">Contract & Performance Notes</Text>
                                    <TextArea
                                        placeholder="Special terms, equipment capabilities, or audit observations..."
                                        value={notes}
                                        onChange={e => setNotes(e.target.value)}
                                        rows={3}
                                        mt="1"
                                    />
                                </Box>
                            </Flex>

                            <Flex justify="end" gap="2" mt="4">
                                <Dialog.Close>
                                    <Button variant="soft" color="gray" type="button">Cancel</Button>
                                </Dialog.Close>
                                <Button color="blue" type="submit">
                                    {editingContractor ? 'Save Changes' : 'Create Contractor'}
                                </Button>
                            </Flex>
                        </form>
                    </Dialog.Content>
                </Dialog.Root>
            </Box>
        </App>
    );
}
