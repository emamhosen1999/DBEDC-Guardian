import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { Box, Flex, Text, Heading, Button, Badge, Table, TextField, Dialog, Select, TextArea, Card, Progress } from '@radix-ui/themes';
import {
    DocumentMagnifyingGlassIcon,
    PlusIcon,
    ShieldExclamationIcon,
    CurrencyDollarIcon,
    BanknotesIcon,
    TruckIcon,
    ScaleIcon,
    CheckCircleIcon,
    ClockIcon,
    ExclamationTriangleIcon,
    CalculatorIcon,
    DocumentTextIcon
} from '@heroicons/react/24/outline';
import App from '@/Layouts/App.jsx';
import { Panel } from '@/Components/ui/Panel';
import StatsCards from '@/Components/StatsCards';
import { useQueryFilters } from '@/Hooks/useQueryFilters';
import { useOperationsRealtimeRefresh } from '@/Hooks/useOperationsRealtimeRefresh';
import { showOperationMutationErrors } from './mutationFeedback';

const BOQ_PRICING = [
    { key: 'w_beam_guardrail', name: 'W-Beam Steel Guardrail (per meter)', unit: 'meter', rate: 14500 },
    { key: 'guardrail_post', name: 'Steel Post & Spacer Block (per unit)', unit: 'ea', rate: 4200 },
    { key: 'concrete_jersey_barrier', name: 'Precast Concrete Barrier (per meter)', unit: 'meter', rate: 18000 },
    { key: 'overhead_gantry_pole', name: 'Overhead Gantry / Sign Mast', unit: 'ea', rate: 45000 },
    { key: 'median_delineator', name: 'Reflective Delineator Post', unit: 'ea', rate: 1500 },
    { key: 'attenuator_crash_cushion', name: 'Impact Attenuator / Cushion', unit: 'ea', rate: 120000 },
];

