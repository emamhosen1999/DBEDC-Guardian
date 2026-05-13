import React, { useState, useCallback, useEffect, useRef } from 'react';
import { Head } from '@inertiajs/react';
import App from '@/Layouts/App';
import { showToast } from '@/utils/toastUtils';
import { useMediaQuery } from '@/Hooks/useMediaQuery.js';
import axios from 'axios';
import {
    Box,
    Flex,
    Text,
    Button,
    TextField,
    Switch,
    Table,
    Badge,
    Tooltip,
    Code,
    Dialog,
    Select,
    Card,
    Separator,
    Tabs,
    Spinner,
    IconButton,
} from '@radix-ui/themes';
import {
    PlusIcon,
    TrashIcon,
    Pencil1Icon,
    ReloadIcon,
    CopyIcon,
    MinusIcon,
    GlobeIcon,
    DesktopIcon,
    ActivityLogIcon,
    Link2Icon,
    MagnifyingGlassIcon,
    Cross2Icon,
    LightningBoltIcon,
    CheckCircledIcon,
    ExclamationTriangleIcon,
} from '@radix-ui/react-icons';

// ─── Constants ────────────────────────────────────────────────────────────────

const EMPTY_DEVICE = {
    name: '',
    serial_number: '',
    ip_address: '',
    location: '',
    model: '',
    protocol: 'push_sdk',
    is_active: true,
};

const EMPTY_MAPPING = {
    device_user_id: '',
};

// ─── Component ────────────────────────────────────────────────────────────────

