/**
 * UsersPanel.jsx
 * Users tab — table with inline device-lock toggle, role dropdown, status toggle.
 * Pure Radix UI.
 */
import React, { useState, useCallback, useEffect, useMemo, useRef } from 'react';
import { Link, router } from '@inertiajs/react';
import {
    Badge, Box, Button, Card, Checkbox, Dialog, DropdownMenu, Flex,
    Grid, IconButton, ScrollArea, Select, Separator, Spinner,
    Switch, Table, Tabs, Text, TextField,
} from '@radix-ui/themes';
import {
    BackpackIcon, ChevronLeftIcon, ChevronRightIcon, Cross2Icon,
    DotsVerticalIcon, EnvelopeClosedIcon, HomeIcon, LockClosedIcon,
    LockOpen1Icon, MagnifyingGlassIcon, MobileIcon, Pencil1Icon,
    PersonIcon, PlusIcon, ReloadIcon, ResetIcon, TrashIcon, CheckIcon,
} from '@radix-ui/react-icons';
import axios from 'axios';
import { showToast } from '@/utils/toastUtils';
import ProfileAvatar from '@/Components/ProfileAvatar.jsx';

/* ── helpers ── */
function StatPill({ label, value, color = 'gray' }) {
    return (
        <Badge size="2" variant="soft" color={color} radius="full">
            <Text weight="bold">{value}</Text>
            <Text style={{ opacity: 0.7 }}> {label}</Text>
        </Badge>
    );
}