export default function TppdClaimsManagement({ auth, claims, stats, filters = {} }) {
    useOperationsRealtimeRefresh();

    const canManage = auth?.permissions?.includes('om.incidents.manage') || auth?.roles?.includes('Super Administrator');
    const claimList = claims?.data || claims || [];

    // Filter states
    /* Status and search are server state, so they live in the URL. */
    const f = useQueryFilters({
        routeName: 'om.tppd',
        defaults: { status: 'all', search: '', page: 1 },
    });
    const statusFilter = f.values.status;
    const search = f.draft.search;

    // Modals
    const [showCreateModal, setShowCreateModal] = useState(false);
    const [showStatusModal, setShowStatusModal] = useState(false);
    const [showDossierModal, setShowDossierModal] = useState(false);
    const [selectedClaim, setSelectedClaim] = useState(null);

    // Create Form state
    const [incidentDate, setIncidentDate] = useState(new Date().toISOString().split('T')[0]);
    const [chainage, setChainage] = useState('Ch 14+200');
    const [direction, setDirection] = useState('northbound');
    const [vehicleReg, setVehicleReg] = useState('');
    const [driverName, setDriverName] = useState('');
    const [driverLicense, setDriverLicense] = useState('');
    const [insuranceCompany, setInsuranceCompany] = useState('');
    const [insurancePolicy, setInsurancePolicy] = useState('');
    const [policeStation, setPoliceStation] = useState('Rupganj Highway Thana');
    const [policeFir, setPoliceFir] = useState('');
    const [notes, setNotes] = useState('');

    // Dynamic BOQ quantities
    const [boqQuantities, setBoqQuantities] = useState({
        w_beam_guardrail: 0,
        guardrail_post: 0,
        concrete_jersey_barrier: 0,
        overhead_gantry_pole: 0,
        median_delineator: 0,
        attenuator_crash_cushion: 0,
    });

    // Calculate estimated repair cost
    const calculatedBoqCost = Object.entries(boqQuantities).reduce((acc, [key, qty]) => {
        const item = BOQ_PRICING.find(p => p.key === key);
        return acc + (item ? item.rate * (Number(qty) || 0) : 0);
    }, 0);

    // Status update modal state
    const [newStatus, setNewStatus] = useState('settled');
    const [recoveredAmount, setRecoveredAmount] = useState('');
    const [statusNotes, setStatusNotes] = useState('');

    const statsData = [
        {
            title: 'Total Crash Claims',
            value: stats?.total_claims ?? 0,
            icon: <ShieldExclamationIcon style={{ width: 22, height: 22 }} />,
            color: 'blue',
            description: 'Logged third-party property damages',
        },
        {
            title: 'Claimed Recovery (BDT)',
            value: `৳${((stats?.total_claimed_amount ?? 0) / 1000).toFixed(1)}k`,
            icon: <CurrencyDollarIcon style={{ width: 22, height: 22 }} />,
            color: 'indigo',
            description: 'Valued via N-105 standard BOQ rates',
        },
        {
            title: 'Recovered Amount (BDT)',
            value: `৳${((stats?.total_recovered_amount ?? 0) / 1000).toFixed(1)}k`,
            icon: <BanknotesIcon style={{ width: 22, height: 22 }} />,
            color: 'green',
            description: 'Settled from insurance & vehicle owners',
        },
        {
            title: 'Recovery Settlement Rate',
            value: `${stats?.recovery_rate_percentage ?? 0}%`,
            icon: <CheckCircleIcon style={{ width: 22, height: 22 }} />,
            color: 'amber',
            description: 'Cost recovery efficiency index',
        },
    ];

    const handleFilterChange = (status) => f.set('status', status);

    // Search commits on its own after a pause; submitting just skips the wait.
    const handleSearchSubmit = (e) => {
        e.preventDefault();
        f.setMany({ search: f.draft.search });
    };

    const openCreateModal = () => {
        setIncidentDate(new Date().toISOString().split('T')[0]);
        setChainage('Ch 14+200');
        setDirection('northbound');
        setVehicleReg('');
        setDriverName('');
        setDriverLicense('');
        setInsuranceCompany('');
        setInsurancePolicy('');
        setPoliceStation('Rupganj Highway Thana');
        setPoliceFir('');
        setNotes('');
        setBoqQuantities({
            w_beam_guardrail: 4,
            guardrail_post: 2,
            concrete_jersey_barrier: 0,
            overhead_gantry_pole: 0,
            median_delineator: 0,
            attenuator_crash_cushion: 0,
        });
        setShowCreateModal(true);
    };

    const handleCreateClaim = () => {
        const damagedItems = Object.entries(boqQuantities)
            .filter(([_, qty]) => Number(qty) > 0)
            .map(([key, qty]) => {
                const item = BOQ_PRICING.find(p => p.key === key);
                return {
                    item: item.name,
                    quantity: Number(qty),
                    unit: item.unit,
                    rate: item.rate,
                    total: item.rate * Number(qty),
                };
            });

        const payload = {
            incident_date: incidentDate,
            chainage,
            direction,
            vehicle_registration_number: vehicleReg,
            driver_name: driverName,
            driver_license_number: driverLicense,
            insurance_company: insuranceCompany,
            insurance_policy_number: insurancePolicy,
            police_station: policeStation,
            police_fir_number: policeFir,
            damaged_components: damagedItems,
            estimated_repair_cost: calculatedBoqCost,
            claimed_amount: calculatedBoqCost,
            status: 'drafted',
            notes,
        };

        router.post(route('om.tppd.store'), payload, {
            onSuccess: () => setShowCreateModal(false),
            onError: (errs) => showOperationMutationErrors(errs),
        });
    };

    const openStatusModal = (claim) => {
        setSelectedClaim(claim);
        setNewStatus(claim.status);
        setRecoveredAmount(claim.recovered_amount ? String(claim.recovered_amount) : String(claim.claimed_amount || ''));
        setStatusNotes(claim.notes || '');
        setShowStatusModal(true);
    };

    const handleUpdateStatus = () => {
        if (!selectedClaim) return;
        router.post(route('om.tppd.status', selectedClaim.id), {
            status: newStatus,
            recovered_amount: Number(recoveredAmount) || 0,
            notes: statusNotes,
        }, {
            onSuccess: () => setShowStatusModal(false),
            onError: (errs) => showOperationMutationErrors(errs),
        });
    };

    const openDossierModal = (claim) => {
        setSelectedClaim(claim);
        setShowDossierModal(true);
    };

    const getStatusBadge = (status) => {
        const config = {
            drafted: { color: 'gray', label: 'Drafted' },
            submitted_police: { color: 'blue', label: 'FIR Filed' },
            submitted_insurance: { color: 'purple', label: 'Insurance Claimed' },
            settled: { color: 'green', label: 'Settled & Recovered' },
            disputed: { color: 'amber', label: 'Under Dispute' },
            written_off: { color: 'red', label: 'Written Off' },
        };
        const c = config[status] || { color: 'gray', label: status };
        return <Badge color={c.color} variant="surface">{c.label}</Badge>;
    };

    return (
        <App auth={auth}>
            <Head title="Third-Party Property Damage (TPPD) - O&M" />
            <Box p={{ initial: '3', md: '6' }} style={{ maxWidth: 1400, margin: '0 auto' }}>
                {/* Header */}
                <Flex justify="between" align={{ initial: 'start', sm: 'center' }} direction={{ initial: 'column', sm: 'row' }} gap="4" mb="5">
                    <Box>
                        <Flex align="center" gap="2" mb="1">
                            <Heading size="6" weight="bold">Third-Party Property Damage (TPPD) Recovery</Heading>
                            <Badge color="crimson" variant="soft">Crash Cost Recovery</Badge>
                        </Flex>
                        <Text size="2" color="gray">
                            Enforce Bangladesh expressway asset liability. Recover roadside barrier, gantry, and sign crash damages via police FIR dossiers and insurance settlements.
                        </Text>
                    </Box>
                    {canManage && (
                        <Button onClick={openCreateModal} size="3" variant="solid" color="indigo" style={{ cursor: 'pointer' }}>
                            <PlusIcon style={{ width: 18, height: 18 }} />
                            File Crash Damage Claim
                        </Button>
                    )}
                </Flex>

                {/* KPI Metrics */}
                <Box mb="5">
                    <StatsCards stats={statsData} />
                </Box>

                {/* Filters and Controls */}
                <Panel mb="4">
                    <Flex justify="between" align="center" direction={{ initial: 'column', sm: 'row' }} gap="3">
                        <Flex gap="3" align="center" wrap="wrap" style={{ flex: 1 }}>
                            <form onSubmit={handleSearchSubmit} style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
                                <TextField.Root
                                    placeholder="Search by claim #, vehicle plate, or driver..."
                                    value={search}
                                    onChange={(e) => f.setDraft('search', e.target.value)}
                                    size="2"
                                    style={{ width: 300 }}
                                />
                                <Button type="submit" variant="soft" size="2">Search</Button>
                            </form>
                            <Box>
                                <Select.Root value={statusFilter} onValueChange={handleFilterChange}>
                                    <Select.Trigger placeholder="Filter by status" />
                                    <Select.Content>
                                        <Select.Item value="all">All Statuses</Select.Item>
                                        <Select.Item value="drafted">Drafted</Select.Item>
                                        <Select.Item value="submitted_police">Police FIR Filed</Select.Item>
                                        <Select.Item value="submitted_insurance">Insurance Submitted</Select.Item>
                                        <Select.Item value="settled">Settled</Select.Item>
                                        <Select.Item value="disputed">Disputed</Select.Item>
                                        <Select.Item value="written_off">Written Off</Select.Item>
                                    </Select.Content>
                                </Select.Root>
                            </Box>
                        </Flex>
                        <Badge color="gray" variant="surface">
                            {claimList.length} Active Records
                        </Badge>
                    </Flex>
                </Panel>

                {/* Claims Table */}
                <Panel>
                    <Box style={{ overflowX: 'auto' }}>
                        <Table.Root variant="surface">
                            <Table.Header>
                                <Table.Row>
                                    <Table.ColumnHeaderCell>Claim No.</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell>Incident Date</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell>Location</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell>Vehicle & Driver</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell>Police FIR & Insurer</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell>Claimed / Recovered</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell>Status</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell align="right">Actions</Table.ColumnHeaderCell>
                                </Table.Row>
                            </Table.Header>
                            <Table.Body>
                                {claimList.length === 0 ? (
                                    <Table.Row>
                                        <Table.Cell colSpan={8}>
                                            <Flex direction="column" align="center" justify="center" py="6" gap="2">
                                                <DocumentMagnifyingGlassIcon style={{ width: 36, height: 36, color: 'gray' }} />
                                                <Text size="2" color="gray">No TPPD crash claims found for the selected filter.</Text>
                                            </Flex>
                                        </Table.Cell>
                                    </Table.Row>
                                ) : (
                                    claimList.map((claim) => (
                                        <Table.Row key={claim.id}>
                                            <Table.Cell>
                                                <Flex direction="column">
                                                    <Text weight="bold" size="2">{claim.claim_number}</Text>
                                                    {claim.incident_ref && (
                                                        <Text size="1" color="gray">Incident #{claim.incident_ref}</Text>
                                                    )}
                                                </Flex>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Text size="2">{claim.incident_date}</Text>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Flex direction="column">
                                                    <Text size="2" weight="medium">{claim.chainage || 'N/A'}</Text>
                                                    <Text size="1" color="gray">{claim.direction || 'Bypass'}</Text>
                                                </Flex>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Flex direction="column">
                                                    <Text size="2" weight="bold" color="indigo">{claim.vehicle_registration_number}</Text>
                                                    <Text size="1" color="gray">{claim.driver_name || 'Driver Not Identified'}</Text>
                                                </Flex>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Flex direction="column">
                                                    <Text size="2">{claim.police_station ? `${claim.police_station} (FIR: ${claim.police_fir_number || 'Pending'})` : 'No FIR filed'}</Text>
                                                    <Text size="1" color="gray">{claim.insurance_company || 'Uninsured / Self-pay'}</Text>
                                                </Flex>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Flex direction="column">
                                                    <Text size="2" weight="bold">৳{(claim.claimed_amount || 0).toLocaleString()}</Text>
                                                    <Text size="1" color="green">Rec: ৳{(claim.recovered_amount || 0).toLocaleString()}</Text>
                                                </Flex>
                                            </Table.Cell>
                                            <Table.Cell>
                                                {getStatusBadge(claim.status)}
                                            </Table.Cell>
                                            <Table.Cell align="right">
                                                <Flex justify="end" gap="2">
                                                    <Button size="1" variant="soft" color="gray" onClick={() => openDossierModal(claim)}>
                                                        Dossier
                                                    </Button>
                                                    {canManage && (
                                                        <Button size="1" variant="soft" color="blue" onClick={() => openStatusModal(claim)}>
                                                            Update
                                                        </Button>
                                                    )}
                                                </Flex>
                                            </Table.Cell>
                                        </Table.Row>
                                    ))
                                )}
                            </Table.Body>
                        </Table.Root>
                    </Box>
                </Panel>

                {/* Create Claim Modal with Integrated BOQ Calculator */}
                <Dialog.Root open={showCreateModal} onOpenChange={setShowCreateModal}>
                    <Dialog.Content style={{ maxWidth: 750 }}>
                        <Dialog.Title>File Third-Party Property Damage (TPPD) Claim</Dialog.Title>
                        <Dialog.Description size="2" mb="4">
                            Assess crash damage to Dhaka Bypass Expressway infrastructure. Calculate recovery invoice using official BOQ replacement schedules.
                        </Dialog.Description>

                        <Flex direction="column" gap="3">
                            <Flex gap="3" direction={{ initial: 'column', sm: 'row' }}>
                                <Box style={{ flex: 1 }}>
                                    <Text size="2" weight="medium" mb="1">Incident Date *</Text>
                                    <TextField.Root type="date" value={incidentDate} onChange={(e) => setIncidentDate(e.target.value)} />
                                </Box>
                                <Box style={{ flex: 1 }}>
                                    <Text size="2" weight="medium" mb="1">Chainage (e.g., Ch 14+200) *</Text>
                                    <TextField.Root value={chainage} onChange={(e) => setChainage(e.target.value)} />
                                </Box>
                                <Box style={{ flex: 1 }}>
                                    <Text size="2" weight="medium" mb="1">Direction</Text>
                                    <Select.Root value={direction} onValueChange={setDirection}>
                                        <Select.Trigger />
                                        <Select.Content>
                                            <Select.Item value="northbound">Northbound (Joydebpur bound)</Select.Item>
                                            <Select.Item value="southbound">Southbound (Madanpur bound)</Select.Item>
                                        </Select.Content>
                                    </Select.Root>
                                </Box>
                            </Flex>

                            <Flex gap="3" direction={{ initial: 'column', sm: 'row' }}>
                                <Box style={{ flex: 1 }}>
                                    <Text size="2" weight="medium" mb="1">Vehicle Plate / Registration *</Text>
                                    <TextField.Root placeholder="e.g. Dhaka Metro-Ta 11-4567" value={vehicleReg} onChange={(e) => setVehicleReg(e.target.value)} />
                                </Box>
                                <Box style={{ flex: 1 }}>
                                    <Text size="2" weight="medium" mb="1">Driver Full Name</Text>
                                    <TextField.Root placeholder="Driver name" value={driverName} onChange={(e) => setDriverName(e.target.value)} />
                                </Box>
                                <Box style={{ flex: 1 }}>
                                    <Text size="2" weight="medium" mb="1">Driver License No.</Text>
                                    <TextField.Root placeholder="Driving License #" value={driverLicense} onChange={(e) => setDriverLicense(e.target.value)} />
                                </Box>
                            </Flex>

                            <Flex gap="3" direction={{ initial: 'column', sm: 'row' }}>
                                <Box style={{ flex: 1 }}>
                                    <Text size="2" weight="medium" mb="1">Insurance Provider</Text>
                                    <TextField.Root placeholder="e.g. Green Delta Insurance" value={insuranceCompany} onChange={(e) => setInsuranceCompany(e.target.value)} />
                                </Box>
                                <Box style={{ flex: 1 }}>
                                    <Text size="2" weight="medium" mb="1">Policy Number</Text>
                                    <TextField.Root placeholder="Policy #" value={insurancePolicy} onChange={(e) => setInsurancePolicy(e.target.value)} />
                                </Box>
                            </Flex>

                            <Flex gap="3" direction={{ initial: 'column', sm: 'row' }}>
                                <Box style={{ flex: 1 }}>
                                    <Text size="2" weight="medium" mb="1">Highway Police Thana</Text>
                                    <TextField.Root value={policeStation} onChange={(e) => setPoliceStation(e.target.value)} />
                                </Box>
                                <Box style={{ flex: 1 }}>
                                    <Text size="2" weight="medium" mb="1">Police FIR / GD Number</Text>
                                    <TextField.Root placeholder="GD / Case Ref #" value={policeFir} onChange={(e) => setPoliceFir(e.target.value)} />
                                </Box>
                            </Flex>

                            {/* BOQ Calculator Subpanel */}
                            <Card style={{ backgroundColor: 'var(--gray-2)', border: '1px solid var(--gray-5)' }}>
                                <Flex justify="between" align="center" mb="2">
                                    <Flex align="center" gap="2">
                                        <CalculatorIcon style={{ width: 18, height: 18, color: 'var(--indigo-9)' }} />
                                        <Text size="2" weight="bold">Damaged Infrastructure Items (BOQ Rate Calculator)</Text>
                                    </Flex>
                                    <Badge size="2" color="indigo" variant="solid">
                                        Total Estimated: ৳{calculatedBoqCost.toLocaleString()} BDT
                                    </Badge>
                                </Flex>

                                <Box style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))', gap: 10 }}>
                                    {BOQ_PRICING.map((item) => (
                                        <Flex key={item.key} justify="between" align="center" p="2" style={{ background: 'var(--color-background)', borderRadius: 6, border: '1px solid var(--gray-4)' }}>
                                            <Box style={{ maxWidth: '65%' }}>
                                                <Text size="1" weight="medium">{item.name}</Text>
                                                <Text size="1" color="gray">৳{item.rate.toLocaleString()} / {item.unit}</Text>
                                            </Box>
                                            <TextField.Root
                                                type="number"
                                                min="0"
                                                value={boqQuantities[item.key] || 0}
                                                onChange={(e) => setBoqQuantities({ ...boqQuantities, [item.key]: Number(e.target.value) })}
                                                size="1"
                                                style={{ width: 70 }}
                                            />
                                        </Flex>
                                    ))}
                                </Box>
                            </Card>

                            <Box>
                                <Text size="2" weight="medium" mb="1">Incident Notes / Circumstances</Text>
                                <TextArea placeholder="Describe the crash dynamics, damage extent, and tow-away details..." value={notes} onChange={(e) => setNotes(e.target.value)} rows={2} />
                            </Box>
                        </Flex>

                        <Flex justify="end" gap="3" mt="4">
                            <Dialog.Close>
                                <Button variant="soft" color="gray">Cancel</Button>
                            </Dialog.Close>
                            <Button variant="solid" color="indigo" onClick={handleCreateClaim} disabled={!vehicleReg}>
                                Submit TPPD Claim
                            </Button>
                        </Flex>
                    </Dialog.Content>
                </Dialog.Root>

                {/* Update Status & Recovered Amount Modal */}
                <Dialog.Root open={showStatusModal} onOpenChange={setShowStatusModal}>
                    <Dialog.Content style={{ maxWidth: 500 }}>
                        <Dialog.Title>Update Claim Status #{selectedClaim?.claim_number}</Dialog.Title>
                        <Dialog.Description size="2" mb="3">
                            Record settlement, recovery payments received, or dispute progress.
                        </Dialog.Description>

                        <Flex direction="column" gap="3">
                            <Box>
                                <Text size="2" weight="medium" mb="1">Claim Status</Text>
                                <Select.Root value={newStatus} onValueChange={setNewStatus}>
                                    <Select.Trigger />
                                    <Select.Content>
                                        <Select.Item value="drafted">Drafted</Select.Item>
                                        <Select.Item value="submitted_police">Submitted to Police (FIR)</Select.Item>
                                        <Select.Item value="submitted_insurance">Submitted to Insurance</Select.Item>
                                        <Select.Item value="settled">Settled (Funds Received)</Select.Item>
                                        <Select.Item value="disputed">Disputed / Legal Action</Select.Item>
                                        <Select.Item value="written_off">Written Off</Select.Item>
                                    </Select.Content>
                                </Select.Root>
                            </Box>

                            <Box>
                                <Text size="2" weight="medium" mb="1">Recovered Amount (BDT)</Text>
                                <TextField.Root
                                    type="number"
                                    min="0"
                                    placeholder="Enter settled recovery amount"
                                    value={recoveredAmount}
                                    onChange={(e) => setRecoveredAmount(e.target.value)}
                                />
                                <Text size="1" color="gray" mt="1">
                                    Original Claimed Amount: ৳{(selectedClaim?.claimed_amount || 0).toLocaleString()}
                                </Text>
                            </Box>

                            <Box>
                                <Text size="2" weight="medium" mb="1">Settlement / Legal Notes</Text>
                                <TextArea placeholder="Pay order details, bank voucher, or court proceeding summary..." value={statusNotes} onChange={(e) => setStatusNotes(e.target.value)} rows={3} />
                            </Box>
                        </Flex>

                        <Flex justify="end" gap="3" mt="4">
                            <Dialog.Close>
                                <Button variant="soft" color="gray">Cancel</Button>
                            </Dialog.Close>
                            <Button variant="solid" color="blue" onClick={handleUpdateStatus}>
                                Save Status Update
                            </Button>
                        </Flex>
                    </Dialog.Content>
                </Dialog.Root>

                {/* Legal Dossier Modal */}
                <Dialog.Root open={showDossierModal} onOpenChange={setShowDossierModal}>
                    <Dialog.Content style={{ maxWidth: 650 }}>
                        <Dialog.Title>TPPD Claim Dossier #{selectedClaim?.claim_number}</Dialog.Title>
                        <Dialog.Description size="2" mb="3">
                            Official legal dossier for submission to Highway Police and Motor Insurance Claim Tribunal.
                        </Dialog.Description>

                        {selectedClaim && (
                            <Flex direction="column" gap="3">
                                <Card>
                                    <Flex justify="between" mb="2">
                                        <Text size="2" weight="bold">Vehicle Registration:</Text>
                                        <Badge size="2" color="indigo">{selectedClaim.vehicle_registration_number}</Badge>
                                    </Flex>
                                    <Flex justify="between" mb="2">
                                        <Text size="2" weight="medium">Location & Chainage:</Text>
                                        <Text size="2">{selectedClaim.chainage} ({selectedClaim.direction})</Text>
                                    </Flex>
                                    <Flex justify="between" mb="2">
                                        <Text size="2" weight="medium">Driver Name:</Text>
                                        <Text size="2">{selectedClaim.driver_name || 'N/A'}</Text>
                                    </Flex>
                                    <Flex justify="between" mb="2">
                                        <Text size="2" weight="medium">Police FIR / GD:</Text>
                                        <Text size="2">{selectedClaim.police_station} - {selectedClaim.police_fir_number || 'N/A'}</Text>
                                    </Flex>
                                    <Flex justify="between">
                                        <Text size="2" weight="medium">Insurer & Policy:</Text>
                                        <Text size="2">{selectedClaim.insurance_company || 'Uninsured'} ({selectedClaim.insurance_policy_number || 'N/A'})</Text>
                                    </Flex>
                                </Card>

                                <Card>
                                    <Text size="2" weight="bold" mb="2">Damaged Components Schedule:</Text>
                                    {Array.isArray(selectedClaim.damaged_components) && selectedClaim.damaged_components.length > 0 ? (
                                        <Table.Root variant="surface">
                                            <Table.Header>
                                                <Table.Row>
                                                    <Table.ColumnHeaderCell>Item</Table.ColumnHeaderCell>
                                                    <Table.ColumnHeaderCell>Qty</Table.ColumnHeaderCell>
                                                    <Table.ColumnHeaderCell>Rate</Table.ColumnHeaderCell>
                                                    <Table.ColumnHeaderCell align="right">Total</Table.ColumnHeaderCell>
                                                </Table.Row>
                                            </Table.Header>
                                            <Table.Body>
                                                {selectedClaim.damaged_components.map((comp, idx) => (
                                                    <Table.Row key={idx}>
                                                        <Table.Cell><Text size="2">{comp.item}</Text></Table.Cell>
                                                        <Table.Cell><Text size="2">{comp.quantity} {comp.unit}</Text></Table.Cell>
                                                        <Table.Cell><Text size="2">৳{(comp.rate || 0).toLocaleString()}</Text></Table.Cell>
                                                        <Table.Cell align="right"><Text size="2" weight="bold">৳{(comp.total || 0).toLocaleString()}</Text></Table.Cell>
                                                    </Table.Row>
                                                ))}
                                            </Table.Body>
                                        </Table.Root>
                                    ) : (
                                        <Text size="2" color="gray">General structural impact claim (lump-sum assessment: ৳{(selectedClaim.estimated_repair_cost || 0).toLocaleString()}).</Text>
                                    )}
                                </Card>

                                <Flex justify="between" align="center" p="2" style={{ background: 'var(--green-3)', borderRadius: 6 }}>
                                    <Text size="2" weight="bold" color="green">Total Claim Recovery Assessment:</Text>
                                    <Heading size="4" color="green">৳{(selectedClaim.claimed_amount || 0).toLocaleString()} BDT</Heading>
                                </Flex>
                            </Flex>
                        )}

                        <Flex justify="end" mt="4">
                            <Dialog.Close>
                                <Button variant="soft" color="gray">Close Dossier</Button>
                            </Dialog.Close>
                        </Flex>
                    </Dialog.Content>
                </Dialog.Root>
            </Box>
        </App>
    );
}
