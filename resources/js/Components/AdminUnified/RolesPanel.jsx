/**
 * RolesPanel.jsx
 * Roles & Permissions tab.
 * - Sub-tab 0: Roles CRUD table
 * - Sub-tab 1: Permissions CRUD table
 * - Sub-tab 2: Role-Permission assignment (card grid desktop / checklist mobile)
 * - Sub-tab 3: User-Role assignment table
 * Pure Radix UI.
 */
import React, { useState, useCallback, useMemo, useEffect, useRef } from 'react';
import {
    Badge, Box, Button, Card, Dialog, DropdownMenu, Flex,
    Grid, IconButton, ScrollArea, Select, Separator,
    Spinner, Switch, Table, Tabs, Text, TextField,
} from '@radix-ui/themes';
import {
    CheckboxIcon, ChevronDownIcon, ChevronLeftIcon, ChevronRightIcon,
    Cross2Icon, DotsVerticalIcon, GearIcon, LockClosedIcon,
    MagnifyingGlassIcon, Pencil1Icon, PersonIcon, PlusIcon,
    ReloadIcon, TrashIcon,
} from '@radix-ui/react-icons';
import axios from 'axios';
import { showToast } from '@/utils/toastUtils';
import ProfileAvatar from '@/Components/ProfileAvatar.jsx';

