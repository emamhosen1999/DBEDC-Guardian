/**
 * LeavesUnified.jsx
 * Single-page Leave Management shell — tabbed, pure Radix UI.
 * Tabs:
 *   1. All Leaves   (admin table view)
 *   2. My Leaves    (employee view)
 *   3. Summary      (per-employee / per-department pivot)
 *   4. Analytics    (charts via API)
 *
 * Same page-shell pattern as AdminUnified.jsx.
 */
import React, { useState, useEffect } from 'react';
import { Head, usePage } from '@inertiajs/react';
import {
    Badge, Box, Button, Card, Flex, Heading,
    IconButton, Separator, Tabs, Text,
} from '@radix-ui/themes';
import {
    BarChartIcon, CalendarIcon, CheckCircledIcon,
    ClockIcon, LayersIcon, PersonIcon, PlusIcon,
} from '@radix-ui/react-icons';
import App from '@/Layouts/App.jsx';
import { useMediaQuery } from '@/Hooks/useMediaQuery.js';

import AdminLeavesPanel  from '@/Components/LeaveUnified/AdminLeavesPanel.jsx';
import MyLeavesPanel     from '@/Components/LeaveUnified/MyLeavesPanel.jsx';
import SummaryPanel      from '@/Components/LeaveUnified/SummaryPanel.jsx';
import AnalyticsPanel    from '@/Components/LeaveUnified/AnalyticsPanel.jsx';

const LeavesUnified = ({ title, allUsers, summaryData }) => {
    const { auth }  = usePage().props;
    const isMobile  = useMediaQuery('(max-width: 640px)');

    const isAdmin   = auth.permissions?.includes('leaves.view') || false;
    const canCreate = auth.permissions?.includes('leaves.create') || false;

    const [activeTab, setActiveTab] = useState(isAdmin ? 'all' : 'my');

    /* quick-count badges populated by panels via callbacks */
    const [counts, setCounts] = useState({ all: 0, my: 0, pending: 0 });

    /* header actions injected per-tab */
    const [headerActions, setHeaderActions] = useState(null);

    return (
        <>
            <Head title={title || 'Leave Management'} />

            <Flex justify="center" p="4">
                <Box style={{ width: '100%', maxWidth: 2000 }}>
                    <Card>

                        {/* ── Page Header ── */}
                        <Box mb="4">
                            <Flex
                                direction={{ initial: 'column', sm: 'row' }}
                                align={{ initial: 'start', sm: 'center' }}
                                justify="between"
                                gap="4"
                            >
                                <Flex align="center" gap="3">
                                    <Box p="3" style={{
                                        background: 'var(--accent-a3)',
                                        borderRadius: 'var(--radius-2)',
                                        border: '1px solid var(--accent-a6)',
                                        display: 'flex', alignItems: 'center', justifyContent: 'center',
                                    }}>
                                        <CalendarIcon style={{ width: 22, height: 22, color: 'var(--accent-9)' }} />
                                    </Box>
                                    <Box>
                                        <Heading size="5">Leave Management</Heading>
                                        <Text size="2" color="gray">
                                            Requests · Approvals · Summary · Analytics
                                        </Text>
                                    </Box>
                                </Flex>

                                {/* dynamic per-tab actions */}
                                <Flex gap="2" align="center" wrap="wrap">
                                    {headerActions}
                                </Flex>
                            </Flex>
                        </Box>

                        <Separator size="4" mb="4" />

                        {/* ── Tabs ── */}
                        <Tabs.Root value={activeTab} onValueChange={setActiveTab}>
                            <Tabs.List mb="4">

                                {isAdmin && (
                                    <Tabs.Trigger value="all">
                                        <Flex align="center" gap="2">
                                            <LayersIcon />
                                            {!isMobile && 'All Leaves'}
                                            {counts.all > 0 && (
                                                <Badge size="1" variant="soft" color="blue" radius="full">
                                                    {counts.all}
                                                </Badge>
                                            )}
                                        </Flex>
                                    </Tabs.Trigger>
                                )}

                                <Tabs.Trigger value="my">
                                    <Flex align="center" gap="2">
                                        <PersonIcon />
                                        {!isMobile && 'My Leaves'}
                                        {counts.my > 0 && (
                                            <Badge size="1" variant="soft" color="green" radius="full">
                                                {counts.my}
                                            </Badge>
                                        )}
                                    </Flex>
                                </Tabs.Trigger>

                                {isAdmin && (
                                    <Tabs.Trigger value="summary">
                                        <Flex align="center" gap="2">
                                            <CheckCircledIcon />
                                            {!isMobile && 'Summary'}
                                        </Flex>
                                    </Tabs.Trigger>
                                )}

                                {isAdmin && (
                                    <Tabs.Trigger value="analytics">
                                        <Flex align="center" gap="2">
                                            <BarChartIcon />
                                            {!isMobile && 'Analytics'}
                                        </Flex>
                                    </Tabs.Trigger>
                                )}

                            </Tabs.List>

                            {/* ── All Leaves (Admin) ── */}
                            {isAdmin && (
                                <Tabs.Content value="all">
                                    <AdminLeavesPanel
                                        allUsers={allUsers}
                                        isMobile={isMobile}
                                        isActive={activeTab === 'all'}
                                        onCountChange={n => setCounts(p => ({ ...p, all: n }))}
                                        onSetHeaderActions={setHeaderActions}
                                    />
                                </Tabs.Content>
                            )}

                            {/* ── My Leaves (Employee) ── */}
                            <Tabs.Content value="my">
                                <MyLeavesPanel
                                    allUsers={allUsers}
                                    isMobile={isMobile}
                                    isActive={activeTab === 'my'}
                                    onCountChange={n => setCounts(p => ({ ...p, my: n }))}
                                    onSetHeaderActions={setHeaderActions}
                                />
                            </Tabs.Content>

                            {/* ── Summary ── */}
                            {isAdmin && (
                                <Tabs.Content value="summary">
                                    <SummaryPanel
                                        summaryData={summaryData}
                                        isMobile={isMobile}
                                        isActive={activeTab === 'summary'}
                                        onSetHeaderActions={setHeaderActions}
                                    />
                                </Tabs.Content>
                            )}

                            {/* ── Analytics ── */}
                            {isAdmin && (
                                <Tabs.Content value="analytics">
                                    <AnalyticsPanel
                                        isMobile={isMobile}
                                        isActive={activeTab === 'analytics'}
                                        onSetHeaderActions={setHeaderActions}
                                    />
                                </Tabs.Content>
                            )}

                        </Tabs.Root>
                    </Card>
                </Box>
            </Flex>
        </>
    );
};

LeavesUnified.layout = page => <App>{page}</App>;
export default LeavesUnified;
