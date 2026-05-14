/**
 * BiometricPanel.jsx
 * Biometric Devices tab — sub-tabs: Devices | ADMS Logs | Webhook Config
 * Pure Radix UI.
 */
import React, { useState, useCallback, useEffect, useMemo } from 'react';
import {
    Badge, Box, Button, Card, Code, Dialog, Flex, Grid,
    IconButton, ScrollArea, Select, Separator, Spinner,
    Switch, Table, Tabs, Text, TextField, Tooltip,
} from '@radix-ui/themes';
import {
    ActivityLogIcon, CheckCircledIcon, CopyIcon, Cross2Icon,
    DesktopIcon, ExclamationTriangleIcon, GlobeIcon,
    LightningBoltIcon, Link2Icon, MagnifyingGlassIcon,
    MinusIcon, Pencil1Icon, PlusIcon, ReloadIcon, TrashIcon,
} from '@radix-ui/react-icons';
import axios from 'axios';
import { showToast } from '@/utils/toastUtils';

const EMPTY_DEVICE = {
    name: '', serial_number: '', ip_address: '', location: '',
    model: '', protocol: 'push_sdk', is_active: true,
};

/* ── Devices sub-tab ── */
function DevicesTab({ devices, setDevices, employees, isMobile }) {
    const [editDevice, setEditDevice]   = useState(null);
    const [dialogOpen, setDialogOpen]   = useState(false);
    const [form, setForm]               = useState(EMPTY_DEVICE);
    const [saving, setSaving]           = useState(false);
    const [pinging, setPinging]         = useState(null);
    const [tokenDialog, setTokenDialog] = useState({ open: false, device: null, token: '' });

    /* ── mapping modal state ── */
    const [mapDevice, setMapDevice]     = useState(null);
    const [mapOpen, setMapOpen]         = useState(false);
    const [deviceUsers, setDeviceUsers] = useState([]);
    const [loadingUsers, setLoadingUsers] = useState(false);
    const [mapForm, setMapForm]         = useState({ device_user_id: '' });
    const [addingUser, setAddingUser]   = useState(false);
    const [linkEntry, setLinkEntry]     = useState(null);
    const [linkOpen, setLinkOpen]       = useState(false);
    const [linkForm, setLinkForm]       = useState({ user_id: '' });
    const [linking, setLinking]         = useState(false);

    const openAdd = () => { setEditDevice(null); setForm(EMPTY_DEVICE); setDialogOpen(true); };
    const openEdit = d => {
        setEditDevice(d);
        setForm({ name: d.name, serial_number: d.serial_number, ip_address: d.ip_address ?? '',
            location: d.location ?? '', model: d.model ?? '', protocol: d.protocol ?? 'push_sdk', is_active: d.is_active });
        setDialogOpen(true);
    };

    const save = async () => {
        if (!form.name.trim() || !form.serial_number.trim())
            return showToast.error('Name and serial number are required.');
        setSaving(true);
        try {
            if (editDevice) {
                const { data } = await axios.put(route('biometric-devices.update', editDevice.id), form);
                setDevices(p => p.map(d => d.id === editDevice.id ? data.device : d));
                showToast.success('Device updated.');
            } else {
                const { data } = await axios.post(route('biometric-devices.store'), form);
                setDevices(p => [...p, data.device]);
                showToast.success('Device registered.');
            }
            setDialogOpen(false);
        } catch (e) {
            const msg = e.response?.data?.errors
                ? Object.values(e.response.data.errors).flat().join(' ')
                : e.response?.data?.message ?? 'Failed to save.';
            showToast.error(msg);
        } finally {
            setSaving(false);
        }
    };

    const deleteDevice = async d => {
        if (!confirm(`Delete "${d.name}"? All mappings will be removed.`)) return;
        try {
            await axios.delete(route('biometric-devices.destroy', d.id));
            setDevices(p => p.filter(x => x.id !== d.id));
            showToast.success('Device deleted.');
        } catch { showToast.error('Failed to delete.'); }
    };

    const ping = async d => {
        if (!d.ip_address) return showToast.error('No IP address configured.');
        setPinging(d.id);
        try {
            const { data } = await axios.post(route('biometric-devices.ping', d.id));
            data.success
                ? showToast.success(`Reachable (${data.latency}ms)`)
                : showToast.error('Device unreachable');
        } catch (e) {
            showToast.error(e.response?.data?.message ?? 'Ping failed.');
        } finally { setPinging(null); }
    };

    const regen = async d => {
        if (!confirm('Regenerate token? Device must be reconfigured.')) return;
        try {
            const { data } = await axios.post(route('biometric-devices.regenerate-token', d.id));
            setTokenDialog({ open: true, device: d, token: data.auth_token });
            showToast.success('Token regenerated.');
        } catch { showToast.error('Failed.'); }
    };

    const openMappings = async d => {
        setMapDevice(d); setMapForm({ device_user_id: '' });
        setLoadingUsers(true); setMapOpen(true);
        try {
            const { data } = await axios.get(route('biometric-devices.users', d.id));
            setDeviceUsers(data.entries ?? []);
        } catch { showToast.error('Failed to load entries.'); }
        finally { setLoadingUsers(false); }
    };

    const addEntry = async () => {
        if (!mapForm.device_user_id.trim()) return showToast.error('Enter enrollment ID.');
        setAddingUser(true);
        try {
            await axios.post(route('biometric-devices.entry.add', mapDevice.id), { device_user_id: mapForm.device_user_id });
            setMapForm({ device_user_id: '' });
            const { data } = await axios.get(route('biometric-devices.users', mapDevice.id));
            setDeviceUsers(data.entries ?? []);
            showToast.success('Entry added.');
        } catch (e) { showToast.error(e.response?.data?.message ?? 'Failed.'); }
        finally { setAddingUser(false); }
    };

    const unlinkUser = async entry => {
        if (!confirm(`Unlink ${entry.name}?`)) return;
        try {
            await axios.post(route('biometric-devices.users.unlink', { id: mapDevice.id, userId: entry.user_id }));
            const { data } = await axios.get(route('biometric-devices.users', mapDevice.id));
            setDeviceUsers(data.entries ?? []);
            showToast.success('Unlinked.');
        } catch { showToast.error('Failed.'); }
    };

    const deleteEntry = async entry => {
        if (!confirm('Delete entry?')) return;
        try {
            await axios.delete(route('biometric-devices.users.remove', { id: mapDevice.id, userId: entry.user_id }));
            setDeviceUsers(p => p.filter(e => e.id !== entry.id));
            showToast.success('Deleted.');
        } catch { showToast.error('Failed.'); }
    };

    const linkUser = async () => {
        if (!linkForm.user_id) return showToast.error('Select an employee.');
        setLinking(true);
        try {
            await axios.post(route('biometric-devices.users.link', mapDevice.id), {
                device_user_id: linkEntry.device_user_id, user_id: linkForm.user_id,
            });
            setLinkOpen(false);
            const { data } = await axios.get(route('biometric-devices.users', mapDevice.id));
            setDeviceUsers(data.entries ?? []);
            showToast.success('Linked.');
        } catch (e) { showToast.error(e.response?.data?.message ?? 'Failed.'); }
        finally { setLinking(false); }
    };

    const copy = t => navigator.clipboard.writeText(t).then(() => showToast.success('Copied!'));

    return (
        <Box>
            <Flex justify="end" mb="3">
                <Button size="2" onClick={openAdd}><PlusIcon /> Add Device</Button>
            </Flex>

            {devices.length === 0 ? (
                <Flex direction="column" align="center" justify="center" py="9" gap="2">
                    <DesktopIcon style={{ width: 40, height: 40, color: 'var(--gray-9)' }} />
                    <Text size="3" weight="medium">No devices registered</Text>
                    <Text size="2" color="gray">Click "Add Device" to get started.</Text>
                </Flex>
            ) : (
                <Box style={{ overflowX: 'auto' }}>
                    <Table.Root variant="surface">
                        <Table.Header>
                            <Table.Row>
                                <Table.ColumnHeaderCell>Device</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Serial</Table.ColumnHeaderCell>
                                {!isMobile && <Table.ColumnHeaderCell>IP / Location</Table.ColumnHeaderCell>}
                                <Table.ColumnHeaderCell>Protocol</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Users</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Status</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Actions</Table.ColumnHeaderCell>
                            </Table.Row>
                        </Table.Header>
                        <Table.Body>
                            {devices.map(d => (
                                <Table.Row key={d.id}>
                                    <Table.Cell>
                                        <Text weight="bold" size="2" as="div">{d.name}</Text>
                                        {d.model && <Text size="1" color="gray">{d.model}</Text>}
                                    </Table.Cell>
                                    <Table.Cell><Code size="1">{d.serial_number}</Code></Table.Cell>
                                    {!isMobile && (
                                        <Table.Cell>
                                            {d.ip_address && <Text size="1" as="div">{d.ip_address}</Text>}
                                            {d.location   && <Text size="1" color="gray">{d.location}</Text>}
                                            {!d.ip_address && !d.location && <Text size="1" color="gray">—</Text>}
                                        </Table.Cell>
                                    )}
                                    <Table.Cell>
                                        <Badge color={d.protocol === 'adms' ? 'green' : 'blue'} variant="soft" size="1">
                                            {d.protocol === 'adms' ? 'ADMS' : 'Push SDK'}
                                        </Badge>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Badge color="accent" variant="soft" size="1">{d.users_count ?? 0}</Badge>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Flex gap="1" direction="column">
                                            <Badge color={d.is_online ? 'green' : 'gray'} variant="soft" size="1">
                                                {d.is_online ? 'Online' : 'Offline'}
                                            </Badge>
                                            <Badge color={d.is_active ? 'blue' : 'gray'} variant="soft" size="1">
                                                {d.is_active ? 'Active' : 'Inactive'}
                                            </Badge>
                                        </Flex>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Flex gap="1" wrap="wrap">
                                            <Tooltip content="Ping">
                                                <IconButton size="1" variant="ghost" color="green"
                                                    disabled={pinging === d.id} onClick={() => ping(d)}>
                                                    {pinging === d.id ? <Spinner size="1" /> : <LightningBoltIcon />}
                                                </IconButton>
                                            </Tooltip>
                                            <Tooltip content="Enrollments">
                                                <IconButton size="1" variant="ghost" color="accent" onClick={() => openMappings(d)}>
                                                    <PlusIcon />
                                                </IconButton>
                                            </Tooltip>
                                            <Tooltip content="Edit">
                                                <IconButton size="1" variant="ghost" onClick={() => openEdit(d)}>
                                                    <Pencil1Icon />
                                                </IconButton>
                                            </Tooltip>
                                            {d.protocol !== 'adms' && (
                                                <Tooltip content="Regen Token">
                                                    <IconButton size="1" variant="ghost" color="amber" onClick={() => regen(d)}>
                                                        <ReloadIcon />
                                                    </IconButton>
                                                </Tooltip>
                                            )}
                                            <Tooltip content="Delete">
                                                <IconButton size="1" variant="ghost" color="red" onClick={() => deleteDevice(d)}>
                                                    <TrashIcon />
                                                </IconButton>
                                            </Tooltip>
                                        </Flex>
                                    </Table.Cell>
                                </Table.Row>
                            ))}
                        </Table.Body>
                    </Table.Root>
                </Box>
            )}

            {/* Add/Edit Device Dialog */}
            <Dialog.Root open={dialogOpen} onOpenChange={setDialogOpen}>
                <Dialog.Content style={{ maxWidth: 480 }}>
                    <Dialog.Title>{editDevice ? 'Edit Device' : 'Register Device'}</Dialog.Title>
                    <Flex direction="column" gap="3" mt="3">
                        {[
                            { key: 'name', label: 'Device Name *', ph: 'Main Entrance' },
                            { key: 'serial_number', label: 'Serial Number *', ph: 'ABJM12345678', disabled: !!editDevice },
                            { key: 'ip_address', label: 'IP Address', ph: '192.168.1.100' },
                            { key: 'location', label: 'Location', ph: '3rd Floor, Block B' },
                            { key: 'model', label: 'Model', ph: 'ZKTeco K40' },
                        ].map(({ key, label, ph, disabled }) => (
                            <Box key={key}>
                                <Text size="2" weight="medium" as="div" mb="1">{label}</Text>
                                <TextField.Root size="2" value={form[key]} placeholder={ph}
                                    disabled={disabled}
                                    onChange={e => setForm(f => ({ ...f, [key]: e.target.value }))} />
                            </Box>
                        ))}
                        <Box>
                            <Text size="2" weight="medium" as="div" mb="1">Protocol</Text>
                            <Select.Root size="2" value={form.protocol}
                                onValueChange={v => setForm(f => ({ ...f, protocol: v }))}>
                                <Select.Trigger style={{ width: '100%' }} />
                                <Select.Content>
                                    <Select.Item value="push_sdk">Push SDK — K40, K60, iFace</Select.Item>
                                    <Select.Item value="adms">ADMS — MB460, MB360</Select.Item>
                                </Select.Content>
                            </Select.Root>
                        </Box>
                        <Flex justify="between" align="center">
                            <Box>
                                <Text size="2" weight="medium" as="div">Active</Text>
                                <Text size="1" color="gray">Inactive devices skip attendance events.</Text>
                            </Box>
                            <Switch size="2" checked={form.is_active}
                                onCheckedChange={v => setForm(f => ({ ...f, is_active: v }))} />
                        </Flex>
                    </Flex>
                    <Flex gap="3" mt="5" justify="end">
                        <Dialog.Close><Button variant="soft" color="gray">Cancel</Button></Dialog.Close>
                        <Button onClick={save} disabled={saving}>
                            {saving ? <><Spinner size="1" /> Saving…</> : (editDevice ? 'Update' : 'Register')}
                        </Button>
                    </Flex>
                </Dialog.Content>
            </Dialog.Root>

            {/* Token Dialog */}
            <Dialog.Root open={tokenDialog.open} onOpenChange={o => setTokenDialog(p => ({ ...p, open: o }))}>
                <Dialog.Content style={{ maxWidth: 460 }}>
                    <Dialog.Title>New Auth Token — {tokenDialog.device?.name}</Dialog.Title>
                    <Dialog.Description size="2" color="gray">
                        Save this token. It will not be shown again.
                    </Dialog.Description>
                    <Card variant="surface" mt="3" style={{ background: 'var(--amber-a3)' }}>
                        <Flex align="start" gap="2">
                            <ExclamationTriangleIcon style={{ color: 'var(--amber-11)', flexShrink: 0, marginTop: 2 }} />
                            <Text size="2" color="amber">
                                Configure your device to send this as the <Code>X-Device-Token</Code> header.
                            </Text>
                        </Flex>
                    </Card>
                    <Flex align="center" gap="2" mt="3">
                        <Code size="2" style={{ flex: 1, background: 'var(--gray-a4)', borderRadius: 'var(--radius-2)', padding: '8px 12px', wordBreak: 'break-all' }}>
                            {tokenDialog.token}
                        </Code>
                        <IconButton variant="soft" size="2" onClick={() => copy(tokenDialog.token)}>
                            <CopyIcon />
                        </IconButton>
                    </Flex>
                    <Flex justify="end" mt="5">
                        <Dialog.Close>
                            <Button><CheckCircledIcon /> Done</Button>
                        </Dialog.Close>
                    </Flex>
                </Dialog.Content>
            </Dialog.Root>

            {/* Mappings Dialog */}
            <Dialog.Root open={mapOpen} onOpenChange={setMapOpen}>
                <Dialog.Content style={{ maxWidth: 600 }}>
                    <Dialog.Title>Device Enrollments — {mapDevice?.name}</Dialog.Title>
                    <Flex direction="column" gap="4" mt="3">
                        <Card variant="surface">
                            <Text size="2" weight="medium" as="div" mb="1">Add Device Entry</Text>
                            <Flex gap="3" align="end">
                                <Box flexGrow="1">
                                    <TextField.Root size="2" placeholder="e.g. 1 or 00042"
                                        value={mapForm.device_user_id}
                                        onChange={e => setMapForm(f => ({ ...f, device_user_id: e.target.value }))} />
                                </Box>
                                <Button size="2" onClick={addEntry} disabled={addingUser}>
                                    {addingUser ? <Spinner size="1" /> : <PlusIcon />} Add
                                </Button>
                            </Flex>
                        </Card>

                        <Box>
                            <Text size="2" weight="medium" as="div" mb="2">Entries</Text>
                            {loadingUsers ? (
                                <Flex justify="center" py="6"><Spinner size="3" /></Flex>
                            ) : deviceUsers.length === 0 ? (
                                <Text size="2" color="gray">No entries yet.</Text>
                            ) : (
                                <Box style={{ overflowX: 'auto' }}>
                                    <Table.Root variant="surface">
                                        <Table.Header>
                                            <Table.Row>
                                                <Table.ColumnHeaderCell>Device ID</Table.ColumnHeaderCell>
                                                <Table.ColumnHeaderCell>Linked User</Table.ColumnHeaderCell>
                                                <Table.ColumnHeaderCell>Status</Table.ColumnHeaderCell>
                                                <Table.ColumnHeaderCell>Actions</Table.ColumnHeaderCell>
                                            </Table.Row>
                                        </Table.Header>
                                        <Table.Body>
                                            {deviceUsers.map(entry => (
                                                <Table.Row key={entry.id}>
                                                    <Table.Cell><Code size="1">{entry.device_user_id}</Code></Table.Cell>
                                                    <Table.Cell>
                                                        {entry.user_id
                                                            ? <Box><Text size="2">{entry.name}</Text>{entry.employee_id && <Text size="1" color="gray">#{entry.employee_id}</Text>}</Box>
                                                            : <Badge color="amber" variant="soft" size="1">Unlinked</Badge>}
                                                    </Table.Cell>
                                                    <Table.Cell>
                                                        <Badge color={entry.is_active ? 'green' : 'gray'} variant="soft" size="1">
                                                            {entry.is_active ? 'Active' : 'Inactive'}
                                                        </Badge>
                                                    </Table.Cell>
                                                    <Table.Cell>
                                                        <Flex gap="1">
                                                            {entry.user_id
                                                                ? <Tooltip content="Unlink"><IconButton size="1" variant="ghost" color="amber" onClick={() => unlinkUser(entry)}><MinusIcon /></IconButton></Tooltip>
                                                                : <Tooltip content="Link Employee"><IconButton size="1" variant="ghost" color="accent" onClick={() => { setLinkEntry(entry); setLinkForm({ user_id: '' }); setLinkOpen(true); }}><Pencil1Icon /></IconButton></Tooltip>
                                                            }
                                                            <Tooltip content="Delete"><IconButton size="1" variant="ghost" color="red" onClick={() => deleteEntry(entry)}><TrashIcon /></IconButton></Tooltip>
                                                        </Flex>
                                                    </Table.Cell>
                                                </Table.Row>
                                            ))}
                                        </Table.Body>
                                    </Table.Root>
                                </Box>
                            )}
                        </Box>
                    </Flex>
                    <Flex justify="end" mt="5">
                        <Dialog.Close><Button variant="soft" color="gray">Close</Button></Dialog.Close>
                    </Flex>
                </Dialog.Content>
            </Dialog.Root>

            {/* Link Employee Dialog */}
            <Dialog.Root open={linkOpen} onOpenChange={setLinkOpen}>
                <Dialog.Content style={{ maxWidth: 400 }}>
                    <Dialog.Title>Link to Employee</Dialog.Title>
                    <Dialog.Description size="2" color="gray">
                        Assign enrollment ID <Code size="1">{linkEntry?.device_user_id}</Code> to an employee.
                    </Dialog.Description>
                    <Box mt="3">
                        <Text size="2" weight="medium" as="div" mb="1">Employee</Text>
                        <Select.Root size="2"
                            value={linkForm.user_id ? String(linkForm.user_id) : ''}
                            onValueChange={v => setLinkForm(f => ({ ...f, user_id: v }))}>
                            <Select.Trigger style={{ width: '100%' }} placeholder="Choose employee…" />
                            <Select.Content>
                                {(employees ?? []).map(emp => (
                                    <Select.Item key={String(emp.id)} value={String(emp.id)}>
                                        {emp.name}{emp.employee_id ? ` (${emp.employee_id})` : ''}
                                    </Select.Item>
                                ))}
                            </Select.Content>
                        </Select.Root>
                    </Box>
                    <Flex gap="3" mt="5" justify="end">
                        <Dialog.Close><Button variant="soft" color="gray">Cancel</Button></Dialog.Close>
                        <Button onClick={linkUser} disabled={linking}>
                            {linking ? <Spinner size="1" /> : null} Link
                        </Button>
                    </Flex>
                </Dialog.Content>
            </Dialog.Root>
        </Box>
    );
}

/* ── Logs sub-tab ── */
function LogsTab({ isMobile }) {
    const [logs, setLogs]       = useState([]);
    const [loading, setLoading] = useState(false);
    const [search, setSearch]   = useState('');

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const { data } = await axios.get(route('api.biometric-devices.logs.all'));
            setLogs(data.logs ?? []);
        } catch { showToast.error('Failed to load logs.'); }
        finally { setLoading(false); }
    }, []);

    useEffect(() => { load(); }, [load]);

    const filtered = useMemo(() =>
        logs.filter(l => !search ||
            l.message?.toLowerCase().includes(search.toLowerCase()) ||
            l.level?.toLowerCase().includes(search.toLowerCase())),
        [logs, search]);

    const levelColor = l => ({ error: 'red', warning: 'amber', info: 'blue' }[l] ?? 'gray');

    return (
        <Box>
            <Flex direction={{ initial: 'column', sm: 'row' }} gap="3" align={{ initial: 'stretch', sm: 'center' }} justify="between" mb="4">
                <TextField.Root placeholder="Search logs…" size="2" style={{ maxWidth: 360, flex: 1 }}
                    onChange={e => setSearch(e.target.value)}>
                    <TextField.Slot><MagnifyingGlassIcon /></TextField.Slot>
                    {search && (
                        <TextField.Slot side="right">
                            <IconButton size="1" variant="ghost" color="gray" onClick={() => setSearch('')}><Cross2Icon /></IconButton>
                        </TextField.Slot>
                    )}
                </TextField.Root>
                <Button size="2" variant="soft" color="indigo" onClick={load} disabled={loading}>
                    {loading ? <Spinner size="1" /> : <ReloadIcon />} Refresh
                </Button>
            </Flex>

            {loading ? (
                <Flex justify="center" py="9"><Spinner size="3" /></Flex>
            ) : filtered.length === 0 ? (
                <Flex direction="column" align="center" justify="center" py="9" gap="2">
                    <ActivityLogIcon style={{ width: 40, height: 40, color: 'var(--gray-9)' }} />
                    <Text size="3" weight="medium">{search ? 'No matching logs' : 'No ADMS logs yet'}</Text>
                </Flex>
            ) : (
                <Box style={{ overflowX: 'auto' }}>
                    <Table.Root variant="surface">
                        <Table.Header>
                            <Table.Row>
                                <Table.ColumnHeaderCell style={{ width: 80 }}>Level</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Message</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell style={{ width: 160 }}>Time</Table.ColumnHeaderCell>
                            </Table.Row>
                        </Table.Header>
                        <Table.Body>
                            {filtered.map(log => (
                                <Table.Row key={log.id}>
                                    <Table.Cell>
                                        <Badge color={levelColor(log.level)} variant="soft" size="1">
                                            {(log.level ?? 'info').toUpperCase()}
                                        </Badge>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Text size="1">{log.message}</Text>
                                        {log.context && Object.keys(log.context).length > 0 && (
                                            <Code size="1" style={{ display: 'block', marginTop: 4, whiteSpace: 'pre-wrap', wordBreak: 'break-word' }}>
                                                {JSON.stringify(log.context, null, 2)}
                                            </Code>
                                        )}
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Text size="1" color="gray">{new Date(log.created_at).toLocaleString()}</Text>
                                    </Table.Cell>
                                </Table.Row>
                            ))}
                        </Table.Body>
                    </Table.Root>
                </Box>
            )}
        </Box>
    );
}

