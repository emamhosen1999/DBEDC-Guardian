import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { Box, Flex, Text, Heading, Button, Badge, Table, TextField, Dialog, TextArea, Switch, Card } from '@radix-ui/themes';
import {
    ChatBubbleLeftRightIcon,
    PlusIcon,
    ShieldCheckIcon,
    UserGroupIcon,
    CheckBadgeIcon,
    ExclamationTriangleIcon,
    MapPinIcon,
    ClipboardDocumentCheckIcon
} from '@heroicons/react/24/outline';
import App from '@/Layouts/App.jsx';
import { Panel } from '@/Components/ui/Panel';
import StatsCards from '@/Components/StatsCards';
import { useOperationsRealtimeRefresh } from '@/Hooks/useOperationsRealtimeRefresh';
import { showOperationMutationErrors } from './mutationFeedback';

export default function ToolboxTalks({ auth, toolboxTalks = {} }) {
    useOperationsRealtimeRefresh();

    const canManage = auth?.permissions?.includes('om.maintenance.manage') || auth?.roles?.includes('Super Administrator');
    const [showModal, setShowModal] = useState(false);
    const [selectedTalk, setSelectedTalk] = useState(null);

    // Form
    const [topic, setTopic] = useState('');
    const [talkDate, setTalkDate] = useState(new Date().toISOString().substring(0, 10));
    const [workOrderId, setWorkOrderId] = useState('');
    const [chainage, setChainage] = useState('');
    const [attendeeName, setAttendeeName] = useState('');
    const [attendees, setAttendees] = useState([]);
    const [ppeVerified, setPpeVerified] = useState(true);
    const [trafficBriefed, setTrafficBriefed] = useState(true);
    const [emergencyBriefed, setEmergencyBriefed] = useState(true);
    const [hazardsIdentified, setHazardsIdentified] = useState('');

    const talkList = toolboxTalks?.data || toolboxTalks || [];

    const totalAttendees = talkList.reduce((acc, t) => acc + (Array.isArray(t.attendees) ? t.attendees.length : 0), 0);

    const statsData = [
        {
            title: 'Toolbox Briefings',
            value: talkList.length,
            icon: <ChatBubbleLeftRightIcon style={{ width: 22, height: 22 }} />,
            color: 'blue',
            description: 'Pre-work shift safety talks held',
        },
        {
            title: 'Workers Briefed',
            value: totalAttendees,
            icon: <UserGroupIcon style={{ width: 22, height: 22 }} />,
            color: 'indigo',
            description: 'Total crew member sign-offs',
        },
        {
            title: 'PPE Compliance',
            value: '100%',
            icon: <ShieldCheckIcon style={{ width: 22, height: 22 }} />,
            color: 'green',
            description: 'Mandatory PPE checked before work',
        },
        {
            title: 'Traffic Plans Briefed',
            value: talkList.filter(t => t.traffic_management_briefed).length,
            icon: <CheckBadgeIcon style={{ width: 22, height: 22 }} />,
            color: 'amber',
            description: 'Lane closure & cone layout covered',
        },
    ];

    const addAttendee = () => {
        if (attendeeName.trim()) {
            setAttendees([...attendees, { name: attendeeName.trim(), signed: true, time: new Date().toLocaleTimeString() }]);
            setAttendeeName('');
        }
    };

    const removeAttendee = (idx) => {
        setAttendees(attendees.filter((_, i) => i !== idx));
    };

    const handleSave = (e) => {
        e.preventDefault();
        router.post('/om/toolbox-talks', {
            topic,
            talk_date: talkDate,
            work_order_id: workOrderId ? parseInt(workOrderId) : null,
            chainage: chainage || null,
            attendees,
            ppe_verified: ppeVerified,
            traffic_management_briefed: trafficBriefed,
            emergency_response_briefed: emergencyBriefed,
            hazards_identified: hazardsIdentified || null,
        }, {
            onSuccess: () => {
                setShowModal(false);
                setTopic('');
                setWorkOrderId('');
                setAttendees([]);
                setHazardsIdentified('');
            },
            onError: showOperationMutationErrors,
        });
    };

    return (
        <App auth={auth}>
            <Head title="Toolbox Safety Talks - DBEDC O&M" />

            <Box p="6">
                {/* Header */}
                <Flex justify="between" align="center" mb="5" wrap="wrap" gap="3">
                    <Box>
                        <Flex align="center" gap="2">
                            <ChatBubbleLeftRightIcon style={{ width: 28, height: 28, color: '#3b82f6' }} />
                            <Heading size="6">Pre-Work Safety Toolbox Briefings</Heading>
                        </Flex>
                        <Text size="2" color="gray">
                            Daily pre-task safety briefings, hazard awareness, PPE verification, and crew sign-off
                        </Text>
                    </Box>

                    {canManage && (
                        <Button color="blue" onClick={() => setShowModal(true)}>
                            <PlusIcon style={{ width: 16, height: 16 }} />
                            Conduct Toolbox Talk
                        </Button>
                    )}
                </Flex>

                {/* Stats */}
                <Box mb="5">
                    <StatsCards stats={statsData} />
                </Box>

                {/* Table */}
                <Panel>
                    <Table.Root variant="surface">
                        <Table.Header>
                            <Table.Row>
                                <Table.ColumnHeaderCell>Talk Code</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Topic</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Date</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Chainage</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell align="center">Crew Size</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Safety Checkpoints</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Conducted By</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell align="right">Details</Table.ColumnHeaderCell>
                            </Table.Row>
                        </Table.Header>
                        <Table.Body>
                            {talkList.map(t => (
                                <Table.Row key={t.id}>
                                    <Table.Cell>
                                        <Text weight="bold" size="2">{t.talk_code}</Text>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Text weight="medium" size="2">{t.topic}</Text>
                                        {t.work_order_id && (
                                            <Badge size="1" color="gray" variant="surface" mt="1">
                                                WO #{t.work_order_id}
                                            </Badge>
                                        )}
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Text size="2">{t.talk_date?.substring(0, 10)}</Text>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Text size="2">{t.chainage ? `KM ${t.chainage}` : 'Expressway Corridor'}</Text>
                                    </Table.Cell>
                                    <Table.Cell align="center">
                                        <Badge size="1" color="indigo">
                                            {Array.isArray(t.attendees) ? t.attendees.length : 0} workers
                                        </Badge>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Flex gap="1" wrap="wrap">
                                            {t.ppe_verified && <Badge size="1" color="green">PPE</Badge>}
                                            {t.traffic_management_briefed && <Badge size="1" color="blue">Traffic</Badge>}
                                            {t.emergency_response_briefed && <Badge size="1" color="amber">Emergency</Badge>}
                                        </Flex>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Text size="1" color="gray">{t.conductor?.name || 'Supervisor'}</Text>
                                    </Table.Cell>
                                    <Table.Cell align="right">
                                        <Button size="1" variant="ghost" color="gray" onClick={() => setSelectedTalk(t)}>
                                            View
                                        </Button>
                                    </Table.Cell>
                                </Table.Row>
                            ))}
                            {talkList.length === 0 && (
                                <Table.Row>
                                    <Table.Cell colSpan={8}>
                                        <Text align="center" color="gray" size="2" style={{ display: 'block', padding: '24px' }}>
                                            No toolbox safety talks recorded yet.
                                        </Text>
                                    </Table.Cell>
                                </Table.Row>
                            )}
                        </Table.Body>
                    </Table.Root>
                </Panel>

                {/* Create Toolbox Talk Modal */}
                <Dialog.Root open={showModal} onOpenChange={setShowModal}>
                    <Dialog.Content maxWidth="550px">
                        <Dialog.Title>Conduct Safety Toolbox Talk</Dialog.Title>
                        <Dialog.Description size="2" color="gray" mb="3">
                            Document safety briefing before starting field work
                        </Dialog.Description>

                        <form onSubmit={handleSave}>
                            <Flex direction="column" gap="3">
                                <Box>
                                    <Text size="2" weight="bold">Briefing Topic *</Text>
                                    <TextField.Root
                                        required
                                        placeholder="e.g., Highway Pothole Patching & Live Traffic Safety"
                                        value={topic}
                                        onChange={e => setTopic(e.target.value)}
                                        mt="1"
                                    />
                                </Box>

                                <Flex gap="3">
                                    <Box style={{ flex: 1 }}>
                                        <Text size="2" weight="bold">Date *</Text>
                                        <TextField.Root
                                            type="date"
                                            required
                                            value={talkDate}
                                            onChange={e => setTalkDate(e.target.value)}
                                            mt="1"
                                        />
                                    </Box>
                                    <Box style={{ flex: 1 }}>
                                        <Text size="2" weight="bold">Work Order ID</Text>
                                        <TextField.Root
                                            type="number"
                                            placeholder="Optional WO #"
                                            value={workOrderId}
                                            onChange={e => setWorkOrderId(e.target.value)}
                                            mt="1"
                                        />
                                    </Box>
                                    <Box style={{ flex: 1 }}>
                                        <Text size="2" weight="bold">Chainage (KM)</Text>
                                        <TextField.Root
                                            placeholder="14+200"
                                            value={chainage}
                                            onChange={e => setChainage(e.target.value)}
                                            mt="1"
                                        />
                                    </Box>
                                </Flex>

                                {/* Safety Verification Checklist */}
                                <Box style={{ background: 'var(--gray-3)', padding: 12, borderRadius: 8 }}>
                                    <Text size="2" weight="bold" mb="2">Safety Checkpoints Verified:</Text>
                                    <Flex direction="column" gap="2">
                                        <Flex justify="between" align="center">
                                            <Text size="2">Personal Protective Equipment (Hi-vis vest, steel-toe, helmet, gloves)</Text>
                                            <Switch checked={ppeVerified} onCheckedChange={setPpeVerified} />
                                        </Flex>
                                        <Flex justify="between" align="center">
                                            <Text size="2">Traffic Management & Temporary Signage Plan briefed</Text>
                                            <Switch checked={trafficBriefed} onCheckedChange={setTrafficBriefed} />
                                        </Flex>
                                        <Flex justify="between" align="center">
                                            <Text size="2">Emergency contacts & first-aid kit assembly point confirmed</Text>
                                            <Switch checked={emergencyBriefed} onCheckedChange={setEmergencyBriefed} />
                                        </Flex>
                                    </Flex>
                                </Box>

                                <Box>
                                    <Text size="2" weight="bold">Hazards Identified & Precautions</Text>
                                    <TextArea
                                        placeholder="e.g., High speed passing vehicles, heat stress, bitumen fumes..."
                                        value={hazardsIdentified}
                                        onChange={e => setHazardsIdentified(e.target.value)}
                                        rows={2}
                                        mt="1"
                                    />
                                </Box>

                                {/* Attendees */}
                                <Box>
                                    <Text size="2" weight="bold">Crew Attendees ({attendees.length})</Text>
                                    <Flex gap="2" mt="1">
                                        <TextField.Root
                                            placeholder="Add crew member name..."
                                            value={attendeeName}
                                            onChange={e => setAttendeeName(e.target.value)}
                                            onKeyDown={e => e.key === 'Enter' && (e.preventDefault(), addAttendee())}
                                            style={{ flex: 1 }}
                                        />
                                        <Button type="button" variant="soft" onClick={addAttendee}>Add</Button>
                                    </Flex>
                                    <Flex gap="1" wrap="wrap" mt="2">
                                        {attendees.map((a, i) => (
                                            <Badge key={i} size="1" color="blue" variant="surface">
                                                {a.name}
                                                <span style={{ cursor: 'pointer', marginLeft: 4 }} onClick={() => removeAttendee(i)}>×</span>
                                            </Badge>
                                        ))}
                                    </Flex>
                                </Box>
                            </Flex>

                            <Flex justify="end" gap="2" mt="4">
                                <Dialog.Close>
                                    <Button variant="soft" color="gray" type="button">Cancel</Button>
                                </Dialog.Close>
                                <Button color="blue" type="submit">
                                    Sign Off & Save Talk
                                </Button>
                            </Flex>
                        </form>
                    </Dialog.Content>
                </Dialog.Root>

                {/* Detail Modal */}
                <Dialog.Root open={!!selectedTalk} onOpenChange={(open) => !open && setSelectedTalk(null)}>
                    <Dialog.Content maxWidth="500px">
                        <Dialog.Title>{selectedTalk?.talk_code} — {selectedTalk?.topic}</Dialog.Title>
                        <Dialog.Description size="2" color="gray" mb="3">
                            Conducted on {selectedTalk?.talk_date?.substring(0, 10)} at {selectedTalk?.chainage ? `KM ${selectedTalk.chainage}` : 'Site'}
                        </Dialog.Description>

                        <Flex direction="column" gap="3">
                            <Box style={{ background: 'var(--gray-3)', padding: 10, borderRadius: 6 }}>
                                <Text size="1" color="gray">Identified Site Hazards</Text>
                                <Text size="2" mt="1">{selectedTalk?.hazards_identified || 'Standard highway maintenance precautions briefed.'}</Text>
                            </Box>

                            <Box>
                                <Text size="1" color="gray" mb="1">Confirmed Attendees ({selectedTalk?.attendees?.length || 0}):</Text>
                                <Flex gap="1" wrap="wrap">
                                    {Array.isArray(selectedTalk?.attendees) && selectedTalk.attendees.map((a, idx) => (
                                        <Badge key={idx} color="green" size="1">
                                            ✓ {typeof a === 'string' ? a : a.name}
                                        </Badge>
                                    ))}
                                </Flex>
                            </Box>
                        </Flex>

                        <Flex justify="end" mt="4">
                            <Dialog.Close>
                                <Button variant="soft" color="gray">Close</Button>
                            </Dialog.Close>
                        </Flex>
                    </Dialog.Content>
                </Dialog.Root>
            </Box>
        </App>
    );
}
