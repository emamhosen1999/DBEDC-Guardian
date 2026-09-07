import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { Box, Flex, Text, Heading, Grid, Button, Badge, Table, TextField, Dialog, Select, Separator } from '@radix-ui/themes';
import {
    ComputerDesktopIcon,
    ExclamationTriangleIcon,
    RadioIcon,
    CheckCircleIcon,
    ArrowPathIcon,
    BoltIcon,
    SpeakerWaveIcon
} from '@heroicons/react/24/outline';
import App from '@/Layouts/App.jsx';
import { Panel } from '@/Components/ui/Panel';
import { useOperationsRealtimeRefresh } from '@/Hooks/useOperationsRealtimeRefresh';
import { showOperationMutationErrors } from './mutationFeedback';

export default function TrafficMonitoring({ auth, trafficSections, vmsMessages, overloadAlerts }) {
    useOperationsRealtimeRefresh();

    const canManage = auth?.permissions?.includes('om.traffic.manage') || auth?.roles?.includes('Super Administrator');
    const [updatingVms, setUpdatingVms] = useState(null);
    const [msg1, setMsg1] = useState('');
    const [msg2, setMsg2] = useState('');
    const [type, setType] = useState('info');

    const sections = Array.isArray(trafficSections) ? trafficSections : [];

    const vmsList = Array.isArray(vmsMessages) ? vmsMessages : [];

    const presets = [
        { name: 'Standard Speed Limit', l1: 'DRIVE SAFELY - SPEED LIMIT 80 KM/H', l2: 'FASTEN SEATBELTS - KEEP LANE', type: 'info' },
        { name: 'Roadwork Warning', l1: 'CAUTION: ROADWORK AHEAD ON RIGHT LANE', l2: 'REDUCE SPEED TO 40 KM/H', type: 'warning' },
        { name: 'Accident Alert', l1: 'WARNING: ACCIDENT AT CH 24+500', l2: 'PATROL & AMBULANCE ON SCENE', type: 'emergency' },
        { name: 'Adverse Fog / Rain', l1: 'DENSE FOG / RAIN - LOW VISIBILITY', l2: 'USE LOW BEAMS - DOUBLE GAP', type: 'warning' },
        { name: 'Overload Enforcement', l1: 'WIM WEIGHBRIDGE ENFORCEMENT ACTIVE', l2: 'OVERLOADED TRUCKS WILL BE FINED', type: 'speed_limit' },
    ];

    const handleUpdateVms = (e) => {
        e.preventDefault();
        if (!updatingVms) return;
        router.post('/om/vms-messages', {
            id: updatingVms.id,
            message_line1: msg1,
            message_line2: msg2,
            type: type,
            lock_version: updatingVms.lock_version,
        }, {
            onSuccess: () => setUpdatingVms(null),
            onError: showOperationMutationErrors,
        });
    };

    const applyPreset = (p) => {
        setMsg1(p.l1);
        setMsg2(p.l2);
        setType(p.type);
    };

    return (
        <App auth={auth}>
            <Head title="Traffic Monitoring Center (TMC / ITS)" />
            <Flex justify="center" p="4">
                <Box style={{ width: '100%', maxWidth: 2000 }}>
                    <Panel>
                        {/* ── Page Header ── */}
                        <Box mb="4">
                            <Flex direction={{ initial: 'column', sm: 'row' }} align={{ initial: 'start', sm: 'center' }} justify="between" gap="4">
                                <Flex align="center" gap="3">
                                    <Box p="3" style={{ background: 'var(--purple-a3)', borderRadius: 12, border: '1px solid var(--purple-a5)', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                                        <ComputerDesktopIcon style={{ width: 22, height: 22, color: 'var(--purple-9)' }} />
                                    </Box>
                                    <Box>
                                        <Heading size="5" style={{ fontFamily: `'Space Grotesk', system-ui, sans-serif`, fontWeight: 800, letterSpacing: '-0.02em' }}>
                                            Traffic Monitoring Center (TMC / ITS)
                                        </Heading>
                                        <Text size="2" style={{ color: 'var(--aero-color-subtle, var(--gray-9))' }}>
                                            Live Expressway Section Flow Rates, VMS Dynamic Broadcasts & Weigh-in-Motion (WIM) Overload Detection
                                        </Text>
                                    </Box>
                                </Flex>
                                <Button variant="soft" color="gray" onClick={() => router.reload()} style={{ borderRadius: 10 }}>
                                    <ArrowPathIcon width={16} height={16} /> Refresh Live Feed
                                </Button>
                            </Flex>
                        </Box>

                        <Separator size="4" mb="4" style={{ background: 'var(--dl-border-color, rgba(0,0,0,0.06))' }} />

                        {/* Section Density Matrix */}
                        <Box mb="4">
                            <Heading size="3" mb="3" style={{ fontFamily: `'Space Grotesk', system-ui, sans-serif`, fontWeight: 700 }}>
                                Expressway Section Flow Rates (Ch 0+000 - Ch 48+000)
                            </Heading>
                            <Grid columns={{ initial: '1', sm: '2', md: '4' }} gap="3">
                                {sections.map((sec) => (
                                    <Panel key={sec.id} tinted style={{ borderRadius: 16, border: '1px solid var(--aero-surface-border, rgba(0,0,0,0.06))', padding: 16, background: 'var(--aero-surface, var(--color-background))' }}>
                                        <Flex align="center" justify="between" mb="2">
                                            <Badge color={sec.density_status === 'free_flow' ? 'green' : sec.density_status === 'moderate' ? 'amber' : 'red'} variant="soft" style={{ borderRadius: 999 }}>
                                                {sec.density_status.replace('_', ' ').toUpperCase()}
                                            </Badge>
                                            <Text size="1" color="gray" style={{ fontFamily: 'monospace' }}>{sec.section_code}</Text>
                                        </Flex>
                                        <Heading size="3" mb="1" style={{ fontFamily: `'Space Grotesk', system-ui, sans-serif` }}>{sec.section_name}</Heading>
                                        <Flex justify="between" align="baseline" mt="2">
                                            <Text size="2" color="gray">Flow: <Text weight="bold" color="blue" style={{ fontFamily: `'Space Grotesk', system-ui, sans-serif`, fontVariantNumeric: 'tabular-nums' }}>{sec.vehicle_count_per_hour} veh/h</Text></Text>
                                            <Text size="2" color="gray">Speed: <Text weight="bold" style={{ fontVariantNumeric: 'tabular-nums' }}>{sec.avg_speed_kmh} km/h</Text></Text>
                                        </Flex>
                                        <Flex justify="between" mt="2" style={{ borderTop: '1px solid var(--dl-border-color, rgba(0,0,0,0.06))', paddingTop: 8 }}>
                                            <Text size="1" color="gray">Overspeed: <Text color="red" weight="bold" style={{ fontVariantNumeric: 'tabular-nums' }}>{sec.overspeed_count}</Text></Text>
                                            <Text size="1" color="gray">Overload WIM: <Text color="amber" weight="bold" style={{ fontVariantNumeric: 'tabular-nums' }}>{sec.overload_count}</Text></Text>
                                        </Flex>
                                    </Panel>
                                ))}
                            </Grid>
                        </Box>

                        {/* VMS Live Control & Message Broadcast */}
                        <Box mb="4">
                            <Heading size="3" mb="3" style={{ fontFamily: `'Space Grotesk', system-ui, sans-serif`, fontWeight: 700 }}>
                                Variable Message Signs (VMS) Live Gantry Controller
                            </Heading>
                            <Grid columns={{ initial: '1', md: '3' }} gap="3">
                                {vmsList.map((vms) => (
                                    <Panel key={vms.id} tinted style={{ borderRadius: 16, border: '1px solid var(--aero-surface-border, rgba(0,0,0,0.06))', padding: 16 }}>
                                        <Flex justify="between" align="center" mb="2">
                                            <Badge color={vms.type === 'emergency' ? 'red' : vms.type === 'warning' ? 'amber' : 'green'} variant="solid">
                                                {vms.vms_code}
                                            </Badge>
                                            <Text size="1" color="gray">{vms.location}</Text>
                                        </Flex>

                                        {/* Electronic LED Display Simulation */}
                                        <Box style={{ background: '#000000', borderRadius: 8, padding: 12, border: '2px solid #333333', margin: '8px 0', fontFamily: 'monospace' }}>
                                            <Text size="2" weight="bold" style={{ color: '#FACC15', display: 'block', textAlign: 'center', letterSpacing: '0.05em' }}>
                                                {vms.message_line1}
                                            </Text>
                                            {vms.message_line2 && (
                                                <Text size="2" weight="bold" style={{ color: '#FACC15', display: 'block', textAlign: 'center', marginTop: 4, letterSpacing: '0.05em' }}>
                                                    {vms.message_line2}
                                                </Text>
                                            )}
                                        </Box>

                                        {canManage && <Flex justify="end" mt="2">
                                            <Button size="1" color="purple" variant="soft" onClick={() => {
                                                setUpdatingVms(vms);
                                                setMsg1(vms.message_line1);
                                                setMsg2(vms.message_line2 || '');
                                                setType(vms.type);
                                            }}>
                                                <SpeakerWaveIcon width={14} height={14} /> Update Broadcast
                                            </Button>
                                        </Flex>}
                                    </Panel>
                                ))}
                            </Grid>
                        </Box>
                    </Panel>
                </Box>
            </Flex>

            {/* VMS Update & Scenario Preset Modal */}
            <Dialog.Root open={canManage && !!updatingVms} onOpenChange={(open) => !open && setUpdatingVms(null)}>
                <Dialog.Content style={{ maxWidth: 560 }}>
                    <Dialog.Title>Update VMS Board: {updatingVms?.vms_code}</Dialog.Title>
                    <Dialog.Description size="2" mb="3">
                        Location: {updatingVms?.location}. Broadcast live LED message to highway drivers.
                    </Dialog.Description>

                    {/* Presets Grid */}
                    <Box mb="3" p="2" style={{ background: 'var(--gray-a2)', borderRadius: 8 }}>
                        <Text size="1" weight="bold" color="gray" mb="1" as="div">Quick Scenario Presets</Text>
                        <Flex gap="1" wrap="wrap">
                            {presets.map((p, idx) => (
                                <Button key={idx} size="1" variant="ghost" color="gray" onClick={() => applyPreset(p)}>
                                    {p.name}
                                </Button>
                            ))}
                        </Flex>
                    </Box>

                    <form onSubmit={handleUpdateVms}>
                        <Flex direction="column" gap="3">
                            <label>
                                <Text as="div" size="2" mb="1" weight="bold">Message Line 1 (Upper)</Text>
                                <TextField.Root value={msg1} onChange={(e) => setMsg1(e.target.value.toUpperCase())} maxLength={40} required />
                            </label>

                            <label>
                                <Text as="div" size="2" mb="1" weight="bold">Message Line 2 (Lower)</Text>
                                <TextField.Root value={msg2} onChange={(e) => setMsg2(e.target.value.toUpperCase())} maxLength={40} />
                            </label>

                            <label>
                                <Text as="div" size="2" mb="1" weight="bold">Message Severity / Color Mode</Text>
                                <Select.Root value={type} onValueChange={setType}>
                                    <Select.Trigger style={{ width: '100%' }} />
                                    <Select.Content>
                                        <Select.Item value="info">Info (Amber / Standard)</Select.Item>
                                        <Select.Item value="warning">Warning (Caution / Orange)</Select.Item>
                                        <Select.Item value="emergency">Emergency (Red Hazard)</Select.Item>
                                        <Select.Item value="speed_limit">Speed Limit / Regulatory</Select.Item>
                                    </Select.Content>
                                </Select.Root>
                            </label>

                            <Flex justify="end" gap="2" mt="2">
                                <Button type="button" variant="soft" color="gray" onClick={() => setUpdatingVms(null)}>Cancel</Button>
                                <Button type="submit" color="purple">Broadcast Live to Gantry</Button>
                            </Flex>
                        </Flex>
                    </form>
                </Dialog.Content>
            </Dialog.Root>
        </App>
    );
}