/* ── sub-tab: Roles ── */
function RolesTab({ roles: initialRoles, permissions, getRolePermissions, canManageSuperAdmin, isMobile, onRolesChange }) {
    const [roles, setRoles]         = useState(initialRoles);
    const [search, setSearch]       = useState('');
    const [loading, setLoading]     = useState(false);
    const [editRole, setEditRole]   = useState(null); // null = add
    const [delRole, setDelRole]     = useState(null);
    const [dialogOpen, setDialogOpen] = useState(false);
    const [delOpen, setDelOpen]     = useState(false);
    const [form, setForm]           = useState({ name: '', description: '' });
    const [saving, setSaving]       = useState(false);
    const [delLoading, setDelLoading] = useState(false);

    const filtered = useMemo(() =>
        roles.filter(r => !search || r.name.toLowerCase().includes(search.toLowerCase())),
        [roles, search]);

    const openAdd = () => { setEditRole(null); setForm({ name: '', description: '' }); setDialogOpen(true); };
    const openEdit = r => { setEditRole(r); setForm({ name: r.name, description: r.description || '' }); setDialogOpen(true); };

    const save = async () => {
        if (!form.name.trim()) return showToast.error('Name is required.');
        setSaving(true);
        try {
            const url    = editRole ? `/api/roles/${editRole.id}` : '/api/roles';
            const method = editRole ? 'put' : 'post';
            const { data } = await axios[method](url, form);
            const next = editRole ? roles.map(r => r.id === editRole.id ? data.role : r) : [...roles, data.role];
            setRoles(next);
            onRolesChange?.(next.length);
            setDialogOpen(false);
            showToast.success(editRole ? 'Role updated.' : 'Role created.');
        } catch (e) {
            showToast.error(e.response?.data?.message || 'Failed to save role.');
        } finally {
            setSaving(false);
        }
    };

    const deleteRole = async () => {
        if (!delRole) return;
        setDelLoading(true);
        try {
            await axios.delete(`/api/roles/${delRole.id}`);
            const next = roles.filter(r => r.id !== delRole.id);
            setRoles(next);
            onRolesChange?.(next.length);
            setDelOpen(false);
            showToast.success('Role deleted.');
        } catch (e) {
            showToast.error(e.response?.data?.message || 'Failed to delete role.');
        } finally {
            setDelLoading(false);
        }
    };

    return (
        <Box>
            <Flex justify="between" align="center" mb="3" gap="3" wrap="wrap">
                <TextField.Root placeholder="Search roles…" size="2" style={{ minWidth: 200, flex: 1 }}
                    onChange={e => setSearch(e.target.value)}>
                    <TextField.Slot><MagnifyingGlassIcon /></TextField.Slot>
                </TextField.Root>
                <Button size="2" onClick={openAdd}><PlusIcon /> Add Role</Button>
            </Flex>

            <Box style={{ overflowX: 'auto' }}>
                <Table.Root variant="surface">
                    <Table.Header>
                        <Table.Row>
                            <Table.ColumnHeaderCell>#</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Role</Table.ColumnHeaderCell>
                            {!isMobile && <Table.ColumnHeaderCell>Description</Table.ColumnHeaderCell>}
                            <Table.ColumnHeaderCell>Permissions</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell style={{ textAlign: 'center' }}>Actions</Table.ColumnHeaderCell>
                        </Table.Row>
                    </Table.Header>
                    <Table.Body>
                        {filtered.map((role, i) => {
                            const permCount = getRolePermissions(role.id).length;
                            const isSysRole = role.name === 'Super Administrator';
                            const canManage = isSysRole ? canManageSuperAdmin : true;
                            return (
                                <Table.Row key={role.id}>
                                    <Table.Cell><Text size="1" color="gray">{i + 1}</Text></Table.Cell>
                                    <Table.Cell>
                                        <Flex align="center" gap="2">
                                            <Box style={{
                                                width: 30, height: 30, borderRadius: '50%',
                                                background: 'var(--accent-9)', display: 'flex',
                                                alignItems: 'center', justifyContent: 'center',
                                                color: '#fff', fontWeight: 700, fontSize: 13, flexShrink: 0,
                                            }}>
                                                {role.name.charAt(0).toUpperCase()}
                                            </Box>
                                            <Box>
                                                <Text weight="bold" size="2">{role.name}</Text>
                                                {isSysRole && <Badge size="1" color="amber" variant="soft" ml="1">System</Badge>}
                                            </Box>
                                        </Flex>
                                    </Table.Cell>
                                    {!isMobile && (
                                        <Table.Cell>
                                            <Text size="1" color="gray">{role.description || '—'}</Text>
                                        </Table.Cell>
                                    )}
                                    <Table.Cell>
                                        <Badge size="1" variant="soft" color="violet">{permCount} perms</Badge>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Flex justify="center" gap="1">
                                            <IconButton size="1" variant="ghost" disabled={!canManage} onClick={() => openEdit(role)}>
                                                <Pencil1Icon />
                                            </IconButton>
                                            <IconButton size="1" variant="ghost" color="red" disabled={!canManage}
                                                onClick={() => { setDelRole(role); setDelOpen(true); }}>
                                                <TrashIcon />
                                            </IconButton>
                                        </Flex>
                                    </Table.Cell>
                                </Table.Row>
                            );
                        })}
                        {filtered.length === 0 && (
                            <Table.Row>
                                <Table.Cell colSpan={5}>
                                    <Text size="2" color="gray" style={{ display: 'block', textAlign: 'center', padding: '24px 0' }}>
                                        No roles found.
                                    </Text>
                                </Table.Cell>
                            </Table.Row>
                        )}
                    </Table.Body>
                </Table.Root>
            </Box>

            {/* Add/Edit Dialog */}
            <Dialog.Root open={dialogOpen} onOpenChange={setDialogOpen}>
                <Dialog.Content style={{ maxWidth: 440 }}>
                    <Dialog.Title>{editRole ? 'Edit Role' : 'Add Role'}</Dialog.Title>
                    <Flex direction="column" gap="3" mt="3">
                        <Box>
                            <Text size="2" weight="medium" as="div" mb="1">Name *</Text>
                            <TextField.Root size="2" value={form.name} placeholder="e.g. Manager"
                                onChange={e => setForm(f => ({ ...f, name: e.target.value }))} />
                        </Box>
                        <Box>
                            <Text size="2" weight="medium" as="div" mb="1">Description</Text>
                            <TextField.Root size="2" value={form.description} placeholder="Optional description"
                                onChange={e => setForm(f => ({ ...f, description: e.target.value }))} />
                        </Box>
                    </Flex>
                    <Flex gap="3" mt="5" justify="end">
                        <Dialog.Close><Button variant="soft" color="gray">Cancel</Button></Dialog.Close>
                        <Button onClick={save} disabled={saving}>
                            {saving ? <><Spinner size="1" /> Saving…</> : (editRole ? 'Update' : 'Create')}
                        </Button>
                    </Flex>
                </Dialog.Content>
            </Dialog.Root>

            {/* Delete Dialog */}
            <Dialog.Root open={delOpen} onOpenChange={setDelOpen}>
                <Dialog.Content style={{ maxWidth: 400 }}>
                    <Dialog.Title>Delete Role</Dialog.Title>
                    <Dialog.Description size="2" color="gray">
                        Delete <Text weight="bold">{delRole?.name}</Text>? This cannot be undone.
                    </Dialog.Description>
                    <Flex gap="3" mt="5" justify="end">
                        <Dialog.Close><Button variant="soft" color="gray">Cancel</Button></Dialog.Close>
                        <Button color="red" onClick={deleteRole} disabled={delLoading}>
                            {delLoading ? <Spinner size="1" /> : <TrashIcon />} Delete
                        </Button>
                    </Flex>
                </Dialog.Content>
            </Dialog.Root>
        </Box>
    );
}

