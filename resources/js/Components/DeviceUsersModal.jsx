/**
 * DeviceUsersModal.jsx
 * Modal for managing biometric device users - fetches from device, shows linked users, allows linking/unlinking
 */
import React, { useState, useEffect } from 'react';
import {
    Badge, Box, Button, Card, Code, Dialog, Flex,
    IconButton, Select, Spinner, Table, Text, TextField, Tooltip,
} from '@radix-ui/themes';
import {
    CheckCircledIcon, MinusIcon, Pencil1Icon, PlusIcon, ReloadIcon, TrashIcon,
} from '@radix-ui/react-icons';
import axios from 'axios';
import { showToast } from '@/utils/toastUtils';

export default function DeviceUsersModal({ open, onOpenChange, device, employees }) {
    const [deviceUsers, setDeviceUsers] = useState([]);
    const [loading, setLoading] = useState(false);
    const [fetchingFromDevice, setFetchingFromDevice] = useState(false);
    const [linkForm, setLinkForm] = useState({ user_id: '' });
    const [linkingEntry, setLinkingEntry] = useState(null);
    const [linking, setLinking] = useState(false);

    useEffect(() => {
        if (open && device) {
            loadDeviceUsers();
        }
    }, [open, device]);

    const loadDeviceUsers = async () => {
        if (!device) return;
        setLoading(true);
        try {
            const { data } = await axios.get(`/api/biometric-devices/${device.id}/users`);
            setDeviceUsers(data.users ?? []);
        } catch (e) {
            showToast.error(e.response?.data?.message ?? 'Failed to load device users.');
        } finally {
            setLoading(false);
        }
    };

    const fetchUsersFromDevice = async () => {
        if (!device || device.protocol !== 'adms') {
            showToast.error('Only ADMS protocol devices support fetching users.');
            return;
        }
        setFetchingFromDevice(true);
        try {
            const { data } = await axios.post(`/api/biometric-devices/${device.id}/request-users`);
            showToast.success(data.message || 'Command queued successfully.');
        } catch (e) {
            showToast.error(e.response?.data?.message ?? 'Failed to request users from device.');
        } finally {
            setFetchingFromDevice(false);
        }
    };

    const linkUser = async () => {
        if (!linkingEntry || !linkForm.user_id) return;
        setLinking(true);
        try {
            await axios.post(`/biometric-devices/${device.id}/users/link`, {
                device_user_id: linkingEntry.device_user_id,
                user_id: linkForm.user_id,
            });
            setLinkingEntry(null);
            setLinkForm({ user_id: '' });
            loadDeviceUsers();
            showToast.success('User linked successfully.');
        } catch (e) {
            showToast.error(e.response?.data?.message ?? 'Failed to link user.');
        } finally {
            setLinking(false);
        }
    };

    const unlinkUser = async (entry) => {
        if (!confirm(`Unlink ${entry.user_name} from device user ${entry.device_user_id}?`)) return;
        try {
            await axios.post(`/biometric-devices/${device.id}/users/unlink`, { userId: entry.user_id });
            loadDeviceUsers();
            showToast.success('User unlinked successfully.');
        } catch (e) {
            showToast.error(e.response?.data?.message ?? 'Failed to unlink user.');
        }
    };

    const deleteEntry = async (entry) => {
        if (!confirm(`Delete device user ${entry.device_user_id}?`)) return;
        try {
            await axios.delete(`/biometric-devices/${device.id}/users/remove`, { userId: entry.user_id });
            loadDeviceUsers();
            showToast.success('Entry deleted successfully.');
        } catch (e) {
            showToast.error(e.response?.data?.message ?? 'Failed to delete entry.');
        }
    };

    const openLinkModal = (entry) => {
        setLinkingEntry(entry);
        setLinkForm({ user_id: '' });
    };

    const linkedUsers = deviceUsers.filter(u => u.user_id);
    const unlinkedUsers = deviceUsers.filter(u => !u.user_id);

    return (
        <Dialog.Root open={open} onOpenChange={onOpenChange}>
            <Dialog.Content style={{ maxWidth: 800 }}>
                <Dialog.Title>Device Users — {device?.name}</Dialog.Title>
                <Dialog.Description size="2" color="gray">
                    Manage device users fetched from {device?.serial_number} and link them to system employees.
                </Dialog.Description>

                <Flex direction="column" gap="4" mt="4">
                    {/* Actions */}
                    {device?.protocol === 'adms' && (
                        <Card variant="surface" style={{ backgroundColor: 'var(--accent-a3)' }}>
                            <Flex justify="between" align="center">
                                <Box>
                                    <Text size="2" weight="medium">Fetch Users from Device</Text>
                                    <Text size="1" color="gray">
                                        Send GET_USERINFO command to fetch user data directly from the device.
                                    </Text>
                                </Box>
                                <Button
                                    size="2"
                                    onClick={fetchUsersFromDevice}
                                    disabled={fetchingFromDevice}
                                >
                                    {fetchingFromDevice ? <Spinner size="1" /> : <ReloadIcon />}
                                    {fetchingFromDevice ? 'Fetching...' : 'Fetch Users'}
                                </Button>
                            </Flex>
                        </Card>
                    )}

                    {/* Linked Users */}
                    <Box>
                        <Text size="2" weight="medium" as="div" mb="2">
                            Linked Users ({linkedUsers.length})
                        </Text>
                        {loading ? (
                            <Flex justify="center" py="6">
                                <Spinner size="3" />
                            </Flex>
                        ) : linkedUsers.length === 0 ? (
                            <Card variant="surface">
                                <Flex justify="center" py="4">
                                    <Text size="2" color="gray">No linked users yet.</Text>
                                </Flex>
                            </Card>
                        ) : (
                            <Box style={{ overflowX: 'auto' }}>
                                <Table.Root variant="surface">
                                    <Table.Header>
                                        <Table.Row>
                                            <Table.ColumnHeaderCell>Device User ID</Table.ColumnHeaderCell>
                                            <Table.ColumnHeaderCell>Linked System User</Table.ColumnHeaderCell>
                                            <Table.ColumnHeaderCell>Status</Table.ColumnHeaderCell>
                                            <Table.ColumnHeaderCell>Actions</Table.ColumnHeaderCell>
                                        </Table.Row>
                                    </Table.Header>
                                    <Table.Body>
                                        {linkedUsers.map(entry => (
                                            <Table.Row key={entry.id}>
                                                <Table.Cell>
                                                    <Code size="1">{entry.device_user_id}</Code>
                                                </Table.Cell>
                                                <Table.Cell>
                                                    <Box>
                                                        <Text size="2">{entry.user_name}</Text>
                                                        {entry.user_email && (
                                                            <Text size="1" color="gray">{entry.user_email}</Text>
                                                        )}
                                                    </Box>
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
                                                        <Tooltip content="Unlink">
                                                            <IconButton
                                                                size="1"
                                                                variant="ghost"
                                                                color="amber"
                                                                onClick={() => unlinkUser(entry)}
                                                            >
                                                                <MinusIcon />
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
                    </Box>

                    {/* Unlinked Users */}
                    {unlinkedUsers.length > 0 && (
                        <Box>
                            <Text size="2" weight="medium" as="div" mb="2">
                                Unlinked Device Users ({unlinkedUsers.length})
                            </Text>
                            <Box style={{ overflowX: 'auto' }}>
                                <Table.Root variant="surface">
                                    <Table.Header>
                                        <Table.Row>
                                            <Table.ColumnHeaderCell>Device User ID</Table.ColumnHeaderCell>
                                            <Table.ColumnHeaderCell>Status</Table.ColumnHeaderCell>
                                            <Table.ColumnHeaderCell>Actions</Table.ColumnHeaderCell>
                                        </Table.Row>
                                    </Table.Header>
                                    <Table.Body>
                                        {unlinkedUsers.map(entry => (
                                            <Table.Row key={entry.id}>
                                                <Table.Cell>
                                                    <Code size="1">{entry.device_user_id}</Code>
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
                                                        <Tooltip content="Link to Employee">
                                                            <IconButton
                                                                size="1"
                                                                variant="ghost"
                                                                color="accent"
                                                                onClick={() => openLinkModal(entry)}
                                                            >
                                                                <Pencil1Icon />
                                                            </IconButton>
                                                        </Tooltip>
                                                        <Tooltip content="Delete">
                                                            <IconButton
                                                                size="1"
                                                                variant="ghost"
                                                                color="red"
                                                                onClick={() => deleteEntry(entry)}
                                                            >
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
                        </Box>
                    )}

                    {/* Link User Dialog */}
                    {linkingEntry && (
                        <Card variant="surface" style={{ border: '2px solid var(--accent-9)' }}>
                            <Flex direction="column" gap="3">
                                <Text size="2" weight="medium">
                                    Link Device User {linkingEntry.device_user_id} to Employee
                                </Text>
                                <Select.Root
                                    value={linkForm.user_id ? String(linkForm.user_id) : ''}
                                    onValueChange={v => setLinkForm(f => ({ ...f, user_id: v }))}
                                >
                                    <Select.Trigger style={{ width: '100%' }} placeholder="Choose an employee…" />
                                    <Select.Content>
                                        {(employees ?? []).map(emp => (
                                            <Select.Item key={String(emp.id)} value={String(emp.id)}>
                                                {emp.name}{emp.employee_id ? ` (${emp.employee_id})` : ''}
                                            </Select.Item>
                                        ))}
                                    </Select.Content>
                                </Select.Root>
                                <Flex gap="2" justify="end">
                                    <Button
                                        variant="soft"
                                        color="gray"
                                        onClick={() => { setLinkingEntry(null); setLinkForm({ user_id: '' }); }}
                                    >
                                        Cancel
                                    </Button>
                                    <Button onClick={linkUser} disabled={linking}>
                                        {linking ? <Spinner size="1" /> : <CheckCircledIcon />}
                                        {linking ? 'Linking…' : 'Link User'}
                                    </Button>
                                </Flex>
                            </Flex>
                        </Card>
                    )}
                </Flex>

                <Flex justify="end" mt="5">
                    <Dialog.Close>
                        <Button variant="soft" color="gray">Close</Button>
                    </Dialog.Close>
                </Flex>
            </Dialog.Content>
        </Dialog.Root>
    );
}
