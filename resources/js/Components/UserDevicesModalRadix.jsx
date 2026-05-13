import React, { useState, useEffect } from "react";
import {
    Dialog,
    Button,
    Table,
    Flex,
    Box,
    Text,
    Badge,
    ScrollArea,
    IconButton,
    Spinner,
    Separator,
    Tooltip
} from '@radix-ui/themes';
import {
    DesktopIcon,
    MobileIcon,
    LockClosedIcon,
    TrashIcon,
    EyeOpenIcon,
    ReloadIcon
} from '@radix-ui/react-icons';
import axios from 'axios';
import { formatDistanceToNow } from 'date-fns';
import { showToast } from '@/utils/toastUtils';

const UserDevicesModalRadix = ({ user, open, closeModal }) => {
    const [devices, setDevices] = useState([]);
    const [loading, setLoading] = useState(false);
    const [selectedDevice, setSelectedDevice] = useState(null);
    const [detailsOpen, setDetailsOpen] = useState(false);
    const [processing, setProcessing] = useState({});

    useEffect(() => {
        if (open && user?.id) {
            fetchUserDevices();
        }
    }, [open, user?.id]);

    const fetchUserDevices = async () => {
        setLoading(true);
        try {
            const response = await axios.get(route('users.devices.index', { user: user.id }));
            setDevices(response.data.devices || []);
        } catch (error) {
            console.error('Failed to fetch user devices:', error);
            showToast.error('Failed to load user devices');
        } finally {
            setLoading(false);
        }
    };

    const handleDeactivateDevice = async (deviceId) => {
        if (!confirm('Are you sure you want to deactivate this device?')) return;

        setProcessing(prev => ({ ...prev, [deviceId]: true }));
        try {
            await axios.post(route('users.devices.deactivate', { user: user.id, device: deviceId }));
            setDevices(prev => prev.map(d => d.id === deviceId ? { ...d, is_active: false } : d));
            showToast.success('Device deactivated successfully');
        } catch (error) {
            console.error('Failed to deactivate device:', error);
            showToast.error('Failed to deactivate device');
        } finally {
            setProcessing(prev => ({ ...prev, [deviceId]: false }));
        }
    };

    const getDeviceIcon = (device) => {
        const type = String(device?.device_type || '').toLowerCase();
        const platform = String(device?.platform || '').toLowerCase();

        if (type === 'mobile' || platform.includes('android') || platform.includes('ios') || platform.includes('iphone')) {
            return <MobileIcon />;
        }

        if (type === 'tablet' || platform.includes('ipad') || platform.includes('tablet')) {
            return <DesktopIcon />;
        }

        return <DesktopIcon />;
    };

    const formatRelative = (value) => {
        if (!value) return 'Never';
        try {
            return formatDistanceToNow(new Date(value), { addSuffix: true });
        } catch {
            return 'N/A';
        }
    };

    const DeviceDetailsDialog = () => (
        <Dialog.Root open={detailsOpen} onOpenChange={setDetailsOpen}>
            <Dialog.Content style={{ maxWidth: '500px' }}>
                <Dialog.Title>Device Details</Dialog.Title>
                <Box>
                    {selectedDevice && (
                        <Flex direction="column" gap="3">
                            <Flex justify="between">
                                <Text size="2" color="gray">Device Name</Text>
                                <Text size="2" weight="medium">{selectedDevice.device_name || 'Unknown'}</Text>
                            </Flex>
                            <Flex justify="between">
                                <Text size="2" color="gray">Platform</Text>
                                <Text size="2" weight="medium">{selectedDevice.platform || 'N/A'}</Text>
                            </Flex>
                            <Flex justify="between">
                                <Text size="2" color="gray">Device Type</Text>
                                <Text size="2" weight="medium">{selectedDevice.device_type || 'N/A'}</Text>
                            </Flex>
                            <Flex justify="between">
                                <Text size="2" color="gray">Last Used</Text>
                                <Text size="2" weight="medium">{formatRelative(selectedDevice.last_used_at)}</Text>
                            </Flex>
                            <Flex justify="between">
                                <Text size="2" color="gray">IP Address</Text>
                                <Text size="2" weight="medium">{selectedDevice.ip_address || 'N/A'}</Text>
                            </Flex>
                            <Flex justify="between">
                                <Text size="2" color="gray">Status</Text>
                                <Badge size="1" variant={selectedDevice.is_active ? 'soft' : 'outline'} color={selectedDevice.is_active ? 'green' : 'gray'}>
                                    {selectedDevice.is_active ? 'Active' : 'Inactive'}
                                </Badge>
                            </Flex>
                        </Flex>
                    )}
                </Box>
                <Flex gap="3" justify="end" mt="4">
                    <Button onClick={() => setDetailsOpen(false)}>Close</Button>
                </Flex>
            </Dialog.Content>
        </Dialog.Root>
    );

    return (
        <>
            <Dialog.Root open={open} onOpenChange={closeModal}>
                <Dialog.Content style={{ maxWidth: '800px', maxHeight: '90vh' }}>
                    <Dialog.Title>
                        <Flex align="center" gap="2">
                            <DesktopIcon width="24" height="24" />
                            <Text>User Devices</Text>
                        </Flex>
                    </Dialog.Title>
                    <Dialog.Description>
                        Manage devices for {user?.name || 'User'}
                    </Dialog.Description>

                    <ScrollArea type="auto" style={{ maxHeight: 'calc(90vh - 200px)' }}>
                        {loading ? (
                            <Flex align="center" justify="center" py="8">
                                <Spinner size="3" />
                            </Flex>
                        ) : devices.length === 0 ? (
                            <Flex align="center" justify="center" py="8" direction="column" gap="2">
                                <DesktopIcon width="40" height="40" style={{ color: 'var(--gray-7)' }} />
                                <Text size="3" color="gray">No devices found</Text>
                                <Text size="2" color="gray">This user has no registered devices</Text>
                            </Flex>
                        ) : (
                            <Table.Root variant="surface">
                                <Table.Header>
                                    <Table.Row>
                                        <Table.ColumnHeaderCell>Device</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell>Platform</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell>Last Used</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell>Status</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell style={{ textAlign: 'center' }}>Actions</Table.ColumnHeaderCell>
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    {devices.map((device) => (
                                        <Table.Row key={device.id}>
                                            <Table.Cell>
                                                <Flex align="center" gap="2">
                                                    {getDeviceIcon(device)}
                                                    <Text size="2">{device.device_name || 'Unknown Device'}</Text>
                                                </Flex>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Text size="2">{device.platform || 'N/A'}</Text>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Text size="2">{formatRelative(device.last_used_at)}</Text>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Badge size="1" variant={device.is_active ? 'soft' : 'outline'} color={device.is_active ? 'green' : 'gray'}>
                                                    {device.is_active ? 'Active' : 'Inactive'}
                                                </Badge>
                                            </Table.Cell>
                                            <Table.Cell style={{ textAlign: 'center' }}>
                                                <Flex gap="1" justify="center">
                                                    <Tooltip content="View Details">
                                                        <IconButton
                                                            size="1"
                                                            variant="ghost"
                                                            onClick={() => {
                                                                setSelectedDevice(device);
                                                                setDetailsOpen(true);
                                                            }}
                                                        >
                                                            <EyeOpenIcon />
                                                        </IconButton>
                                                    </Tooltip>
                                                    {device.is_active && (
                                                        <Tooltip content="Deactivate Device">
                                                            <IconButton
                                                                size="1"
                                                                variant="ghost"
                                                                color="red"
                                                                onClick={() => handleDeactivateDevice(device.id)}
                                                                disabled={processing[device.id]}
                                                            >
                                                                {processing[device.id] ? <Spinner size="1" /> : <TrashIcon />}
                                                            </IconButton>
                                                        </Tooltip>
                                                    )}
                                                </Flex>
                                            </Table.Cell>
                                        </Table.Row>
                                    ))}
                                </Table.Body>
                            </Table.Root>
                        )}
                    </ScrollArea>

                    <Separator my="4" />

                    <Flex gap="3" justify="end" mt="4">
                        <Button variant="soft" color="gray" onClick={closeModal}>
                            Close
                        </Button>
                    </Flex>
                </Dialog.Content>
            </Dialog.Root>
            <DeviceDetailsDialog />
        </>
    );
};

export default UserDevicesModalRadix;
