import React from 'react';
import { Head } from '@inertiajs/react';
import { Box, Flex, Text, Heading, Grid, Badge, Table, Separator } from '@radix-ui/themes';
import { ComputerDesktopIcon } from '@heroicons/react/24/outline';
import App from '@/Layouts/App.jsx';
import { Panel } from '@/Components/ui/Panel';
import StatsCards from '@/Components/StatsCards';
import { useOperationsRealtimeRefresh } from '@/Hooks/useOperationsRealtimeRefresh';

export default function EquipmentFacilities({ auth, equipment }) {
    useOperationsRealtimeRefresh();

    const eqList = Array.isArray(equipment) ? equipment : [];

    const onlineCount = eqList.filter(e => e.status === 'online').length;
    const avgUptime = eqList.length
        ? (eqList.reduce((acc, e) => acc + Number(e.uptime_pct || 0), 0) / eqList.length).toFixed(2)
        : null;

    const statItems = [
        { key: 'total', title: 'Total Equipment', value: eqList.length, color: 'blue' },
        { key: 'online', title: 'Online', value: onlineCount, color: 'green' },
        { key: 'uptime', title: 'Avg Uptime', value: avgUptime === null ? '—' : `${avgUptime}%`, color: 'indigo' },
    ];

    return (
        <App auth={auth}>
            <Head title="Equipment & Facilities Status" />
            <Flex justify="center" p="4">
                <Box style={{ width: '100%', maxWidth: 2000 }}>
                    <Panel>
                        {/* ── Page Header ── */}
                        <Box mb="4">
                            <Flex direction={{ initial: 'column', sm: 'row' }} align={{ initial: 'start', sm: 'center' }} justify="between" gap="4">
                                <Flex align="center" gap="3">
                                    <Box p="3" style={{ background: 'var(--blue-a3)', borderRadius: 12, border: '1px solid var(--blue-a5)', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                                        <ComputerDesktopIcon style={{ width: 22, height: 22, color: 'var(--blue-9)' }} />
                                    </Box>
                                    <Box>
                                        <Heading size="5" style={{ fontFamily: `'Space Grotesk', system-ui, sans-serif`, fontWeight: 800, letterSpacing: '-0.02em' }}>Equipment & Hardware Asset Health</Heading>
                                        <Text size="2" style={{ color: 'var(--aero-color-subtle, var(--gray-9))' }}>Live Uptime Monitoring: CCTV Cameras, VMS Display Panels, WIM Scales & Power Generators</Text>
                                    </Box>
                                </Flex>
                            </Flex>
                        </Box>

                        <Separator size="4" mb="4" style={{ background: 'var(--dl-border-color, rgba(0,0,0,0.06))' }} />

                        <StatsCards stats={statItems} variant="pill" mb="4" />

                        <Box style={{ overflowX: 'auto', WebkitOverflowScrolling: 'touch', borderRadius: 16, border: '1px solid var(--aero-surface-border, rgba(0,0,0,0.06))', background: 'var(--aero-surface, var(--color-background))' }}>
                            <Table.Root size="2" style={{ minWidth: 880, width: '100%' }}>
                                <Table.Header style={{
                                    position: 'sticky',
                                    top: 0,
                                    zIndex: 2,
                                    background: 'var(--aero-surface, var(--color-background))',
                                    backdropFilter: 'blur(8px)',
                                    boxShadow: '0 1px 0 var(--dl-border-color, rgba(0,0,0,0.06))'
                                }}>
                                    <Table.Row>
                                        <Table.ColumnHeaderCell style={{ minWidth: 130, background: 'inherit' }}>Asset Code</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell style={{ minWidth: 220, background: 'inherit' }}>Equipment Name</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell style={{ minWidth: 110, background: 'inherit' }}>Category</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell style={{ minWidth: 160, background: 'inherit' }}>Location</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell style={{ minWidth: 110, background: 'inherit' }}>Status</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell style={{ minWidth: 100, background: 'inherit' }}>Uptime %</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell style={{ textAlign: 'right', minWidth: 150, background: 'inherit' }}>Last Ping</Table.ColumnHeaderCell>
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    {eqList.map((eq) => (
                                        <Table.Row key={eq.id} align="center">
                                            <Table.Cell style={{ fontFamily: 'monospace', fontWeight: 600 }}>
                                                {eq.equipment_code}
                                            </Table.Cell>
                                            <Table.Cell><Text weight="bold" style={{ display: 'block', maxWidth: 220, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{eq.name}</Text></Table.Cell>
                                            <Table.Cell>
                                                <Badge color="indigo" variant="soft" style={{ borderRadius: 999 }}>{eq.category.toUpperCase()}</Badge>
                                            </Table.Cell>
                                            <Table.Cell><Text size="2" style={{ whiteSpace: 'nowrap' }}>{eq.location}</Text></Table.Cell>
                                            <Table.Cell>
                                                <Badge color={eq.status === 'online' ? 'green' : 'red'} variant="soft" style={{ borderRadius: 999 }}>
                                                    {eq.status}
                                                </Badge>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Text weight="bold" color="green" style={{ fontFamily: `'Space Grotesk', system-ui, sans-serif`, fontVariantNumeric: 'tabular-nums' }}>{eq.uptime_pct}%</Text>
                                            </Table.Cell>
                                            <Table.Cell style={{ textAlign: 'right', color: 'var(--gray-10)', fontVariantNumeric: 'tabular-nums', whiteSpace: 'nowrap' }}>{eq.last_ping_at}</Table.Cell>
                                        </Table.Row>
                                    ))}
                                </Table.Body>
                            </Table.Root>
                        </Box>
                    </Panel>
                </Box>
            </Flex>
        </App>
    );
}
