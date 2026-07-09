import React, { useState } from 'react';
import { Head, usePage } from '@inertiajs/react';
import App from '@/Layouts/App';
import { Box, Flex, Text, Card, Tabs, Separator } from '@radix-ui/themes';
import { 
    PersonIcon, 
    HomeIcon, 
    LayersIcon, 
    SewingPinIcon,
    Component1Icon,
    LockClosedIcon,
} from '@radix-ui/react-icons';
import { useMediaQuery } from '@/Hooks/useMediaQuery.js';

import EmployeesTab from '../Organization/Tabs/EmployeesTab';
import DepartmentsTab from '../Organization/Tabs/DepartmentsTab';
import DesignationsTab from '../Organization/Tabs/DesignationsTab';
import WorkLocationsTab from '../Organization/Tabs/WorkLocationsTab';
import OrganizationOverview from '../Organization/Components/OrganizationOverview';
import RolesPanel from '@/Components/AdminUnified/RolesPanel.jsx';
import ErrorBoundary from '@/Components/ErrorBoundary/ErrorBoundary';

const EmployeesPage = ({
    title,
    // Roles & permissions props
    permissions = [],
    role_has_permissions = [],
    permissionsGrouped = {},
    can_manage_super_admin = false,
}) => {
    const { auth, overviewStats, roles } = usePage().props;
    const isMobile = useMediaQuery('(max-width: 640px)');
    const isDesktop = useMediaQuery('(min-width: 1025px)');

    /* ── shared state ─────────────────────────────────────── */
    const [activeTab, setActiveTab] = useState('employees');
    const [headerActions, setHeaderActions] = useState(null);

    /* ── permission checks ─────────────────────────────────── */
    const canViewRoles = auth?.permissions?.includes('roles.view') || auth?.roles?.includes('Super Administrator');

    /* ── tab definitions ──────────────────────────────────── */
    const tabs = [
        { value: 'employees',      label: 'Employees',      icon: <PersonIcon /> },
        { value: 'departments',    label: 'Departments',    icon: <HomeIcon /> },
        { value: 'designations',   label: 'Designations',   icon: <LayersIcon /> },
        { value: 'work_locations', label: 'Work Locations', icon: <SewingPinIcon /> },
        ...(canViewRoles ? [{ value: 'roles', label: 'Roles & Permissions', icon: <LockClosedIcon /> }] : []),
    ];

    /* ── handle tab change to clear header actions ── */
    const handleTabChange = (val) => {
        setActiveTab(val);
        setHeaderActions(null);
    };

    /* ── render ───────────────────────────────────────────── */
    return (
        <>
            <Head title={title || 'Employees Console'} />

            <Flex justify="center" p={{ initial: '3', md: '4' }}>
                <Box style={{ width: '100%', maxWidth: 2000 }}>
                    <Card size="4">
                        
                        {/* ══ PAGE HEADER ════════════════════════════════ */}
                        <Box mb="4">
                            <Flex
                                direction={{ initial: 'column', md: 'row' }}
                                align={{ initial: 'start', md: 'center' }}
                                justify="between"
                                gap="4"
                            >
                                {/* title + subtitle */}
                                <Flex align="center" gap="3">
                                    <Box
                                        p={{ initial: '2', md: '3' }}
                                        style={{
                                            background: 'var(--accent-a3)',
                                            borderRadius: 'var(--radius-2)',
                                            display: 'flex',
                                            alignItems: 'center',
                                            justifyContent: 'center',
                                        }}
                                    >
                                        <Component1Icon
                                            width={isDesktop ? 28 : 20}
                                            height={isDesktop ? 28 : 20}
                                            color="var(--accent-9)"
                                        />
                                    </Box>
                                    <Box>
                                        <Text size={{ initial: '4', sm: '5', md: '6' }} weight="bold" as="div">
                                            Employees Console
                                        </Text>
                                        <Text size={{ initial: '1', md: '2' }} color="gray" as="div">
                                            Manage employees, departments, designations, and permissions
                                        </Text>
                                    </Box>
                                </Flex>

                                {/* Dynamic actions per tab */}
                                <Flex gap="2" align="center" wrap="wrap">
                                    {headerActions}
                                </Flex>
                            </Flex>
                        </Box>

                        <OrganizationOverview stats={overviewStats} />

                        <Separator size="4" mb="4" />

                        {/* ══ TABS ═══════════════════════════════════════ */}
                        <Tabs.Root value={activeTab} onValueChange={handleTabChange}>
                            <Tabs.List 
                                style={{ 
                                    marginBottom: 'var(--space-4)',
                                    overflowX: 'auto',
                                    display: 'flex',
                                    flexWrap: 'nowrap',
                                    scrollbarWidth: 'none', // hide scrollbar Firefox
                                    msOverflowStyle: 'none', // hide scrollbar IE/Edge
                                }}
                                className="hide-scrollbar"
                            >
                                {tabs.map(tab => (
                                    <Tabs.Trigger key={tab.value} value={tab.value}>
                                        <Flex align="center" gap="2">
                                            {tab.icon}
                                            <Text size="2" weight="medium" style={{ whiteSpace: 'nowrap' }}>
                                                {tab.label}
                                            </Text>
                                        </Flex>
                                    </Tabs.Trigger>
                                ))}
                            </Tabs.List>

                            {/* ── Employees Tab ─────────────────────────── */}
                            <Tabs.Content value="employees">
                                <Box mt="4">
                                    <ErrorBoundary>
                                        <EmployeesTab isActive={activeTab === 'employees'} />
                                    </ErrorBoundary>
                                </Box>
                            </Tabs.Content>

                            {/* ── Departments Tab ───────────────────────── */}
                            <Tabs.Content value="departments">
                                <Box mt="4">
                                    <ErrorBoundary>
                                        <DepartmentsTab isActive={activeTab === 'departments'} />
                                    </ErrorBoundary>
                                </Box>
                            </Tabs.Content>

                            {/* ── Designations Tab ──────────────────────── */}
                            <Tabs.Content value="designations">
                                <Box mt="4">
                                    <ErrorBoundary>
                                        <DesignationsTab isActive={activeTab === 'designations'} />
                                    </ErrorBoundary>
                                </Box>
                            </Tabs.Content>

                            {/* ── Work Locations Tab ────────────────────── */}
                            <Tabs.Content value="work_locations">
                                <Box mt="4">
                                    <ErrorBoundary>
                                        <WorkLocationsTab isActive={activeTab === 'work_locations'} />
                                    </ErrorBoundary>
                                </Box>
                            </Tabs.Content>

                            {/* ── Roles & Permissions Tab (IT Admin) ───── */}
                            {canViewRoles && (
                                <Tabs.Content value="roles">
                                    <Box mt="4">
                                        <ErrorBoundary>
                                            <RolesPanel
                                                roles={roles}
                                                permissions={permissions}
                                                roleHasPermissions={role_has_permissions}
                                                permissionsGrouped={permissionsGrouped}
                                                canManageSuperAdmin={can_manage_super_admin}
                                                isMobile={isMobile}
                                                tick={0}
                                                onCountChange={() => {}}
                                                onSetHeaderActions={setHeaderActions}
                                                isActive={activeTab === 'roles'}
                                            />
                                        </ErrorBoundary>
                                    </Box>
                                </Tabs.Content>
                            )}

                        </Tabs.Root>

                    </Card>
                </Box>
            </Flex>
            <style dangerouslySetInnerHTML={{__html: `
                .hide-scrollbar::-webkit-scrollbar {
                    display: none;
                }
            `}} />
        </>
    );
};

EmployeesPage.layout = page => <App>{page}</App>;

export default EmployeesPage;
