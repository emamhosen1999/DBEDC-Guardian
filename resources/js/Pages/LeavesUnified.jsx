import { Panel } from '@/Components/ui/Panel';
/**
 * LeavesUnified.jsx
 * Single-page Leave Management shell — tabbed, pure Radix UI.
 * Aligned with AdminUnified.jsx theming.
 */
import React, { useState, useCallback } from 'react';
import { Head, usePage } from '@inertiajs/react';
import { Badge, Box, Flex, Heading, Separator, Tabs, Text, ScrollArea } from '@radix-ui/themes';
import {
    BarChartIcon, CalendarIcon, CheckCircledIcon,
    GearIcon, LayersIcon, BackpackIcon,
} from '@radix-ui/react-icons';
import App from '@/Layouts/App.jsx';
import { useMediaQuery } from '@/Hooks/useMediaQuery.js';

import AdminLeavesPanel    from '@/Components/LeaveUnified/AdminLeavesPanel.jsx';
import SummaryPanel        from '@/Components/LeaveUnified/SummaryPanel.jsx';
import AnalyticsPanel      from '@/Components/LeaveUnified/AnalyticsPanel.jsx';
import BalancesPanel       from '@/Components/LeaveUnified/BalancesPanel.jsx';
import LeaveSettingsPanel  from '@/Components/LeaveUnified/LeaveSettingsPanel.jsx';
import ErrorBoundary       from '@/Components/ErrorBoundary/ErrorBoundary';

const LeavesUnified = ({ title, allUsers, summaryData, leaveTypes }) => {
    const { auth }  = usePage().props;
    const isMobile  = useMediaQuery('(max-width: 640px)');

    const isAdmin    = auth.permissions?.includes('leaves.view')     || false;
    const canSettings = auth.roles?.includes('Administrator')
                     || auth.roles?.includes('Super Administrator')  || false;

    const [activeTab, setActiveTab] = useState('all');

    /* quick-count badge populated by AdminLeavesPanel callback */
    const [counts, setCounts] = useState({ all: 0 });
    const handleAllCount = useCallback(n => setCounts(p => ({ ...p, all: n })), []);

    /* per-tab header actions injected by each panel */
    const [headerActions, setHeaderActions] = useState(null);

    return (
        <>
            <Head title={title || 'Leave Management'} />

            <Flex justify="center" p={{ initial: '3', md: '5' }}>
                <Box style={{ width: '100%', maxWidth: 2000 }}>
                    <Panel>

                        {/* ── Page Header ── */}
                        <Box mb="5">
                            <Flex
                                direction={{ initial: 'column', sm: 'row' }}
                                align={{ initial: 'start', sm: 'center' }}
                                justify="between"
                                gap="4"
                            >
                                <Flex align="center" gap="3">
                                    <Box p="3" style={{
                                        background: 'linear-gradient(135deg, var(--accent-a3) 0%, var(--accent-a2) 100%)',
                                        borderRadius: 'var(--radius-3)',
                                        border: '1px solid var(--accent-a5)',
                                        display: 'flex', alignItems: 'center', justifyContent: 'center',
                                        boxShadow: '0 4px 12px var(--accent-a2)'
                                    }}>
                                        <CalendarIcon style={{ width: 24, height: 24, color: 'var(--accent-9)' }} />
                                    </Box>
                                    <Box>
                                        <Heading size="5" style={{ letterSpacing: '-0.02em', color: 'var(--gray-12)' }}>Leave Management</Heading>
                                        <Text size="2" color="gray" style={{ display: 'block', mt: 0.5 }}>
                                            Manage requests, view analytics, and configure policies
                                        </Text>
                                    </Box>
                                </Flex>

                                {/* Dynamic actions per tab */}
                                <Flex gap="2" align="center" wrap="wrap">
                                    {headerActions}
                                </Flex>
                            </Flex>
                        </Box>

                        <Separator size="4" mb="5" style={{ background: 'var(--gray-a3)' }} />

                        {/* ── Tabs ── */}
                        <Tabs.Root value={activeTab} onValueChange={setActiveTab}>
                            
                            {/* ScrollArea ensures tabs don't squash on mobile devices */}
                            <ScrollArea type="auto" scrollbars="horizontal">
                                <Tabs.List mb="4" style={{ whiteSpace: 'nowrap', width: 'max-content', minWidth: '100%' }}>

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

                                    {/* Balances — admin only (ledger) */}
                                    {isAdmin && (
                                        <Tabs.Trigger value="balances">
                                            <Flex align="center" gap="2">
                                                <BackpackIcon />
                                                {!isMobile && 'Balances'}
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
                            </ScrollArea>

                            {/* ── Tab Contents ── */}
                            {isAdmin && (
                                <Tabs.Content value="all">
                                    <ErrorBoundary>
                                        <AdminLeavesPanel
                                            allUsers={allUsers}
                                            isMobile={isMobile}
                                            isActive={activeTab === 'all'}
                                            onCountChange={handleAllCount}
                                            onSetHeaderActions={setHeaderActions}
                                        />
                                    </ErrorBoundary>
                                </Tabs.Content>
                            )}

                            {isAdmin && (
                                <Tabs.Content value="summary">
                                    <ErrorBoundary>
                                        <SummaryPanel
                                            summaryData={summaryData}
                                            isMobile={isMobile}
                                            isActive={activeTab === 'summary'}
                                            onSetHeaderActions={setHeaderActions}
                                        />
                                    </ErrorBoundary>
                                </Tabs.Content>
                            )}

                            {isAdmin && (
                                <Tabs.Content value="analytics">
                                    <ErrorBoundary>
                                        <AnalyticsPanel
                                            isMobile={isMobile}
                                            isActive={activeTab === 'analytics'}
                                            onSetHeaderActions={setHeaderActions}
                                        />
                                    </ErrorBoundary>
                                </Tabs.Content>
                            )}

                            {isAdmin && (
                                <Tabs.Content value="balances">
                                    <ErrorBoundary>
                                        <BalancesPanel
                                            allUsers={allUsers}
                                            isActive={activeTab === 'balances'}
                                        />
                                    </ErrorBoundary>
                                </Tabs.Content>
                            )}

                            {canSettings && (
                                <Tabs.Content value="settings">
                                    <ErrorBoundary>
                                        <LeaveSettingsPanel
                                            leaveTypes={leaveTypes || []}
                                            isMobile={isMobile}
                                            isActive={activeTab === 'settings'}
                                            onSetHeaderActions={setHeaderActions}
                                        />
                                    </ErrorBoundary>
                                </Tabs.Content>
                            )}

                        </Tabs.Root>
                    </Panel>
                </Box>
            </Flex>
        </>
    );
};

LeavesUnified.layout = page => <App>{page}</App>;
export default LeavesUnified;