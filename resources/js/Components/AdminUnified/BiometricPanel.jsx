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
    ActivityLogIcon, ArrowRightIcon, CheckCircledIcon, ChevronLeftIcon,
    ChevronRightIcon, CopyIcon, Cross2Icon, DesktopIcon, DotsVerticalIcon,
    EnvelopeClosedIcon, Link2Icon, LockClosedIcon, LockOpen1Icon,
    MagnifyingGlassIcon, MobileIcon, Pencil1Icon, PlusIcon, ReloadIcon,
    TrashIcon, HeartIcon, CheckIcon, CrossCircledIcon, ExclamationTriangleIcon,
    DownloadIcon,
} from '@radix-ui/react-icons';
import axios from 'axios';
import { showToast } from '@/utils/toastUtils';
import TablePagination from '@/Components/TablePagination.jsx';
import * as XLSX from 'xlsx';
import { jsPDF } from 'jspdf';
import 'jspdf-autotable';

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
    const [downloadingDevice, setDownloadingDevice] = useState(null);

    // Bulk selection state
    const [selectedIds, setSelectedIds] = useState([]);
    const [bulkLoading, setBulkLoading] = useState(false);
    const [bulkDeleteDialog, setBulkDeleteDialog] = useState(false);

    // Device commands state
    const [commandDevice, setCommandDevice] = useState(null);
    const [commandType, setCommandType] = useState('REBOOT');
    const [commandPayload, setCommandPayload] = useState('');
    const [logStartDate, setLogStartDate] = useState('');
    const [logEndDate, setLogEndDate] = useState('');
    const [sendingCommand, setSendingCommand] = useState(false);
    const [commandHistory, setCommandHistory] = useState([]);
    const [loadingCommands, setLoadingCommands] = useState(false);
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

    const handleDownloadLogs = async (device) => {
        setDownloadingDevice(device.id);
        try {
            const { data } = await axios.post(route('biometric-devices.download-logs', device.id));
            showToast.success(data.message || 'Log download initiated.');
        } catch (e) {
            showToast.error(e.response?.data?.message || 'Failed to initiate log download.');
        } finally {
            setDownloadingDevice(null);
        }
    };

    const regen = async d => {
        if (!confirm('Regenerate token? Device must be reconfigured.')) return;
        try {
            const { data } = await axios.post(route('biometric-devices.regenerate-token', d.id));
            setTokenDialog({ open: true, device: d, token: data.auth_token });
            showToast.success('Token regenerated.');
        } catch { showToast.error('Failed.'); }
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

    const openCommandModal = async (device) => {
        if (device.protocol !== 'adms') {
            showToast.error('Commands only supported for ADMS protocol devices.');
            return;
        }
        setCommandDevice(device);
        setCommandType('REBOOT');
        setCommandPayload('');
        setLogStartDate('');
        setLogEndDate('');
        setLoadingCommands(true);
        setIsCommandOpen(true);
        try {
            const { data } = await axios.get(
                route('api.biometric-devices.commands.index', device.id),
            );
            setCommandHistory(data.commands ?? []);
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
            } else if (commandType === 'CHECK_ATTLOG') {
                if (logStartDate && logEndDate) {
                    const formatDateTime = (val) => {
                        let formatted = val.replace('T', ' ');
                        if (formatted.length === 16) {
                            formatted += ':00';
                        }
                        return formatted;
                    };
                    payload = {
                        start_time: formatDateTime(logStartDate),
                        end_time: formatDateTime(logEndDate),
                    };
                } else if (logStartDate || logEndDate) {
                    showToast.error('Please specify both start and end date/time, or leave both empty.');
                    setSendingCommand(false);
                    return;
                }
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
            setLogStartDate('');
            setLogEndDate('');
        } catch (err) {
            showToast.error(err.response?.data?.message ?? 'Failed to queue command.');
        } finally {
            setSendingCommand(false);
        }
    };

    const handleBulkDownloadLogs = async () => {
        setBulkLoading(true);
        try {
            const { data } = await axios.post(route('biometric-devices.bulk.download-logs'), {
                device_ids: selectedIds,
            });
            showToast.success(data.message || 'Bulk log download initiated.');
            clearSelection();
        } catch (e) {
            showToast.error(e.response?.data?.message || 'Failed to initiate bulk download.');
        } finally {
            setBulkLoading(false);
        }
    };

    const hasSelectedAdms = useMemo(() => {
        return devices.some(d => selectedIds.includes(d.id) && d.protocol === 'adms');
    }, [devices, selectedIds]);

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
                            <Button size="2" variant="soft" color="indigo" disabled={bulkLoading} onClick={handleBulkPing}>
                                {bulkLoading ? <Spinner size="1" /> : 'Ping'}
                            </Button>
                            {hasSelectedAdms && (
                                <Button size="2" variant="soft" color="green" disabled={bulkLoading} onClick={handleBulkDownloadLogs}>
                                    {bulkLoading ? <Spinner size="1" /> : <><DownloadIcon /> Download Logs</>}
                                </Button>
                            )}
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
                                    <Checkbox
                                        checked={selectedIds.length === devices.length && devices.length > 0}
                                        onCheckedChange={toggleSelectAll}
                                    />
                                </Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Device</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Serial</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>IP / Location</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Protocol</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Last Ping</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Status</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Actions</Table.ColumnHeaderCell>
                            </Table.Row>
                        </Table.Header>
                        <Table.Body>
                            {devices.map(d => (
                                <Table.Row key={d.id}>
                                    <Table.Cell>
                                        <Checkbox
                                            checked={selectedIds.includes(d.id)}
                                            onCheckedChange={() => toggleSelect(d.id)}
                                        />
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Text weight="bold" size="2" as="div">{d.name}</Text>
                                        {d.model && <Text size="1" color="gray">{d.model}</Text>}
                                    </Table.Cell>
                                    <Table.Cell><Code size="1">{d.serial_number}</Code></Table.Cell>
                                    <Table.Cell>
                                        {d.ip_address && <Text size="1" as="div">{d.ip_address}</Text>}
                                        {d.location   && <Text size="1" color="gray">{d.location}</Text>}
                                        {!d.ip_address && !d.location && <Text size="1" color="gray">—</Text>}
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Badge color={d.protocol === 'adms' ? 'green' : 'blue'} variant="soft" size="1">
                                            {d.protocol === 'adms' ? 'ADMS' : 'Push SDK'}
                                        </Badge>
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
                                            {d.protocol === 'adms' && (
                                                <>
                                                    <Tooltip content="Download Logs">
                                                        <IconButton
                                                            size="1"
                                                            variant="soft"
                                                            color="green"
                                                            onClick={() => handleDownloadLogs(d)}
                                                            disabled={downloadingDevice === d.id}
                                                        >
                                                            {downloadingDevice === d.id ? <Spinner size="1" /> : <DownloadIcon />}
                                                        </IconButton>
                                                    </Tooltip>
                                                    <Tooltip content="Device Commands">
                                                        <IconButton
                                                            size="1"
                                                            variant="soft"
                                                            color="violet"
                                                            onClick={() => openCommandModal(d)}
                                                        >
                                                            <DotsVerticalIcon />
                                                        </IconButton>
                                                    </Tooltip>
                                                </>
                                            )}
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
                        Queue commands to this ADMS device.
                    </Dialog.Description>

                    <Flex direction="column" gap="4" mt="4">

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
                                        <Select.Item value="CHECK_ATTLOG">Download Attendance Logs</Select.Item>
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

                                {commandType === 'CHECK_ATTLOG' && (
                                    <Flex direction="column" gap="3">
                                        <Box>
                                            <Text size="2" weight="medium" as="div" mb="1">Start Date & Time (Optional)</Text>
                                            <input
                                                type="datetime-local"
                                                value={logStartDate}
                                                onChange={e => setLogStartDate(e.target.value)}
                                                style={{
                                                    width: '100%',
                                                    padding: '8px 12px',
                                                    borderRadius: 'var(--radius-2)',
                                                    border: '1px solid var(--gray-7)',
                                                    background: 'var(--color-background)',
                                                    color: 'var(--color-text)',
                                                    fontSize: 'var(--font-size-2)',
                                                    fontFamily: 'inherit',
                                                }}
                                            />
                                        </Box>
                                        <Box>
                                            <Text size="2" weight="medium" as="div" mb="1">End Date & Time (Optional)</Text>
                                            <input
                                                type="datetime-local"
                                                value={logEndDate}
                                                onChange={e => setLogEndDate(e.target.value)}
                                                style={{
                                                    width: '100%',
                                                    padding: '8px 12px',
                                                    borderRadius: 'var(--radius-2)',
                                                    border: '1px solid var(--gray-7)',
                                                    background: 'var(--color-background)',
                                                    color: 'var(--color-text)',
                                                    fontSize: 'var(--font-size-2)',
                                                    fontFamily: 'inherit',
                                                }}
                                            />
                                        </Box>
                                        <Text size="1" color="gray" mt="1" as="div">
                                            Leave both fields empty to sync all records from the device.
                                        </Text>
                                    </Flex>
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
                                    onClick={() => {
                                        setLoadingCommands(true);
                                        axios.get(route('api.biometric-devices.commands.index', commandDevice.id))
                                            .then(({ data }) => setCommandHistory(data.commands ?? []))
                                            .catch(() => showToast.error('Failed to refresh command history.'))
                                            .finally(() => setLoadingCommands(false));
                                    }}
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
    const [pagination, setPagination] = useState({ currentPage: 1, perPage: 20, total: 0 });

    const load = useCallback(async (page = pagination.currentPage, pp = pagination.perPage) => {
        setLoading(true);
        try {
            const { data } = await axios.get(route('biometric-devices.logs'), {
                params: { page, per_page: pp }
            });
            setLogs(data.logs ?? []);
            setPagination(prev => ({
                ...prev,
                currentPage: data.current_page || 1,
                total: data.total || 0,
            }));
        } catch { showToast.error('Failed to load logs.'); }
        finally { setLoading(false); }
    }, [pagination.currentPage, pagination.perPage]);

    useEffect(() => { load(1); }, [load]);

    const filtered = useMemo(() =>
        logs.filter(l => !search ||
            l.message?.toLowerCase().includes(search.toLowerCase()) ||
            l.type?.toLowerCase().includes(search.toLowerCase())),
        [logs, search]);

    const handlePageChange = (page) => {
        setPagination(prev => ({ ...prev, currentPage: page }));
    };

    const handleRowsPerPageChange = (newPerPage) => {
        setPagination(prev => ({ ...prev, perPage: newPerPage, currentPage: 1 }));
    };

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
                <TablePagination
                    pagination={pagination}
                    onPageChange={handlePageChange}
                    onRowsPerPageChange={handleRowsPerPageChange}
                    loading={loading}
                />
            )}
        </Box>
    );
}

/* ── Heartbeat sub-tab ── */
function HeartbeatTab({ isMobile }) {
    const [logs, setLogs] = useState([]);
    const [loading, setLoading] = useState(false);
    const [search, setSearch] = useState('');
    const [pagination, setPagination] = useState({ currentPage: 1, perPage: 20, total: 0 });

    const load = useCallback(async (page = pagination.currentPage, pp = pagination.perPage) => {
        setLoading(true);
        try {
            const { data } = await axios.get(route('biometric-devices.logs'), {
                params: { page, per_page: pp }
            });
            setLogs(data.logs ?? []);
            setPagination(prev => ({
                ...prev,
                currentPage: data.current_page || 1,
                total: data.total || 0,
            }));
        } catch { showToast.error('Failed to load heartbeat logs.'); }
        finally { setLoading(false); }
    }, [pagination.currentPage, pagination.perPage]);

    useEffect(() => { load(1); }, [load]);

    const filtered = useMemo(() =>
        logs.filter(l => !search ||
            l.message?.toLowerCase().includes(search.toLowerCase()) ||
            l.type?.toLowerCase().includes(search.toLowerCase())),
        [logs, search]);

    const handlePageChange = (page) => {
        setPagination(prev => ({ ...prev, currentPage: page }));
    };

    const handleRowsPerPageChange = (newPerPage) => {
        setPagination(prev => ({ ...prev, perPage: newPerPage, currentPage: 1 }));
    };

    const levelColor = l => ({ error: 'red', warning: 'amber', info: 'blue' }[l] ?? 'gray');

    return (
        <Box>
            <Flex direction={{ initial: 'column', sm: 'row' }} gap="3" align={{ initial: 'stretch', sm: 'center' }} justify="between" mb="4">
                <TextField.Root placeholder="Search heartbeat logs…" size="2" style={{ maxWidth: 360, flex: 1 }}
                    onChange={e => setSearch(e.target.value)}>
                    <TextField.Slot><MagnifyingGlassIcon /></TextField.Slot>
                    {search && (
                        <TextField.Slot side="right">
                            <IconButton size="1" variant="ghost" color="gray" onClick={() => setSearch('')}><Cross2Icon /></IconButton>
                        </TextField.Slot>
                    )}
                </TextField.Root>
                <Button size="2" variant="soft" color="indigo" onClick={() => load(1)} disabled={loading}>
                    {loading ? <Spinner size="1" /> : <ReloadIcon />} Refresh
                </Button>
            </Flex>

            {loading ? (
                <Flex justify="center" py="9"><Spinner size="3" /></Flex>
            ) : filtered.length === 0 ? (
                <Flex direction="column" align="center" justify="center" py="9" gap="2">
                    <ActivityLogIcon style={{ width: 36, height: 36, color: 'var(--gray-9)' }} />
                    <Text size="3" weight="medium">{search ? 'No matching logs' : 'No heartbeat logs yet'}</Text>
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
                <TablePagination
                    pagination={pagination}
                    onPageChange={handlePageChange}
                    onRowsPerPageChange={handleRowsPerPageChange}
                    loading={loading}
                />
            )}
        </Box>
    );
}

/* ── OPERLOG sub-tab ── */
function OperLogTab({ isMobile }) {
    const [logs, setLogs] = useState([]);
    const [loading, setLoading] = useState(false);
    const [search, setSearch] = useState('');
    const [pagination, setPagination] = useState({ currentPage: 1, perPage: 20, total: 0 });

    const load = useCallback(async (page = pagination.currentPage, pp = pagination.perPage) => {
        setLoading(true);
        try {
            const { data } = await axios.get(route('biometric-devices.operlogs'), {
                params: { page, per_page: pp }
            });
            setLogs(data.logs ?? []);
            setPagination(prev => ({
                ...prev,
                currentPage: data.current_page || 1,
                total: data.total || 0,
            }));
        } catch { showToast.error('Failed to load OPERLOG entries.'); }
        finally { setLoading(false); }
    }, [pagination.currentPage, pagination.perPage]);

    useEffect(() => { load(1); }, [load]);

    const filtered = useMemo(() =>
        logs.filter(l => !search ||
            l.operation_type?.toLowerCase().includes(search.toLowerCase()) ||
            l.user_pin?.toLowerCase().includes(search.toLowerCase())),
        [logs, search]);

    const handlePageChange = (page) => {
        setPagination(prev => ({ ...prev, currentPage: page }));
    };

    const handleRowsPerPageChange = (newPerPage) => {
        setPagination(prev => ({ ...prev, perPage: newPerPage, currentPage: 1 }));
    };

    return (
        <Box>
            <Flex direction={{ initial: 'column', sm: 'row' }} gap="3" align={{ initial: 'stretch', sm: 'center' }} justify="between" mb="4">
                <TextField.Root placeholder="Search OPERLOG…" size="2" style={{ maxWidth: 360, flex: 1 }}
                    onChange={e => setSearch(e.target.value)}>
                    <TextField.Slot><MagnifyingGlassIcon /></TextField.Slot>
                    {search && (
                        <TextField.Slot side="right">
                            <IconButton size="1" variant="ghost" color="gray" onClick={() => setSearch('')}><Cross2Icon /></IconButton>
                        </TextField.Slot>
                    )}
                </TextField.Root>
                <Button size="2" variant="soft" color="indigo" onClick={() => load(1)} disabled={loading}>
                    {loading ? <Spinner size="1" /> : <ReloadIcon />} Refresh
                </Button>
            </Flex>

            {loading ? (
                <Flex justify="center" py="9"><Spinner size="3" /></Flex>
            ) : filtered.length === 0 ? (
                <Flex direction="column" align="center" justify="center" py="9" gap="2">
                    <LockClosedIcon style={{ width: 36, height: 36, color: 'var(--gray-9)' }} />
                    <Text size="3" weight="medium">{search ? 'No matching logs' : 'No OPERLOG entries yet'}</Text>
                </Flex>
            ) : (
                <Box style={{ overflowX: 'auto' }}>
                    <Table.Root variant="surface">
                        <Table.Header>
                            <Table.Row>
                                <Table.ColumnHeaderCell>Operation</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>User PIN</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Serial</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Occurred At</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Details</Table.ColumnHeaderCell>
                            </Table.Row>
                        </Table.Header>
                        <Table.Body>
                            {filtered.map(log => (
                                <Table.Row key={log.id}>
                                    <Table.Cell>
                                        <Badge color="blue" variant="soft" size="1">
                                            {log.operation_type || 'Unknown'}
                                        </Badge>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Text size="1">{log.user_pin || '—'}</Text>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Text size="1" color="gray">{log.serial_number}</Text>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Text size="1">{log.occurred_at ? new Date(log.occurred_at).toLocaleString() : '—'}</Text>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Text size="1" color="gray">{log.raw_data}</Text>
                                    </Table.Cell>
                                </Table.Row>
                            ))}
                        </Table.Body>
                    </Table.Root>
                </Box>
            )}

            {/* Pagination */}
            {pagination.total > 0 && (
                <TablePagination
                    pagination={pagination}
                    onPageChange={handlePageChange}
                    onRowsPerPageChange={handleRowsPerPageChange}
                    loading={loading}
                />
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

/* ── ATTLOG sub-tab ── */
const STATUS_META = {
    processed:    { color: 'green',  label: 'Processed' },
    unknown_user: { color: 'orange', label: 'Unknown User' },
    failed:       { color: 'red',    label: 'Failed' },
    wrong_device: { color: 'red',    label: 'Wrong Device' },
    duplicate:    { color: 'gray',   label: 'Duplicate' },
};

function AttLogTab({ isMobile }) {
    const [logs,     setLogs]     = useState([]);
    const [stats,    setStats]    = useState({ total: 0, processed: 0, unknown_user: 0, failed: 0 });
    const [loading,  setLoading]  = useState(false);
    const [search,   setSearch]   = useState('');
    const [status,   setStatus]   = useState('all');
    const [pagination, setPagination] = useState({ currentPage: 1, perPage: 20, total: 0 });
    const debRef  = React.useRef(null);

    const fetchLogs = React.useCallback(async (q = search, s = status, p = pagination.currentPage, pp = pagination.perPage) => {
        setLoading(true);
        try {
            const { data } = await axios.get(route('biometric-devices.attlogs'), {
                params: {
                    search: q || undefined,
                    status: s !== 'all' ? s : undefined,
                    page: p,
                    per_page: pp,
                },
            });
            const items = data.logs?.data ?? data.logs ?? [];
            setLogs(items);
            setPagination(prev => ({ ...prev, total: data.logs?.total ?? items.length }));
            if (data.stats) setStats(data.stats);
        } catch {
            showToast.error('Failed to load att logs.');
        } finally {
            setLoading(false);
        }
    }, [search, status, pagination.currentPage, pagination.perPage]);

    React.useEffect(() => { fetchLogs(); }, [fetchLogs]);

    const triggerSearch = (val) => {
        setSearch(val);
        setPagination(prev => ({ ...prev, currentPage: 1 }));
        clearTimeout(debRef.current);
        debRef.current = setTimeout(() => fetchLogs(val, status, 1, pagination.perPage), 300);
    };

    const triggerStatus = (val) => {
        setStatus(val);
        setPagination(prev => ({ ...prev, currentPage: 1 }));
        fetchLogs(search, val, 1);
    };

    const handlePageChange = (page) => {
        setPagination(prev => ({ ...prev, currentPage: page }));
    };

    const handleRowsPerPageChange = (newPerPage) => {
        setPagination(prev => ({ ...prev, perPage: newPerPage, currentPage: 1 }));
    };

    return (
        <Box>
            {/* Stats */}
            <Flex wrap="wrap" gap="2" mb="4">
                <Badge size="2" variant="soft" color="blue"   radius="full"><Text weight="bold">{stats.total}</Text> <Text style={{ opacity: 0.7 }}>Total</Text></Badge>
                <Badge size="2" variant="soft" color="green"  radius="full"><Text weight="bold">{stats.processed}</Text> <Text style={{ opacity: 0.7 }}>Processed</Text></Badge>
                <Badge size="2" variant="soft" color="orange" radius="full"><Text weight="bold">{stats.unknown_user}</Text> <Text style={{ opacity: 0.7 }}>Unknown User</Text></Badge>
                <Badge size="2" variant="soft" color="red"    radius="full"><Text weight="bold">{stats.failed}</Text> <Text style={{ opacity: 0.7 }}>Failed/Rejected</Text></Badge>
                <Button size="1" variant="soft" color="gray" ml="auto" onClick={() => fetchLogs(search, status, pagination.currentPage)}>
                    {loading ? <Spinner size="1" /> : <ReloadIcon />} Refresh
                </Button>
            </Flex>

            {/* Filters */}
            <Flex gap="3" mb="3" wrap="wrap" align="center">
                <TextField.Root placeholder="Search PIN or name…" size="2" style={{ maxWidth: 280 }}
                    onChange={e => triggerSearch(e.target.value)}>
                    <TextField.Slot><MagnifyingGlassIcon /></TextField.Slot>
                </TextField.Root>
                <Select.Root size="2" value={status} onValueChange={triggerStatus}>
                    <Select.Trigger style={{ width: 160 }} />
                    <Select.Content>
                        <Select.Item value="all">All Status</Select.Item>
                        <Select.Item value="processed">Processed</Select.Item>
                        <Select.Item value="unknown_user">Unknown User</Select.Item>
                        <Select.Item value="failed">Failed</Select.Item>
                        <Select.Item value="wrong_device">Wrong Device</Select.Item>
                        <Select.Item value="duplicate">Duplicate</Select.Item>
                    </Select.Content>
                </Select.Root>
                {loading && <Spinner size="2" />}
                <Text size="1" color="gray" ml="auto">{pagination.total} records</Text>
            </Flex>

            {/* Table */}
            <Box style={{ overflowX: 'auto' }}>
                <Table.Root variant="surface" size="1">
                    <Table.Header>
                        <Table.Row>
                            <Table.ColumnHeaderCell>PIN</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>User</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Device</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Punch Time</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Type</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Status</Table.ColumnHeaderCell>
                            {!isMobile && <Table.ColumnHeaderCell>Reason</Table.ColumnHeaderCell>}
                        </Table.Row>
                    </Table.Header>
                    <Table.Body>
                        {logs.map(log => {
                            const meta = STATUS_META[log.punch_status] ?? { color: 'gray', label: log.punch_status };
                            const isUnknown = log.punch_status === 'unknown_user';
                            return (
                                <Table.Row key={log.id} style={isUnknown ? { background: 'var(--orange-a2)' } : undefined}>
                                    <Table.Cell>
                                        <Code size="1" variant="soft">{log.user_pin}</Code>
                                    </Table.Cell>
                                    <Table.Cell>
                                        {log.user ? (
                                            <Flex direction="column">
                                                <Text size="1" weight="medium">{log.user.name}</Text>
                                                {isUnknown && (
                                                    <Badge size="1" color="orange" variant="soft" radius="full">Auto-created</Badge>
                                                )}
                                            </Flex>
                                        ) : (
                                            <Text size="1" color="gray">—</Text>
                                        )}
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Text size="1" color="gray">{log.device?.name ?? log.serial_number}</Text>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Text size="1">{log.punch_time ? new Date(log.punch_time).toLocaleString() : '—'}</Text>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Badge size="1" variant="soft" color={log.check_type === 'out' ? 'red' : 'green'} radius="full">
                                            {log.check_type?.toUpperCase() ?? 'IN'}
                                        </Badge>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Badge size="1" variant="soft" color={meta.color} radius="full">{meta.label}</Badge>
                                    </Table.Cell>
                                    {!isMobile && (
                                        <Table.Cell>
                                            <Text size="1" color="gray" style={{ maxWidth: 260, display: 'block', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                                                {log.punch_status_reason ?? '—'}
                                            </Text>
                                        </Table.Cell>
                                    )}
                                </Table.Row>
                            );
                        })}
                        {!loading && logs.length === 0 && (
                            <Table.Row>
                                <Table.Cell colSpan={isMobile ? 6 : 7}>
                                    <Text size="2" color="gray" style={{ display: 'block', textAlign: 'center', padding: '24px 0' }}>
                                        No att logs found.
                                    </Text>
                                </Table.Cell>
                            </Table.Row>
                        )}
                    </Table.Body>
                </Table.Root>
            </Box>

            {/* Pagination */}
            {pagination.total > 0 && (
                <TablePagination
                    pagination={pagination}
                    onPageChange={handlePageChange}
                    onRowsPerPageChange={handleRowsPerPageChange}
                    loading={loading}
                />
            )}
        </Box>
    );
}

/* ── Downloads sub-tab ── */
function DownloadsTab({ isMobile, devices = [] }) {
    const [sessions, setSessions] = useState([]);
    const [loading, setLoading] = useState(false);
    const [selectedDevice, setSelectedDevice] = useState('all');
    const [pagination, setPagination] = useState({ currentPage: 1, perPage: 20, total: 0 });
    const [downloadingSessionLogs, setDownloadingSessionLogs] = useState(null);

    const downloadSessionPunches = async (session) => {
        setDownloadingSessionLogs(session.id);
        try {
            const { data } = await axios.get(route('biometric-devices.download-sessions.logs', session.id));
            const groupedRows = data.logs ?? [];
            if (groupedRows.length === 0) {
                showToast.info('No attendance logs found for this session.');
                return;
            }

            const exportData = groupedRows.map(r => ({
                'Employee ID': r.pin,
                'Employee Name': r.name,
                'Date': r.date,
                'In Time': r.inTime,
                'Out Time': r.outTime
            }));

            const worksheet = XLSX.utils.json_to_sheet(exportData);
            const workbook = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(workbook, worksheet, 'Attendance Report');
            
            const filename = `attendance_report_device_${session.device?.name.replace(/\s+/g, '_')}_session_${session.id}.xlsx`;
            XLSX.writeFile(workbook, filename);
            showToast.success('Attendance report downloaded successfully.');
        } catch (err) {
            console.error('Failed to download session logs:', err);
            showToast.error('Failed to download attendance logs.');
        } finally {
            setDownloadingSessionLogs(null);
        }
    };

    const downloadSessionPunchesPDF = async (session) => {
        setDownloadingSessionLogs(session.id);
        try {
            const { data } = await axios.get(route('biometric-devices.download-sessions.logs', session.id));
            const groupedRows = data.logs ?? [];
            if (groupedRows.length === 0) {
                showToast.info('No attendance logs found for this session.');
                return;
            }

            const doc = new jsPDF({ orientation: 'portrait' });
            
            // Title
            doc.setFontSize(16);
            doc.text('Biometric Attendance Logs Download Report', 14, 15);
            doc.setFontSize(10);
            
            // Meta Info
            doc.text(`Device: ${session.device?.name || 'Unknown'} (${session.device?.serial_number || '—'})`, 14, 22);
            doc.text(`Session ID: ${session.id} | Trigger: ${session.trigger_type.toUpperCase()}`, 14, 28);
            doc.text(`Date Range: ${session.command?.payload?.start_time && session.command?.payload?.end_time ? `${session.command.payload.start_time} to ${session.command.payload.end_time}` : 'Full Sync'}`, 14, 34);
            doc.text(`Generated on: ${new Date().toLocaleString()}`, 14, 40);

            const tableRows = groupedRows.map(r => [
                r.pin,
                r.name,
                r.date,
                r.inTime,
                r.outTime
            ]);

            doc.autoTable({
                startY: 46,
                head: [['Employee ID', 'Employee Name', 'Date', 'In Time', 'Out Time']],
                body: tableRows,
                theme: 'striped',
                headStyles: { fillColor: [43, 108, 176] }
            });

            const filename = `attendance_report_device_${session.device?.name.replace(/\s+/g, '_')}_session_${session.id}.pdf`;
            doc.save(filename);
            showToast.success('PDF report downloaded successfully.');
        } catch (err) {
            console.error('Failed to download session logs PDF:', err);
            showToast.error('Failed to download PDF logs.');
        } finally {
            setDownloadingSessionLogs(null);
        }
    };

    const fetchHistory = useCallback(async (deviceFilter = selectedDevice, page = pagination.currentPage, pp = pagination.perPage) => {
        setLoading(true);
        try {
            const { data } = await axios.get(route('biometric-devices.download-history'), {
                params: {
                    device_id: deviceFilter !== 'all' ? deviceFilter : undefined,
                    page: page,
                    per_page: pp,
                }
            });
            const items = data.sessions?.data ?? data.sessions ?? [];
            setSessions(items);
            setPagination(prev => ({
                ...prev,
                currentPage: data.sessions?.current_page || page,
                total: data.sessions?.total ?? items.length
            }));
        } catch (e) {
            showToast.error('Failed to load download history.');
        } finally {
            setLoading(false);
        }
    }, [selectedDevice, pagination.currentPage, pagination.perPage]);

    useEffect(() => {
        fetchHistory();
    }, [selectedDevice, pagination.currentPage, pagination.perPage]);

    // Auto-refresh every 10 seconds
    useEffect(() => {
        const interval = setInterval(() => {
            fetchHistory(selectedDevice, pagination.currentPage, pagination.perPage);
        }, 10000);
        return () => clearInterval(interval);
    }, [fetchHistory, selectedDevice, pagination.currentPage, pagination.perPage]);

    const handlePageChange = (page) => {
        setPagination(prev => ({ ...prev, currentPage: page }));
    };

    const handleRowsPerPageChange = (newPerPage) => {
        setPagination(prev => ({ ...prev, perPage: newPerPage, currentPage: 1 }));
    };

    const handleDeviceFilterChange = (val) => {
        setSelectedDevice(val);
        setPagination(prev => ({ ...prev, currentPage: 1 }));
    };

    // Calculate summary statistics
    const stats = useMemo(() => {
        const total = pagination.total;
        const completed = sessions.filter(s => s.status === 'completed').length;
        const inProgress = sessions.filter(s => s.status === 'in_progress' || s.status === 'pending').length;
        const failed = sessions.filter(s => s.status === 'failed').length;
        return { total, completed, inProgress, failed };
    }, [sessions, pagination.total]);

    const statusBadge = (status) => {
        const config = {
            pending: { color: 'yellow', label: 'Pending' },
            in_progress: { color: 'blue', label: 'In Progress' },
            completed: { color: 'green', label: 'Completed' },
            failed: { color: 'red', label: 'Failed' },
            partial: { color: 'orange', label: 'Partial' }
        }[status] ?? { color: 'gray', label: status };

        return <Badge color={config.color} variant="soft" size="1">{config.label.toUpperCase()}</Badge>;
    };

    const triggerBadge = (trigger) => {
        const config = {
            manual: { color: 'plum', label: 'Manual' },
            scheduled: { color: 'cyan', label: 'Scheduled' },
            reconnect: { color: 'indigo', label: 'Reconnect' },
            bulk: { color: 'violet', label: 'Bulk' }
        }[trigger] ?? { color: 'gray', label: trigger };

        return <Badge color={config.color} variant="soft" size="1">{config.label}</Badge>;
    };

    const formatDuration = (start, end) => {
        if (!start || !end) return '—';
        const ms = new Date(end) - new Date(start);
        const sec = Math.max(0, Math.floor(ms / 1000));
        if (sec < 60) return `${sec}s`;
        return `${Math.floor(sec / 60)}m ${sec % 60}s`;
    };

    return (
        <Box>
            {/* Stats Summary Cards */}
            <Grid columns={{ initial: '3', sm: '3' }} gap="3" mb="4">
                <Card variant="surface">
                    <Flex direction="column" gap="1">
                        <Text size="1" color="gray">Total Sessions</Text>
                        <Text size="4" weight="bold">{stats.total}</Text>
                    </Flex>
                </Card>
                <Card variant="surface">
                    <Flex direction="column" gap="1">
                        <Text size="1" color="gray">In Progress / Pending</Text>
                        <Text size="4" weight="bold" color="blue">{stats.inProgress}</Text>
                    </Flex>
                </Card>
                <Card variant="surface">
                    <Flex direction="column" gap="1">
                        <Text size="1" color="gray">Completed Successfully</Text>
                        <Text size="4" weight="bold" color="green">{stats.completed}</Text>
                    </Flex>
                </Card>
            </Grid>

            {/* Filter toolbar */}
            <Flex gap="3" mb="3" wrap="wrap" align="center">
                <Select.Root size="2" value={selectedDevice} onValueChange={handleDeviceFilterChange}>
                    <Select.Trigger style={{ width: 220 }} placeholder="Filter by device" />
                    <Select.Content>
                        <Select.Item value="all">All Devices</Select.Item>
                        {devices.filter(d => d.protocol === 'adms').map(d => (
                            <Select.Item key={d.id} value={String(d.id)}>{d.name}</Select.Item>
                        ))}
                    </Select.Content>
                </Select.Root>
                <Button size="1" variant="soft" color="gray" onClick={() => fetchHistory()} disabled={loading}>
                    {loading ? <Spinner size="1" /> : <ReloadIcon />} Refresh
                </Button>
                {(loading || downloadingSessionLogs) && <Spinner size="2" />}
            </Flex>

            {/* Downloads Table */}
            <Box style={{ overflowX: 'auto' }}>
                <Table.Root variant="surface" size="2">
                    <Table.Header>
                        <Table.Row>
                            <Table.ColumnHeaderCell>Device</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Date Range</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Trigger</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Status</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Records (Processed/Total)</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Duplicates</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Failed</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Duration</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Started At</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Initiated By</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Action</Table.ColumnHeaderCell>
                        </Table.Row>
                    </Table.Header>
                    <Table.Body>
                        {sessions.map(session => (
                            <Table.Row key={session.id}>
                                <Table.Cell>
                                    <Flex direction="column">
                                        <Text weight="bold" size="2">{session.device?.name ?? '—'}</Text>
                                        <Text size="1" color="gray">{session.device?.serial_number ?? '—'}</Text>
                                    </Flex>
                                </Table.Cell>
                                <Table.Cell>
                                    {session.command?.payload?.start_time && session.command?.payload?.end_time ? (
                                        <Tooltip content={`${session.command.payload.start_time} to ${session.command.payload.end_time}`}>
                                            <Text size="1" style={{ whiteSpace: 'nowrap' }}>
                                                {session.command.payload.start_time.split(' ')[0]} to {session.command.payload.end_time.split(' ')[0]}
                                            </Text>
                                        </Tooltip>
                                    ) : (
                                        <Badge color="gray" variant="soft">Full Sync</Badge>
                                    )}
                                </Table.Cell>
                                <Table.Cell>{triggerBadge(session.trigger_type)}</Table.Cell>
                                <Table.Cell>
                                    <Flex direction="column" gap="1" align="start">
                                        {statusBadge(session.status)}
                                        {session.error_message && (
                                            <Tooltip content={session.error_message}>
                                                <Text size="1" color="red" style={{ maxWidth: 180, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                                                    {session.error_message}
                                                </Text>
                                            </Tooltip>
                                        )}
                                    </Flex>
                                </Table.Cell>
                                <Table.Cell>
                                    <Text size="2">{session.processed_count} / {session.total_records}</Text>
                                </Table.Cell>
                                <Table.Cell>
                                    <Text size="2" color="gray">{session.duplicate_count}</Text>
                                </Table.Cell>
                                <Table.Cell>
                                    <Text size="2" color={session.failed_count > 0 ? 'red' : 'gray'}>{session.failed_count}</Text>
                                </Table.Cell>
                                <Table.Cell>
                                    <Text size="2">{formatDuration(session.started_at, session.completed_at)}</Text>
                                </Table.Cell>
                                <Table.Cell>
                                    <Text size="1" color="gray">
                                        {session.created_at ? new Date(session.created_at).toLocaleString() : '—'}
                                    </Text>
                                </Table.Cell>
                                <Table.Cell>
                                    <Text size="1">{session.creator?.name ?? 'System'}</Text>
                                </Table.Cell>
                                <Table.Cell>
                                    {(session.status === 'completed' || session.status === 'partial') ? (
                                        <Flex gap="2">
                                            <Tooltip content="Download Excel Logs">
                                                <IconButton
                                                    size="1"
                                                    variant="soft"
                                                    color="green"
                                                    onClick={() => downloadSessionPunches(session)}
                                                    disabled={downloadingSessionLogs === session.id}
                                                >
                                                    {downloadingSessionLogs === session.id ? <Spinner size="1" /> : <DownloadIcon />}
                                                </IconButton>
                                            </Tooltip>
                                            <Tooltip content="Download PDF Logs">
                                                <IconButton
                                                    size="1"
                                                    variant="soft"
                                                    color="red"
                                                    onClick={() => downloadSessionPunchesPDF(session)}
                                                    disabled={downloadingSessionLogs === session.id}
                                                >
                                                    {downloadingSessionLogs === session.id ? <Spinner size="1" /> : <DownloadIcon />}
                                                </IconButton>
                                            </Tooltip>
                                        </Flex>
                                    ) : (
                                        <Text size="1" color="gray">—</Text>
                                    )}
                                </Table.Cell>
                            </Table.Row>
                        ))}
                        {sessions.length === 0 && !loading && (
                            <Table.Row>
                                <Table.Cell colSpan={11}>
                                    <Text size="2" color="gray" style={{ display: 'block', textAlign: 'center', padding: '24px 0' }}>
                                        No download sessions found.
                                    </Text>
                                </Table.Cell>
                            </Table.Row>
                        )}
                    </Table.Body>
                </Table.Root>
            </Box>

            {/* Pagination */}
            {pagination.total > 0 && (
                <TablePagination
                    pagination={pagination}
                    onPageChange={handlePageChange}
                    onRowsPerPageChange={handleRowsPerPageChange}
                    loading={loading}
                />
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

    useEffect(() => { onCountChange?.(devices.length); }, [devices.length, onCountChange]);

    useEffect(() => {
        if (!isActive) return;
        onSetHeaderActions?.(null);
    }, [isActive, onSetHeaderActions]);

    const [syncing, setSyncing] = useState(false);
    const syncPool = async () => {
        setSyncing(true);
        try {
            await axios.post(route('biometric-devices.sync-pool'));
            showToast.success('All devices synced to Biometric AT.');
        } catch {
            showToast.error('Sync failed.');
        } finally { setSyncing(false); }
    };

    return (
        <Box>
            {/* Quick stats */}
            <Flex wrap="wrap" gap="2" mb="4" align="center">
                <Badge size="2" variant="soft" color="green"  radius="full">
                    <Text weight="bold">{devices.length}</Text> <Text style={{ opacity: 0.7 }}>Devices</Text>
                </Badge>
                <Badge size="2" variant="soft" color="blue"   radius="full">
                    <Text weight="bold">{devices.filter(d => d.is_online).length}</Text> <Text style={{ opacity: 0.7 }}>Online</Text>
                </Badge>
                <Badge size="2" variant="soft" color="violet" radius="full">All linked to Biometric AT</Badge>
                <Button size="1" variant="soft" color="gray" onClick={syncPool} disabled={syncing} ml="auto">
                    {syncing ? <Spinner size="1" /> : <ReloadIcon />} Sync Pool
                </Button>
            </Flex>

            <Tabs.Root value={subTab} onValueChange={setSubTab}>
                <Tabs.List mb="4">
                    <Tabs.Trigger value="devices">
                        <Flex align="center" gap="2"><DesktopIcon /> Devices</Flex>
                    </Tabs.Trigger>
                    <Tabs.Trigger value="health">
                        <Flex align="center" gap="2"><HeartIcon /> Device Health</Flex>
                    </Tabs.Trigger>
                    <Tabs.Trigger value="heartbeat">
                        <Flex align="center" gap="2"><ActivityLogIcon /> Heartbeat</Flex>
                    </Tabs.Trigger>
                    <Tabs.Trigger value="operlog">
                        <Flex align="center" gap="2"><LockClosedIcon /> OPERLOG</Flex>
                    </Tabs.Trigger>
                    <Tabs.Trigger value="attlog">
                        <Flex align="center" gap="2"><EnvelopeClosedIcon /> ATTLOG</Flex>
                    </Tabs.Trigger>
                    <Tabs.Trigger value="webhook">
                        <Flex align="center" gap="2"><Link2Icon /> Webhook Config</Flex>
                    </Tabs.Trigger>
                    <Tabs.Trigger value="downloads">
                        <Flex align="center" gap="2"><DownloadIcon /> Downloads</Flex>
                    </Tabs.Trigger>
                </Tabs.List>

                <Tabs.Content value="devices">
                    <DevicesTab devices={devices} setDevices={setDevices} employees={employees} isMobile={isMobile} />
                </Tabs.Content>
                <Tabs.Content value="health">
                    <HealthTab isMobile={isMobile} />
                </Tabs.Content>
                <Tabs.Content value="heartbeat">
                    <HeartbeatTab isMobile={isMobile} />
                </Tabs.Content>
                <Tabs.Content value="operlog">
                    <OperLogTab isMobile={isMobile} />
                </Tabs.Content>
                <Tabs.Content value="attlog">
                    <AttLogTab isMobile={isMobile} />
                </Tabs.Content>
                <Tabs.Content value="webhook">
                    <WebhookTab />
                </Tabs.Content>
                <Tabs.Content value="downloads">
                    <DownloadsTab isMobile={isMobile} devices={devices} />
                </Tabs.Content>
            </Tabs.Root>
        </Box>
    );
}
