import React, { useState, useCallback } from 'react';
import { showToast } from '@/utils/toastUtils';
import axios from 'axios';
import {
    Table as RadixTable,
    Button,
    Badge,
    Tooltip,
    Text,
    Flex,
    Box,
    Card,
    Code,
    IconButton,
    Spinner,
    ScrollArea
} from '@radix-ui/themes';
import {
    PlusIcon,
    TrashIcon,
    Pencil1Icon,
    ReloadIcon,
    GlobeIcon
} from '@radix-ui/react-icons';

const BiometricDevicesTableRadix = ({ 
    devices: initialDevices, 
    loading = false,
    onRefresh,
    onEdit,
    onAdd,
    onDelete,
    onManageUsers,
    onSendCommand,
    onRegenerateToken
}) => {
    const [devices, setDevices] = useState(initialDevices || []);
    const [deletingId, setDeletingId] = useState(null);

    const handleDelete = async (device) => {
        if (!confirm(`Delete device "${device.name}"? This will also remove all user mappings.`)) return;
        setDeletingId(device.id);
        try {
            await axios.delete(`/biometric-devices/${device.id}`);
            setDevices(prev => prev.filter(d => d.id !== device.id));
            if (onDelete) onDelete(device.id);
            showToast('Device deleted.', 'success');
        } catch (error) {
            showToast('Failed to delete device.', 'error');
        } finally {
            setDeletingId(null);
        }
    };

    const handleRegenerateToken = async (device) => {
        if (!confirm('Regenerate auth token? The device will need to be reconfigured with the new token.')) return;
        try {
            const { data } = await axios.post(`/biometric-devices/${device.id}/regenerate-token`);
            showToast('Token regenerated.', 'success');
            if (onRegenerateToken) onRegenerateToken(device, data.auth_token);
        } catch (error) {
            showToast('Failed to regenerate token.', 'error');
        }
    };

    // Loading skeleton
    const TableSkeleton = () => (
        <RadixTable.Root variant="surface">
            <RadixTable.Header>
                <RadixTable.Row>
                    <RadixTable.ColumnHeaderCell>DEVICE</RadixTable.ColumnHeaderCell>
                    <RadixTable.ColumnHeaderCell>SERIAL</RadixTable.ColumnHeaderCell>
                    <RadixTable.ColumnHeaderCell>IP / LOCATION</RadixTable.ColumnHeaderCell>
                    <RadixTable.ColumnHeaderCell>PROTOCOL</RadixTable.ColumnHeaderCell>
                    <RadixTable.ColumnHeaderCell>USERS</RadixTable.ColumnHeaderCell>
                    <RadixTable.ColumnHeaderCell>LAST PING</RadixTable.ColumnHeaderCell>
                    <RadixTable.ColumnHeaderCell>STATUS</RadixTable.ColumnHeaderCell>
                    <RadixTable.ColumnHeaderCell>ACTIONS</RadixTable.ColumnHeaderCell>
                </RadixTable.Row>
            </RadixTable.Header>
            <RadixTable.Body>
                {Array.from({ length: 5 }).map((_, rowIndex) => (
                    <RadixTable.Row key={rowIndex}>
                        {Array.from({ length: 8 }).map((_, colIndex) => (
                            <RadixTable.Cell key={colIndex}>
                                <Spinner size="1" />
                            </RadixTable.Cell>
                        ))}
                    </RadixTable.Row>
                ))}
            </RadixTable.Body>
        </RadixTable.Root>
    );

    // Empty state
    const EmptyState = () => (
        <Flex direction="column" align="center" justify="center" py="8" gap="3">
            <GlobeIcon width="40" height="40" style={{ color: 'var(--gray-7)' }} />
            <Text size="3" weight="medium" color="gray">No devices found</Text>
            <Text size="2" color="gray">No biometric devices registered yet</Text>
        </Flex>
    );

    if (loading) {
        return (
            <Box style={{ maxHeight: 'calc(100vh - 280px)', display: 'flex', flexDirection: 'column' }}>
                <Flex align="center" justify="between" mb="4" px="2">
                    <Text size={{ initial: '3', md: '4' }} weight="bold" color="gray">Biometric Devices</Text>
                    <Button variant="soft" color="indigo" size="1" disabled>
                        <ReloadIcon style={{ animation: 'spin 1s linear infinite' }} />
                        Loading...
                    </Button>
                </Flex>
                <ScrollArea type="auto" style={{ flexGrow: 1, minHeight: '400px' }}>
                    <TableSkeleton />
                </ScrollArea>
            </Box>
        );
    }

    if (!devices || devices.length === 0) {
        return (
            <Box style={{ maxHeight: 'calc(100vh - 280px)' }}>
                <Flex align="center" justify="between" mb="4" px="2">
                    <Text size={{ initial: '3', md: '4' }} weight="bold">Biometric Devices</Text>
                </Flex>
                <Card>
                    <EmptyState />
                </Card>
            </Box>
        );
    }

    return (
        <Box style={{ maxHeight: 'calc(100vh - 280px)', display: 'flex', flexDirection: 'column' }}>
            {/* Header */}
            <Flex align="center" justify="between" mb="4" px="2">
                <Text size={{ initial: '3', md: '4' }} weight="bold">Biometric Devices</Text>
                <Text size="2" color="gray">Total: {devices.length}</Text>
            </Flex>

            {/* Table */}
            <ScrollArea type="auto" style={{ flexGrow: 1, minHeight: '400px' }}>
                <RadixTable.Root variant="surface" style={{ width: '100%', minWidth: '900px' }}>
                    <RadixTable.Header>
                        <RadixTable.Row>
                            <RadixTable.ColumnHeaderCell>DEVICE</RadixTable.ColumnHeaderCell>
                            <RadixTable.ColumnHeaderCell>SERIAL</RadixTable.ColumnHeaderCell>
                            <RadixTable.ColumnHeaderCell>IP / LOCATION</RadixTable.ColumnHeaderCell>
                            <RadixTable.ColumnHeaderCell>PROTOCOL</RadixTable.ColumnHeaderCell>
                            <RadixTable.ColumnHeaderCell>USERS</RadixTable.ColumnHeaderCell>
                            <RadixTable.ColumnHeaderCell>LAST PING</RadixTable.ColumnHeaderCell>
                            <RadixTable.ColumnHeaderCell>STATUS</RadixTable.ColumnHeaderCell>
                            <RadixTable.ColumnHeaderCell>ACTIONS</RadixTable.ColumnHeaderCell>
                        </RadixTable.Row>
                    </RadixTable.Header>
                    <RadixTable.Body>
                        {devices.map(device => (
                            <RadixTable.Row key={device.id}>
                                <RadixTable.Cell>
                                    <Box>
                                        <Text weight="medium" size="2">{device.name}</Text>
                                        {device.model && <Text size="1" color="gray">{device.model}</Text>}
                                    </Box>
                                </RadixTable.Cell>
                                <RadixTable.Cell>
                                    <Code size="1">{device.serial_number}</Code>
                                </RadixTable.Cell>
                                <RadixTable.Cell>
                                    <Box>
                                        {device.ip_address && <Text size="1">{device.ip_address}</Text>}
                                        {device.location && <Text size="1" color="gray">{device.location}</Text>}
                                    </Box>
                                </RadixTable.Cell>
                                <RadixTable.Cell>
                                    <Badge color={device.protocol === 'adms' ? 'green' : 'blue'} variant="soft" size="1">
                                        {device.protocol === 'adms' ? 'ADMS' : 'Push SDK'}
                                    </Badge>
                                </RadixTable.Cell>
                                <RadixTable.Cell>
                                    <Badge color="accent" variant="soft" size="1">
                                        {device.users_count ?? 0} enrolled
                                    </Badge>
                                </RadixTable.Cell>
                                <RadixTable.Cell>
                                    <Text size="1" color="gray">
                                        {device.last_heartbeat_at
                                            ? new Date(device.last_heartbeat_at).toLocaleString()
                                            : '—'}
                                    </Text>
                                </RadixTable.Cell>
                                <RadixTable.Cell>
                                    <Badge color={device.is_active ? 'green' : 'gray'} variant="soft" size="1">
                                        {device.is_active ? 'Active' : 'Inactive'}
                                    </Badge>
                                </RadixTable.Cell>
                                <RadixTable.Cell>
                                    <Flex gap="1">
                                        <Tooltip content="Manage user enrollments">
                                            <Button
                                                variant="ghost" color="accent"
                                                size="1"
                                                onClick={() => onManageUsers && onManageUsers(device)}
                                            >
                                                <PlusIcon style={{ width: 16, height: 16 }} />
                                            </Button>
                                        </Tooltip>
                                        <Tooltip content="Edit device">
                                            <Button
                                                variant="ghost"
                                                size="1"
                                                onClick={() => onEdit && onEdit(device)}
                                            >
                                                <Pencil1Icon style={{ width: 16, height: 16 }} />
                                            </Button>
                                        </Tooltip>
                                        {device.protocol === 'adms' && (
                                            <Tooltip content="Device commands">
                                                <Button
                                                    variant="ghost" color="blue"
                                                    size="1"
                                                    onClick={() => onSendCommand && onSendCommand(device)}
                                                >
                                                    <GlobeIcon style={{ width: 16, height: 16 }} />
                                                </Button>
                                            </Tooltip>
                                        )}
                                        {device.protocol !== 'adms' && (
                                            <Tooltip content="Regenerate auth token">
                                                <Button
                                                    variant="ghost" color="amber"
                                                    size="1"
                                                    onClick={() => handleRegenerateToken(device)}
                                                >
                                                    <ReloadIcon style={{ width: 16, height: 16 }} />
                                                </Button>
                                            </Tooltip>
                                        )}
                                        <Tooltip content="Delete device">
                                            <Button
                                                variant="ghost" color="red"
                                                size="1"
                                                onClick={() => handleDelete(device)}
                                                disabled={deletingId === device.id}
                                            >
                                                {deletingId === device.id ? (
                                                    <Spinner size="1" />
                                                ) : (
                                                    <TrashIcon style={{ width: 16, height: 16 }} />
                                                )}
                                            </Button>
                                        </Tooltip>
                                    </Flex>
                                </RadixTable.Cell>
                            </RadixTable.Row>
                        ))}
                    </RadixTable.Body>
                </RadixTable.Root>
            </ScrollArea>
        </Box>
    );
};

export default BiometricDevicesTableRadix;
