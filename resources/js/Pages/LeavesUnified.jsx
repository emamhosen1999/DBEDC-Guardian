/**
 * LeavesUnified.jsx
 * Single-page Leave Management shell — tabbed, pure Radix UI.
 *
 * Tabs:
 *   1. All Leaves   (admin table view)
 *   2. Summary      (per-employee / per-department pivot)
 *   3. Analytics    (charts via API)
 *   4. Settings     (leave types CRUD — admin only)
 *
 * "My Leaves" panel removed — self-service is handled separately.
 * Same page-shell pattern as DailyWorksUnified.jsx.
 */
import React, { useState } from 'react';
import { Head, usePage } from '@inertiajs/react';
import {
    Badge, Box, Button, Card, Flex, Heading,
    Separator, Tabs, Text,
} from '@radix-ui/themes';
import {
    BarChartIcon, CalendarIcon, CheckCircledIcon,
    GearIcon, LayersIcon,
} from '@radix-ui/react-icons';
import App from '@/Layouts/App.jsx';
import { useMediaQuery } from '@/Hooks/useMediaQuery.js';

import AdminLeavesPanel    from '@/Components/LeaveUnified/AdminLeavesPanel.jsx';
import SummaryPanel        from '@/Components/LeaveUnified/SummaryPanel.jsx';
import AnalyticsPanel      from '@/Components/LeaveUnified/AnalyticsPanel.jsx';
import LeaveSettingsPanel  from '@/Components/LeaveUnified/LeaveSettingsPanel.jsx';

const LeavesUnified = ({ title, allUsers, summaryData, leaveTypes }) => {
    const { auth }  = usePage().props;
    const isMobile  = useMediaQuery('(max-width: 640px)');
    const isDesktop = useMediaQuery('(min-width: 1025px)');

    const isAdmin    = auth.permissions?.includes('leaves.view')     || false;
    const canSettings = auth.roles?.includes('Administrator')
                     || auth.roles?.includes('Super Administrator')  || false;

    const [activeTab, setActiveTab] = useState('all');

    /* quick-count badge populated by AdminLeavesPanel callback */
    const [counts, setCounts] = useState({ all: 0 });

    /* per-tab header actions injected by each panel */
    const [headerActions, setHeaderActions] = useState(null);

    return (
        <>
            <Head title={title || 'Leave Management'} />

            <Flex justify="center" p={{ initial: '3', md: '4' }}>
                <Box style={{ width: '100%', maxWidth: 2000 }}>
                    <Card>

                        {/* ── Page Header ─────────────────────────────── */}
                        <Box mb="4">
                            <Flex
                                direction={{ initial: 'column', sm: 'row' }}
                                align={{ initial: 'start', sm: 'center' }}
                                justify="between"
                                gap="4"
                            >
                                {/* icon + title */}
                                <Flex align="center" gap="3">
                                    <Box
                                        p={{ initial: '2', md: '3' }}
                                        style={{
                                            background: 'var(--accent-a3)',
                                            borderRadius: 'var(--radius-2)',
                                            border: '1px solid var(--accent-a6)',
                                            display: 'flex', alignItems: 'center', justifyContent: 'center',
                                        }}
                                    >
                                        <CalendarIcon
                                            style={{
                                                width: isDesktop ? 26 : 20,
                                                height: isDesktop ? 26 : 20,
                                                color: 'var(--accent-9)',
                                            }}
                                        />
                                    </Box>
                                    <Box>
                                        <Heading size={{ initial: '4', md: '5' }}>
                                            Leave Management
                                        </Heading>
                                        <Text size="2" color="gray">
                                            Requests · Approvals · Summary · Analytics · Settings
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

                        {/* ── Tabs ─────────────────────────────────────── */}
                        <Tabs.Root value={activeTab} onValueChange={setActiveTab}>
                            <Tabs.List mb="4">

                                {/* All Leaves — admin only */}
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

                                {/* Summary — admin only */}
                                {isAdmin && (
                                    <Tabs.Trigger value="summary">
                                        <Flex align="center" gap="2">
                                            <CheckCircledIcon />
                                            {!isMobile && 'Summary'}
                                        </Flex>
                                    </Tabs.Trigger>
                                )}

                                {/* Analytics — admin only */}
                                {isAdmin && (
                                    <Tabs.Trigger value="analytics">
                                        <Flex align="center" gap="2">
                                            <BarChartIcon />
                                            {!isMobile && 'Analytics'}
                                        </Flex>
                                    </Tabs.Trigger>
                                )}

                                {/* Settings — super admin only */}
                                {canSettings && (
                                    <Tabs.Trigger value="settings">
                                        <Flex align="center" gap="2">
                                            <GearIcon />
                                            {!isMobile && 'Settings'}
                                        </Flex>
                                    </Tabs.Trigger>
                                )}

                            </Tabs.List>

                            {/* ── All Leaves ───────────────────────────── */}
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

                            {/* ── Summary ──────────────────────────────── */}
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

                            {/* ── Analytics ────────────────────────────── */}
                            {isAdmin && (
                                <Tabs.Content value="analytics">
                                    <AnalyticsPanel
                                        isMobile={isMobile}
                                        isActive={activeTab === 'analytics'}
                                        onSetHeaderActions={setHeaderActions}
                                    />
                                </Tabs.Content>
                            )}

                            {/* ── Settings ─────────────────────────────── */}
                            {canSettings && (
                                <Tabs.Content value="settings">
                                    <LeaveSettingsPanel
                                        leaveTypes={leaveTypes || []}
                                        isMobile={isMobile}
                                        isActive={activeTab === 'settings'}
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