/* ── sub-tab: Permissions ── */
function PermissionsTab({ permissions: initialPerms, isMobile }) {
    const [perms, setPerms]     = useState(initialPerms);
    const [search, setSearch]   = useState('');
    const [editPerm, setEditPerm] = useState(null);
    const [delPerm, setDelPerm]   = useState(null);
    const [dialogOpen, setDialogOpen] = useState(false);
    const [delOpen, setDelOpen]   = useState(false);
    const [form, setForm]         = useState({ name: '', display_name: '', description: '', module: '' });
    const [saving, setSaving]     = useState(false);
    const [delLoading, setDelLoading] = useState(false);

    const filtered = useMemo(() =>
        perms.filter(p => !search ||
            p.name.toLowerCase().includes(search.toLowerCase()) ||
            (p.display_name && p.display_name.toLowerCase().includes(search.toLowerCase()))),
        [perms, search]);

    const openAdd = () => {
        setEditPerm(null);
        setForm({ name: '', display_name: '', description: '', module: '' });
        setDialogOpen(true);
    };
    const openEdit = p => {
        setEditPerm(p);
        setForm({ name: p.name, display_name: p.display_name || '', description: p.description || '', module: p.module || '' });
        setDialogOpen(true);
    };

    const save = async () => {
        if (!form.name.trim()) return showToast.error('Name required.');
        setSaving(true);
        try {
            const url    = editPerm ? `/api/permissions/${editPerm.id}` : '/api/permissions';
            const method = editPerm ? 'put' : 'post';
            const { data } = await axios[method](url, form);
            setPerms(p => editPerm ? p.map(x => x.id === editPerm.id ? data.permission : x) : [...p, data.permission]);
            setDialogOpen(false);
            showToast.success(editPerm ? 'Permission updated.' : 'Permission created.');
        } catch (e) {
            showToast.error(e.response?.data?.message || 'Failed.');
        } finally {
            setSaving(false);
        }
    };

    const deletePerm = async () => {
        setDelLoading(true);
        try {
            await axios.delete(`/api/permissions/${delPerm.id}`);
            setPerms(p => p.filter(x => x.id !== delPerm.id));
            setDelOpen(false);
            showToast.success('Permission deleted.');
        } catch (e) {
            showToast.error(e.response?.data?.message || 'Failed.');
        } finally {
            setDelLoading(false);
        }
    };

    return (
        <Box>
            <Flex justify="between" align="center" mb="3" gap="3" wrap="wrap">
                <TextField.Root placeholder="Search permissions…" size="2" style={{ minWidth: 200, flex: 1 }}
                    onChange={e => setSearch(e.target.value)}>
                    <TextField.Slot><MagnifyingGlassIcon /></TextField.Slot>
                </TextField.Root>
                <Button size="2" onClick={openAdd}><PlusIcon /> Add Permission</Button>
            </Flex>

            <Box style={{ overflowX: 'auto' }}>
                <Table.Root variant="surface">
                    <Table.Header>
                        <Table.Row>
                            <Table.ColumnHeaderCell>#</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Permission</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Key</Table.ColumnHeaderCell>
                            {!isMobile && <Table.ColumnHeaderCell>Module</Table.ColumnHeaderCell>}
                            <Table.ColumnHeaderCell style={{ textAlign: 'center' }}>Actions</Table.ColumnHeaderCell>
                        </Table.Row>
                    </Table.Header>
                    <Table.Body>
                        {filtered.map((perm, i) => (
                            <Table.Row key={perm.id}>
                                <Table.Cell><Text size="1" color="gray">{i + 1}</Text></Table.Cell>
                                <Table.Cell>
                                    <Text weight="bold" size="2">{perm.display_name || perm.name}</Text>
                                    {perm.description && <Text size="1" color="gray" as="div">{perm.description}</Text>}
                                </Table.Cell>
                                <Table.Cell>
                                    <Box as="span" style={{
                                        fontFamily: 'monospace', fontSize: 11,
                                        background: 'var(--gray-a4)', borderRadius: 'var(--radius-1)',
                                        padding: '2px 6px',
                                    }}>
                                        {perm.name}
                                    </Box>
                                </Table.Cell>
                                {!isMobile && (
                                    <Table.Cell>
                                        {perm.module && <Badge size="1" variant="soft" color="blue">{perm.module}</Badge>}
                                    </Table.Cell>
                                )}
                                <Table.Cell>
                                    <Flex justify="center" gap="1">
                                        <IconButton size="1" variant="ghost" onClick={() => openEdit(perm)}><Pencil1Icon /></IconButton>
                                        <IconButton size="1" variant="ghost" color="red"
                                            onClick={() => { setDelPerm(perm); setDelOpen(true); }}><TrashIcon /></IconButton>
                                    </Flex>
                                </Table.Cell>
                            </Table.Row>
                        ))}
                        {filtered.length === 0 && (
                            <Table.Row>
                                <Table.Cell colSpan={5}>
                                    <Text size="2" color="gray" style={{ display: 'block', textAlign: 'center', padding: '24px 0' }}>
                                        No permissions found.
                                    </Text>
                                </Table.Cell>
                            </Table.Row>
                        )}
                    </Table.Body>
                </Table.Root>
            </Box>

            {/* Add/Edit */}
            <Dialog.Root open={dialogOpen} onOpenChange={setDialogOpen}>
                <Dialog.Content style={{ maxWidth: 480 }}>
                    <Dialog.Title>{editPerm ? 'Edit Permission' : 'Add Permission'}</Dialog.Title>
                    <Flex direction="column" gap="3" mt="3">
                        {[
                            { key: 'name', label: 'Key (e.g. users.create) *', ph: 'users.create' },
                            { key: 'display_name', label: 'Display Name', ph: 'Create Users' },
                            { key: 'module', label: 'Module', ph: 'users' },
                            { key: 'description', label: 'Description', ph: 'Optional' },
                        ].map(({ key, label, ph }) => (
                            <Box key={key}>
                                <Text size="2" weight="medium" as="div" mb="1">{label}</Text>
                                <TextField.Root size="2" value={form[key]} placeholder={ph}
                                    onChange={e => setForm(f => ({ ...f, [key]: e.target.value }))} />
                            </Box>
                        ))}
                    </Flex>
                    <Flex gap="3" mt="5" justify="end">
                        <Dialog.Close><Button variant="soft" color="gray">Cancel</Button></Dialog.Close>
                        <Button onClick={save} disabled={saving}>
                            {saving ? <Spinner size="1" /> : null} {editPerm ? 'Update' : 'Create'}
                        </Button>
                    </Flex>
                </Dialog.Content>
            </Dialog.Root>

            <Dialog.Root open={delOpen} onOpenChange={setDelOpen}>
                <Dialog.Content style={{ maxWidth: 380 }}>
                    <Dialog.Title>Delete Permission</Dialog.Title>
                    <Dialog.Description size="2" color="gray">
                        Delete <Text weight="bold">{delPerm?.name}</Text>? Cannot be undone.
                    </Dialog.Description>
                    <Flex gap="3" mt="5" justify="end">
                        <Dialog.Close><Button variant="soft" color="gray">Cancel</Button></Dialog.Close>
                        <Button color="red" onClick={deletePerm} disabled={delLoading}>
                            {delLoading ? <Spinner size="1" /> : <TrashIcon />} Delete
                        </Button>
                    </Flex>
                </Dialog.Content>
            </Dialog.Root>
        </Box>
    );
}

