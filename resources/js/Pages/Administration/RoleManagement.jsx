import React, { useState, useMemo } from 'react';
import { Head, usePage } from '@inertiajs/react';
import { Badge, Box, Button, Flex, Grid, Heading, ScrollArea, Table, Tabs, Text, TextField, Separator } from '@radix-ui/themes';
import {
    ShieldCheckIcon,
    KeyIcon,
    UserGroupIcon,
    MagnifyingGlassIcon,
    LockClosedIcon,
} from '@heroicons/react/24/outline';
import App from '@/Layouts/App.jsx';
import { Panel } from '@/Components/ui/Panel';
import StatsCards from '@/Components/StatsCards';
import SearchFilterBar from '@/Components/SearchFilterBar';
import { useQueryFilters } from '@/Hooks/useQueryFilters';
import { usePersistentPageState } from '@/Hooks/usePersistentPageState';

const RoleManagement = ({ title, roles = [], permissions = [], permissionsGrouped = {}, role_has_permissions = [], enterprise_modules = [] }) => {
    /* The tab and the role being inspected are what the page is "showing", so
       they go in the URL: an admin can link a colleague straight to a role. */
    const f = useQueryFilters({
        mode: 'client',
        defaults: { tab: 'roles', role: '' },
        debounceKeys: [],
    });
    const ROLE_TABS = ['roles', 'permissions', 'modules'];
    const selectedTab = ROLE_TABS.includes(f.values.tab) ? f.values.tab : 'roles';
    const setSelectedTab = (value) => f.set('tab', value);

    // A role named in the URL may have been deleted, or may not be visible to
    // this admin — fall back to the first role rather than showing nothing.
    const urlRole = f.values.role === '' ? null : Number(f.values.role);
    const selectedRole = roles.some((r) => r.id === urlRole) ? urlRole : roles[0]?.id ?? null;
    const setSelectedRole = (value) => f.set('role', value);

    /* Searching the permission list is a transient lookup aid, not part of the
       view worth sharing, so it is remembered rather than put in the URL. */
    const [ui, setUi] = usePersistentPageState('Administration/RoleManagement', { searchTerm: '' });
    const searchTerm = ui.searchTerm;
    const setSearchTerm = (value) => setUi({ searchTerm: value });

    const rolePermissionsMap = useMemo(() => {
        const map = new Map();
        role_has_permissions.forEach((rel) => {
            if (!map.has(rel.role_id)) {
                map.set(rel.role_id, new Set());
            }
            map.get(rel.role_id).add(rel.permission_id);
        });
        return map;
    }, [role_has_permissions]);

    const activeRole = useMemo(() => {
        return roles.find((r) => r.id === selectedRole) || roles[0];
    }, [roles, selectedRole]);

    const filteredPermissions = useMemo(() => {
        if (!searchTerm) return permissions;
        return permissions.filter((p) =>
            p.name.toLowerCase().includes(searchTerm.toLowerCase())
        );
    }, [permissions, searchTerm]);

    return (
        <App>
            <Head title={title || 'Enterprise Role Management'} />
            <Flex justify="center" p="4">
                <Box style={{ width: '100%', maxWidth: 2000 }}>
                    <Panel>
                        {/* ── Page Header ── */}
                        <Box mb="4">
                            <Flex direction={{ initial: 'column', sm: 'row' }} align={{ initial: 'start', sm: 'center' }} justify="between" gap="4">
                                <Flex align="center" gap="3">
                                    <Box p="3" style={{ background: 'var(--blue-a3)', borderRadius: 12, border: '1px solid var(--blue-a5)', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                                        <ShieldCheckIcon style={{ width: 22, height: 22, color: 'var(--blue-9)' }} />
                                    </Box>
                                    <Box>
                                        <Heading size="5" style={{ fontFamily: `'Space Grotesk', system-ui, sans-serif`, fontWeight: 800, letterSpacing: '-0.02em' }}>Enterprise Role & Permission Management</Heading>
                                        <Text size="2" style={{ color: 'var(--aero-color-subtle, var(--gray-9))' }}>
                                            View and manage system access roles, security scopes, and enterprise permissions
                                        </Text>
                                    </Box>
                                </Flex>
                            </Flex>
                        </Box>

                        <Separator size="4" mb="4" style={{ background: 'var(--dl-border-color, rgba(0,0,0,0.06))' }} />

                        <StatsCards
                            stats={[
                                { key: 'roles', title: 'System Roles', value: roles.length, color: 'blue', icon: <ShieldCheckIcon /> },
                                { key: 'permissions', title: 'Total Permissions', value: permissions.length, color: 'indigo', icon: <KeyIcon /> },
                                { key: 'modules', title: 'Enterprise Modules', value: enterprise_modules.length || Object.keys(permissionsGrouped).length || 8, color: 'jade', icon: <UserGroupIcon /> },
                            ]}
                            variant="pill"
                            mb="4"
                        />

                        <Tabs.Root value={selectedTab} onValueChange={setSelectedTab}>
                            <Tabs.List>
                                <Tabs.Trigger value="roles">
                                    <Flex align="center" gap="2">
                                        <ShieldCheckIcon style={{ width: 16, height: 16 }} />
                                        Roles & Access ({roles.length})
                                    </Flex>
                                </Tabs.Trigger>
                                <Tabs.Trigger value="permissions">
                                    <Flex align="center" gap="2">
                                        <KeyIcon style={{ width: 16, height: 16 }} />
                                        All Permissions ({permissions.length})
                                    </Flex>
                                </Tabs.Trigger>
                                <Tabs.Trigger value="modules">
                                    <Flex align="center" gap="2">
                                        <UserGroupIcon style={{ width: 16, height: 16 }} />
                                        Module Matrix
                                    </Flex>
                                </Tabs.Trigger>
                            </Tabs.List>

                            <Box pt="4">
                                <Tabs.Content value="roles">
                                    <Grid columns={{ initial: '1', md: '3' }} gap="4">
                                        <Box>
                                            <Heading size="3" mb="3" style={{ fontFamily: `'Space Grotesk', system-ui, sans-serif`, fontWeight: 700 }}>System Roles</Heading>
                                            <Flex direction="column" gap="2">
                                                {roles.map((role) => {
                                                    const permCount = rolePermissionsMap.get(role.id)?.size || 0;
                                                    const isSelected = selectedRole === role.id;
                                                    return (
                                                        <Box
                                                            key={role.id}
                                                            p="3"
                                                            onClick={() => setSelectedRole(role.id)}
                                                            style={{
                                                                cursor: 'pointer',
                                                                borderRadius: 12,
                                                                background: isSelected ? 'var(--blue-a3)' : 'var(--gray-a2)',
                                                                border: isSelected ? '1px solid var(--blue-a6)' : '1px solid transparent',
                                                                transition: 'all 0.2s ease',
                                                            }}
                                                        >
                                                            <Flex justify="between" align="center">
                                                                <Box>
                                                                    <Text weight="bold" size="3" style={{ color: isSelected ? 'var(--blue-11)' : 'var(--gray-12)' }}>{role.name}</Text>
                                                                    <Text size="1" color="gray" as="div">Guard: {role.guard_name}</Text>
                                                                </Box>
                                                                <Badge color={isSelected ? 'blue' : 'gray'} variant="soft" style={{ borderRadius: 999 }}>{permCount} perms</Badge>
                                                            </Flex>
                                                        </Box>
                                                    );
                                                })}
                                            </Flex>
                                        </Box>

                                        <Box style={{ gridColumn: 'span 2' }}>
                                            {activeRole && (
                                                <Box style={{ borderRadius: 16, border: '1px solid var(--aero-surface-border, rgba(0,0,0,0.06))', padding: '20px' }}>
                                                    <Box>
                                                        <Flex justify="between" align="start" mb="4">
                                                            <Box>
                                                                <Heading size="4" style={{ fontFamily: `'Space Grotesk', system-ui, sans-serif` }}>{activeRole.name}</Heading>
                                                                <Text size="2" color="gray">
                                                                    Active security role with {rolePermissionsMap.get(activeRole.id)?.size || 0} granted capabilities.
                                                                </Text>
                                                            </Box>
                                                            <Badge color="green" size="2" style={{ borderRadius: 999 }}>Active Guard: {activeRole.guard_name}</Badge>
                                                        </Flex>

                                                        <SearchFilterBar
                                                            searchValue={searchTerm}
                                                            onSearchChange={setSearchTerm}
                                                            searchPlaceholder="Filter granted permissions..."
                                                            mb="4"
                                                        />

                                                        <ScrollArea style={{ maxHeight: 500 }}>
                                                            <Flex wrap="wrap" gap="2">
                                                                {permissions
                                                                    .filter((p) => rolePermissionsMap.get(activeRole.id)?.has(p.id))
                                                                    .filter((p) => !searchTerm || p.name.toLowerCase().includes(searchTerm.toLowerCase()))
                                                                    .map((perm) => (
                                                                        <Badge key={perm.id} color="indigo" variant="soft" size="2" style={{ borderRadius: 8 }}>
                                                                            <LockClosedIcon style={{ width: 12, height: 12, marginRight: 4 }} />
                                                                            {perm.name}
                                                                        </Badge>
                                                                    ))}
                                                            </Flex>
                                                        </ScrollArea>
                                                    </Box>
                                                </Box>
                                            )}
                                        </Box>
                                    </Grid>
                                </Tabs.Content>

                                <Tabs.Content value="permissions">
                                    <Box style={{ borderRadius: 16, border: '1px solid var(--aero-surface-border, rgba(0,0,0,0.06))', padding: '20px' }}>
                                        <Box>
                                            <SearchFilterBar
                                                searchValue={searchTerm}
                                                onSearchChange={setSearchTerm}
                                                searchPlaceholder="Search all permissions..."
                                                mb="4"
                                            />

                                            <Box style={{ overflowX: 'auto', WebkitOverflowScrolling: 'touch', borderRadius: 14, border: '1px solid var(--aero-surface-border, rgba(0,0,0,0.06))', background: 'var(--aero-surface, var(--color-background))' }}>
                                                <Table.Root size="2" style={{ minWidth: 680, width: '100%' }}>
                                                    <Table.Header style={{
                                                        position: 'sticky',
                                                        top: 0,
                                                        zIndex: 2,
                                                        background: 'var(--aero-surface, var(--color-background))',
                                                        backdropFilter: 'blur(8px)',
                                                        boxShadow: '0 1px 0 var(--dl-border-color, rgba(0,0,0,0.06))'
                                                    }}>
                                                        <Table.Row>
                                                            <Table.ColumnHeaderCell style={{ minWidth: 200, background: 'inherit' }}>Permission Identifier</Table.ColumnHeaderCell>
                                                            <Table.ColumnHeaderCell style={{ minWidth: 100, background: 'inherit' }}>Guard</Table.ColumnHeaderCell>
                                                            <Table.ColumnHeaderCell style={{ minWidth: 200, background: 'inherit' }}>Assigned Roles</Table.ColumnHeaderCell>
                                                        </Table.Row>
                                                    </Table.Header>
                                                    <Table.Body>
                                                        {filteredPermissions.slice(0, 100).map((perm) => {
                                                            const assignedRoles = roles.filter((r) => rolePermissionsMap.get(r.id)?.has(perm.id));
                                                            return (
                                                                <Table.Row key={perm.id} align="center">
                                                                    <Table.Cell>
                                                                        <Text weight="bold" size="2">{perm.name}</Text>
                                                                    </Table.Cell>
                                                                    <Table.Cell>
                                                                        <Badge size="1" color="gray" variant="soft" style={{ borderRadius: 999 }}>{perm.guard_name}</Badge>
                                                                    </Table.Cell>
                                                                    <Table.Cell>
                                                                        <Flex wrap="wrap" gap="1">
                                                                            {assignedRoles.map((r) => (
                                                                                <Badge key={r.id} color="blue" size="1" variant="soft" style={{ borderRadius: 999 }}>{r.name}</Badge>
                                                                            ))}
                                                                        </Flex>
                                                                    </Table.Cell>
                                                                </Table.Row>
                                                            );
                                                        })}
                                                    </Table.Body>
                                                </Table.Root>
                                            </Box>
                                        </Box>
                                    </Box>
                                </Tabs.Content>

                                <Tabs.Content value="modules">
                                    <Panel tinted style={{ borderRadius: 16, border: '1px solid var(--aero-surface-border, rgba(0,0,0,0.06))', padding: '20px' }}>
                                        <Box>
                                            <Heading size="3" mb="3" style={{ fontFamily: `'Space Grotesk', system-ui, sans-serif`, fontWeight: 700 }}>Module Permission Hierarchy</Heading>
                                            <Grid columns={{ initial: '1', md: '2' }} gap="4">
                                                {Object.entries(permissionsGrouped).map(([moduleName, perms]) => (
                                                    <Box key={moduleName} p="3" style={{ borderRadius: 14, border: '1px solid var(--aero-surface-border, rgba(0,0,0,0.06))', background: 'var(--aero-surface, var(--color-background))' }}>
                                                        <Heading size="2" mb="2" style={{ textTransform: 'capitalize', fontFamily: `'Space Grotesk', system-ui, sans-serif` }}>
                                                            {moduleName} Module ({perms.length})
                                                        </Heading>
                                                        <Flex wrap="wrap" gap="1">
                                                            {perms.map((p) => (
                                                                <Badge key={p.id} size="1" color="purple" variant="outline" style={{ borderRadius: 8 }}>
                                                                    {p.name}
                                                                </Badge>
                                                            ))}
                                                        </Flex>
                                                    </Box>
                                                ))}
                                            </Grid>
                                        </Box>
                                    </Panel>
                                </Tabs.Content>
                            </Box>
                        </Tabs.Root>
                    </Panel>
                </Box>
            </Flex>
        </App>
    );
};

export default RoleManagement;
