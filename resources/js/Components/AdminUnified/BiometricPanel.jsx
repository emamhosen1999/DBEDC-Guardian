/**
 * BiometricPanel.jsx
 * Biometric Devices tab — sub-tabs: Devices | ADMS Logs | Webhook Config
 * Pure Radix UI.
 */
import React, { useState, useCallback, useEffect, useMemo } from 'react';
import {
    Badge, Box, Button, Card, Checkbox, Code, Dialog, Flex, Grid,
    IconButton, ScrollArea, Select, Separator, Spinner,
    Switch, Table, Tabs, Text, TextField, Tooltip,
} from '@radix-ui/themes';
import {
    ActivityLogIcon, ArrowRightIcon, CheckCircledIcon, CopyIcon,
    Cross2Icon, DesktopIcon, DotsVerticalIcon, EnvelopeClosedIcon,
    Link2Icon, LockClosedIcon, LockOpen1Icon, MagnifyingGlassIcon,
    MobileIcon, Pencil1Icon, PlusIcon, ReloadIcon, TrashIcon,
    HeartIcon, CheckIcon, CrossCircledIcon, ExclamationTriangleIcon,
} from '@radix-ui/react-icons';
import axios from 'axios';
import { showToast } from '@/utils/toastUtils';
import DeviceUsersModal from '@/Components/DeviceUsersModal';

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
    const [usersModalOpen, setUsersModalOpen] = useState(false);
    const [selectedDevice, setSelectedDevice] = useState(null);

    // Bulk selection state
    const [selectedIds, setSelectedIds] = useState([]);
    const [bulkLoading, setBulkLoading] = useState(false);
    const [bulkDeleteDialog, setBulkDeleteDialog] = useState(false);

    // Device commands state
    const [commandDevice, setCommandDevice] = useState(null);
    const [commandType, setCommandType] = useState('REBOOT');
    const [commandPayload, setCommandPayload] = useState('');
    const [sendingCommand, setSendingCommand] = useState(false);
    const [commandHistory, setCommandHistory] = useState([]);
    const [loadingCommands, setLoadingCommands] = useState(false);
    const [syncStatus, setSyncStatus] = useState(null);
    const [pollingSync, setPollingSync] = useState(false);
    const [isCommandOpen, setIsCommandOpen] = useState(false);

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

    const openUsersModal = (device) => {
        setSelectedDevice(device);
        setUsersModalOpen(true);
    };

    /* ── bulk selection handlers ── */
    const toggleSelect = (id) => {
        setSelectedIds(p => p.includes(id) ? p.filter(x => x !== id) : [...p, id]);
    };

    const toggleSelectAll = () => {
        if (selectedIds.length === devices.length && selectedIds.length > 0) {
            setSelectedIds([]);
        } else {
            setSelectedIds(devices.map(d => d.id));
        }
    };

    const clearSelection = () => setSelectedIds([]);

    const handleBulkSync = async () => {
        setBulkLoading(true);
        try {
            const { data } = await axios.post(route('biometric-devices.bulk.sync'), {
                device_ids: selectedIds,
            });
            showToast.success(data.message);
            clearSelection();
        } catch (e) {
            showToast.error(e.response?.data?.message || 'Failed to queue sync jobs.');
        } finally {
            setBulkLoading(false);
        }
    };

    const handleBulkPing = async () => {
        setBulkLoading(true);
        try {
            const { data } = await axios.post(route('biometric-devices.bulk.ping'), {
                device_ids: selectedIds,
            });
            showToast.success(data.message);
        } catch (e) {
            showToast.error(e.response?.data?.message || 'Failed to ping devices.');
        } finally {
            setBulkLoading(false);
        }
    };

    const handleBulkDelete = async () => {
        setBulkLoading(true);
        try {
            const { data } = await axios.post(route('biometric-devices.bulk.delete'), {
                device_ids: selectedIds,
            });
            showToast.success(data.message);
            setDevices(p => p.filter(d => !selectedIds.includes(d.id)));
            clearSelection();
            setBulkDeleteDialog(false);
        } catch (e) {
            showToast.error(e.response?.data?.message || 'Failed to delete devices.');
        } finally {
            setBulkLoading(false);
        }
    };

    // Device commands functions
    const loadSyncStatus = useCallback(async () => {
        if (!commandDevice) return;
        try {
            const { data } = await axios.get(
                route('api.biometric-devices.sync-status', commandDevice.id),
            );
            setSyncStatus(data);
            if (data.pending === 0 || data.total === 0) setPollingSync(false);
        } catch {
            setPollingSync(false);
        }
    }, [commandDevice]);

    useEffect(() => {
        if (pollingSync && isCommandOpen) {
            const interval = setInterval(loadSyncStatus, 5000);
            return () => clearInterval(interval);
        }
    }, [pollingSync, isCommandOpen, loadSyncStatus]);

    const openCommandModal = async (device) => {
        if (device.protocol !== 'adms') {
            showToast.error('Commands only supported for ADMS protocol devices.');
            return;
        }
        setCommandDevice(device);
        setCommandType('REBOOT');
        setCommandPayload('');
        setLoadingCommands(true);
        setSyncStatus(null);
        setIsCommandOpen(true);
        try {
            const [{ data }, { data: statusData }] = await Promise.all([
                axios.get(route('api.biometric-devices.commands.index', device.id)),
                axios.get(route('api.biometric-devices.sync-status',   device.id)),
            ]);
            setCommandHistory(data.commands ?? []);
            setSyncStatus(statusData);
            if (statusData.total > 0 && statusData.pending > 0) setPollingSync(true);
        } catch {
            showToast.error('Failed to load command history.');
        } finally {
            setLoadingCommands(false);
        }
    };

    const sendCommand = async () => {
        if (!commandDevice) return;
        setSendingCommand(true);
        try {
            let payload = null;
            if (commandType === 'SET_TIME') {
                payload = { time: commandPayload || new Date().toISOString().slice(0, 19).replace('T', ' ') };
            } else if (commandType === 'ADD_USER' || commandType === 'UPDATE_USER') {
                try   { payload = JSON.parse(commandPayload); }
                catch { showToast.error('Invalid JSON payload.'); setSendingCommand(false); return; }
            } else if (commandType === 'DELETE_USER') {
                payload = { pin: commandPayload };
            }

            const { data } = await axios.post(
                route('api.biometric-devices.commands.queue', commandDevice.id),
                { device_id: commandDevice.id, command_type: commandType, payload },
            );
            showToast.success(`Command queued: ${data.command.adms_string}`);

            const { data: historyData } = await axios.get(
                route('api.biometric-devices.commands.index', commandDevice.id),
            );
            setCommandHistory(historyData.commands ?? []);
            setCommandType('REBOOT');
            setCommandPayload('');
        } catch (err) {
            showToast.error(err.response?.data?.message ?? 'Failed to queue command.');
        } finally {
            setSendingCommand(false);
        }
    };

    const syncUsersToDevice = async () => {
        if (!commandDevice) return;
        setSendingCommand(true);
        try {
            const { data } = await axios.post(
                route('api.biometric-devices.sync-users', commandDevice.id),
            );
            showToast.success(`Sync job queued for ${data.device}.`);
            setTimeout(async () => {
                const { data: historyData } = await axios.get(
                    route('api.biometric-devices.commands.index', commandDevice.id),
                );
                setCommandHistory(historyData.commands ?? []);
            }, 2000);
        } catch (err) {
            showToast.error(err.response?.data?.message ?? 'Failed to queue sync job.');
        } finally {
            setSendingCommand(false);
        }
    };

    const refreshCommandHistory = async () => {
        if (!commandDevice) return;
        setLoadingCommands(true);
        try {
            const { data } = await axios.get(
                route('api.biometric-devices.commands.index', commandDevice.id),
            );
            setCommandHistory(data.commands ?? []);
        } catch {
            showToast.error('Failed to reload commands.');
        } finally {
            setLoadingCommands(false);
        }
    };

    const copy = t => navigator.clipboard.writeText(t).then(() => showToast.success('Copied!'));

    return (
        <Box>
            <Flex justify="end" mb="3">
                <Button size="2" onClick={openAdd}><PlusIcon /> Add Device</Button>
            </Flex>

            {/* Bulk Actions Toolbar */}
            {selectedIds.length > 0 && (
                <Card size="2" variant="surface" mb="3" style={{ background: 'var(--indigo-a3)', border: '1px solid var(--indigo-a7)' }}>
                    <Flex align="center" justify="between" gap="3">
                        <Flex align="center" gap="2">
                            <CheckIcon style={{ color: 'var(--indigo-9)' }} />
                            <Text size="2" weight="medium">{selectedIds.length} device(s) selected</Text>
                        </Flex>
                        <Flex gap="2">
                            <Button size="2" variant="soft" color="green" disabled={bulkLoading} onClick={handleBulkSync}>
                                {bulkLoading ? <Spinner size="1" /> : 'Sync Users'}
                            </Button>
                            <Button size="2" variant="soft" color="indigo" disabled={bulkLoading} onClick={handleBulkPing}>
                                {bulkLoading ? <Spinner size="1" /> : 'Ping'}
                            </Button>
                            <Button size="2" variant="soft" color="red" disabled={bulkLoading} onClick={() => setBulkDeleteDialog(true)}>
                                {bulkLoading ? <Spinner size="1" /> : <><TrashIcon /> Delete</>}
                            </Button>
                            <IconButton size="2" variant="ghost" color="gray" onClick={clearSelection} aria-label="Clear selection">
                                <Cross2Icon />
                            </IconButton>
                        </Flex>
                    </Flex>
                </Card>
            )}

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
                                <Table.ColumnHeaderCell style={{ width: 40 }}>
                                    <Checkbox.Root
                                        checked={selectedIds.length === devices.length && devices.length > 0}
                                    />
                                </Table.Cell>
                                <Table.Cell>
                                    <Text weight="bold" size="2" as="div">{d.name}</Text>
                                    {d.model && <Text size="1" color="gray">{d.model}</Text>}
                                </Table.Cell>
                                <Table.Cell><Code size="1">{d.serial_number}</Code></Table.Cell>
                                <Table.Cell>
                                    {d.ip_address && <Text size="1" as="div">{d.ip_address}</Text>}
                                    {d.location && <Text size="1" color="gray">{d.location}</Text>}
                                    {!d.ip_address && !d.location && <Text size="1" color="gray">—</Text>}
                                </Table.Cell>
                                <Table.Cell>
                                    <Badge color={d.protocol === 'adms' ? 'green' : 'blue'} variant="soft" size="1">
                                        {d.protocol === 'adms' ? 'ADMS' : 'Push SDK'}
                                    </Badge>
                                </Table.Cell>
                                <Table.Cell>
                                    <Button
                                        variant="soft"
                                        color="accent"
                                        size="1"
                                        onClick={() => openUsersModal(d)}
                                    >
                                        {d.users_count ?? 0} users
                                    </Button>
                                </Table.Cell>
                                <Table.Cell>
                                    <Text size="1" color="gray">{d.last_ping ? new Date(d.last_ping).toLocaleString() : 'Never'}</Text>
                                </Table.Cell>
                                <Table.Cell>
                                    <Badge color={d.is_active ? 'green' : 'red'} variant="soft" size="1">
                                        {d.is_active ? 'Active' : 'Inactive'}
                                    </Badge>
                                </Table.Cell>
                                <Table.Cell>
                                    <Flex gap="1">
                                        <Tooltip content="Ping device">
                                            <IconButton size="1" variant="soft" color="indigo" onClick={() => handlePing(d)}>
                                                <ReloadIcon />
                                            </IconButton>
                                        </Tooltip>
                                        <Tooltip content="Edit device">
                                            <IconButton size="1" variant="soft" color="gray" onClick={() => openEdit(d)}>
                                                <Pencil1Icon />
                                            </IconButton>
                                        </Tooltip>
                                        <Tooltip content="Delete device">
                                            <IconButton size="1" variant="soft" color="red" onClick={() => openDelete(d)}>
                                                <TrashIcon />
                                            </IconButton>
                                        </Tooltip>
                                    </Flex>
                                </Table.Cell>
                            </Table.Row>
                                                <Tooltip content="Device commands">
                                                    <IconButton size="1" variant="ghost" color="blue" onClick={() => openCommandModal(d)}>
                                                        <LightningBoltIcon />
                                                    </IconButton>
                                                </Tooltip>
                                            )}
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

            {/* Device Commands Modal */}
            <Dialog.Root open={isCommandOpen} onOpenChange={setIsCommandOpen}>
                <Dialog.Content style={{ maxWidth: 620 }}>
                    <Dialog.Title>Device Commands — {commandDevice?.name}</Dialog.Title>
                    <Dialog.Description size="2" color="gray">
                        Queue commands and sync employees to this ADMS device.
                    </Dialog.Description>

                    <Flex direction="column" gap="4" mt="4">

                        {/* Bulk Sync */}
                        <Card variant="surface" style={{ backgroundColor: 'var(--accent-a3)' }}>
                            <Flex direction="column" gap="3">
                                <Flex justify="between" align="start" gap="3">
                                    <Box>
                                        <Text size="2" weight="medium" as="div">Bulk User Sync</Text>
                                        <Text size="1" color="gray" as="div" mt="1">
                                            Push all active employees to this device. Enroll biometrics on the
                                            device afterwards.
                                        </Text>
                                    </Box>
                                    <Button
                                        size="2"
                                        onClick={syncUsersToDevice}
                                        disabled={sendingCommand}
                                        style={{ flexShrink: 0 }}
                                    >
                                        {sendingCommand
                                            ? <><Spinner size="1" /> Syncing…</>
                                            : 'Sync All Users'}
                                    </Button>
                                </Flex>

                                {/* Sync progress */}
                                {syncStatus && syncStatus.total > 0 && (
                                    <Flex direction="column" gap="2">
                                        <Flex justify="between" align="center">
                                            <Text size="1" weight="medium">Sync Progress</Text>
                                            <Text size="1">{syncStatus.progress}%</Text>
                                        </Flex>
                                        <Box
                                            style={{
                                                height: 8,
                                                backgroundColor: 'var(--gray-5)',
                                                borderRadius: 'var(--radius-1)',
                                                overflow: 'hidden',
                                            }}
                                        >
                                            <Box
                                                style={{
                                                    height: '100%',
                                                    backgroundColor: 'var(--accent-9)',
                                                    width: `${syncStatus.progress}%`,
                                                    transition: 'width 0.3s ease',
                                                }}
                                            />
                                        </Box>
                                        <Flex gap="3" wrap="wrap">
                                            <Badge color="gray"  variant="soft" size="1">Total: {syncStatus.total}</Badge>
                                            <Badge color="amber" variant="soft" size="1">Pending: {syncStatus.pending}</Badge>
                                            <Badge color="blue"  variant="soft" size="1">Sent: {syncStatus.sent}</Badge>
                                            <Badge color="green" variant="soft" size="1">Executed: {syncStatus.executed}</Badge>
                                            {syncStatus.failed > 0 && (
                                                <Badge color="red" variant="soft" size="1">Failed: {syncStatus.failed}</Badge>
                                            )}
                                        </Flex>
                                    </Flex>
                                )}
                            </Flex>
                        </Card>

                        {/* Single command */}
                        <Box>
                            <Text size="2" weight="medium" as="div" mb="2">Single Command</Text>
                            <Flex direction="column" gap="3">
                                <Select.Root value={commandType} onValueChange={setCommandType}>
                                    <Select.Trigger
                                        style={{ width: '100%' }}
                                        placeholder="Select command type"
                                    />
                                    <Select.Content>
                                        <Select.Item value="REBOOT">Reboot Device</Select.Item>
                                        <Select.Item value="SET_TIME">Set Device Time</Select.Item>
                                        <Select.Item value="ADD_USER">Add User</Select.Item>
                                        <Select.Item value="UPDATE_USER">Update User</Select.Item>
                                        <Select.Item value="DELETE_USER">Delete User</Select.Item>
                                        <Select.Item value="CLEAR_LOG">Clear Attendance Logs</Select.Item>
                                        <Select.Item value="CLEAR_DATA">Clear All Data</Select.Item>
                                    </Select.Content>
                                </Select.Root>

                                {(commandType === 'SET_TIME' ||
                                  commandType === 'DELETE_USER' ||
                                  commandType === 'ADD_USER' ||
                                  commandType === 'UPDATE_USER') && (
                                    <Box>
                                        <Text size="2" as="div" mb="1">
                                            {commandType === 'SET_TIME'    && 'Time (YYYY-MM-DD HH:MM:SS)'}
                                            {commandType === 'DELETE_USER' && 'User PIN / ID'}
                                            {(commandType === 'ADD_USER' || commandType === 'UPDATE_USER') &&
                                                'User Data (JSON)'}
                                        </Text>
                                        <TextField.Root
                                            value={commandPayload}
                                            onChange={e => setCommandPayload(e.target.value)}
                                            placeholder={
                                                commandType === 'SET_TIME'
                                                    ? '2026-05-12 18:30:00'
                                                    : commandType === 'DELETE_USER'
                                                    ? '42'
                                                    : '{"pin":"42","name":"John Doe","card":"123456"}'
                                            }
                                            size="2"
                                        />
                                        {(commandType === 'ADD_USER' || commandType === 'UPDATE_USER') && (
                                            <Text size="1" color="gray" mt="1" as="div">
                                                Example: {'{"pin":"42","name":"John Doe","card":"123456","privilege":0}'}
                                            </Text>
                                        )}
                                    </Box>
                                )}

                                <Button
                                    onClick={sendCommand}
                                    disabled={sendingCommand}
                                >
                                    {sendingCommand ? <><Spinner size="1" /> Sending…</> : 'Send Command'}
                                </Button>
                            </Flex>
                        </Box>

                        {/* Command history */}
                        <Box>
                            <Flex justify="between" align="center" mb="2">
                                <Text size="2" weight="medium">Command History</Text>
                                <IconButton
                                    variant="ghost"
                                    size="1"
                                    onClick={refreshCommandHistory}
                                    disabled={loadingCommands}
                                    aria-label="Refresh command history"
                                >
                                    {loadingCommands
                                        ? <Spinner size="1" />
                                        : <ReloadIcon />}
                                </IconButton>
                            </Flex>

                            {loadingCommands ? (
                                <Flex justify="center" py="4">
                                    <Spinner size="3" />
                                </Flex>
                            ) : commandHistory.length === 0 ? (
                                <Card variant="surface">
                                    <Flex justify="center" py="4">
                                        <Text size="2" color="gray">No commands sent yet.</Text>
                                    </Flex>
                                </Card>
                            ) : (
                                <Box style={{ overflowX: 'auto' }}>
                                    <Table.Root variant="surface">
                                        <Table.Header>
                                            <Table.Row>
                                                <Table.ColumnHeaderCell>Type</Table.ColumnHeaderCell>
                                                <Table.ColumnHeaderCell>Status</Table.ColumnHeaderCell>
                                                <Table.ColumnHeaderCell>Created</Table.ColumnHeaderCell>
                                            </Table.Row>
                                        </Table.Header>
                                        <Table.Body>
                                            {commandHistory.map(cmd => (
                                                <Table.Row key={cmd.id}>
                                                    <Table.Cell>
                                                        <Code size="1">{cmd.command_type}</Code>
                                                    </Table.Cell>
                                                    <Table.Cell>
                                                        <Badge
                                                            color={
                                                                cmd.status === 'executed' ? 'green' :
                                                                cmd.status === 'sent' ? 'blue' :
                                                                cmd.status === 'failed' ? 'red' : 'gray'
                                                            }
                                                            variant="soft"
                                                            size="1"
                                                        >
                                                            {cmd.status}
                                                        </Badge>
                                                    </Table.Cell>
                                                    <Table.Cell>
                                                        <Text size="1" color="gray">
                                                            {new Date(cmd.created_at).toLocaleString()}
                                                        </Text>
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
                        <Dialog.Close>
                            <Button variant="soft" color="gray">Close</Button>
                        </Dialog.Close>
                    </Flex>
                </Dialog.Content>
            </Dialog.Root>

            {/* Device Users Modal */}
            <DeviceUsersModal
                open={usersModalOpen}
                onOpenChange={setUsersModalOpen}
                device={selectedDevice}
                employees={employees}
            />

            {/* Bulk Delete Dialog */}
            <Dialog.Root open={bulkDeleteDialog} onOpenChange={setBulkDeleteDialog}>
                <Dialog.Content style={{ maxWidth: 420 }}>
                    <Dialog.Title>Delete Devices</Dialog.Title>
                    <Dialog.Description size="2" color="gray">
                        Are you sure you want to delete <Text weight="bold">{selectedIds.length} device(s)</Text>?
                        All user mappings and commands will be removed. This action cannot be undone.
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

/* ── Logs sub-tab ── */
function LogsTab({ isMobile }) {
    const [logs, setLogs]       = useState([]);
    const [loading, setLoading] = useState(false);
    const [search, setSearch]   = useState('');
    const [pagination, setPagination] = useState({ current_page: 1, per_page: 50, total: 0 });

    const load = useCallback(async (page = 1) => {
        setLoading(true);
        try {
            const { data } = await axios.get(route('biometric-devices.logs'), {
                params: { page, per_page: pagination.per_page }
            });
            setLogs(data.logs ?? []);
            setPagination({
                current_page: data.current_page || 1,
                per_page: data.per_page || 50,
                total: data.total || 0,
            });
        } catch { showToast.error('Failed to load logs.'); }
        finally { setLoading(false); }
    }, [pagination.per_page]);

    useEffect(() => { load(1); }, [load]);

    const filtered = useMemo(() =>
        logs.filter(l => !search ||
            l.message?.toLowerCase().includes(search.toLowerCase()) ||
            l.type?.toLowerCase().includes(search.toLowerCase())),
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

            {/* Pagination */}
            {pagination.total > 0 && (
                <Flex justify="end" align="center" gap="2" mt="4">
                    <Text size="1" color="gray">
                        Showing {(pagination.current_page - 1) * pagination.per_page + 1} to {Math.min(pagination.current_page * pagination.per_page, pagination.total)} of {pagination.total}
                    </Text>
                    <Button
                        size="1"
                        variant="soft"
                        disabled={pagination.current_page === 1}
                        onClick={() => load(1)}
                    >
                        «
                    </Button>
                    <Button
                        size="1"
                        variant="soft"
                        disabled={pagination.current_page === 1}
                        onClick={() => load(pagination.current_page - 1)}
                    >
                        ‹
                    </Button>
                    <Text size="1" color="gray" px="2">
                        Page {pagination.current_page} of {Math.ceil(pagination.total / pagination.per_page)}
                    </Text>
                    <Button
                        size="1"
                        variant="soft"
                        disabled={pagination.current_page >= Math.ceil(pagination.total / pagination.per_page)}
                        onClick={() => load(pagination.current_page + 1)}
                    >
                        ›
                    </Button>
                    <Button
                        size="1"
                        variant="soft"
                        disabled={pagination.current_page >= Math.ceil(pagination.total / pagination.per_page)}
                        onClick={() => load(Math.ceil(pagination.total / pagination.per_page))}
                    >
                        »
                    </Button>
                </Flex>
            )}
        </Box>
    );
}

/* ── Webhook sub-tab ── */
function WebhookTab() {
    const webhookUrl = `${window.location.origin}/api/biometric/webhook`;
    const admsUrl    = `http://erp.dhakabypass.com/iclock/cdata`;
    const admsDomain = 'erp.dhakabypass.com';
    const copy = t => navigator.clipboard.writeText(t).then(() => showToast.success('Copied to clipboard.'));

    return (
        <Flex direction="column" gap="4">

            {/* Push SDK */}
            <Card variant="surface">
                <Flex direction="column" gap="3">
                    <Flex align="center" gap="2">
                        <Badge color="blue" variant="soft">Push SDK</Badge>
                        <Text size="2" weight="medium">
                            For devices with Push SDK (K40, K60, iFace series)
                        </Text>
                    </Flex>
                    <Text size="2" color="gray">
                        Use the device's auth token as the{' '}
                        <Code>X-Device-Token</Code> request header.
                        Regenerate the token per device from the Devices tab.
                    </Text>
                    <Flex align="center" gap="2">
                        <Box flexGrow="1" style={{ minWidth: 0 }}>
                            <Code
                                size="2"
                                style={{
                                    display: 'block',
                                    background: 'var(--gray-a4)',
                                    borderRadius: 'var(--radius-2)',
                                    padding: '8px 12px',
                                    overflow: 'hidden',
                                    textOverflow: 'ellipsis',
                                    whiteSpace: 'nowrap',
                                }}
                            >
                                POST {webhookUrl}
                            </Code>
                        </Box>
                        <Tooltip content="Copy URL">
                            <IconButton
                                variant="soft"
                                size="2"
                                onClick={() => copy(webhookUrl)}
                                aria-label="Copy webhook URL"
                            >
                                <CopyIcon width={16} height={16} />
                            </IconButton>
                        </Tooltip>
                    </Flex>
                </Flex>
            </Card>

            {/* ADMS */}
            <Card variant="surface">
                <Flex direction="column" gap="3">
                    <Flex align="center" gap="2">
                        <Badge color="green" variant="soft">ADMS</Badge>
                        <Text size="2" weight="medium">
                            For ZKTeco MB460 / MB360 (ADMS Push Protocol)
                        </Text>
                    </Flex>

                    <Text size="2" color="gray">
                        The MB460 has two push-server fields:{' '}
                        <strong>Enable</strong> and{' '}
                        <strong>Server Domain Name</strong>.
                        The device automatically appends{' '}
                        <Code size="1">/iclock/cdata</Code> to whatever you enter —
                        enter only the domain or IP, no path, no{' '}
                        <Code size="1">https://</Code>.
                    </Text>

                    {/* Server Domain Name field value */}
                    <Box>
                        <Text size="1" color="gray" as="div" mb="1">
                            Server Domain Name — paste this into the device:
                        </Text>
                        <Flex align="center" gap="2">
                            <Box flexGrow="1" style={{ minWidth: 0 }}>
                                <Code
                                    size="2"
                                    style={{
                                        display: 'block',
                                        background: 'var(--gray-a4)',
                                        borderRadius: 'var(--radius-2)',
                                        padding: '8px 12px',
                                        overflow: 'hidden',
                                        textOverflow: 'ellipsis',
                                        whiteSpace: 'nowrap',
                                    }}
                                >
                                    {admsDomain}
                                </Code>
                            </Box>
                            <Tooltip content="Copy domain">
                                <IconButton
                                    variant="soft"
                                    size="2"
                                    onClick={() => copy(admsDomain)}
                                    aria-label="Copy domain"
                                >
                                    <CopyIcon width={16} height={16} />
                                </IconButton>
                            </Tooltip>
                        </Flex>
                    </Box>

                    {/* Resulting full URL for reference */}
                    <Box>
                        <Text size="1" color="gray" as="div" mb="1">
                            Full URL (for reference, device will construct this automatically):
                        </Text>
                        <Box flexGrow="1" style={{ minWidth: 0 }}>
                            <Code
                                size="2"
                                style={{
                                    display: 'block',
                                    background: 'var(--gray-a4)',
                                    borderRadius: 'var(--radius-2)',
                                    padding: '8px 12px',
                                    overflow: 'hidden',
                                    textOverflow: 'ellipsis',
                                    whiteSpace: 'nowrap',
                                }}
                            >
                                {admsUrl}
                            </Code>
                        </Box>
                    </Box>

                    {/* DHCP warning */}
                    <Card variant="surface" style={{ backgroundColor: 'var(--amber-a3)' }}>
                        <Flex align="start" gap="2">
                            <ExclamationTriangleIcon
                                width={16}
                                height={16}
                                color="var(--amber-11)"
                                style={{ flexShrink: 0, marginTop: 2 }}
                            />
                            <Flex direction="column" gap="1">
                                <Text size="2" color="amber" weight="medium">
                                    Device IP is on DHCP
                                </Text>
                                <Text size="1" color="amber">
                                    If your device gets its IP via DHCP, the IP may change over time.
                                    Consider setting a static IP reservation on your router or configuring
                                    a static IP on the device itself.
                                </Text>
                            </Flex>
                        </Flex>
                    </Card>
                </Flex>
            </Card>

            {/* Integration Checklist */}
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

/* ── Health sub-tab ── */
function HealthTab({ isMobile }) {
    const [healthData, setHealthData] = useState({ devices: [], summary: {} });
    const [loading, setLoading] = useState(true);
    const [filterStatus, setFilterStatus] = useState('all');

    const loadHealth = useCallback(async () => {
        setLoading(true);
        try {
            const { data } = await axios.get(route('biometric-devices.health'));
            setHealthData(data);
        } catch (e) {
            showToast.error('Failed to load health metrics.');
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        loadHealth();
        const interval = setInterval(loadHealth, 30000); // Auto-refresh every 30 seconds
        return () => clearInterval(interval);
    }, [loadHealth]);

    const filteredDevices = useMemo(() => {
        if (filterStatus === 'all') return healthData.devices;
        return healthData.devices.filter(d => d.status === filterStatus);
    }, [healthData.devices, filterStatus]);

    const statusColor = s => ({
        healthy: 'green',
        warning: 'amber',
        critical: 'red',
    }[s] ?? 'gray');

    const statusIcon = s => ({
        healthy: <CheckIcon />,
        warning: <ExclamationTriangleIcon />,
        critical: <CrossCircledIcon />,
    }[s] ?? null);

    const formatTime = (iso) => iso ? new Date(iso).toLocaleString() : 'Never';

    return (
        <Box>
            {/* Summary cards */}
            <Grid columns={{ initial: '2', sm: '4' }} gap="3" mb="4">
                <Card variant="surface">
                    <Flex direction="column" gap="1">
                        <Text size="1" color="gray">Overall Health</Text>
                        <Text size="4" weight="bold" color={healthData.summary.overall_health_score >= 80 ? 'green' : healthData.summary.overall_health_score >= 50 ? 'amber' : 'red'}>
                            {healthData.summary.overall_health_score ?? 0}%
                        </Text>
                    </Flex>
                </Card>
                <Card variant="surface">
                    <Flex direction="column" gap="1">
                        <Text size="1" color="gray">Online</Text>
                        <Text size="4" weight="bold" color="green">
                            {healthData.summary.online ?? 0}
                        </Text>
                    </Flex>
                </Card>
                <Card variant="surface">
                    <Flex direction="column" gap="1">
                        <Text size="1" color="gray">Offline</Text>
                        <Text size="4" weight="bold" color="red">
                            {healthData.summary.offline ?? 0}
                        </Text>
                    </Flex>
                </Card>
                <Card variant="surface">
                    <Flex direction="column" gap="1">
                        <Text size="1" color="gray">Total Devices</Text>
                        <Text size="4" weight="bold">
                            {healthData.summary.total ?? 0}
                        </Text>
                    </Flex>
                </Card>
            </Grid>

            {/* Filter toolbar */}
            <Flex direction={{ initial: 'column', sm: 'row' }} gap="3" align={{ initial: 'stretch', sm: 'center' }} justify="between" mb="4">
                <Select.Root value={filterStatus} onValueChange={setFilterStatus} size="2">
                    <Select.Trigger style={{ width: 180 }} />
                    <Select.Content>
                        <Select.Item value="all">All Status</Select.Item>
                        <Select.Item value="healthy">Healthy</Select.Item>
                        <Select.Item value="warning">Warning</Select.Item>
                        <Select.Item value="critical">Critical</Select.Item>
                    </Select.Content>
                </Select.Root>
                <Button size="2" variant="soft" color="indigo" onClick={loadHealth} disabled={loading}>
                    {loading ? <Spinner size="1" /> : <ReloadIcon />} Refresh
                </Button>
            </Flex>

            {/* Health table */}
            {loading ? (
                <Flex justify="center" py="9"><Spinner size="3" /></Flex>
            ) : filteredDevices.length === 0 ? (
                <Flex direction="column" align="center" justify="center" py="9" gap="2">
                    <HeartIcon style={{ width: 36, height: 36, color: 'var(--gray-9)' }} />
                    <Text size="3" weight="medium">No devices found</Text>
                </Flex>
            ) : (
                <Box style={{ overflowX: 'auto' }}>
                    <Table.Root variant="surface" size="2">
                        <Table.Header>
                            <Table.Row>
                                <Table.ColumnHeaderCell>Device</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Serial</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Status</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Health Score</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Last Heartbeat</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Latency</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Uptime</Table.ColumnHeaderCell>
                            </Table.Row>
                        </Table.Header>
                        <Table.Body>
                            {filteredDevices.map(device => (
                                <Table.Row key={device.id}>
                                    <Table.Cell>
                                        <Flex direction="column">
                                            <Text weight="bold" size="2">{device.name}</Text>
                                            <Text size="1" color="gray">{device.ip_address}</Text>
                                        </Flex>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Text size="1" color="gray">{device.serial_number}</Text>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Badge color={statusColor(device.status)} variant="soft" size="1">
                                            <Flex align="center" gap="1">
                                                {statusIcon(device.status)}
                                                {device.status.toUpperCase()}
                                            </Flex>
                                        </Badge>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Text weight="bold" color={device.health_score >= 80 ? 'green' : device.health_score >= 50 ? 'amber' : 'red'}>
                                            {device.health_score}%
                                        </Text>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Text size="1" color="gray">{formatTime(device.last_heartbeat)}</Text>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Text size="1">{device.latency ? `${device.latency}ms` : 'N/A'}</Text>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Text size="1">{device.uptime_days}d</Text>
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

/* ── Main BiometricPanel ── */
export default function BiometricPanel({
    initialDevices = [], employees = [],
    isMobile, tick, onCountChange, onSetHeaderActions, isActive,
}) {
    const [devices, setDevices] = useState(initialDevices);
    const [subTab, setSubTab]   = useState('devices');

    const refreshDevices = useCallback(async () => {
        try {
            const { data } = await axios.get(route('biometric-devices.index'));
            setDevices(data.devices ?? []);
        } catch { /* silently fail */ }
    }, []);

    // Poll devices and logs when active
    useEffect(() => {
        if (!isActive) return;

        const interval = setInterval(() => {
            refreshDevices();
        }, 5000);

        return () => clearInterval(interval);
    }, [isActive, refreshDevices]);

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
                    <Tabs.Trigger value="health">
                        <Flex align="center" gap="2"><HeartIcon /> Device Health</Flex>
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
                <Tabs.Content value="health">
                    <HealthTab isMobile={isMobile} />
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