/* ── sub-tab: Role-Permission assignment ── */
function AssignmentTab({ roles, permissions, permissionsGrouped, getRolePermissions, roleHasPermission, isMobile }) {
    const [activeRoleId, setActiveRoleId] = useState(roles[0]?.id ?? null);
    const [localPerms, setLocalPerms]     = useState(() => {
        // build { permName: bool } for active role
        const map = {};
        permissions.forEach(p => { map[p.name] = false; });
        return map;
    });
    const [toggling, setToggling] = useState({});

    const activeRole = useMemo(() => roles.find(r => r.id === activeRoleId), [roles, activeRoleId]);

    // Rebuild localPerms when role changes
    useEffect(() => {
        if (!activeRole) return;
        const map = {};
        permissions.forEach(p => { map[p.name] = roleHasPermission(activeRole.id, p.name); });
        setLocalPerms(map);
    }, [activeRoleId, roles, permissions, roleHasPermission]);

    const toggle = async (permName) => {
        if (toggling[permName]) return;
        const next = !localPerms[permName];
        setLocalPerms(p => ({ ...p, [permName]: next }));
        setToggling(t => ({ ...t, [permName]: true }));
        try {
            await axios.post('/admin/roles/update-permission', {
                role_id: activeRole.id,
                permission: permName,
                action: next ? 'grant' : 'revoke',
            });
            showToast.success(`Permission ${next ? 'granted' : 'revoked'}.`);
        } catch (e) {
            setLocalPerms(p => ({ ...p, [permName]: !next })); // rollback
            showToast.error(e.response?.data?.message || 'Failed.');
        } finally {
            setToggling(t => ({ ...t, [permName]: false }));
        }
    };

    const grantedCount = Object.values(localPerms).filter(Boolean).length;

    return (
        <Box>
            <Flex direction={{ initial: 'column', sm: 'row' }} gap="3" align={{ initial: 'stretch', sm: 'center' }} mb="4">
                <Box style={{ minWidth: 220 }}>
                    <Text size="2" color="gray" weight="medium" as="div" mb="1">Select Role</Text>
                    <Select.Root size="2"
                        value={activeRoleId ? String(activeRoleId) : ''}
                        onValueChange={v => setActiveRoleId(parseInt(v))}>
                        <Select.Trigger style={{ width: '100%' }} placeholder="Choose a role…" />
                        <Select.Content>
                            {roles.map(r => <Select.Item key={r.id} value={String(r.id)}>{r.name}</Select.Item>)}
                        </Select.Content>
                    </Select.Root>
                </Box>
                {activeRole && (
                    <Badge size="2" variant="soft" color="violet">
                        {grantedCount} / {permissions.length} permissions granted
                    </Badge>
                )}
            </Flex>

            {!activeRole ? (
                <Flex direction="column" align="center" justify="center" py="9" gap="2">
                    <LockClosedIcon style={{ width: 36, height: 36, color: 'var(--gray-9)' }} />
                    <Text size="3" weight="medium">Select a role to manage permissions</Text>
                </Flex>
            ) : isMobile ? (
                /* ── Mobile: checklist table ── */
                <Box style={{ overflowX: 'auto' }}>
                    <Table.Root variant="surface">
                        <Table.Header>
                            <Table.Row>
                                <Table.ColumnHeaderCell>Permission</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell style={{ width: 70, textAlign: 'center' }}>Granted</Table.ColumnHeaderCell>
                            </Table.Row>
                        </Table.Header>
                        <Table.Body>
                            {permissions.map(perm => (
                                <Table.Row key={perm.id}>
                                    <Table.Cell>
                                        <Text size="2" weight="medium">{perm.display_name || perm.name}</Text>
                                        <Text size="1" color="gray" as="div">{perm.name}</Text>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Flex justify="center">
                                            {toggling[perm.name]
                                                ? <Spinner size="1" />
                                                : <Switch size="1" checked={!!localPerms[perm.name]}
                                                    onCheckedChange={() => toggle(perm.name)} color="green" />
                                            }
                                        </Flex>
                                    </Table.Cell>
                                </Table.Row>
                            ))}
                        </Table.Body>
                    </Table.Root>
                </Box>
            ) : (
                /* ── Desktop: module card grid ── */
                <Grid columns={{ initial: '1', md: '2', lg: '3' }} gap="4">
                    {Object.entries(permissionsGrouped).map(([moduleKey, moduleData]) => {
                        const modulePerms = moduleData.permissions || [];
                        const granted = modulePerms.filter(p => localPerms[p.name]).length;
                        const allGranted = granted === modulePerms.length;
                        return (
                            <Card key={moduleKey} variant="surface">
                                <Flex justify="between" align="center" mb="3">
                                    <Flex align="center" gap="2">
                                        <Text weight="bold" size="2" style={{ textTransform: 'capitalize' }}>
                                            {moduleKey}
                                        </Text>
                                        <Badge size="1" variant="soft"
                                            color={allGranted ? 'green' : granted > 0 ? 'amber' : 'gray'}>
                                            {granted}/{modulePerms.length}
                                        </Badge>
                                    </Flex>
                                </Flex>
                                <Flex direction="column" gap="2">
                                    {modulePerms.map(perm => (
                                        <Flex key={perm.id} justify="between" align="center"
                                            style={{
                                                padding: '6px 8px',
                                                borderRadius: 'var(--radius-1)',
                                                background: 'var(--gray-a2)',
                                            }}>
                                            <Box>
                                                <Text size="2" weight="medium">{perm.display_name || perm.name}</Text>
                                                <Text size="1" color="gray" as="div">{perm.name}</Text>
                                            </Box>
                                            {toggling[perm.name]
                                                ? <Spinner size="1" />
                                                : <Switch size="1" checked={!!localPerms[perm.name]}
                                                    onCheckedChange={() => toggle(perm.name)} color="green" />
                                            }
                                        </Flex>
                                    ))}
                                </Flex>
                            </Card>
                        );
                    })}
                </Grid>
            )}
        </Box>
    );
}