/* ── main ── */
export default function UsersPanel({
    roles = [], departments = [], designations = [],
    isMobile, tick, onCountChange, onSetHeaderActions, isActive,
}) {
    /* ── state ── */
    const [users, setUsers]           = useState([]);
    const [loading, setLoading]       = useState(true);
    const [search, setSearch]         = useState('');
    const [filterRole, setFilterRole] = useState('all');
    const [filterStatus, setFilterStatus] = useState('all');
    const [filterDept, setFilterDept]   = useState('all');
    const [showDeleted, setShowDeleted] = useState(false);
    const [showFilters, setShowFilters]   = useState(false);
    const [pagination, setPagination] = useState({ currentPage: 1, perPage: 15, total: 0 });
    const [devAction, setDevAction]   = useState({}); // { [userId]: bool }
    const [stats, setStats]           = useState({ total: 0, active: 0, inactive: 0 });

    /* ── bulk selection ── */
    const [selectedIds, setSelectedIds] = useState([]);
    const [bulkAction, setBulkAction]   = useState(null); // 'role' | 'status' | 'delete'
    const [bulkLoading, setBulkLoading] = useState(false);
    const [bulkDialogOpen, setBulkDialogOpen] = useState(false);

    /* ── delete dialog ── */
    const [delUser, setDelUser]   = useState(null);
    const [delLoading, setDelLoading] = useState(false);

    /* ── role dialog ── */
    const [roleDialogUser, setRoleDialogUser] = useState(null);
    const [roleDialogSelected, setRoleDialogSelected] = useState(new Set());
    const [roleSaving, setRoleSaving] = useState(false);

    const openRoleDialog = (user) => {
        // Always key by name — reliable regardless of whether API returns role IDs
        const current = new Set(
            (user.roles || []).map(r => (typeof r === 'object' ? r.name : r))
        );
        setRoleDialogSelected(current);
        setRoleDialogUser(user);
    };

    const saveRoles = async () => {
        if (!roleDialogUser) return;
        setRoleSaving(true);
        try {
            const roleNames = Array.from(roleDialogSelected); // keys are already names
            await axios.post(route('users.updateRole', { id: roleDialogUser.id }), { roles: roleNames });
            updateUser(roleDialogUser.id, {
                roles: roleNames.map(n => ({ name: n })),
            });
            setRoleDialogUser(null);
            showToast.success('Roles updated.');
        } catch {
            showToast.error('Failed to update roles.');
        } finally {
            setRoleSaving(false);
        }
    };

    /* ── debounce ── */
    const debRef = useRef(null);
    const triggerSearch = useCallback((val) => {
        clearTimeout(debRef.current);
        debRef.current = setTimeout(() => setSearch(val), 280);
    }, []);

    /* ── fetch ── */
    const fetchUsers = useCallback(async () => {
        setLoading(true);
        try {
            const { data } = await axios.get(route('users.paginate'), {
                params: {
                    page: pagination.currentPage,
                    perPage: pagination.perPage,
                    search: search || undefined,
                    role: filterRole !== 'all' ? filterRole : undefined,
                    status: filterStatus !== 'all' ? filterStatus : undefined,
                    department: filterDept !== 'all' ? filterDept : undefined,
                    showDeleted: showDeleted || undefined,
                },
            });
            const list = data.users?.data ?? data.users ?? [];
            const total = data.users?.meta?.total ?? data.users?.total ?? list.length;
            setUsers(list);
            setPagination(p => ({ ...p, total }));
            onCountChange?.(total);
            if (data.stats) {
                setStats({
                    total:    data.stats.overview?.total_users   ?? total,
                    active:   data.stats.overview?.active_users  ?? 0,
                    inactive: data.stats.overview?.inactive_users ?? 0,
                });
            }
        } catch {
            showToast.error('Failed to load users.');
        } finally {
            setLoading(false);
        }
    }, [pagination.currentPage, pagination.perPage, search, filterRole, filterStatus, filterDept, showDeleted]);

    useEffect(() => { if (isActive) fetchUsers(); }, [fetchUsers, tick, isActive]);

    /* ── header actions ── */
    useEffect(() => {
        if (!isActive) return;
        onSetHeaderActions?.(
            <Button size="2" onClick={() => router.visit(route('users.create'))}>
                <PlusIcon /> {!isMobile && 'Add User'}
            </Button>
        );
    }, [isActive, isMobile]);

    /* ── optimistic helpers ── */
    const updateUser = useCallback((id, fields) =>
        setUsers(p => p.map(u => u.id === id ? { ...u, ...fields } : u)), []);

    /* ── status toggle ── */
    const toggleStatus = async (user) => {
        const newActive = !user.active;
        updateUser(user.id, { active: newActive });
        try {
            const { data } = await axios.post(
                route('users.toggleStatus', { id: user.id }),
                { active: newActive }
            );
            // Sync with server response (may include deleted_at changes)
            if (data.user) updateUser(user.id, data.user);
            showToast.success('Status updated.');
        } catch {
            updateUser(user.id, { active: user.active }); // rollback
            showToast.error('Failed to update status.');
        }
    };

    /* ── device lock toggle ── */
    const toggleDeviceLock = async (user) => {
        setDevAction(p => ({ ...p, [user.id]: true }));
        try {
            const { data } = await axios.post(route('admin.users.devices.toggle', { userId: user.id }));
            updateUser(user.id, { single_device_login_enabled: data.single_device_login_enabled });
            showToast.success(data.message || 'Device lock updated.');
        } catch (e) {
            showToast.error(e.response?.data?.message || 'Failed to toggle device lock.');
        } finally {
            setDevAction(p => ({ ...p, [user.id]: false }));
        }
    };


    /* ── restore ── */
    const restoreUser = async (user) => {
        try {
            await axios.post(route('users.restore', { id: user.id }));
            setUsers(p => p.filter(u => u.id !== user.id));
            setPagination(p => ({ ...p, total: p.total - 1 }));
            showToast.success(`${user.name} restored.`);
        } catch (e) {
            showToast.error(e.response?.data?.message || 'Failed to restore user.');
        }
    };

    /* ── delete ── */
    const confirmDelete = async () => {
        if (!delUser) return;
        setDelLoading(true);
        try {
            await axios.delete(route('profile.delete'), {
                data: { user_id: delUser.id },
            });
            setUsers(p => p.filter(u => u.id !== delUser.id));
            setPagination(p => ({ ...p, total: p.total - 1 }));
            onCountChange?.(pagination.total - 1);
            setDelUser(null);
            showToast.success('User deleted.');
        } catch (e) {
            showToast.error(e.response?.data?.message || 'Failed to delete user.');
        } finally {
            setDelLoading(false);
        }
    };

    /* ── bulk selection ── */
    const toggleSelect = (id) => {
        setSelectedIds(p => p.includes(id) ? p.filter(x => x !== id) : [...p, id]);
    };

    const toggleSelectAll = () => {
        if (selectedIds.length === users.length && selectedIds.length > 0) {
            setSelectedIds([]);
        } else {
            setSelectedIds(users.map(u => u.id));
        }
    };

    const clearSelection = () => {
        setSelectedIds([]);
        setBulkAction(null);
    };

    /* ── bulk actions ── */
    const handleBulkRole = async (roleName) => {
        setBulkLoading(true);
        try {
            const { data } = await axios.post(route('users.bulk.role'), {
                user_ids: selectedIds,
                role: roleName,
            });
            showToast.success(data.message);
            fetchUsers();
            clearSelection();
        } catch (e) {
            showToast.error(e.response?.data?.message || 'Failed to assign role.');
        } finally {
            setBulkLoading(false);
            setBulkAction(null);
        }
    };

    const handleBulkStatus = async (status) => {
        setBulkLoading(true);
        try {
            const { data } = await axios.post(route('users.bulk.status'), {
                user_ids: selectedIds,
                active: status,
            });
            showToast.success(data.message);
            fetchUsers();
            clearSelection();
        } catch (e) {
            showToast.error(e.response?.data?.message || 'Failed to update status.');
        } finally {
            setBulkLoading(false);
            setBulkAction(null);
        }
    };

    const handleBulkDelete = async () => {
        setBulkLoading(true);
        try {
            const { data } = await axios.post(route('users.bulk.delete'), {
                user_ids: selectedIds,
            });
            showToast.success(data.message);
            fetchUsers();
            clearSelection();
            setBulkDialogOpen(false);
        } catch (e) {
            showToast.error(e.response?.data?.message || 'Failed to delete users.');
        } finally {
            setBulkLoading(false);
        }
    };

    /* ── pagination ── */
    const totalPages = Math.ceil(pagination.total / pagination.perPage);
    const startRow   = (pagination.currentPage - 1) * pagination.perPage + 1;
    const endRow     = Math.min(pagination.currentPage * pagination.perPage, pagination.total);
    const pageNums   = useMemo(() => {
        const total = totalPages;
        const cur   = pagination.currentPage;
        if (total <= 5) return Array.from({ length: total }, (_, i) => i + 1);
        if (cur <= 3)   return [1, 2, 3, 4, 5];
        if (cur >= total - 2) return [total - 4, total - 3, total - 2, total - 1, total];
        return [cur - 2, cur - 1, cur, cur + 1, cur + 2];
    }, [totalPages, pagination.currentPage]);

    const hasActiveFilters = filterRole !== 'all' || filterStatus !== 'all' || filterDept !== 'all' || showDeleted || search;

    /* ── section split (reactive) ── */
    const activeUsers   = users.filter(u => u.active);
    const inactiveUsers = users.filter(u => !u.active);

    /* ── render ── */
    return (
        <Box>
            {/* ── Stats ── */}
            <Flex wrap="wrap" gap="2" mb="4">
                <StatPill label="Total"    value={stats.total}    color="blue" />
                <StatPill label="Active"   value={stats.active}   color="green" />
                <StatPill label="Inactive" value={stats.inactive} color="red" />
            </Flex>

            {/* ── Toolbar ── */}
            <Flex direction={{ initial: 'column', sm: 'row' }} gap="3" align={{ initial: 'stretch', sm: 'center' }} mb="3">
                <Box style={{ flex: 1, minWidth: 200 }}>
                    <TextField.Root
                        placeholder="Search by name, email…"
                        onChange={e => triggerSearch(e.target.value)}
                        size="2"
                    >
                        <TextField.Slot><MagnifyingGlassIcon /></TextField.Slot>
                    </TextField.Root>
                </Box>
                <Flex gap="2">
                    <Button
                        size="2"
                        variant={showFilters ? 'solid' : 'surface'}
                        color={showFilters ? 'indigo' : 'gray'}
                        onClick={() => setShowFilters(v => !v)}
                    >
                        Filters
                    </Button>
                    <IconButton size="2" variant="soft" color="gray" onClick={fetchUsers} aria-label="Refresh">
                        <ReloadIcon />
                    </IconButton>
                </Flex>
            </Flex>

            {/* ── Bulk Actions Toolbar ── */}
            {selectedIds.length > 0 && (
                <Card size="2" variant="surface" mb="3" style={{ background: 'var(--indigo-a3)', border: '1px solid var(--indigo-a7)' }}>
                    <Flex align="center" justify="between" gap="3">
                        <Flex align="center" gap="2">
                            <CheckIcon style={{ color: 'var(--indigo-9)' }} />
                            <Text size="2" weight="medium">{selectedIds.length} user(s) selected</Text>
                        </Flex>
                        <Flex gap="2">
                            <DropdownMenu.Root>
                                <DropdownMenu.Trigger>
                                    <Button size="2" variant="solid" color="indigo" disabled={bulkLoading}>
                                        {bulkLoading ? <Spinner size="1" /> : 'Assign Role'}
                                    </Button>
                                </DropdownMenu.Trigger>
                                <DropdownMenu.Content size="1">
                                    {roles.map(r => {
                                        const name = typeof r === 'object' ? r.name : r;
                                        return (
                                            <DropdownMenu.Item key={name} onSelect={() => handleBulkRole(name)}>
                                                {name}
                                            </DropdownMenu.Item>
                                        );
                                    })}
                                </DropdownMenu.Content>
                            </DropdownMenu.Root>
                            <Button size="2" variant="soft" color="green" disabled={bulkLoading} onClick={() => handleBulkStatus(true)}>
                                {bulkLoading ? <Spinner size="1" /> : 'Set Active'}
                            </Button>
                            <Button size="2" variant="soft" color="red" disabled={bulkLoading} onClick={() => handleBulkStatus(false)}>
                                {bulkLoading ? <Spinner size="1" /> : 'Set Inactive'}
                            </Button>
                            <Button size="2" variant="soft" color="red" disabled={bulkLoading} onClick={() => setBulkDialogOpen(true)}>
                                {bulkLoading ? <Spinner size="1" /> : <><TrashIcon /> Delete</>}
                            </Button>
                            <IconButton size="2" variant="ghost" color="gray" onClick={clearSelection} aria-label="Clear selection">
                                <Cross2Icon />
                            </IconButton>
                        </Flex>
                    </Flex>
                </Card>
            )}

            {/* ── Filter Panel ── */}
            {showFilters && (
                <Card size="2" variant="surface" mb="3">
                    <Grid columns={{ initial: '1', sm: '2', md: '4' }} gap="4" align="end">
                        <Box>
                            <Text size="2" color="gray" weight="medium" as="div" mb="1">Status</Text>
                            <Select.Root size="2" value={filterStatus} onValueChange={v => { setFilterStatus(v); setPagination(p => ({ ...p, currentPage: 1 })); }}>
                                <Select.Trigger style={{ width: '100%' }} />
                                <Select.Content>
                                    <Select.Item value="all">All Status</Select.Item>
                                    <Select.Item value="active">Active</Select.Item>
                                    <Select.Item value="inactive">Inactive</Select.Item>
                                </Select.Content>
                            </Select.Root>
                        </Box>
                        <Box>
                            <Text size="2" color="gray" weight="medium" as="div" mb="1">Role</Text>
                            <Select.Root size="2" value={filterRole} onValueChange={v => { setFilterRole(v); setPagination(p => ({ ...p, currentPage: 1 })); }}>
                                <Select.Trigger style={{ width: '100%' }} />
                                <Select.Content>
                                    <Select.Item value="all">All Roles</Select.Item>
                                    {roles.map(r => {
                                        const name = typeof r === 'object' ? r.name : r;
                                        return <Select.Item key={name} value={name}>{name}</Select.Item>;
                                    })}
                                </Select.Content>
                            </Select.Root>
                        </Box>
                            <Box>
                            <Text size="2" color="gray" weight="medium" as="div" mb="1">Department</Text>
                            <Select.Root size="2" value={filterDept} onValueChange={v => { setFilterDept(v); setPagination(p => ({ ...p, currentPage: 1 })); }}>
                                <Select.Trigger style={{ width: '100%' }} />
                                <Select.Content>
                                    <Select.Item value="all">All Departments</Select.Item>
                                    {departments.map(d => (
                                        <Select.Item key={d.id} value={String(d.id)}>{d.name}</Select.Item>
                                    ))}
                                </Select.Content>
                            </Select.Root>
                        </Box>
                        <Flex align="end" gap="2">
                            <Button size="2" variant={showDeleted ? 'solid' : 'soft'} color="amber"
                                onClick={() => { setShowDeleted(v => !v); setPagination(p => ({ ...p, currentPage: 1 })); }}
                                style={{ flex: 1 }}>
                                {showDeleted ? 'Hide Deleted' : 'Show Deleted'}
                            </Button>
                            <Button size="2" variant="soft" color="red" disabled={!hasActiveFilters}
                                onClick={() => { setFilterRole('all'); setFilterStatus('all'); setFilterDept('all'); setShowDeleted(false); setSearch(''); setPagination(p => ({ ...p, currentPage: 1 })); }}
                                style={{ flex: 1 }}>
                                <Cross2Icon /> Clear
                            </Button>
                        </Flex>
                    </Grid>
                </Card>
            )}

            {/* ── Active filter chips ── */}
            {hasActiveFilters && (
                <Flex wrap="wrap" gap="2" align="center" mb="3">
                    <Text size="2" color="gray">Active:</Text>
                    {search       && <Badge size="2" variant="soft" color="gray"   radius="full">Search: "{search}"</Badge>}
                    {filterStatus !== 'all' && <Badge size="2" variant="soft" color="blue"   radius="full">Status: {filterStatus}</Badge>}
                    {filterRole   !== 'all' && <Badge size="2" variant="soft" color="violet" radius="full">Role: {filterRole}</Badge>}
                    {filterDept   !== 'all' && <Badge size="2" variant="soft" color="orange" radius="full">Dept: {departments.find(d => String(d.id) === filterDept)?.name ?? filterDept}</Badge>}
                    {showDeleted  && <Badge size="2" variant="soft" color="red"    radius="full">Showing deleted</Badge>}
                </Flex>
            )}

            {/* ── Section header ── */}
            <Flex align="center" justify="between" mb="3">
                <Flex align="center" gap="2">
                    <PersonIcon />
                    <Text size="3" weight="medium">Users</Text>
                    {!loading && <Badge size="1" variant="soft" color="gray" radius="full">{pagination.total}</Badge>}
                </Flex>
                {!loading && pagination.total > 0 && (
                    <Text size="1" color="gray">{startRow}–{endRow} of {pagination.total}</Text>
                )}
            </Flex>

            {/* ── Table ── */}
            {loading ? (
                <Flex align="center" justify="center" py="9" gap="3" direction="column">
                    <Spinner size="3" /><Text color="gray" size="2">Loading users…</Text>
                </Flex>
            ) : users.length === 0 ? (
                <Flex direction="column" align="center" justify="center" py="9" gap="2">
                    <PersonIcon style={{ width: 36, height: 36, color: 'var(--gray-9)' }} />
                    <Text size="3" weight="medium">No users found</Text>
                    <Text size="2" color="gray">Try adjusting your search or filters.</Text>
                </Flex>
            ) : (
                <Box style={{ overflowX: 'auto' }}>
                    <Table.Root variant="surface" size="2">
                        <Table.Header>
                            <Table.Row>
                                <Table.ColumnHeaderCell style={{ width: 40 }}>
                                    <Checkbox
                                        checked={selectedIds.length === users.length && users.length > 0}
                                        onCheckedChange={toggleSelectAll}
                                    />
                                </Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell style={{ width: 40 }}>#</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>User</Table.ColumnHeaderCell>
                                {!isMobile && <Table.ColumnHeaderCell>Contact</Table.ColumnHeaderCell>}
                                <Table.ColumnHeaderCell>Role</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell style={{ width: 90 }}>Status</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell style={{ width: 110 }}>Device Lock</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell style={{ width: 50, textAlign: 'center' }}>⋯</Table.ColumnHeaderCell>
                            </Table.Row>
                        </Table.Header>
                        <Table.Body>
                            {[...activeUsers, ...inactiveUsers].map((user, idx) => {
                                const isFirstInactive = !user.active && idx === activeUsers.length;
                                const serial = startRow + idx;
                                const userRoles = Array.isArray(user.roles)
                                    ? [...new Set(user.roles.map(r => typeof r === 'object' ? r.name : r).filter(Boolean))]
                                    : [];
                                const isLocking = devAction[user.id];

                                return (
                                    <React.Fragment key={user.id}>
                                    {isFirstInactive && (
                                        <Table.Row>
                                            <Table.Cell colSpan={isMobile ? 5 : 7} style={{ padding: '6px 12px', background: 'var(--red-a2)', borderTop: '1px solid var(--red-a5)' }}>
                                                <Flex align="center" gap="2">
                                                    <Badge size="1" color="red" variant="soft" radius="full">
                                                        {inactiveUsers.length} Deactivated
                                                    </Badge>
                                                    <Text size="1" color="gray">Toggle the switch to re-activate</Text>
                                                </Flex>
                                            </Table.Cell>
                                        </Table.Row>
                                    )}
                                    <Table.Row style={!user.active ? { opacity: 0.65 } : undefined}>
                                        {/* Checkbox */}
                                        <Table.Cell>
                                            <Checkbox
                                                checked={selectedIds.includes(user.id)}
                                                onCheckedChange={() => toggleSelect(user.id)}
                                            />
                                        </Table.Cell>
                                        {/* # */}
                                        <Table.Cell>
                                            <Text size="1" color="gray" weight="medium">{serial}</Text>
                                        </Table.Cell>

                                        {/* User */}
                                        <Table.Cell>
                                            <Flex align="center" gap="3">
                                                <Box style={{ flexShrink: 0 }}>
                                                    <ProfileAvatar
                                                        src={user.profile_image_url || user.profile_image}
                                                        name={user.name}
                                                        size={isMobile ? 'sm' : 'md'}
                                                    />
                                                </Box>
                                                <Box>
                                                    <Text weight="bold" size="2" as="div" style={{ whiteSpace: 'nowrap' }}>
                                                        {user.name}
                                                    </Text>
                                                    <Text size="1" color="gray" as="div">ID: {user.id}</Text>
                                                    {isMobile && (
                                                        <Flex align="center" gap="1" mt="1">
                                                            <EnvelopeClosedIcon style={{ width: 11, height: 11 }} />
                                                            <Text size="1" color="gray" style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', maxWidth: 140 }}>
                                                                {user.email}
                                                            </Text>
                                                        </Flex>
                                                    )}
                                                </Box>
                                            </Flex>
                                        </Table.Cell>

                                        {/* Contact */}
                                        {!isMobile && (
                                            <Table.Cell>
                                                <Flex direction="column" gap="1">
                                                    <Flex align="center" gap="2">
                                                        <EnvelopeClosedIcon style={{ width: 13, height: 13, color: 'var(--gray-9)', flexShrink: 0 }} />
                                                        <Text size="1" color="gray" style={{ whiteSpace: 'nowrap' }}>{user.email}</Text>
                                                    </Flex>
                                                    {user.phone && (
                                                        <Flex align="center" gap="2">
                                                            <MobileIcon style={{ width: 13, height: 13, color: 'var(--gray-9)', flexShrink: 0 }} />
                                                            <Text size="1" color="gray">{user.phone}</Text>
                                                        </Flex>
                                                    )}
                                                </Flex>
                                            </Table.Cell>
                                        )}

                                        {/* Role */}
                                        <Table.Cell>
                                            <Box style={{ minWidth: 150 }}>
                                                {userRoles.length > 0 && (
                                                    <Flex gap="1" wrap="wrap" mb="1">
                                                        {userRoles.slice(0, 2).map(r => (
                                                            <Badge key={r} size="1" variant="soft" color="violet" radius="full">{r}</Badge>
                                                        ))}
                                                        {userRoles.length > 2 && (
                                                            <Badge size="1" variant="soft" color="gray" radius="full">+{userRoles.length - 2}</Badge>
                                                        )}
                                                    </Flex>
                                                )}
                                                <Button size="1" variant="ghost" color="indigo" style={{ width: '100%', justifyContent: 'flex-start' }}
                                                    onClick={() => openRoleDialog(user)}>
                                                    <Text size="1" color="indigo">Edit roles…</Text>
                                                </Button>
                                            </Box>
                                        </Table.Cell>

                                        {/* Status */}
                                        <Table.Cell>
                                            <Flex align="center" gap="2">
                                                <Switch
                                                    size="1"
                                                    checked={!!user.active}
                                                    onCheckedChange={() => toggleStatus(user)}
                                                    color={user.active ? 'green' : 'red'}
                                                />
                                                <Text size="1" color={user.active ? 'green' : 'red'} weight="medium">
                                                    {user.active ? 'Active' : 'Inactive'}
                                                </Text>
                                            </Flex>
                                        </Table.Cell>

                                        {/* Device Lock */}
                                        <Table.Cell>
                                            <Flex align="center" gap="2">
                                                {isLocking ? (
                                                    <Spinner size="1" />
                                                ) : (
                                                    <Switch
                                                        size="1"
                                                        checked={!!user.single_device_login_enabled}
                                                        onCheckedChange={() => toggleDeviceLock(user)}
                                                        color="amber"
                                                    />
                                                )}
                                                <Badge size="1" variant="soft"
                                                    color={user.single_device_login_enabled ? 'amber' : 'gray'}>
                                                    {user.single_device_login_enabled
                                                        ? <><LockClosedIcon style={{ width: 9, height: 9 }} /> On</>
                                                        : <><LockOpen1Icon  style={{ width: 9, height: 9 }} /> Off</>}
                                                </Badge>
                                            </Flex>
                                        </Table.Cell>

                                        {/* Actions */}
                                        <Table.Cell>
                                            <Flex justify="center">
                                                <DropdownMenu.Root>
                                                    <DropdownMenu.Trigger>
                                                        <IconButton size="1" variant="ghost" color="gray">
                                                            <DotsVerticalIcon />
                                                        </IconButton>
                                                    </DropdownMenu.Trigger>
                                                    <DropdownMenu.Content size="1">
                                                        <DropdownMenu.Item asChild>
                                                            <Link href={route('profile', { user: user.id })}>
                                                                <Flex align="center" gap="2">
                                                                    <Pencil1Icon /> Edit Profile
                                                                </Flex>
                                                            </Link>
                                                        </DropdownMenu.Item>
                                                        <DropdownMenu.Item asChild>
                                                            <Link href={route('admin.users.devices', { userId: user.id })}>
                                                                <Flex align="center" gap="2">
                                                                    <MobileIcon /> Device History
                                                                </Flex>
                                                            </Link>
                                                        </DropdownMenu.Item>
                                                        <DropdownMenu.Separator />
                                                        {user.deleted_at ? (
                                                            <DropdownMenu.Item color="green" onSelect={() => restoreUser(user)}>
                                                                <Flex align="center" gap="2">
                                                                    <ResetIcon /> Restore
                                                                </Flex>
                                                            </DropdownMenu.Item>
                                                        ) : (
                                                            <DropdownMenu.Item color="red" onSelect={() => setDelUser(user)}>
                                                                <Flex align="center" gap="2">
                                                                    <TrashIcon /> Delete
                                                                </Flex>
                                                            </DropdownMenu.Item>
                                                        )}
                                                    </DropdownMenu.Content>
                                                </DropdownMenu.Root>
                                            </Flex>
                                        </Table.Cell>
                                    </Table.Row>
                                    </React.Fragment>
                                );
                            })}
                        </Table.Body>
                    </Table.Root>
                </Box>
            )}

            {/* ── Pagination ── */}
            {!loading && pagination.total > 0 && (
                <Flex align="center" justify="between" pt="3" mt="2" style={{ borderTop: '1px solid var(--gray-a4)' }} wrap="wrap" gap="3">
                    <Flex align="center" gap="2">
                        <Text size="1" color="gray">Rows</Text>
                        <Select.Root size="1" value={String(pagination.perPage)}
                            onValueChange={v => setPagination(p => ({ ...p, perPage: parseInt(v), currentPage: 1 }))}>
                            <Select.Trigger />
                            <Select.Content>
                                {[10, 15, 20, 30, 50].map(n => <Select.Item key={n} value={String(n)}>{n}</Select.Item>)}
                            </Select.Content>
                        </Select.Root>
                    </Flex>
                    <Flex align="center" gap="2">
                        <Text size="1" color="gray">{startRow}–{endRow} of {pagination.total}</Text>
                        <IconButton size="1" variant="soft" color="gray" disabled={pagination.currentPage <= 1}
                            onClick={() => setPagination(p => ({ ...p, currentPage: p.currentPage - 1 }))}>
                            <ChevronLeftIcon />
                        </IconButton>
                        {pageNums.map(n => (
                            <Button key={n} size="1"
                                variant={n === pagination.currentPage ? 'solid' : 'soft'}
                                color={n === pagination.currentPage ? 'indigo' : 'gray'}
                                onClick={() => setPagination(p => ({ ...p, currentPage: n }))}>
                                {n}
                            </Button>
                        ))}
                        <IconButton size="1" variant="soft" color="gray" disabled={pagination.currentPage >= totalPages}
                            onClick={() => setPagination(p => ({ ...p, currentPage: p.currentPage + 1 }))}>
                            <ChevronRightIcon />
                        </IconButton>
                    </Flex>
                </Flex>
            )}

            {/* ── Role Dialog ── */}
            <Dialog.Root open={!!roleDialogUser} onOpenChange={o => !o && setRoleDialogUser(null)}>
                <Dialog.Content style={{ maxWidth: 380 }}>
                    <Dialog.Title>Edit Roles — {roleDialogUser?.name}</Dialog.Title>
                    <Dialog.Description size="2" color="gray">Toggle roles on/off. Changes replace the current role set.</Dialog.Description>
                    <Flex direction="column" gap="2" mt="3">
                        {roles.map(r => {
                            const name = typeof r === 'object' ? r.name : r;
                            const key  = name; // use name as key — consistent with openRoleDialog
                            const checked = roleDialogSelected.has(key);
                            return (
                                <Flex key={key} align="center" gap="3"
                                    style={{ padding: '6px 10px', borderRadius: 'var(--radius-2)', background: 'var(--gray-a2)' }}>
                                    <Switch size="1" checked={checked} color="violet"
                                        onCheckedChange={() => {
                                            setRoleDialogSelected(p => {
                                                const n = new Set(p);
                                                n.has(key) ? n.delete(key) : n.add(key);
                                                return n;
                                            });
                                        }} />
                                    <Text size="2" weight="medium">{name}</Text>
                                </Flex>
                            );
                        })}
                    </Flex>
                    <Flex gap="3" mt="5" justify="end">
                        <Dialog.Close><Button variant="soft" color="gray">Cancel</Button></Dialog.Close>
                        <Button onClick={saveRoles} disabled={roleSaving}>
                            {roleSaving ? <><Spinner size="1" /> Saving…</> : 'Save'}
                        </Button>
                    </Flex>
                </Dialog.Content>
            </Dialog.Root>

            {/* ── Delete Dialog ── */}
            <Dialog.Root open={!!delUser} onOpenChange={o => !o && setDelUser(null)}>
                <Dialog.Content style={{ maxWidth: 420 }}>
                    <Dialog.Title>Delete User</Dialog.Title>
                    <Dialog.Description size="2" color="gray">
                        Are you sure you want to delete <Text weight="bold">{delUser?.name}</Text>?
                        This will soft-delete the user — you can restore them via the Show Deleted filter.
                    </Dialog.Description>
                    <Flex gap="3" mt="5" justify="end">
                        <Dialog.Close>
                            <Button variant="soft" color="gray">Cancel</Button>
                        </Dialog.Close>
                        <Button color="red" onClick={confirmDelete} disabled={delLoading}>
                            {delLoading ? <><Spinner size="1" /> Deleting…</> : <><TrashIcon /> Delete</>}
                        </Button>
                    </Flex>
                </Dialog.Content>
            </Dialog.Root>

            {/* ── Bulk Delete Dialog ── */}
            <Dialog.Root open={bulkDialogOpen} onOpenChange={setBulkDialogOpen}>
                <Dialog.Content style={{ maxWidth: 420 }}>
                    <Dialog.Title>Delete Users</Dialog.Title>
                    <Dialog.Description size="2" color="gray">
                        Are you sure you want to delete <Text weight="bold">{selectedIds.length} user(s)</Text>?
                        This action cannot be undone.
                    </Dialog.Description>
                    <Flex gap="3" mt="5" justify="end">
                        <Dialog.Close>
                            <Button variant="soft" color="gray">Cancel</Button>
                        </Dialog.Close>
                        <Button color="red" onClick={handleBulkDelete} disabled={bulkLoading}>
                            {bulkLoading ? <><Spinner size="1" /> Deleting…</> : <><TrashIcon /> Delete</>}
                        </Button>
                    </Flex>
                </Dialog.Content>
            </Dialog.Root>
        </Box>
    );
}
