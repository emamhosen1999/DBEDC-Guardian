import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { Box, Flex, Text, Heading, Button, Badge, Table, TextField, Dialog, Select, TextArea, Card, Checkbox, Tooltip } from '@radix-ui/themes';
import {
    SparklesIcon,
    CameraIcon,
    WrenchScrewdriverIcon,
    CheckCircleIcon,
    XCircleIcon,
    ExclamationTriangleIcon,
    MapPinIcon,
    EyeIcon,
    AdjustmentsHorizontalIcon,
    ArrowPathIcon
} from '@heroicons/react/24/outline';
import App from '@/Layouts/App.jsx';
import { Panel } from '@/Components/ui/Panel';
import StatsCards from '@/Components/StatsCards';
import { useOperationsRealtimeRefresh } from '@/Hooks/useOperationsRealtimeRefresh';
import { showOperationMutationErrors } from './mutationFeedback';

export default function AiDistressQueue({ auth, detections, stats, filters = {} }) {
    useOperationsRealtimeRefresh();

    const canManage = auth?.permissions?.includes('om.maintenance.manage') || auth?.roles?.includes('Super Administrator');
    const detectionList = detections?.data || detections || [];

    // Filter states
    const [statusFilter, setStatusFilter] = useState(filters?.status || 'all');
    const [typeFilter, setTypeFilter] = useState(filters?.distress_type || 'all');
    const [search, setSearch] = useState(filters?.search || '');

    // Selection for batch conversion
    const [selectedIds, setSelectedIds] = useState([]);

    // Modals
    const [showBatchModal, setShowBatchModal] = useState(false);
    const [showInspectorModal, setShowInspectorModal] = useState(false);
    const [inspectingItem, setInspectingItem] = useState(null);
    const [rejectReason, setRejectReason] = useState('');
    const [showRejectModal, setShowRejectModal] = useState(false);
    const [rejectingItem, setRejectingItem] = useState(null);

    // Batch Work Order form
    const [woTitle, setWoTitle] = useState('Batch AI Repair: Asphalt Mill & Patching');
    const [woPriority, setWoPriority] = useState('high');
    const [woChainage, setWoChainage] = useState('Ch 14+000 - Ch 16+000');
    const [woDescription, setWoDescription] = useState('Unified maintenance order generated from verified Edge-AI dashcam detections.');

    const statsData = [
        {
            title: 'AI Detections Logged',
            value: stats?.total_detections ?? 0,
            icon: <SparklesIcon style={{ width: 22, height: 22 }} />,
            color: 'indigo',
            description: 'YOLOv8/v11 on-device edge inference',
        },
        {
            title: 'Pending Engineering Review',
            value: stats?.pending_review ?? 0,
            icon: <ExclamationTriangleIcon style={{ width: 22, height: 22 }} />,
            color: 'amber',
            description: 'Awaiting engineer verification',
        },
        {
            title: 'Converted to Work Orders',
            value: stats?.approved_work_orders ?? 0,
            icon: <CheckCircleIcon style={{ width: 22, height: 22 }} />,
            color: 'green',
            description: 'Batch grouped into repair tasks',
        },
        {
            title: 'Mean AI Confidence',
            value: `${stats?.mean_confidence_score ?? 91.4}%`,
            icon: <CameraIcon style={{ width: 22, height: 22 }} />,
            color: 'blue',
            description: 'Distress classification accuracy',
        },
    ];

    const handleFilterChange = (key, val) => {
        const nextFilters = {
            status: key === 'status' ? val : statusFilter,
            distress_type: key === 'type' ? val : typeFilter,
            search,
        };
        if (key === 'status') setStatusFilter(val);
        if (key === 'type') setTypeFilter(val);
        router.get(route('om.ai-distress'), nextFilters, { preserveState: true, replace: true });
    };

    const handleSearchSubmit = (e) => {
        e.preventDefault();
        router.get(route('om.ai-distress'), { status: statusFilter, distress_type: typeFilter, search }, { preserveState: true, replace: true });
    };

    const toggleSelect = (id) => {
        setSelectedIds(prev => prev.includes(id) ? prev.filter(x => x !== id) : [...prev, id]);
    };

    const selectAllPending = () => {
        const pendingIds = detectionList.filter(d => d.status === 'pending_review').map(d => d.id);
        if (selectedIds.length === pendingIds.length) {
            setSelectedIds([]);
        } else {
            setSelectedIds(pendingIds);
        }
    };

    const openBatchModal = () => {
        if (selectedIds.length === 0) return;
        setWoTitle(`Batch AI Repair: ${selectedIds.length} Verified Distress Sites`);
        setShowBatchModal(true);
    };

    const handleBatchConvert = () => {
        router.post(route('om.ai-distress.convert'), {
            detection_ids: selectedIds,
            title: woTitle,
            priority: woPriority,
            chainage: woChainage,
            description: woDescription,
        }, {
            onSuccess: () => {
                setShowBatchModal(false);
                setSelectedIds([]);
            },
            onError: (errs) => showOperationMutationErrors(errs),
        });
    };

    const openRejectModal = (item) => {
        setRejectingItem(item);
        setRejectReason('Shadow / oil stain artifact misinterpreted as defect');
        setShowRejectModal(true);
    };

    const handleReject = () => {
        if (!rejectingItem) return;
        router.post(route('om.ai-distress.reject', rejectingItem.id), {
            reason: rejectReason,
        }, {
            onSuccess: () => {
                setShowRejectModal(false);
                setRejectingItem(null);
            },
            onError: (errs) => showOperationMutationErrors(errs),
        });
    };

    const openInspector = (item) => {
        setInspectingItem(item);
        setShowInspectorModal(true);
    };

    const getSeverityBadge = (sev) => {
        const config = {
            low: { color: 'green', label: 'Low' },
            medium: { color: 'amber', label: 'Medium' },
            high: { color: 'orange', label: 'High' },
            critical: { color: 'red', label: 'Critical' },
        };
        const c = config[sev] || { color: 'gray', label: sev };
        return <Badge color={c.color} variant="solid">{c.label}</Badge>;
    };

    const getStatusBadge = (status) => {
        const config = {
            pending_review: { color: 'amber', label: 'Pending Review' },
            approved_work_order: { color: 'green', label: 'Work Order Created' },
            rejected_false_positive: { color: 'gray', label: 'False Positive' },
            auto_converted: { color: 'indigo', label: 'Auto Converted' },
        };
        const c = config[status] || { color: 'gray', label: status };
        return <Badge color={c.color} variant="surface">{c.label}</Badge>;
    };

    return (
        <App auth={auth}>
            <Head title="AI Road Defect Vision - O&M" />
            <Box p={{ initial: '3', md: '6' }} style={{ maxWidth: 1400, margin: '0 auto' }}>
                {/* Header */}
                <Flex justify="between" align={{ initial: 'start', sm: 'center' }} direction={{ initial: 'column', sm: 'row' }} gap="4" mb="5">
                    <Box>
                        <Flex align="center" gap="2" mb="1">
                            <Heading size="6" weight="bold">AI Road Defect Vision & Ingestion Queue</Heading>
                            <Badge color="purple" variant="soft">YOLOv8/v11 Edge Dashcam Inference</Badge>
                        </Flex>
                        <Text size="2" color="gray">
                            Automated computer vision detection streamed from smartphone mounts during highway patrol. Batch convert verified defects into unified maintenance work orders.
                        </Text>
                    </Box>
                    {canManage && (
                        <Button
                            onClick={openBatchModal}
                            disabled={selectedIds.length === 0}
                            size="3"
                            variant="solid"
                            color="indigo"
                            style={{ cursor: selectedIds.length === 0 ? 'not-allowed' : 'pointer' }}
                        >
                            <WrenchScrewdriverIcon style={{ width: 18, height: 18 }} />
                            Batch Convert ({selectedIds.length}) to Work Order
                        </Button>
                    )}
                </Flex>

                {/* KPI Metrics */}
                <Box mb="5">
                    <StatsCards stats={statsData} />
                </Box>

                {/* Filters & Control Panel */}
                <Panel mb="4">
                    <Flex justify="between" align="center" direction={{ initial: 'column', sm: 'row' }} gap="3" wrap="wrap">
                        <Flex gap="3" align="center" wrap="wrap" style={{ flex: 1 }}>
                            <form onSubmit={handleSearchSubmit} style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
                                <TextField.Root
                                    placeholder="Search code, chainage, or notes..."
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    size="2"
                                    style={{ width: 280 }}
                                />
                                <Button type="submit" variant="soft" size="2">Search</Button>
                            </form>
                            <Box>
                                <Select.Root value={statusFilter} onValueChange={(val) => handleFilterChange('status', val)}>
                                    <Select.Trigger placeholder="Filter by status" />
                                    <Select.Content>
                                        <Select.Item value="all">All Statuses</Select.Item>
                                        <Select.Item value="pending_review">Pending Review</Select.Item>
                                        <Select.Item value="approved_work_order">Converted to Work Order</Select.Item>
                                        <Select.Item value="rejected_false_positive">Rejected False Positives</Select.Item>
                                    </Select.Content>
                                </Select.Root>
                            </Box>
                            <Box>
                                <Select.Root value={typeFilter} onValueChange={(val) => handleFilterChange('type', val)}>
                                    <Select.Trigger placeholder="Distress type" />
                                    <Select.Content>
                                        <Select.Item value="all">All Distress Types</Select.Item>
                                        <Select.Item value="pothole">Pothole</Select.Item>
                                        <Select.Item value="alligator_crack">Alligator / Fatigue Crack</Select.Item>
                                        <Select.Item value="longitudinal_crack">Longitudinal Crack</Select.Item>
                                        <Select.Item value="rutting">Rutting</Select.Item>
                                        <Select.Item value="road_debris">Road Debris / Obstruction</Select.Item>
                                    </Select.Content>
                                </Select.Root>
                            </Box>
                        </Flex>
                        {canManage && (
                            <Button size="1" variant="ghost" color="indigo" onClick={selectAllPending}>
                                {selectedIds.length > 0 ? 'Deselect All' : 'Select All Pending'}
                            </Button>
                        )}
                    </Flex>
                </Panel>

                {/* Detections Queue Table */}
                <Panel>
                    <Box style={{ overflowX: 'auto' }}>
                        <Table.Root variant="surface">
                            <Table.Header>
                                <Table.Row>
                                    {canManage && <Table.ColumnHeaderCell style={{ width: 40 }}></Table.ColumnHeaderCell>}
                                    <Table.ColumnHeaderCell>Detection Code</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell>Distress Classification</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell>AI Confidence</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell>Location / Chainage</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell>Est. Area</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell>Severity</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell>Status</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell align="right">Actions</Table.ColumnHeaderCell>
                                </Table.Row>
                            </Table.Header>
                            <Table.Body>
                                {detectionList.length === 0 ? (
                                    <Table.Row>
                                        <Table.Cell colSpan={9}>
                                            <Flex direction="column" align="center" justify="center" py="6" gap="2">
                                                <CameraIcon style={{ width: 36, height: 36, color: 'gray' }} />
                                                <Text size="2" color="gray">No AI distress detections match the active filter criteria.</Text>
                                            </Flex>
                                        </Table.Cell>
                                    </Table.Row>
                                ) : (
                                    detectionList.map((item) => {
                                        const isSelected = selectedIds.includes(item.id);
                                        return (
                                            <Table.Row key={item.id} style={{ backgroundColor: isSelected ? 'var(--indigo-2)' : undefined }}>
                                                {canManage && (
                                                    <Table.Cell>
                                                        {item.status === 'pending_review' ? (
                                                            <Checkbox
                                                                checked={isSelected}
                                                                onCheckedChange={() => toggleSelect(item.id)}
                                                            />
                                                        ) : (
                                                            <Box style={{ width: 16 }} />
                                                        )}
                                                    </Table.Cell>
                                                )}
                                                <Table.Cell>
                                                    <Flex direction="column">
                                                        <Text weight="bold" size="2">{item.detection_code}</Text>
                                                        <Text size="1" color="gray">{item.created_at || 'Recent patrol'}</Text>
                                                    </Flex>
                                                </Table.Cell>
                                                <Table.Cell>
                                                    <Text size="2" weight="bold" style={{ textTransform: 'capitalize' }}>
                                                        {item.distress_type?.replace(/_/g, ' ')}
                                                    </Text>
                                                </Table.Cell>
                                                <Table.Cell>
                                                    <Badge color={item.confidence_score >= 0.90 ? 'green' : 'blue'} variant="surface">
                                                        {Math.round(item.confidence_score * 100)}% Match
                                                    </Badge>
                                                </Table.Cell>
                                                <Table.Cell>
                                                    <Flex align="center" gap="1">
                                                        <MapPinIcon style={{ width: 16, height: 16, color: 'gray' }} />
                                                        <Text size="2">{item.chainage || 'KM 00+000'} ({item.direction})</Text>
                                                    </Flex>
                                                </Table.Cell>
                                                <Table.Cell>
                                                    <Text size="2">{item.estimated_area_sqm || 0.5} m²</Text>
                                                </Table.Cell>
                                                <Table.Cell>
                                                    {getSeverityBadge(item.severity)}
                                                </Table.Cell>
                                                <Table.Cell>
                                                    {getStatusBadge(item.status)}
                                                </Table.Cell>
                                                <Table.Cell align="right">
                                                    <Flex justify="end" gap="2">
                                                        <Button size="1" variant="soft" color="indigo" onClick={() => openInspector(item)}>
                                                            <EyeIcon style={{ width: 14, height: 14 }} />
                                                            Inspect
                                                        </Button>
                                                        {canManage && item.status === 'pending_review' && (
                                                            <Button size="1" variant="soft" color="red" onClick={() => openRejectModal(item)}>
                                                                Reject
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
                    </Box>
                </Panel>

                {/* AI Bounding Box & Frame Inspector Modal */}
                <Dialog.Root open={showInspectorModal} onOpenChange={setShowInspectorModal}>
                    <Dialog.Content style={{ maxWidth: 650 }}>
                        <Dialog.Title>Edge-AI Detection Inspector: {inspectingItem?.detection_code}</Dialog.Title>
                        <Dialog.Description size="2" mb="3">
                            Bounding box visualization and spatial coordinates captured by patrol dashcam.
                        </Dialog.Description>

                        {inspectingItem && (
                            <Flex direction="column" gap="3">
                                {/* Simulated Camera View with AI Bounding Box */}
                                <Box style={{
                                    height: 260,
                                    backgroundColor: '#0F172A',
                                    borderRadius: 8,
                                    position: 'relative',
                                    overflow: 'hidden',
                                    display: 'flex',
                                    alignItems: 'center',
                                    justifyContent: 'center',
                                    border: '1px solid var(--gray-6)'
                                }}>
                                    {/* Road Asphalt Texture Canvas */}
                                    <Box style={{ position: 'absolute', inset: 0, opacity: 0.25, background: 'repeating-linear-gradient(45deg, #1E293B 0, #1E293B 10px, #0F172A 10px, #0F172A 20px)' }} />

                                    {/* Simulated Bounding Box */}
                                    <Box style={{
                                        position: 'absolute',
                                        left: '25%',
                                        top: '30%',
                                        width: '45%',
                                        height: '40%',
                                        border: '3px solid #10B981',
                                        borderRadius: 4,
                                        backgroundColor: 'rgba(16, 185, 129, 0.15)',
                                    }}>
                                        <Box style={{
                                            position: 'absolute',
                                            top: -24,
                                            left: -2,
                                            backgroundColor: '#10B981',
                                            padding: '2px 8px',
                                            borderRadius: '3px 3px 0 0',
                                        }}>
                                            <Text size="1" weight="bold" style={{ color: '#000' }}>
                                                {inspectingItem.distress_type?.toUpperCase()} ({(inspectingItem.confidence_score * 100).toFixed(0)}%)
                                            </Text>
                                        </Box>
                                    </Box>

                                    <Flex direction="column" align="center" style={{ zIndex: 1, pointerEvents: 'none' }}>
                                        <CameraIcon style={{ width: 32, height: 32, color: 'rgba(255,255,255,0.4)' }} />
                                        <Text size="1" color="gray" mt="1">Dhaka Bypass Patrol Forward Windshield Dashcam (1080p @ 30fps)</Text>
                                    </Flex>
                                </Box>

                                <Card>
                                    <Flex justify="between" mb="2">
                                        <Text size="2" weight="bold">Classification:</Text>
                                        <Badge size="2" color="indigo" style={{ textTransform: 'capitalize' }}>
                                            {inspectingItem.distress_type?.replace(/_/g, ' ')}
                                        </Badge>
                                    </Flex>
                                    <Flex justify="between" mb="2">
                                        <Text size="2" weight="medium">Location:</Text>
                                        <Text size="2">{inspectingItem.chainage} ({inspectingItem.direction})</Text>
                                    </Flex>
                                    <Flex justify="between" mb="2">
                                        <Text size="2" weight="medium">Coordinates:</Text>
                                        <Text size="2">{inspectingItem.latitude || 23.9482}, {inspectingItem.longitude || 90.5821}</Text>
                                    </Flex>
                                    <Flex justify="between" mb="2">
                                        <Text size="2" weight="medium">Estimated Repair Area:</Text>
                                        <Text size="2" weight="bold">{inspectingItem.estimated_area_sqm || 0.5} m²</Text>
                                    </Flex>
                                    <Flex justify="between">
                                        <Text size="2" weight="medium">Severity Assessment:</Text>
                                        {getSeverityBadge(inspectingItem.severity)}
                                    </Flex>
                                </Card>

                                <Text size="2" color="gray">
                                    Notes: {inspectingItem.notes || 'Automated detection via edge mobile inference.'}
                                </Text>
                            </Flex>
                        )}

                        <Flex justify="end" mt="4">
                            <Dialog.Close>
                                <Button variant="soft" color="gray">Close</Button>
                            </Dialog.Close>
                        </Flex>
                    </Dialog.Content>
                </Dialog.Root>

                {/* Batch Work Order Conversion Modal */}
                <Dialog.Root open={showBatchModal} onOpenChange={setShowBatchModal}>
                    <Dialog.Content style={{ maxWidth: 550 }}>
                        <Dialog.Title>Convert {selectedIds.length} Detections to Work Order</Dialog.Title>
                        <Dialog.Description size="2" mb="3">
                            Consolidate multiple localized defects into a single unified contractor or in-house repair work order.
                        </Dialog.Description>

                        <Flex direction="column" gap="3">
                            <Box>
                                <Text size="2" weight="medium" mb="1">Work Order Title *</Text>
                                <TextField.Root value={woTitle} onChange={(e) => setWoTitle(e.target.value)} />
                            </Box>
                            <Flex gap="3">
                                <Box style={{ flex: 1 }}>
                                    <Text size="2" weight="medium" mb="1">Priority</Text>
                                    <Select.Root value={woPriority} onValueChange={setWoPriority}>
                                        <Select.Trigger />
                                        <Select.Content>
                                            <Select.Item value="low">Low</Select.Item>
                                            <Select.Item value="medium">Medium</Select.Item>
                                            <Select.Item value="high">High</Select.Item>
                                            <Select.Item value="critical">Critical</Select.Item>
                                        </Select.Content>
                                    </Select.Root>
                                </Box>
                                <Box style={{ flex: 1 }}>
                                    <Text size="2" weight="medium" mb="1">Chainage Span</Text>
                                    <TextField.Root value={woChainage} onChange={(e) => setWoChainage(e.target.value)} />
                                </Box>
                            </Flex>
                            <Box>
                                <Text size="2" weight="medium" mb="1">Work Scope Description</Text>
                                <TextArea rows={3} value={woDescription} onChange={(e) => setWoDescription(e.target.value)} />
                            </Box>
                        </Flex>

                        <Flex justify="end" gap="3" mt="4">
                            <Dialog.Close>
                                <Button variant="soft" color="gray">Cancel</Button>
                            </Dialog.Close>
                            <Button variant="solid" color="indigo" onClick={handleBatchConvert}>
                                Generate Work Order
                            </Button>
                        </Flex>
                    </Dialog.Content>
                </Dialog.Root>

                {/* Reject False Positive Modal */}
                <Dialog.Root open={showRejectModal} onOpenChange={setShowRejectModal}>
                    <Dialog.Content style={{ maxWidth: 450 }}>
                        <Dialog.Title>Reject Detection #{rejectingItem?.detection_code}</Dialog.Title>
                        <Dialog.Description size="2" mb="3">
                            Flag this detection as a false positive (e.g. shadow, temporary road staining, or non-distress feature).
                        </Dialog.Description>

                        <Box mb="3">
                            <Text size="2" weight="medium" mb="1">Reason for Rejection</Text>
                            <TextArea rows={3} value={rejectReason} onChange={(e) => setRejectReason(e.target.value)} />
                        </Box>

                        <Flex justify="end" gap="3">
                            <Dialog.Close>
                                <Button variant="soft" color="gray">Cancel</Button>
                            </Dialog.Close>
                            <Button variant="solid" color="red" onClick={handleReject}>
                                Confirm Rejection
                            </Button>
                        </Flex>
                    </Dialog.Content>
                </Dialog.Root>
            </Box>
        </App>
    );
}
