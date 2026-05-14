import React, { useState, useCallback, useEffect } from 'react';
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
    Select,
    Table,
    Badge,
    Tooltip,
    Dialog,
    Code,
    Separator,
    Spinner,
    IconButton,
} from '@radix-ui/themes';
import {
    TrashIcon,
    ReloadIcon,
    MagnifyingGlassIcon,
    Cross2Icon,
    DownloadIcon,
    EyeIcon,
} from '@radix-ui/react-icons';

const RequestLogs = ({ title }) => {
    const isMobile = useMediaQuery('(max-width: 640px)');

    const [logs, setLogs] = useState([]);
    const [loading, setLoading] = useState(false);
    const [selectedLogs, setSelectedLogs] = useState(new Set());
    const [showDetails, setShowDetails] = useState(null);
    const [confirmClearAll, setConfirmClearAll] = useState(false);

    // Filters
    const [filters, setFilters] = useState({
        search: '',
        ip_address: '',
        user_id: '',
        method: '',
        status: '',
        start_date: '',
        end_date: '',
    });

    const [pagination, setPagination] = useState({
        current_page: 1,
        per_page: 50,
        total: 0,
    });

    const loadLogs = useCallback(async (page = 1) => {
        setLoading(true);
        try {
            const { data } = await axios.get(route('request-logs.list'), {
                params: {
                    page,
                    per_page: pagination.per_page,
                    ...filters,
                },
            });
            setLogs(data.data);
            setPagination({
                current_page: data.current_page,
                per_page: data.per_page,
                total: data.total,
            });
        } catch {
            showToast.error('Failed to load logs.');
        } finally {
            setLoading(false);
        }
    }, [filters, pagination.per_page]);

    useEffect(() => {
        loadLogs(1);
    }, [loadLogs]);

    const handleFilterChange = (key, value) => {
        setFilters(prev => ({ ...prev, [key]: value }));
    };

    const applyFilters = () => {
        loadLogs(1);
    };

    const resetFilters = () => {
        setFilters({
            search: '',
            ip_address: '',
            user_id: '',
            method: '',
            status: '',
            start_date: '',
            end_date: '',
        });
        loadLogs(1);
    };

    const toggleLogSelection = (id) => {
        const newSelected = new Set(selectedLogs);
        if (newSelected.has(id)) {
            newSelected.delete(id);
        } else {
            newSelected.add(id);
        }
        setSelectedLogs(newSelected);
    };

    const toggleAllSelection = () => {
        if (selectedLogs.size === logs.length) {
            setSelectedLogs(new Set());
        } else {
            setSelectedLogs(new Set(logs.map(log => log.id)));
        }
    };

    const deleteLog = async (id) => {
        if (! confirm('Delete this log?')) return;
        try {
            await axios.delete(route('request-logs.destroy', id));
            showToast.success('Log deleted.');
            loadLogs(pagination.current_page);
        } catch {
            showToast.error('Failed to delete log.');
        }
    };

    const bulkDelete = async () => {
        if (selectedLogs.size === 0) return;
        if (! confirm(`Delete ${selectedLogs.size} selected logs?`)) return;
        try {
            await axios.post(route('request-logs.bulk-delete'), { ids: Array.from(selectedLogs) });
            showToast.success('Logs deleted.');
            setSelectedLogs(new Set());
            loadLogs(pagination.current_page);
        } catch {
            showToast.error('Failed to delete logs.');
        }
    };

    const clearAllLogs = async () => {
        try {
            await axios.post(route('request-logs.clear-all'), { confirm: 'DELETE_ALL' });
            showToast.success('All logs cleared.');
            setConfirmClearAll(false);
            loadLogs(1);
        } catch {
            showToast.error('Failed to clear logs.');
        }
    };

    const exportLogs = async () => {
        try {
            const { data } = await axios.get(route('request-logs.export'), {
                params: filters,
                responseType: 'blob',
            });
            const url = window.URL.createObjectURL(new Blob([data]));
            const link = document.createElement('a');
            link.href = url;
            link.setAttribute('download', `request_logs_${new Date().toISOString()}.csv`);
            document.body.appendChild(link);
            link.click();
            link.remove();
            showToast.success('Logs exported.');
        } catch {
            showToast.error('Failed to export logs.');
        }
    };

    const viewDetails = async (id) => {
        try {
            const { data } = await axios.get(route('request-logs.show', id));
            setShowDetails(data);
        } catch {
            showToast.error('Failed to load log details.');
        }
    };

    const getStatusColor = (status) => {
        if (status >= 200 && status < 300) return 'green';
        if (status >= 300 && status < 400) return 'blue';
        if (status >= 400 && status < 500) return 'amber';
        if (status >= 500) return 'red';
        return 'gray';
    };

    return (
        <>
            <Head title={title} />
            <App>
                <Box p={{ initial: '4', md: '6' }}>
                    <Flex direction="column" gap="6">
                        {/* Header */}
                        <Flex justify="between" align={{ initial: 'stretch', md: 'center' }} direction={{ initial: 'column', md: 'row' }} gap="4">
                            <Text size="6" weight="medium">Request Logs</Text>
                            <Flex gap="2" wrap="wrap">
                                <Button size="2" variant="soft" color="indigo" onClick={applyFilters}>
                                    <MagnifyingGlassIcon width={16} height={16} />
                                    {!isMobile && ' Apply Filters'}
                                </Button>
                                <Button size="2" variant="soft" color="gray" onClick={resetFilters}>
                                    <Cross2Icon width={16} height={16} />
                                    {!isMobile && ' Reset'}
                                </Button>
                                <Button size="2" variant="soft" color="green" onClick={exportLogs}>
                                    <DownloadIcon width={16} height={16} />
                                    {!isMobile && ' Export'}
                                </Button>
                                <Button size="2" variant="soft" color="blue" onClick={() => loadLogs(pagination.current_page)}>
                                    <ReloadIcon width={16} height={16} />
                                    {!isMobile && ' Refresh'}
                                </Button>
                                {selectedLogs.size > 0 && (
                                    <Button size="2" variant="soft" color="red" onClick={bulkDelete}>
                                        <TrashIcon width={16} height={16} />
                                        Delete ({selectedLogs.size})
                                    </Button>
                                )}
                                <Button size="2" variant="soft" color="red" onClick={() => setConfirmClearAll(true)}>
                                    <TrashIcon width={16} height={16} />
                                    {!isMobile && ' Clear All'}
                                </Button>
                            </Flex>
                        </Flex>

                        {/* Filters */}
                        <Flex direction="column" gap="4">
                            <Flex gap="3" wrap="wrap">
                                <TextField.Root style={{ minWidth: 200, flex: 1 }}>
                                    <TextField.Slot>
                                        <MagnifyingGlassIcon width={16} height={16} />
                                    </TextField.Slot>
                                    <TextField.Input
                                        placeholder="Search URL..."
                                        value={filters.search}
                                        onChange={e => handleFilterChange('search', e.target.value)}
                                    />
                                </TextField.Root>

                                <TextField.Root style={{ minWidth: 150 }}>
                                    <TextField.Input
                                        placeholder="IP Address"
                                        value={filters.ip_address}
                                        onChange={e => handleFilterChange('ip_address', e.target.value)}
                                    />
                                </TextField.Root>

                                <Select.Root value={filters.method} onValueChange={v => handleFilterChange('method', v)}>
                                    <Select.Trigger style={{ minWidth: 120 }}>
                                        <Select.Value placeholder="Method" />
                                    </Select.Trigger>
                                    <Select.Content>
                                        <Select.Item value="">All Methods</Select.Item>
                                        <Select.Item value="GET">GET</Select.Item>
                                        <Select.Item value="POST">POST</Select.Item>
                                        <Select.Item value="PUT">PUT</Select.Item>
                                        <Select.Item value="PATCH">PATCH</Select.Item>
                                        <Select.Item value="DELETE">DELETE</Select.Item>
                                    </Select.Content>
                                </Select.Root>

                                <Select.Root value={filters.status} onValueChange={v => handleFilterChange('status', v)}>
                                    <Select.Trigger style={{ minWidth: 120 }}>
                                        <Select.Value placeholder="Status" />
                                    </Select.Trigger>
                                    <Select.Content>
                                        <Select.Item value="">All Status</Select.Item>
                                        <Select.Item value="200">200 OK</Select.Item>
                                        <Select.Item value="201">201 Created</Select.Item>
                                        <Select.Item value="204">204 No Content</Select.Item>
                                        <Select.Item value="400">400 Bad Request</Select.Item>
                                        <Select.Item value="401">401 Unauthorized</Select.Item>
                                        <Select.Item value="403">403 Forbidden</Select.Item>
                                        <Select.Item value="404">404 Not Found</Select.Item>
                                        <Select.Item value="500">500 Server Error</Select.Item>
                                    </Select.Content>
                                </Select.Root>

                                <TextField.Root style={{ minWidth: 160 }}>
                                    <TextField.Input
                                        type="date"
                                        placeholder="Start Date"
                                        value={filters.start_date}
                                        onChange={e => handleFilterChange('start_date', e.target.value)}
                                    />
                                </TextField.Root>

                                <TextField.Root style={{ minWidth: 160 }}>
                                    <TextField.Input
                                        type="date"
                                        placeholder="End Date"
                                        value={filters.end_date}
                                        onChange={e => handleFilterChange('end_date', e.target.value)}
                                    />
                                </TextField.Root>
                            </Flex>
                        </Flex>

                        <Separator />

                        {/* Table */}
                        {loading ? (
                            <Flex justify="center" py="9">
                                <Spinner size="3" />
                            </Flex>
                        ) : logs.length === 0 ? (
                            <Flex justify="center" py="9">
                                <Text size="3" color="gray">No logs found.</Text>
                            </Flex>
                        ) : (
                            <Table.Root variant="surface">
                                <Table.Header>
                                    <Table.Row>
                                        <Table.ColumnHeaderCell style={{ width: 40 }}>
                                            <input
                                                type="checkbox"
                                                checked={selectedLogs.size === logs.length}
                                                onChange={toggleAllSelection}
                                            />
                                        </Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell>IP</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell>Method</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell>URL</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell>Status</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell>User</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell>Duration</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell>Time</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell>Actions</Table.ColumnHeaderCell>
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    {logs.map(log => (
                                        <Table.Row key={log.id}>
                                            <Table.Cell>
                                                <input
                                                    type="checkbox"
                                                    checked={selectedLogs.has(log.id)}
                                                    onChange={() => toggleLogSelection(log.id)}
                                                />
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Text size="1">{log.ip_address || '—'}</Text>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Badge color="blue" variant="soft" size="1">
                                                    {log.method}
                                                </Badge>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Text size="1" style={{ wordBreak: 'break-word', maxWidth: 300 }}>
                                                    {log.url}
                                                </Text>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Badge color={getStatusColor(log.response_status)} variant="soft" size="1">
                                                    {log.response_status}
                                                </Badge>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Text size="1">{log.user?.name || 'Guest'}</Text>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Text size="1">{log.duration_ms}ms</Text>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Text size="1" color="gray">
                                                    {new Date(log.created_at).toLocaleString()}
                                                </Text>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Flex gap="1">
                                                    <Tooltip content="View details">
                                                        <IconButton
                                                            size="1"
                                                            variant="ghost"
                                                            color="blue"
                                                            onClick={() => viewDetails(log.id)}
                                                        >
                                                            <EyeIcon width={14} height={14} />
                                                        </IconButton>
                                                    </Tooltip>
                                                    <Tooltip content="Delete">
                                                        <IconButton
                                                            size="1"
                                                            variant="ghost"
                                                            color="red"
                                                            onClick={() => deleteLog(log.id)}
                                                        >
                                                            <TrashIcon width={14} height={14} />
                                                        </IconButton>
                                                    </Tooltip>
                                                </Flex>
                                            </Table.Cell>
                                        </Table.Row>
                                    ))}
                                </Table.Body>
                            </Table.Root>
                        )}

                        {/* Pagination */}
                        {pagination.total > 0 && (
                            <Flex justify="between" align="center" gap="4">
                                <Text size="1" color="gray">
                                    Showing {((pagination.current_page - 1) * pagination.per_page) + 1} to {Math.min(pagination.current_page * pagination.per_page, pagination.total)} of {pagination.total}
                                </Text>
                                <Flex gap="2">
                                    <Button
                                        size="1"
                                        variant="soft"
                                        disabled={pagination.current_page === 1}
                                        onClick={() => loadLogs(pagination.current_page - 1)}
                                    >
                                        Previous
                                    </Button>
                                    <Text size="1" color="gray">
                                        Page {pagination.current_page}
                                    </Text>
                                    <Button
                                        size="1"
                                        variant="soft"
                                        disabled={pagination.current_page * pagination.per_page >= pagination.total}
                                        onClick={() => loadLogs(pagination.current_page + 1)}
                                    >
                                        Next
                                    </Button>
                                </Flex>
                            </Flex>
                        )}
                    </Flex>
                </Box>

                {/* Details Dialog */}
                <Dialog.Root open={!!showDetails} onOpenChange={() => setShowDetails(null)}>
                    <Dialog.Content style={{ maxWidth: 800, maxHeight: '80vh' }}>
                        <Dialog.Title>Request Log Details</Dialog.Title>
                        <Dialog.Description>
                            <Flex direction="column" gap="4" py="4">
                                <Box>
                                    <Text size="1" weight="medium">IP Address:</Text>
                                    <Text size="1">{showDetails?.ip_address}</Text>
                                </Box>
                                <Box>
                                    <Text size="1" weight="medium">Method:</Text>
                                    <Text size="1">{showDetails?.method}</Text>
                                </Box>
                                <Box>
                                    <Text size="1" weight="medium">URL:</Text>
                                    <Text size="1" style={{ wordBreak: 'break-word' }}>{showDetails?.url}</Text>
                                </Box>
                                <Box>
                                    <Text size="1" weight="medium">Status:</Text>
                                    <Badge color={getStatusColor(showDetails?.response_status)} variant="soft" size="1">
                                        {showDetails?.response_status}
                                    </Badge>
                                </Box>
                                <Box>
                                    <Text size="1" weight="medium">Duration:</Text>
                                    <Text size="1">{showDetails?.duration_ms}ms</Text>
                                </Box>
                                <Box>
                                    <Text size="1" weight="medium">User Agent:</Text>
                                    <Text size="1" style={{ wordBreak: 'break-word' }}>{showDetails?.user_agent}</Text>
                                </Box>
                                {showDetails?.headers && (
                                    <Box>
                                        <Text size="1" weight="medium">Headers:</Text>
                                        <Code size="1" style={{ display: 'block', whiteSpace: 'pre-wrap', wordBreak: 'break-word' }}>
                                            {JSON.stringify(showDetails.headers, null, 2)}
                                        </Code>
                                    </Box>
                                )}
                                {showDetails?.request_body && (
                                    <Box>
                                        <Text size="1" weight="medium">Request Body:</Text>
                                        <Code size="1" style={{ display: 'block', whiteSpace: 'pre-wrap', wordBreak: 'break-word' }}>
                                            {JSON.stringify(showDetails.request_body, null, 2)}
                                        </Code>
                                    </Box>
                                )}
                                {showDetails?.response_body && (
                                    <Box>
                                        <Text size="1" weight="medium">Response Body:</Text>
                                        <Code size="1" style={{ display: 'block', whiteSpace: 'pre-wrap', wordBreak: 'break-word', maxHeight: 200, overflow: 'auto' }}>
                                            {showDetails.response_body}
                                        </Code>
                                    </Box>
                                )}
                            </Flex>
                        </Dialog.Description>
                        <Flex gap="3" mt="4">
                            <Dialog.Close>
                                <Button variant="soft" color="gray">Close</Button>
                            </Dialog.Close>
                        </Flex>
                    </Dialog.Content>
                </Dialog.Root>

                {/* Clear All Confirmation Dialog */}
                <Dialog.Root open={confirmClearAll} onOpenChange={setConfirmClearAll}>
                    <Dialog.Content>
                        <Dialog.Title>Clear All Logs</Dialog.Title>
                        <Dialog.Description>
                            Are you sure you want to delete ALL request logs? This action cannot be undone.
                        </Dialog.Description>
                        <Flex gap="3" mt="4">
                            <Dialog.Close>
                                <Button variant="soft" color="gray">Cancel</Button>
                            </Dialog.Close>
                            <Button color="red" onClick={clearAllLogs}>
                                Delete All Logs
                            </Button>
                        </Flex>
                    </Dialog.Content>
                </Dialog.Root>
            </App>
        </>
    );
};

export default RequestLogs;