const BiometricDevices = ({ title, devices: initialDevices, employees }) => {
    const isMobile  = useMediaQuery('(max-width: 640px)');
    const isDesktop = useMediaQuery('(min-width: 1025px)');

    const [devices, setDevices] = useState(initialDevices ?? []);

    // ── Tab ──
    const [activeTab, setActiveTab] = useState('devices');

    // ── Device modal ──
    const [isDeviceOpen,  setIsDeviceOpen]  = useState(false);
    const [deviceForm,    setDeviceForm]    = useState(EMPTY_DEVICE);
    const [editingDevice, setEditingDevice] = useState(null);
    const [savingDevice,  setSavingDevice]  = useState(false);

    // ── Token modal ──
    const [isTokenOpen, setIsTokenOpen] = useState(false);
    const [tokenData,   setTokenData]   = useState({ device: null, token: '' });

    // ── User-mapping modal ──
    const [isMapOpen,     setIsMapOpen]     = useState(false);
    const [activeDevice,  setActiveDevice]  = useState(null);
    const [deviceUsers,   setDeviceUsers]   = useState([]);
    const [mappingForm,   setMappingForm]   = useState(EMPTY_MAPPING);
    const [loadingUsers,  setLoadingUsers]  = useState(false);
    const [addingUser,    setAddingUser]    = useState(false);

    // ── Link-user modal ──
    const [isLinkOpen,    setIsLinkOpen]    = useState(false);
    const [linkingEntry,  setLinkingEntry]  = useState(null);
    const [linkingForm,   setLinkingForm]   = useState({ user_id: '' });
    const [linkingUser,   setLinkingUser]   = useState(false);

    // ── Command modal ──
    const [isCommandOpen,    setIsCommandOpen]    = useState(false);
    const [commandDevice,    setCommandDevice]    = useState(null);
    const [commandType,      setCommandType]      = useState('REBOOT');
    const [commandPayload,   setCommandPayload]   = useState('');
    const [sendingCommand,   setSendingCommand]   = useState(false);
    const [commandHistory,   setCommandHistory]   = useState([]);
    const [loadingCommands,  setLoadingCommands]  = useState(false);
    const [syncStatus,       setSyncStatus]       = useState(null);
    const [pollingSync,      setPollingSync]      = useState(false);

    // ── Logs tab ──
    const [deviceLogs,   setDeviceLogs]   = useState([]);
    const [loadingLogs,  setLoadingLogs]  = useState(false);
    const [logSearch,    setLogSearch]    = useState('');

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    const webhookUrl = `${window.location.origin}/api/biometric/webhook`;
    const admsUrl    = `${window.location.origin}/api/iclock/cdata`;

    const copyToClipboard = (text) => {
        navigator.clipboard.writeText(text).then(() => showToast.success('Copied to clipboard.'));
    };

    // ─────────────────────────────────────────────────────────────────────────
    // Logs
    // ─────────────────────────────────────────────────────────────────────────

    const loadLogs = useCallback(async (deviceId = null) => {
        setLoadingLogs(true);
        try {
            const url = deviceId
                ? route('api.biometric-devices.logs.index', deviceId)
                : route('api.biometric-devices.logs.all');
            const { data } = await axios.get(url);
            setDeviceLogs(data.logs ?? []);
        } catch {
            showToast.error('Failed to load logs.');
        } finally {
            setLoadingLogs(false);
        }
    }, []);

    // Load logs when switching to the logs tab
    useEffect(() => {
        if (activeTab === 'logs') loadLogs();
    }, [activeTab, loadLogs]);

    const filteredLogs = logSearch.trim()
        ? deviceLogs.filter(l =>
            l.message?.toLowerCase().includes(logSearch.toLowerCase()) ||
            l.level?.toLowerCase().includes(logSearch.toLowerCase())
          )
        : deviceLogs;

    // ─────────────────────────────────────────────────────────────────────────
    // Device CRUD
    // ─────────────────────────────────────────────────────────────────────────

    const openAddDevice = () => {
        setEditingDevice(null);
        setDeviceForm(EMPTY_DEVICE);
        setIsDeviceOpen(true);
    };

    const openEditDevice = (device) => {
        setEditingDevice(device);
        setDeviceForm({
            name:          device.name,
            serial_number: device.serial_number,
            ip_address:    device.ip_address  ?? '',
            location:      device.location    ?? '',
            model:         device.model       ?? '',
            protocol:      device.protocol    ?? 'push_sdk',
            is_active:     device.is_active,
        });
        setIsDeviceOpen(true);
    };

    const saveDevice = async () => {
        if (!deviceForm.name.trim() || !deviceForm.serial_number.trim()) {
            showToast.error('Name and serial number are required.');
            return;
        }
        setSavingDevice(true);
        try {
            if (editingDevice) {
                const { data } = await axios.put(
                    route('biometric-devices.update', editingDevice.id),
                    deviceForm,
                );
                setDevices(prev => prev.map(d => d.id === editingDevice.id ? data.device : d));
                showToast.success('Device updated.');
            } else {
                const { data } = await axios.post(route('biometric-devices.store'), deviceForm);
                setDevices(prev => [...prev, data.device]);
                showToast.success('Device registered.');
            }
            setIsDeviceOpen(false);
        } catch (err) {
            const msg = err.response?.data?.errors
                ? Object.values(err.response.data.errors).flat().join(' ')
                : err.response?.data?.message ?? 'Failed to save device.';
            showToast.error(msg);
        } finally {
            setSavingDevice(false);
        }
    };

    const deleteDevice = async (device) => {
        if (!confirm(`Delete device "${device.name}"? This will also remove all user mappings.`)) return;
        try {
            await axios.delete(route('biometric-devices.destroy', device.id));
            setDevices(prev => prev.filter(d => d.id !== device.id));
            showToast.success('Device deleted.');
        } catch {
            showToast.error('Failed to delete device.');
        }
    };

    // ─────────────────────────────────────────────────────────────────────────
    // Token
    // ─────────────────────────────────────────────────────────────────────────

    const regenerateToken = async (device) => {
        if (!confirm('Regenerate auth token? The device will need to be reconfigured with the new token.')) return;
        try {
            const { data } = await axios.post(route('biometric-devices.regenerate-token', device.id));
            setTokenData({ device, token: data.auth_token });
            setIsTokenOpen(true);
            showToast.success('Token regenerated.');
        } catch {
            showToast.error('Failed to regenerate token.');
        }
    };

    // ─────────────────────────────────────────────────────────────────────────
    // Command management
    // ─────────────────────────────────────────────────────────────────────────

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

    useEffect(() => {
        if (!isCommandOpen) {
            setPollingSync(false);
            setSyncStatus(null);
        }
    }, [isCommandOpen]);

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

    // ─────────────────────────────────────────────────────────────────────────
    // User-mapping
    // ─────────────────────────────────────────────────────────────────────────

    const openMappings = async (device) => {
        setActiveDevice(device);
        setMappingForm(EMPTY_MAPPING);
        setLoadingUsers(true);
        setIsMapOpen(true);
        try {
            const { data } = await axios.get(route('biometric-devices.users', device.id));
            setDeviceUsers(data.entries ?? []);
        } catch {
            showToast.error('Failed to load device entries.');
        } finally {
            setLoadingUsers(false);
        }
    };

    const addDeviceEntry = async () => {
        if (!mappingForm.device_user_id.trim()) {
            showToast.error('Enter the device enrollment ID.');
            return;
        }
        setAddingUser(true);
        try {
            await axios.post(
                route('biometric-devices.entry.add', activeDevice.id),
                { device_user_id: mappingForm.device_user_id },
            );
            setMappingForm(EMPTY_MAPPING);
            const { data } = await axios.get(route('biometric-devices.users', activeDevice.id));
            setDeviceUsers(data.entries ?? []);
            showToast.success('Device entry added.');
        } catch (err) {
            showToast.error(err.response?.data?.message ?? 'Failed to add entry.');
        } finally {
            setAddingUser(false);
        }
    };

    const openLinkModal = (entry) => {
        setLinkingEntry(entry);
        setLinkingForm({ user_id: '' });
        setIsLinkOpen(true);
    };

    const linkUser = async () => {
        if (!linkingForm.user_id) {
            showToast.error('Select an employee.');
            return;
        }
        setLinkingUser(true);
        try {
            await axios.post(route('biometric-devices.users.link', activeDevice.id), {
                device_user_id: linkingEntry.device_user_id,
                user_id:        linkingForm.user_id,
            });
            setIsLinkOpen(false);
            const { data } = await axios.get(route('biometric-devices.users', activeDevice.id));
            setDeviceUsers(data.entries ?? []);
            setDevices(prev => prev.map(d =>
                d.id === activeDevice.id ? { ...d, users_count: (d.users_count ?? 0) + 1 } : d,
            ));
            showToast.success('User linked.');
        } catch (err) {
            showToast.error(err.response?.data?.message ?? 'Failed to link user.');
        } finally {
            setLinkingUser(false);
        }
    };

    const unlinkUser = async (entry) => {
        if (!confirm(`Unlink ${entry.name} from this device?`)) return;
        try {
            await axios.post(
                route('biometric-devices.users.unlink', { id: activeDevice.id, userId: entry.user_id }),
            );
            const { data } = await axios.get(route('biometric-devices.users', activeDevice.id));
            setDeviceUsers(data.entries ?? []);
            setDevices(prev => prev.map(d =>
                d.id === activeDevice.id ? { ...d, users_count: Math.max(0, (d.users_count ?? 1) - 1) } : d,
            ));
            showToast.success('User unlinked.');
        } catch {
            showToast.error('Failed to unlink user.');
        }
    };

    const deleteEntry = async (entry) => {
        if (!confirm(`Delete device entry ${entry.device_user_id}?`)) return;
        try {
            await axios.delete(
                route('biometric-devices.users.remove', { id: activeDevice.id, userId: entry.user_id }),
            );
            setDeviceUsers(prev => prev.filter(e => e.id !== entry.id));
            if (entry.user_id) {
                setDevices(prev => prev.map(d =>
                    d.id === activeDevice.id
                        ? { ...d, users_count: Math.max(0, (d.users_count ?? 1) - 1) }
                        : d,
                ));
            }
            showToast.success('Entry deleted.');
        } catch {
            showToast.error('Failed to delete entry.');
        }
    };

    // ─────────────────────────────────────────────────────────────────────────
    // Sub-components (inline helpers)
    // ─────────────────────────────────────────────────────────────────────────

    const LogLevelBadge = ({ level }) => {
        const colorMap = { error: 'red', warning: 'amber', info: 'blue' };
        return (
            <Badge color={colorMap[level] ?? 'gray'} variant="soft" size="1">
                {(level ?? 'info').toUpperCase()}
            </Badge>
        );
    };

    const CommandStatusBadge = ({ status }) => {
        const colorMap = { executed: 'green', failed: 'red', sent: 'amber', pending: 'gray' };
        return (
            <Badge color={colorMap[status] ?? 'gray'} variant="soft" size="1">
                {status}
            </Badge>
        );
    };

    // ─────────────────────────────────────────────────────────────────────────
    // Render
    // ─────────────────────────────────────────────────────────────────────────

    return (
        <App title={title ?? 'Biometric Devices'}>
            <Head title={title ?? 'Biometric Devices'} />

            <Flex justify="center" p="4">
                <Box style={{ width: '100%', maxWidth: 2000 }}>
                    <Card>

                        {/* ── Page Header ─────────────────────────────────── */}
                        <Box mb="4">
                            <Flex
                                direction={{ initial: 'column', md: 'row' }}
                                align={{ initial: 'start', md: 'center' }}
                                justify="between"
                                gap="4"
                            >
                                {/* Icon + title */}
                                <Flex align="center" gap="4">
                                    <Box
                                        p={{ initial: '2', md: '3' }}
                                        style={{
                                            backgroundColor: 'var(--accent-a3)',
                                            borderRadius: 'var(--radius-2)',
                                        }}
                                    >
                                        <DesktopIcon
                                            width={isDesktop ? 32 : 24}
                                            height={isDesktop ? 32 : 24}
                                            color="var(--accent-9)"
                                        />
                                    </Box>
                                    <Box>
                                        <Text
                                            size={{ initial: '4', sm: '5', md: '6' }}
                                            weight="bold"
                                            as="div"
                                        >
                                            Biometric Devices
                                        </Text>
                                        <Text
                                            size={{ initial: '1', md: '2' }}
                                            color="gray"
                                            as="div"
                                        >
                                            Manage ZKTeco devices and employee enrollment mappings
                                        </Text>
                                    </Box>
                                </Flex>

                                {/* Actions */}
                                <Flex align="center" gap="3" wrap="wrap">
                                    <Button size={{ initial: '1', md: '2' }} onClick={openAddDevice}>
                                        <PlusIcon width={16} height={16} />
                                        {!isMobile && 'Add Device'}
                                    </Button>
                                </Flex>
                            </Flex>
                        </Box>

                        <Separator size="4" mb="4" />

                        {/* ── Tabs ────────────────────────────────────────── */}
                        <Tabs.Root value={activeTab} onValueChange={setActiveTab}>
                            <Tabs.List>
                                <Tabs.Trigger value="devices">
                                    <DesktopIcon style={{ width: 16, height: 16, marginRight: 6 }} />
                                    Devices
                                </Tabs.Trigger>
                                <Tabs.Trigger value="logs">
                                    <ActivityLogIcon style={{ width: 16, height: 16, marginRight: 6 }} />
                                    ADMS Logs
                                </Tabs.Trigger>
                                <Tabs.Trigger value="webhook">
                                    <Link2Icon style={{ width: 16, height: 16, marginRight: 6 }} />
                                    Webhook Config
                                </Tabs.Trigger>
                            </Tabs.List>

                            {/* ── Devices Tab ─────────────────────────────── */}
                            <Tabs.Content value="devices">
                                <Box mt="4">
                                    {devices.length === 0 ? (
                                        <Card variant="surface">
                                            <Flex
                                                direction="column"
                                                align="center"
                                                justify="center"
                                                gap="3"
                                                py="9"
                                            >
                                                <DesktopIcon
                                                    width={48}
                                                    height={48}
                                                    color="var(--gray-8)"
                                                />
                                                <Text size="4" weight="medium" color="gray">
                                                    No devices registered yet
                                                </Text>
                                                <Text size="2" color="gray">
                                                    Click "Add Device" to register your first ZKTeco device.
                                                </Text>
                                                <Button mt="2" onClick={openAddDevice}>
                                                    <PlusIcon width={16} height={16} />
                                                    Add Device
                                                </Button>
                                            </Flex>
                                        </Card>
                                    ) : (
                                        <Table.Root variant="surface">
                                            <Table.Header>
                                                <Table.Row>
                                                    <Table.ColumnHeaderCell>DEVICE</Table.ColumnHeaderCell>
                                                    <Table.ColumnHeaderCell>SERIAL</Table.ColumnHeaderCell>
                                                    {!isMobile && (
                                                        <Table.ColumnHeaderCell>IP / LOCATION</Table.ColumnHeaderCell>
                                                    )}
                                                    <Table.ColumnHeaderCell>PROTOCOL</Table.ColumnHeaderCell>
                                                    <Table.ColumnHeaderCell>USERS</Table.ColumnHeaderCell>
                                                    {!isMobile && (
                                                        <Table.ColumnHeaderCell>LAST PING</Table.ColumnHeaderCell>
                                                    )}
                                                    <Table.ColumnHeaderCell>STATUS</Table.ColumnHeaderCell>
                                                    <Table.ColumnHeaderCell>ACTIONS</Table.ColumnHeaderCell>
                                                </Table.Row>
                                            </Table.Header>
                                            <Table.Body>
                                                {devices.map(device => (
                                                    <Table.Row key={device.id}>
                                                        <Table.Cell>
                                                            <Box>
                                                                <Text weight="medium" size="2" as="div">
                                                                    {device.name}
                                                                </Text>
                                                                {device.model && (
                                                                    <Text size="1" color="gray" as="div">
                                                                        {device.model}
                                                                    </Text>
                                                                )}
                                                            </Box>
                                                        </Table.Cell>

                                                        <Table.Cell>
                                                            <Code size="1">{device.serial_number}</Code>
                                                        </Table.Cell>

                                                        {!isMobile && (
                                                            <Table.Cell>
                                                                <Box>
                                                                    {device.ip_address && (
                                                                        <Text size="1" as="div">
                                                                            {device.ip_address}
                                                                        </Text>
                                                                    )}
                                                                    {device.location && (
                                                                        <Text size="1" color="gray" as="div">
                                                                            {device.location}
                                                                        </Text>
                                                                    )}
                                                                    {!device.ip_address && !device.location && (
                                                                        <Text size="1" color="gray">—</Text>
                                                                    )}
                                                                </Box>
                                                            </Table.Cell>
                                                        )}

                                                        <Table.Cell>
                                                            <Badge
                                                                color={device.protocol === 'adms' ? 'green' : 'blue'}
                                                                variant="soft"
                                                                size="1"
                                                            >
                                                                {device.protocol === 'adms' ? 'ADMS' : 'Push SDK'}
                                                            </Badge>
                                                        </Table.Cell>

                                                        <Table.Cell>
                                                            <Badge color="accent" variant="soft" size="1">
                                                                {device.users_count ?? 0} enrolled
                                                            </Badge>
                                                        </Table.Cell>

                                                        {!isMobile && (
                                                            <Table.Cell>
                                                                <Text size="1" color="gray">
                                                                    {device.last_heartbeat_at
                                                                        ? new Date(device.last_heartbeat_at).toLocaleString()
                                                                        : '—'}
                                                                </Text>
                                                            </Table.Cell>
                                                        )}

                                                        <Table.Cell>
                                                            <Badge
                                                                color={device.is_active ? 'green' : 'gray'}
                                                                variant="soft"
                                                                size="1"
                                                            >
                                                                {device.is_active ? 'Active' : 'Inactive'}
                                                            </Badge>
                                                        </Table.Cell>

                                                        <Table.Cell>
                                                            <Flex gap="1">
                                                                <Tooltip content="Manage enrollments">
                                                                    <IconButton
                                                                        variant="ghost"
                                                                        color="accent"
                                                                        size="1"
                                                                        onClick={() => openMappings(device)}
                                                                        aria-label="Manage enrollments"
                                                                    >
                                                                        <PlusIcon width={15} height={15} />
                                                                    </IconButton>
                                                                </Tooltip>

                                                                <Tooltip content="Edit device">
                                                                    <IconButton
                                                                        variant="ghost"
                                                                        size="1"
                                                                        onClick={() => openEditDevice(device)}
                                                                        aria-label="Edit device"
                                                                    >
                                                                        <Pencil1Icon width={15} height={15} />
                                                                    </IconButton>
                                                                </Tooltip>

                                                                {device.protocol === 'adms' && (
                                                                    <Tooltip content="Device commands">
                                                                        <IconButton
                                                                            variant="ghost"
                                                                            color="blue"
                                                                            size="1"
                                                                            onClick={() => openCommandModal(device)}
                                                                            aria-label="Device commands"
                                                                        >
                                                                            <LightningBoltIcon width={15} height={15} />
                                                                        </IconButton>
                                                                    </Tooltip>
                                                                )}

                                                                {device.protocol !== 'adms' && (
                                                                    <Tooltip content="Regenerate auth token">
                                                                        <IconButton
                                                                            variant="ghost"
                                                                            color="amber"
                                                                            size="1"
                                                                            onClick={() => regenerateToken(device)}
                                                                            aria-label="Regenerate token"
                                                                        >
                                                                            <ReloadIcon width={15} height={15} />
                                                                        </IconButton>
                                                                    </Tooltip>
                                                                )}

                                                                <Tooltip content="Delete device">
                                                                    <IconButton
                                                                        variant="ghost"
                                                                        color="red"
                                                                        size="1"
                                                                        onClick={() => deleteDevice(device)}
                                                                        aria-label="Delete device"
                                                                    >
                                                                        <TrashIcon width={15} height={15} />
                                                                    </IconButton>
                                                                </Tooltip>
                                                            </Flex>
                                                        </Table.Cell>
                                                    </Table.Row>
                                                ))}
                                            </Table.Body>
                                        </Table.Root>
                                    )}
                                </Box>
                            </Tabs.Content>

                            {/* ── Logs Tab ────────────────────────────────── */}
                            <Tabs.Content value="logs">
                                <Box mt="4">
                                    {/* Toolbar */}
                                    <Flex
                                        direction={{ initial: 'column', sm: 'row' }}
                                        align={{ initial: 'stretch', sm: 'center' }}
                                        justify="between"
                                        gap="3"
                                        mb="4"
                                    >
                                        <TextField.Root
                                            placeholder="Search logs by level or message…"
                                            value={logSearch}
                                            onChange={e => setLogSearch(e.target.value)}
                                            size="2"
                                            style={{ maxWidth: 360 }}
                                        >
                                            <TextField.Slot>
                                                <MagnifyingGlassIcon width={16} height={16} />
                                            </TextField.Slot>
                                            {logSearch && (
                                                <TextField.Slot side="right">
                                                    <IconButton
                                                        size="1"
                                                        variant="ghost"
                                                        color="gray"
                                                        onClick={() => setLogSearch('')}
                                                        aria-label="Clear search"
                                                    >
                                                        <Cross2Icon width={14} height={14} />
                                                    </IconButton>
                                                </TextField.Slot>
                                            )}
                                        </TextField.Root>

                                        <Button
                                            size="2"
                                            variant="soft"
                                            color="indigo"
                                            onClick={() => loadLogs()}
                                            disabled={loadingLogs}
                                        >
                                            {loadingLogs
                                                ? <Spinner size="2" />
                                                : <ReloadIcon width={16} height={16} />
                                            }
                                            {!isMobile && ' Refresh'}
                                        </Button>
                                    </Flex>

                                    {loadingLogs ? (
                                        <Flex justify="center" py="9">
                                            <Spinner size="3" />
                                        </Flex>
                                    ) : filteredLogs.length === 0 ? (
                                        <Card variant="surface">
                                            <Flex direction="column" align="center" gap="3" py="9">
                                                <ActivityLogIcon width={48} height={48} color="var(--gray-8)" />
                                                <Text size="3" color="gray">
                                                    {logSearch ? 'No logs match your search.' : 'No ADMS logs found.'}
                                                </Text>
                                            </Flex>
                                        </Card>
                                    ) : (
                                        <Table.Root variant="surface">
                                            <Table.Header>
                                                <Table.Row>
                                                    <Table.ColumnHeaderCell>LEVEL</Table.ColumnHeaderCell>
                                                    <Table.ColumnHeaderCell>MESSAGE</Table.ColumnHeaderCell>
                                                    <Table.ColumnHeaderCell>TIME</Table.ColumnHeaderCell>
                                                </Table.Row>
                                            </Table.Header>
                                            <Table.Body>
                                                {filteredLogs.map(log => (
                                                    <Table.Row key={log.id}>
                                                        <Table.Cell>
                                                            <LogLevelBadge level={log.level} />
                                                        </Table.Cell>
                                                        <Table.Cell>
                                                            <Text size="1" as="div">{log.message}</Text>
                                                            {log.context && Object.keys(log.context).length > 0 && (
                                                                <Code
                                                                    size="1"
                                                                    style={{
                                                                        display: 'block',
                                                                        marginTop: 4,
                                                                        whiteSpace: 'pre-wrap',
                                                                        wordBreak: 'break-word',
                                                                    }}
                                                                >
                                                                    {JSON.stringify(log.context, null, 2)}
                                                                </Code>
                                                            )}
                                                        </Table.Cell>
                                                        <Table.Cell>
                                                            <Text size="1" color="gray">
                                                                {new Date(log.created_at).toLocaleString()}
                                                            </Text>
                                                        </Table.Cell>
                                                    </Table.Row>
                                                ))}
                                            </Table.Body>
                                        </Table.Root>
                                    )}
                                </Box>
                            </Tabs.Content>

                            {/* ── Webhook Config Tab ──────────────────────── */}
                            <Tabs.Content value="webhook">
                                <Box mt="4">
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
                                                            onClick={() => copyToClipboard(webhookUrl)}
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
                                                        For devices with ADMS protocol (MB460, MB360)
                                                    </Text>
                                                </Flex>
                                                <Text size="2" color="gray">
                                                    Configure the device to push to server using ZKTeco ADMS protocol.
                                                    No auth token required — devices are identified by serial number.
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
                                                            GET / POST {admsUrl}
                                                        </Code>
                                                    </Box>
                                                    <Tooltip content="Copy URL">
                                                        <IconButton
                                                            variant="soft"
                                                            size="2"
                                                            onClick={() => copyToClipboard(admsUrl)}
                                                            aria-label="Copy ADMS URL"
                                                        >
                                                            <CopyIcon width={16} height={16} />
                                                        </IconButton>
                                                    </Tooltip>
                                                </Flex>
                                            </Flex>
                                        </Card>

                                        {/* Integration checklist */}
                                        <Card variant="surface">
                                            <Flex direction="column" gap="3">
                                                <Text size="2" weight="medium">Integration Checklist</Text>
                                                {[
                                                    'Register the device in the Devices tab with the correct serial number.',
                                                    'For Push SDK: copy the generated auth token and set X-Device-Token on the device.',
                                                    'For ADMS: point the device\'s server address to the ADMS URL above.',
                                                    'Enroll fingerprints on the device, then link device IDs to employees in the Devices tab.',
                                                    'Verify attendance events are arriving via the ADMS Logs tab.',
                                                ].map((step, i) => (
                                                    <Flex key={i} align="start" gap="2">
                                                        <CheckCircledIcon
                                                            width={16}
                                                            height={16}
                                                            color="var(--green-9)"
                                                            style={{ flexShrink: 0, marginTop: 2 }}
                                                        />
                                                        <Text size="2" color="gray">{step}</Text>
                                                    </Flex>
                                                ))}
                                            </Flex>
                                        </Card>
                                    </Flex>
                                </Box>
                            </Tabs.Content>
                        </Tabs.Root>
                    </Card>
                </Box>
            </Flex>

            {/* ══════════════════════════════════════════════════════════════ */}
            {/* Modals                                                         */}
            {/* ══════════════════════════════════════════════════════════════ */}

            {/* ── Add / Edit Device ── */}
            <Dialog.Root open={isDeviceOpen} onOpenChange={setIsDeviceOpen}>
                <Dialog.Content style={{ maxWidth: 460 }}>
                    <Dialog.Title>
                        {editingDevice ? 'Edit Device' : 'Register New Device'}
                    </Dialog.Title>
                    <Dialog.Description size="2" color="gray">
                        {editingDevice
                            ? 'Update device information.'
                            : 'Register a new ZKTeco biometric device.'}
                    </Dialog.Description>

                    <Flex direction="column" gap="3" mt="4">
                        {/* Name */}
                        <Box>
                            <Text size="2" weight="medium" as="div" mb="1">Device Name *</Text>
                            <TextField.Root
                                placeholder="e.g., Main Entrance"
                                value={deviceForm.name}
                                onChange={e => setDeviceForm(f => ({ ...f, name: e.target.value }))}
                                size="2"
                            />
                        </Box>

                        {/* Serial */}
                        <Box>
                            <Text size="2" weight="medium" as="div" mb="1">Serial Number *</Text>
                            <TextField.Root
                                placeholder="e.g., ABJM12345678"
                                value={deviceForm.serial_number}
                                onChange={e => setDeviceForm(f => ({ ...f, serial_number: e.target.value }))}
                                disabled={!!editingDevice}
                                size="2"
                            />
                            {editingDevice && (
                                <Text size="1" color="gray" mt="1" as="div">
                                    Serial number cannot be changed after registration.
                                </Text>
                            )}
                        </Box>

                        {/* IP + Location side-by-side on desktop */}
                        <Flex direction={{ initial: 'column', sm: 'row' }} gap="3">
                            <Box flexGrow="1">
                                <Text size="2" weight="medium" as="div" mb="1">IP Address</Text>
                                <TextField.Root
                                    placeholder="e.g., 192.168.1.100"
                                    value={deviceForm.ip_address}
                                    onChange={e => setDeviceForm(f => ({ ...f, ip_address: e.target.value }))}
                                    size="2"
                                />
                            </Box>
                            <Box flexGrow="1">
                                <Text size="2" weight="medium" as="div" mb="1">Location</Text>
                                <TextField.Root
                                    placeholder="e.g., 3rd Floor, Block B"
                                    value={deviceForm.location}
                                    onChange={e => setDeviceForm(f => ({ ...f, location: e.target.value }))}
                                    size="2"
                                />
                            </Box>
                        </Flex>

                        {/* Model */}
                        <Box>
                            <Text size="2" weight="medium" as="div" mb="1">Model</Text>
                            <TextField.Root
                                placeholder="e.g., ZKTeco K40"
                                value={deviceForm.model}
                                onChange={e => setDeviceForm(f => ({ ...f, model: e.target.value }))}
                                size="2"
                            />
                        </Box>

                        {/* Protocol */}
                        <Box>
                            <Text size="2" weight="medium" as="div" mb="1">Communication Protocol</Text>
                            <Select.Root
                                value={deviceForm.protocol}
                                onValueChange={v => setDeviceForm(f => ({ ...f, protocol: v }))}
                            >
                                <Select.Trigger
                                    style={{ width: '100%' }}
                                    placeholder="Select protocol"
                                />
                                <Select.Content>
                                    <Select.Item value="push_sdk">
                                        Push SDK — K40, K60, iFace series
                                    </Select.Item>
                                    <Select.Item value="adms">
                                        ADMS — MB460, MB360
                                    </Select.Item>
                                </Select.Content>
                            </Select.Root>
                            <Text size="1" color="gray" mt="1" as="div">
                                {deviceForm.protocol === 'adms'
                                    ? 'ADMS devices use ZKTeco cloud protocol — no auth token required.'
                                    : 'Push SDK devices authenticate via X-Device-Token header.'}
                            </Text>
                        </Box>

                        {/* Active toggle */}
                        <Flex justify="between" align="center">
                            <Box>
                                <Text size="2" weight="medium" as="div">Active</Text>
                                <Text size="1" color="gray" as="div">
                                    Inactive devices will not process attendance events.
                                </Text>
                            </Box>
                            <Switch
                                size="2"
                                checked={deviceForm.is_active}
                                onCheckedChange={v => setDeviceForm(f => ({ ...f, is_active: v }))}
                            />
                        </Flex>
                    </Flex>

                    <Flex gap="2" justify="end" mt="5">
                        <Dialog.Close>
                            <Button variant="soft" color="gray">Cancel</Button>
                        </Dialog.Close>
                        <Button onClick={saveDevice} disabled={savingDevice}>
                            {savingDevice
                                ? <><Spinner size="1" /> Saving…</>
                                : editingDevice ? 'Update Device' : 'Register Device'}
                        </Button>
                    </Flex>
                </Dialog.Content>
            </Dialog.Root>

            {/* ── Token Display ── */}
            <Dialog.Root open={isTokenOpen} onOpenChange={setIsTokenOpen}>
                <Dialog.Content style={{ maxWidth: 460 }}>
                    <Dialog.Title>New Auth Token — {tokenData.device?.name}</Dialog.Title>
                    <Dialog.Description size="2" color="gray">
                        Save this token now. It will not be shown again.
                    </Dialog.Description>

                    <Flex direction="column" gap="3" mt="4">
                        <Card variant="surface" style={{ backgroundColor: 'var(--amber-a3)' }}>
                            <Flex align="start" gap="2">
                                <ExclamationTriangleIcon
                                    width={16}
                                    height={16}
                                    color="var(--amber-11)"
                                    style={{ flexShrink: 0, marginTop: 2 }}
                                />
                                <Text size="2" color="amber">
                                    Configure your ZKTeco device to send this value as the{' '}
                                    <Code>X-Device-Token</Code> header on every push request.
                                </Text>
                            </Flex>
                        </Card>

                        <Flex align="center" gap="2">
                            <Code
                                size="2"
                                style={{
                                    flex: 1,
                                    background: 'var(--gray-a4)',
                                    borderRadius: 'var(--radius-2)',
                                    padding: '8px 12px',
                                    wordBreak: 'break-all',
                                    display: 'block',
                                }}
                            >
                                {tokenData.token}
                            </Code>
                            <IconButton
                                variant="soft"
                                size="2"
                                onClick={() => copyToClipboard(tokenData.token)}
                                aria-label="Copy token"
                            >
                                <CopyIcon width={16} height={16} />
                            </IconButton>
                        </Flex>

                        <Text size="1" color="gray">
                            Webhook endpoint:{' '}
                            <Code size="1">{webhookUrl}</Code>
                        </Text>
                    </Flex>

                    <Flex justify="end" mt="5">
                        <Dialog.Close>
                            <Button>
                                <CheckCircledIcon width={16} height={16} />
                                Done
                            </Button>
                        </Dialog.Close>
                    </Flex>
                </Dialog.Content>
            </Dialog.Root>

            {/* ── User Mapping ── */}
            <Dialog.Root open={isMapOpen} onOpenChange={setIsMapOpen}>
                <Dialog.Content style={{ maxWidth: 620 }}>
                    <Dialog.Title>Device Enrollments — {activeDevice?.name}</Dialog.Title>
                    <Dialog.Description size="2" color="gray">
                        Add device user IDs and link them to system employees.
                    </Dialog.Description>

                    <Flex direction="column" gap="4" mt="4">
                        {/* Add Entry */}
                        <Card variant="surface">
                            <Flex direction="column" gap="2">
                                <Text size="2" weight="medium">Add Device Entry</Text>
                                <Text size="1" color="gray">
                                    Register a device user ID. You can link it to an employee after adding.
                                </Text>
                                <Flex gap="3" align="end">
                                    <Box flexGrow="1">
                                        <Text size="2" as="div" mb="1">Device Enrollment ID</Text>
                                        <TextField.Root
                                            placeholder="e.g., 1 or 00042"
                                            value={mappingForm.device_user_id}
                                            onChange={e =>
                                                setMappingForm(f => ({ ...f, device_user_id: e.target.value }))
                                            }
                                            size="2"
                                        />
                                    </Box>
                                    <Button onClick={addDeviceEntry} disabled={addingUser} size="2">
                                        {addingUser
                                            ? <Spinner size="1" />
                                            : <PlusIcon width={16} height={16} />}
                                        Add Entry
                                    </Button>
                                </Flex>
                            </Flex>
                        </Card>

                        {/* Entries list */}
                        <Box>
                            <Text size="2" weight="medium" as="div" mb="2">All Device Entries</Text>
                            {loadingUsers ? (
                                <Flex justify="center" py="8">
                                    <Spinner size="3" />
                                </Flex>
                            ) : deviceUsers.length === 0 ? (
                                <Card variant="surface">
                                    <Flex justify="center" py="6">
                                        <Text size="2" color="gray">No entries on this device yet.</Text>
                                    </Flex>
                                </Card>
                            ) : (
                                <Table.Root variant="surface">
                                    <Table.Header>
                                        <Table.Row>
                                            <Table.ColumnHeaderCell>DEVICE ID</Table.ColumnHeaderCell>
                                            <Table.ColumnHeaderCell>LINKED USER</Table.ColumnHeaderCell>
                                            <Table.ColumnHeaderCell>STATUS</Table.ColumnHeaderCell>
                                            <Table.ColumnHeaderCell>ACTIONS</Table.ColumnHeaderCell>
                                        </Table.Row>
                                    </Table.Header>
                                    <Table.Body>
                                        {deviceUsers.map(entry => (
                                            <Table.Row key={entry.id}>
                                                <Table.Cell>
                                                    <Code size="1">{entry.device_user_id}</Code>
                                                </Table.Cell>
                                                <Table.Cell>
                                                    {entry.user_id ? (
                                                        <Box>
                                                            <Text size="2" as="div">{entry.name}</Text>
                                                            {entry.employee_id && (
                                                                <Text size="1" color="gray" as="div">
                                                                    #{entry.employee_id}
                                                                </Text>
                                                            )}
                                                        </Box>
                                                    ) : (
                                                        <Badge color="amber" variant="soft" size="1">
                                                            Unlinked
                                                        </Badge>
                                                    )}
                                                </Table.Cell>
                                                <Table.Cell>
                                                    <Badge
                                                        color={entry.is_active ? 'green' : 'gray'}
                                                        variant="soft"
                                                        size="1"
                                                    >
                                                        {entry.is_active ? 'Active' : 'Inactive'}
                                                    </Badge>
                                                </Table.Cell>
                                                <Table.Cell>
                                                    <Flex gap="1">
                                                        {entry.user_id ? (
                                                            <Tooltip content="Unlink from user">
                                                                <IconButton
                                                                    variant="ghost"
                                                                    color="amber"
                                                                    size="1"
                                                                    onClick={() => unlinkUser(entry)}
                                                                    aria-label="Unlink user"
                                                                >
                                                                    <MinusIcon width={15} height={15} />
                                                                </IconButton>
                                                            </Tooltip>
                                                        ) : (
                                                            <Tooltip content="Link to employee">
                                                                <IconButton
                                                                    variant="ghost"
                                                                    color="accent"
                                                                    size="1"
                                                                    onClick={() => openLinkModal(entry)}
                                                                    aria-label="Link employee"
                                                                >
                                                                    <Pencil1Icon width={15} height={15} />
                                                                </IconButton>
                                                            </Tooltip>
                                                        )}
                                                        <Tooltip content="Delete entry">
                                                            <IconButton
                                                                variant="ghost"
                                                                color="red"
                                                                size="1"
                                                                onClick={() => deleteEntry(entry)}
                                                                aria-label="Delete entry"
                                                            >
                                                                <TrashIcon width={15} height={15} />
                                                            </IconButton>
                                                        </Tooltip>
                                                    </Flex>
                                                </Table.Cell>
                                            </Table.Row>
                                        ))}
                                    </Table.Body>
                                </Table.Root>
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

            {/* ── Link User ── */}
            <Dialog.Root open={isLinkOpen} onOpenChange={setIsLinkOpen}>
                <Dialog.Content style={{ maxWidth: 440 }}>
                    <Dialog.Title>Link Device User to Employee</Dialog.Title>
                    <Dialog.Description size="2" color="gray">
                        Assign device enrollment ID{' '}
                        <Code size="1">{linkingEntry?.device_user_id}</Code>{' '}
                        to a system employee.
                    </Dialog.Description>

                    <Box mt="4">
                        <Text size="2" weight="medium" as="div" mb="1">Employee</Text>
                        <Select.Root
                            value={linkingForm.user_id ? String(linkingForm.user_id) : ''}
                            onValueChange={v => setLinkingForm(f => ({ ...f, user_id: v }))}
                        >
                            <Select.Trigger
                                style={{ width: '100%' }}
                                placeholder="Choose an employee…"
                            />
                            <Select.Content>
                                {(employees ?? []).map(emp => (
                                    <Select.Item key={String(emp.id)} value={String(emp.id)}>
                                        {emp.name}{emp.employee_id ? ` (${emp.employee_id})` : ''}
                                    </Select.Item>
                                ))}
                            </Select.Content>
                        </Select.Root>
                    </Box>

                    <Flex gap="2" justify="end" mt="5">
                        <Dialog.Close>
                            <Button variant="soft" color="gray">Cancel</Button>
                        </Dialog.Close>
                        <Button onClick={linkUser} disabled={linkingUser}>
                            {linkingUser ? <><Spinner size="1" /> Linking…</> : 'Link User'}
                        </Button>
                    </Flex>
                </Dialog.Content>
            </Dialog.Root>

            {/* ── Device Commands ── */}
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
                            </Flex>
                        </Box>

                        {/* Command history */}
                        <Box>
                            <Flex justify="between" align="center" mb="2">
                                <Text size="2" weight="medium">Command History</Text>
                                <Button
                                    variant="soft"
                                    size="1"
                                    color="gray"
                                    onClick={refreshCommandHistory}
                                    disabled={loadingCommands}
                                    aria-label="Refresh command history"
                                >
                                    {loadingCommands
                                        ? <Spinner size="1" />
                                        : <ReloadIcon width={14} height={14} />}
                                    Refresh
                                </Button>
                            </Flex>

                            {loadingCommands ? (
                                <Flex justify="center" py="6">
                                    <Spinner size="3" />
                                </Flex>
                            ) : commandHistory.length === 0 ? (
                                <Card variant="surface">
                                    <Flex justify="center" py="4">
                                        <Text size="2" color="gray">No commands sent yet.</Text>
                                    </Flex>
                                </Card>
                            ) : (
                                <Box style={{ maxHeight: 220, overflow: 'auto' }}>
                                    <Table.Root variant="surface">
                                        <Table.Header>
                                            <Table.Row>
                                                <Table.ColumnHeaderCell>TYPE</Table.ColumnHeaderCell>
                                                <Table.ColumnHeaderCell>STATUS</Table.ColumnHeaderCell>
                                                <Table.ColumnHeaderCell>TIME</Table.ColumnHeaderCell>
                                            </Table.Row>
                                        </Table.Header>
                                        <Table.Body>
                                            {commandHistory.map(cmd => (
                                                <Table.Row key={cmd.id}>
                                                    <Table.Cell>
                                                        <Code size="1">{cmd.command_type}</Code>
                                                    </Table.Cell>
                                                    <Table.Cell>
                                                        <CommandStatusBadge status={cmd.status} />
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

                    <Flex gap="2" justify="end" mt="5">
                        <Dialog.Close>
                            <Button variant="soft" color="gray">Close</Button>
                        </Dialog.Close>
                        <Button onClick={sendCommand} disabled={sendingCommand}>
                            {sendingCommand
                                ? <><Spinner size="1" /> Sending…</>
                                : 'Send Command'}
                        </Button>
                    </Flex>
                </Dialog.Content>
            </Dialog.Root>
        </App>
    );
}

BiometricDevices.layout = (page) => <App>{page}</App>;

export default BiometricDevices;