/* ── Webhook sub-tab ── */
function WebhookTab() {
    const webhookUrl = `${window.location.origin}/api/biometric/webhook`;
    const admsUrl    = `${window.location.origin}/api/iclock/cdata`;
    const copy = t => navigator.clipboard.writeText(t).then(() => showToast.success('Copied!'));

    const EndpointRow = ({ label, color, url }) => (
        <Card variant="surface">
            <Flex direction="column" gap="3">
                <Flex align="center" gap="2">
                    <Badge color={color} variant="soft">{label}</Badge>
                </Flex>
                <Flex align="center" gap="2">
                    <Code size="2" style={{ flex: 1, background: 'var(--gray-a4)', borderRadius: 'var(--radius-2)', padding: '8px 12px', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                        {url}
                    </Code>
                    <Tooltip content="Copy">
                        <IconButton variant="soft" size="2" onClick={() => copy(url)}><CopyIcon /></IconButton>
                    </Tooltip>
                </Flex>
            </Flex>
        </Card>
    );

    return (
        <Flex direction="column" gap="4">
            <EndpointRow label="Push SDK" color="blue" url={`POST ${webhookUrl}`} />
            <EndpointRow label="ADMS" color="green" url={`GET / POST ${admsUrl}`} />

            <Card variant="surface">
                <Text size="2" weight="medium" as="div" mb="3">Integration Checklist</Text>
                <Flex direction="column" gap="2">
                    {[
                        'Register the device with the correct serial number in the Devices tab.',
                        'For Push SDK: copy the auth token and configure X-Device-Token on the device.',
                        'For ADMS: set the device server address to the ADMS URL above.',
                        'Enroll fingerprints on the device, then link device IDs to employees.',
                        'Verify events arrive via the ADMS Logs tab.',
                    ].map((step, i) => (
                        <Flex key={i} align="start" gap="2">
                            <CheckCircledIcon style={{ color: 'var(--green-9)', flexShrink: 0, marginTop: 2 }} />
                            <Text size="2" color="gray">{step}</Text>
                        </Flex>
                    ))}
                </Flex>
            </Card>
        </Flex>
    );
}

/* ── Main BiometricPanel ── */
export default function BiometricPanel({
    initialDevices = [], employees = [],
    isMobile, tick, onCountChange, onSetHeaderActions, isActive,
}) {
    const [devices, setDevices] = useState(initialDevices);
    const [subTab, setSubTab]   = useState('devices');

    useEffect(() => { onCountChange?.(devices.length); }, [devices.length]);

    useEffect(() => {
        if (!isActive) return;
        onSetHeaderActions?.(null);
    }, [isActive]);

    return (
        <Box>
            {/* Quick stats */}
            <Flex wrap="wrap" gap="2" mb="4">
                <Badge size="2" variant="soft" color="green"  radius="full">
                    <Text weight="bold">{devices.length}</Text> <Text style={{ opacity: 0.7 }}>Devices</Text>
                </Badge>
                <Badge size="2" variant="soft" color="blue"   radius="full">
                    <Text weight="bold">{devices.filter(d => d.is_online).length}</Text> <Text style={{ opacity: 0.7 }}>Online</Text>
                </Badge>
                <Badge size="2" variant="soft" color="violet" radius="full">
                    <Text weight="bold">{devices.reduce((acc, d) => acc + (d.users_count ?? 0), 0)}</Text> <Text style={{ opacity: 0.7 }}>Enrolled</Text>
                </Badge>
            </Flex>

            <Tabs.Root value={subTab} onValueChange={setSubTab}>
                <Tabs.List mb="4">
                    <Tabs.Trigger value="devices">
                        <Flex align="center" gap="2"><DesktopIcon /> Devices</Flex>
                    </Tabs.Trigger>
                    <Tabs.Trigger value="logs">
                        <Flex align="center" gap="2"><ActivityLogIcon /> ADMS Logs</Flex>
                    </Tabs.Trigger>
                    <Tabs.Trigger value="webhook">
                        <Flex align="center" gap="2"><Link2Icon /> Webhook Config</Flex>
                    </Tabs.Trigger>
                </Tabs.List>

                <Tabs.Content value="devices">
                    <DevicesTab devices={devices} setDevices={setDevices} employees={employees} isMobile={isMobile} />
                </Tabs.Content>
                <Tabs.Content value="logs">
                    <LogsTab isMobile={isMobile} />
                </Tabs.Content>
                <Tabs.Content value="webhook">
                    <WebhookTab />
                </Tabs.Content>
            </Tabs.Root>
        </Box>
    );
}
