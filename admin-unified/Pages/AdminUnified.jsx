/**
 * AdminUnified.jsx
 * Single-page admin shell: Users | Roles & Permissions | Biometric Devices
 * Pure Radix UI — no Tailwind, no HeroUI, no custom CSS
 */
import React, { useState, useEffect, useCallback, useMemo } from 'react';
import { Head } from '@inertiajs/react';
import {
    Badge, Box, Button, Card, Flex, Heading, IconButton,
    Separator, Spinner, Tabs, Text, TextField,
} from '@radix-ui/themes';
import {
    GearIcon, LockClosedIcon, MagnifyingGlassIcon,
    PersonIcon, PlusIcon, ReloadIcon,
} from '@radix-ui/react-icons';
import axios from 'axios';
import App from '@/Layouts/App.jsx';
import { showToast } from '@/utils/toastUtils';
import { useMediaQuery } from '@/Hooks/useMediaQuery.js';

// Tab panels (split files for load-balancing)
import UsersPanel from '@/Components/AdminUnified/UsersPanel.jsx';
import RolesPanel from '@/Components/AdminUnified/RolesPanel.jsx';
import BiometricPanel from '@/Components/AdminUnified/BiometricPanel.jsx';

/* ── page shell ────────────────────────────────────────────────────────────── */

const AdminUnified = ({
    title,
    // Users
    roles = [],
    departments = [],
    designations = [],
    // Roles & permissions
    permissions = [],
    role_has_permissions = [],
    permissionsGrouped = {},
    can_manage_super_admin = false,
    users: initialUsers = [],
    // Biometric
    devices: initialDevices = [],
    employees = [],
}) => {
    const isMobile = useMediaQuery('(max-width: 640px)');
    const [activeTab, setActiveTab] = useState('users');

    /* ── shared refresh counters ── */
    const [usersTick,  setUsersTick]  = useState(0);
    const [rolesTick,  setRolesTick]  = useState(0);
    const [bioTick,    setBioTick]    = useState(0);

    /* ── per-tab quick stats (header badges) ── */
    const [quickStats, setQuickStats] = useState({
        totalUsers:  initialUsers.length,
        totalRoles:  roles.length,
        totalDevices: initialDevices.length,
    });

    /* ── header action buttons — injected by each panel via callback ── */
    const [headerActions, setHeaderActions] = useState(null);

    return (
        <>
            <Head title={title || 'Admin'} />

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
                                        <GearIcon style={{ width: 22, height: 22, color: 'var(--accent-9)' }} />
                                    </Box>
                                    <Box>
                                        <Heading size="5">Admin Console</Heading>
                                        <Text size="2" color="gray">
                                            Users · Roles & Permissions · Biometric Devices
                                        </Text>
                                    </Box>
                                </Flex>

                                {/* Dynamic actions per tab */}
                                <Flex gap="2" align="center" wrap="wrap">
                                    {headerActions}
                                </Flex>
                            </Flex>
                        </Box>

                        <Separator size="4" mb="4" />

                        {/* ── Tabs ── */}
                        <Tabs.Root value={activeTab} onValueChange={setActiveTab}>
                            <Tabs.List mb="4">
                                <Tabs.Trigger value="users">
                                    <Flex align="center" gap="2">
                                        <PersonIcon />
                                        {!isMobile && 'Users'}
                                        <Badge size="1" variant="soft" color="blue" radius="full">
                                            {quickStats.totalUsers}
                                        </Badge>
                                    </Flex>
                                </Tabs.Trigger>

                                <Tabs.Trigger value="roles">
                                    <Flex align="center" gap="2">
                                        <LockClosedIcon />
                                        {!isMobile && 'Roles & Permissions'}
                                        <Badge size="1" variant="soft" color="violet" radius="full">
                                            {quickStats.totalRoles}
                                        </Badge>
                                    </Flex>
                                </Tabs.Trigger>

                                <Tabs.Trigger value="biometric">
                                    <Flex align="center" gap="2">
                                        <GearIcon />
                                        {!isMobile && 'Biometric Devices'}
                                        <Badge size="1" variant="soft" color="green" radius="full">
                                            {quickStats.totalDevices}
                                        </Badge>
                                    </Flex>
                                </Tabs.Trigger>
                            </Tabs.List>

                            {/* ── Users ── */}
                            <Tabs.Content value="users">
                                <UsersPanel
                                    roles={roles}
                                    departments={departments}
                                    designations={designations}
                                    isMobile={isMobile}
                                    tick={usersTick}
                                    onCountChange={n => setQuickStats(p => ({ ...p, totalUsers: n }))}
                                    onSetHeaderActions={setHeaderActions}
                                    isActive={activeTab === 'users'}
                                />
                            </Tabs.Content>

                            {/* ── Roles & Permissions ── */}
                            <Tabs.Content value="roles">
                                <RolesPanel
                                    roles={roles}
                                    permissions={permissions}
                                    roleHasPermissions={role_has_permissions}
                                    permissionsGrouped={permissionsGrouped}
                                    users={initialUsers}
                                    canManageSuperAdmin={can_manage_super_admin}
                                    isMobile={isMobile}
                                    tick={rolesTick}
                                    onCountChange={n => setQuickStats(p => ({ ...p, totalRoles: n }))}
                                    onSetHeaderActions={setHeaderActions}
                                    isActive={activeTab === 'roles'}
                                />
                            </Tabs.Content>

                            {/* ── Biometric Devices ── */}
                            <Tabs.Content value="biometric">
                                <BiometricPanel
                                    initialDevices={initialDevices}
                                    employees={employees}
                                    isMobile={isMobile}
                                    tick={bioTick}
                                    onCountChange={n => setQuickStats(p => ({ ...p, totalDevices: n }))}
                                    onSetHeaderActions={setHeaderActions}
                                    isActive={activeTab === 'biometric'}
                                />
                            </Tabs.Content>
                        </Tabs.Root>
                    </Card>
                </Box>
            </Flex>
        </>
    );
};

AdminUnified.layout = page => <App>{page}</App>;
export default AdminUnified;
