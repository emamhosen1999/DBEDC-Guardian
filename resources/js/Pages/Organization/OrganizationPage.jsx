import React, { useState } from 'react';
import { Head, usePage } from '@inertiajs/react';
import App from '@/Layouts/App';
import { Box, Flex, Text, Card, Tabs, Separator } from '@radix-ui/themes';
import { 
    PersonIcon, 
    HomeIcon, 
    LayersIcon, 
    SewingPinIcon,
    Component1Icon
} from '@radix-ui/react-icons';
import { useMediaQuery } from '@/Hooks/useMediaQuery.js';

import EmployeesTab from './Tabs/EmployeesTab';
import DepartmentsTab from './Tabs/DepartmentsTab';
import DesignationsTab from './Tabs/DesignationsTab';
import WorkLocationsTab from './Tabs/WorkLocationsTab';

const OrganizationPage = ({ title }) => {
    const { auth } = usePage().props;
    const isMobile = useMediaQuery('(max-width: 640px)');
    const isDesktop = useMediaQuery('(min-width: 1025px)');

    /* ── shared state ─────────────────────────────────────── */
    const [activeTab, setActiveTab] = useState('employees');

    /* ── tab definitions ──────────────────────────────────── */
    const tabs = [
        { value: 'employees',      label: 'Employees',      icon: <PersonIcon /> },
        { value: 'departments',    label: 'Departments',    icon: <HomeIcon /> },
        { value: 'designations',   label: 'Designations',   icon: <LayersIcon /> },
        { value: 'work_locations', label: 'Work Locations', icon: <SewingPinIcon /> },
    ];

    /* ── render ───────────────────────────────────────────── */
    return (
        <>
            <Head title={title || 'Organization Directory'} />

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
                                            Organization Directory
                                        </Text>
                                        <Text size={{ initial: '1', md: '2' }} color="gray" as="div">
                                            Manage employees, departments, designations, and locations
                                        </Text>
                                    </Box>
                                </Flex>
                            </Flex>
                        </Box>

                        <Separator size="4" mb="4" />

                        {/* ══ TABS ═══════════════════════════════════════ */}
                        <Tabs.Root value={activeTab} onValueChange={setActiveTab}>
                            <Tabs.List style={{ marginBottom: 'var(--space-4)' }}>
                                {tabs.map(tab => (
                                    <Tabs.Trigger key={tab.value} value={tab.value}>
                                        <Flex align="center" gap="2">
                                            {tab.icon}
                                            {!isMobile && tab.label}
                                        </Flex>
                                    </Tabs.Trigger>
                                ))}
                            </Tabs.List>

                            {/* ── Employees Tab ─────────────────────────── */}
                            <Tabs.Content value="employees">
                                <Box mt="4">
                                    <EmployeesTab isActive={activeTab === 'employees'} />
                                </Box>
                            </Tabs.Content>

                            {/* ── Departments Tab ───────────────────────── */}
                            <Tabs.Content value="departments">
                                <Box mt="4">
                                    <DepartmentsTab isActive={activeTab === 'departments'} />
                                </Box>
                            </Tabs.Content>

                            {/* ── Designations Tab ──────────────────────── */}
                            <Tabs.Content value="designations">
                                <Box mt="4">
                                    <DesignationsTab isActive={activeTab === 'designations'} />
                                </Box>
                            </Tabs.Content>

                            {/* ── Work Locations Tab ────────────────────── */}
                            <Tabs.Content value="work_locations">
                                <Box mt="4">
                                    <WorkLocationsTab isActive={activeTab === 'work_locations'} />
                                </Box>
                            </Tabs.Content>
                        </Tabs.Root>

                    </Card>
                </Box>
            </Flex>
        </>
    );
};

OrganizationPage.layout = page => <App>{page}</App>;

export default OrganizationPage;