/* ── sub-tab: User-Role ── */
function UserRoleTab({ roles, isMobile }) {
    const [users,    setUsers]    = useState([]);
    const [loading,  setLoading]  = useState(false);
    const [search,   setSearch]   = useState('');
    const [page,     setPage]     = useState(1);
    const [total,    setTotal]    = useState(0);
    const perPage = 20;
    const debRef  = useRef(null);

    const fetchUsers = useCallback(async (q = search, p = page) => {
        setLoading(true);
        try {
            const { data } = await axios.get(route('users.paginate'), {
                params: { page: p, perPage, search: q || undefined },
            });
            const list = data.users?.data ?? data.users ?? [];
            setUsers(list);
            setTotal(data.users?.total ?? list.length);
        } catch {
            showToast.error('Failed to load users.');
        } finally {
            setLoading(false);
        }
    }, [search, page]);

    useEffect(() => { fetchUsers(); }, []);

    const triggerSearch = (val) => {
        setSearch(val);
        setPage(1);
        clearTimeout(debRef.current);
        debRef.current = setTimeout(() => fetchUsers(val, 1), 280);
    };

    const totalPages = Math.max(1, Math.ceil(total / perPage));

    const [editUser, setEditUser] = useState(null);
    const [selectedRoles, setSelectedRoles] = useState(new Set());
    const [saving, setSaving] = useState(false);
    const [dialogOpen, setDialogOpen] = useState(false);

    const openEdit = (user) => {
        setEditUser(user);
        const rNames = (user.roles || []).map(r => typeof r === 'object' ? r.name : r);
        const rIds   = roles.filter(r => rNames.includes(r.name || r)).map(r => r.id || r.name || r);
        setSelectedRoles(new Set(rIds.map(String)));
        setDialogOpen(true);
    };

    const save = async () => {
        if (!editUser) return;
        setSaving(true);
        try {
            const roleNames = Array.from(selectedRoles).map(key => {
                const found = roles.find(r => String(r.id) === key || r.name === key);
                return found ? found.name : key;
            });
            await axios.post(route('users.updateRole', { id: editUser.id }), { roles: roleNames });
            setUsers(p => p.map(u => u.id === editUser.id
                ? { ...u, roles: roleNames.map(n => ({ name: n })) }
                : u));
            showToast.success('User roles updated.');
            setDialogOpen(false);
        } catch (e) {
            showToast.error(e.response?.data?.message || 'Failed.');
        } finally {
            setSaving(false);
        }
    };

    return (
        <Box>
            <Flex align="center" gap="3" mb="3" wrap="wrap">
                <TextField.Root placeholder="Search users…" size="2" style={{ maxWidth: 360 }}
                    onChange={e => triggerSearch(e.target.value)}>
                    <TextField.Slot><MagnifyingGlassIcon /></TextField.Slot>
                </TextField.Root>
                {loading && <Spinner size="2" />}
                <Text size="1" color="gray" ml="auto">{total} users</Text>
            </Flex>

            <Box style={{ overflowX: 'auto' }}>
                <Table.Root variant="surface">
                    <Table.Header>
                        <Table.Row>
                            <Table.ColumnHeaderCell>#</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>User</Table.ColumnHeaderCell>
                            {!isMobile && <Table.ColumnHeaderCell>Email</Table.ColumnHeaderCell>}
                            <Table.ColumnHeaderCell>Roles</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell style={{ width: 70, textAlign: 'center' }}>Edit</Table.ColumnHeaderCell>
                        </Table.Row>
                    </Table.Header>
                    <Table.Body>
                        {users.map((user, i) => {
                            const roleNames = (user.roles || []).map(r => typeof r === 'object' ? r.name : r);
                            return (
                                <Table.Row key={user.id}>
                                    <Table.Cell><Text size="1" color="gray">{i + 1}</Text></Table.Cell>
                                    <Table.Cell>
                                        <Flex align="center" gap="2">
                                            <ProfileAvatar src={user.profile_image_url} name={user.name} size="sm" />
                                            <Box>
                                                <Text weight="bold" size="2">{user.name}</Text>
                                                {isMobile && <Text size="1" color="gray" as="div">{user.email}</Text>}
                                            </Box>
                                        </Flex>
                                    </Table.Cell>
                                    {!isMobile && <Table.Cell><Text size="2" color="gray">{user.email}</Text></Table.Cell>}
                                    <Table.Cell>
                                        <Flex gap="1" wrap="wrap">
                                            {roleNames.length > 0
                                                ? roleNames.slice(0, 3).map(n => (
                                                    <Badge key={n} size="1" color="violet" variant="soft">{n}</Badge>
                                                ))
                                                : <Badge size="1" color="gray" variant="soft">No roles</Badge>
                                            }
                                            {roleNames.length > 3 && (
                                                <Badge size="1" color="gray" variant="outline">+{roleNames.length - 3}</Badge>
                                            )}
                                        </Flex>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Flex justify="center">
                                            <IconButton size="1" variant="ghost" onClick={() => openEdit(user)}>
                                                <Pencil1Icon />
                                            </IconButton>
                                        </Flex>
                                    </Table.Cell>
                                </Table.Row>
                            );
                        })}
                        {!loading && users.length === 0 && (
                            <Table.Row>
                                <Table.Cell colSpan={5}>
                                    <Text size="2" color="gray" style={{ display: 'block', textAlign: 'center', padding: '24px 0' }}>
                                        No users found.
                                    </Text>
                                </Table.Cell>
                            </Table.Row>
                        )}
                    </Table.Body>
                </Table.Root>
            </Box>

            {/* Pagination */}
            {totalPages > 1 && (
                <Flex align="center" justify="end" gap="2" mt="3">
                    <IconButton size="1" variant="soft" disabled={page <= 1}
                        onClick={() => { const p = page - 1; setPage(p); fetchUsers(search, p); }}>
                        <ChevronLeftIcon />
                    </IconButton>
                    <Text size="1" color="gray">{page} / {totalPages}</Text>
                    <IconButton size="1" variant="soft" disabled={page >= totalPages}
                        onClick={() => { const p = page + 1; setPage(p); fetchUsers(search, p); }}>
                        <ChevronRightIcon />
                    </IconButton>
                </Flex>
            )}

            {/* Edit Roles Dialog */}
            <Dialog.Root open={dialogOpen} onOpenChange={setDialogOpen}>
                <Dialog.Content style={{ maxWidth: 440 }}>
                    <Dialog.Title>Edit Roles — {editUser?.name}</Dialog.Title>
                    <Dialog.Description size="2" color="gray">
                        Select one or more roles for this user.
                    </Dialog.Description>
                    <Flex direction="column" gap="2" mt="3">
                        {roles.map(r => {
                            const key  = String(r.id || r.name || r);
                            const name = r.name || r;
                            const checked = selectedRoles.has(key);
                            return (
                                <Flex key={key} align="center" gap="3"
                                    style={{ padding: '6px 10px', borderRadius: 'var(--radius-2)', background: 'var(--gray-a2)' }}>
                                    <Switch size="1" checked={checked} color="violet"
                                        onCheckedChange={() => {
                                            setSelectedRoles(p => {
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
                        <Button onClick={save} disabled={saving}>
                            {saving ? <Spinner size="1" /> : null} Save
                        </Button>
                    </Flex>
                </Dialog.Content>
            </Dialog.Root>
        </Box>
    );
}

/* ── Main RolesPanel ── */
export default function RolesPanel({
    roles: initialRoles = [], permissions = [], roleHasPermissions = [],
    permissionsGrouped = {}, canManageSuperAdmin = false,
    isMobile, tick, onCountChange, onSetHeaderActions, isActive,
}) {
    const [subTab, setSubTab] = useState('roles');

    const getRolePermissions = useCallback((roleId) => {
        // from role_has_permissions array
        const fromArr = roleHasPermissions
            .filter(rp => rp.role_id === roleId)
            .map(rp => rp.permission_id);
        if (fromArr.length) return fromArr;
        // from embedded
        const role = initialRoles.find(r => r.id === roleId);
        return (role?.permissions || []).map(p => p.id).filter(Boolean);
    }, [roleHasPermissions, initialRoles]);

    const roleHasPermission = useCallback((roleId, permName) => {
        const permIds = getRolePermissions(roleId);
        const perm    = permissions.find(p => p.name === permName);
        return perm ? permIds.includes(perm.id) : false;
    }, [getRolePermissions, permissions]);

    useEffect(() => {
        if (!isActive) return;
        onSetHeaderActions?.(null); // no global action for roles tab
    }, [isActive]);

    return (
        <Box>
            {/* Stats */}
            <Flex wrap="wrap" gap="2" mb="4">
                <Badge size="2" variant="soft" color="violet" radius="full">
                    <Text weight="bold">{initialRoles.length}</Text> <Text style={{ opacity: 0.7 }}>Roles</Text>
                </Badge>
                <Badge size="2" variant="soft" color="indigo" radius="full">
                    <Text weight="bold">{permissions.length}</Text> <Text style={{ opacity: 0.7 }}>Permissions</Text>
                </Badge>
            </Flex>

            {/* Sub-tabs */}
            <Tabs.Root value={subTab} onValueChange={setSubTab}>
                <Tabs.List mb="4">
                    <Tabs.Trigger value="roles">Roles</Tabs.Trigger>
                    <Tabs.Trigger value="permissions">Permissions</Tabs.Trigger>
                    <Tabs.Trigger value="assignment">Role ↔ Permission</Tabs.Trigger>
                    <Tabs.Trigger value="userroles">User ↔ Role</Tabs.Trigger>
                </Tabs.List>

                <Tabs.Content value="roles">
                    <RolesTab
                        roles={initialRoles}
                        permissions={permissions}
                        getRolePermissions={getRolePermissions}
                        canManageSuperAdmin={canManageSuperAdmin}
                        isMobile={isMobile}
                        onRolesChange={n => onCountChange?.(n)}
                    />
                </Tabs.Content>

                <Tabs.Content value="permissions">
                    <PermissionsTab permissions={permissions} isMobile={isMobile} />
                </Tabs.Content>

                <Tabs.Content value="assignment">
                    <AssignmentTab
                        roles={initialRoles}
                        permissions={permissions}
                        permissionsGrouped={permissionsGrouped}
                        getRolePermissions={getRolePermissions}
                        roleHasPermission={roleHasPermission}
                        isMobile={isMobile}
                    />
                </Tabs.Content>

                <Tabs.Content value="userroles">
                    <UserRoleTab users={users} roles={initialRoles} isMobile={isMobile} />
                </Tabs.Content>
            </Tabs.Root>
        </Box>
    );
}
