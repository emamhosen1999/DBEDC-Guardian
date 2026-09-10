import React from 'react';
import { Head, router } from '@inertiajs/react';
import { Box, Flex, Text, Heading, Grid, Button, Badge, Separator } from '@radix-ui/themes';
import {
    ComputerDesktopIcon,
    ArrowPathIcon,
    ScaleIcon,
    ExclamationTriangleIcon
} from '@heroicons/react/24/outline';
import App from '@/Layouts/App.jsx';
import { Panel } from '@/Components/ui/Panel';
import { useOperationsRealtimeRefresh } from '@/Hooks/useOperationsRealtimeRefresh';

export default function TrafficMonitoring({ auth, trafficSections, overloadAlerts }) {
    useOperationsRealtimeRefresh();

    const sections = Array.isArray(trafficSections) ? trafficSections : [];
    const overloads = Array.isArray(overloadAlerts) ? overloadAlerts : [];

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
                                            Live Expressway Section Flow Rates & Weigh-in-Motion (WIM) Overload Detection
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
                        <Box mb="5">
                            <Heading size="3" mb="3" style={{ fontFamily: `'Space Grotesk', system-ui, sans-serif`, fontWeight: 700 }}>
                                Expressway Section Flow Rates (Ch 0+000 - Ch 48+000)
                            </Heading>
                            <Grid columns={{ initial: '1', sm: '2', md: '4' }} gap="3">
                                {sections.map((sec) => (
                                    <Panel key={sec.id} tinted style={{ borderRadius: 16, border: '1px solid var(--aero-surface-border, rgba(0,0,0,0.06))', padding: 16, background: 'var(--aero-surface, var(--color-background))' }}>
                                        <Flex align="center" justify="between" mb="2">
                                            <Badge color={sec.density_status === 'free_flow' ? 'green' : sec.density_status === 'moderate' ? 'amber' : 'red'} variant="soft" style={{ borderRadius: 999 }}>
                                                {sec.density_status?.replace('_', ' ').toUpperCase() || 'NORMAL'}
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

                        {/* WIM Overload Detection Stream */}
                        {overloads.length > 0 && (
                            <Box mb="4">
                                <Flex align="center" gap="2" mb="3">
                                    <ScaleIcon style={{ width: 18, height: 18, color: 'var(--amber-9)' }} />
                                    <Heading size="3" style={{ fontFamily: `'Space Grotesk', system-ui, sans-serif`, fontWeight: 700 }}>
                                        Active Weigh-in-Motion (WIM) Overload Alerts
                                    </Heading>
                                </Flex>
                                <Grid columns={{ initial: '1', md: '3' }} gap="3">
                                    {overloads.map((al, idx) => (
                                        <Panel key={idx} tinted style={{ borderRadius: 14, border: '1px solid var(--amber-a5)', padding: 14, background: 'var(--amber-a2)' }}>
                                            <Flex justify="between" align="center" mb="1">
                                                <Badge color="amber" variant="solid">Overload Event</Badge>
                                                <Text size="1" color="gray">{al.section_name || al.section_code}</Text>
                                            </Flex>
                                            <Text size="2" weight="bold" mt="1">
                                                {al.overload_count} Overweight Vehicle(s) Detected
                                            </Text>
                                            <Text size="1" color="gray" mt="1">
                                                Section Flow: {al.vehicle_count_per_hour} veh/h · Avg Speed: {al.avg_speed_kmh} km/h
                                            </Text>
                                        </Panel>
                                    ))}
                                </Grid>
                            </Box>
                        )}
                    </Panel>
                </Box>
            </Flex>
        </App>
    );
}
