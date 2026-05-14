import React, { useState, useCallback, useEffect } from 'react';
import { Head } from '@inertiajs/react';
import { route } from 'ziggy-js';
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
    ScrollArea,
    Card,
} from '@radix-ui/themes';
import {
    TrashIcon,
    ReloadIcon,
    MagnifyingGlassIcon,
    Cross2Icon,
    DownloadIcon,
    EyeOpenIcon,
    ActivityLogIcon,
} from '@radix-ui/react-icons';

const RequestLogs = ({ title }) => {
    const isMobile = useMediaQuery('(max-width: 640px)');
    const isTablet = useMediaQuery('(max-width: 1024px)');

    const [logs, setLogs] = useState([]);
    const [loading, setLoading] = useState(false);
    const [selectedLogs, setSelectedLogs] = useState(new Set());
    const [showDetails, setShowDetails] = useState(null);
    const [confirmClearAll, setConfirmClearAll] = useState(false);

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
    }, []);

    const handleFilterChange = (key, value) => {
        setFilters(prev => ({ ...prev, [key]: value }));
    };

    const applyFilters = () => loadLogs(1);

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
        if (!confirm('Delete this log?')) return;
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
        if (!confirm(`Delete ${selectedLogs.size} selected logs?`)) return;
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

    const getMethodColor = (method) => {
        const colors = {
            GET: 'blue',
            POST: 'green',
            PUT: 'amber',
            PATCH: 'orange',
            DELETE: 'red',
        };
        return colors[method] || 'gray';
    };

    const totalPages = Math.ceil(pagination.total / pagination.per_page);
    const from = ((pagination.current_page - 1) * pagination.per_page) + 1;
    const to = Math.min(pagination.current_page * pagination.per_page, pagination.total);

    return (
        <>
            <Head title={title} />
            <App>
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
                                            <ActivityLogIcon
                                                width={isMobile ? 24 : 32}
                                                height={isMobile ? 24 : 32}
                                                color="var(--accent-9)"
                                            />
                                        </Box>
                                        <Box>
                                            <Text
                                                size={{ initial: '4', sm: '5', md: '6' }}
                                                weight="bold"
                                                as="div"
                                            >
                                                Request Logs
                                            </Text>
                                            <Text
                                                size={{ initial: '1', md: '2' }}
                                                color="gray"
                                                as="div"
                                            >
                                                View and manage all HTTP request logs
                                                {pagination.total > 0 && ` · ${pagination.total.toLocaleString()} total`}
                                            </Text>
                                        </Box>
                                    </Flex>

                                    {/* Actions */}
                                    <Flex align="center" gap="3" wrap="wrap">
                                        <Button size={{ initial: '1', md: '2' }} variant="soft" color="green" onClick={exportLogs}>
                                            <DownloadIcon width={16} height={16} />
                                            {!isMobile && 'Export'}
                                        </Button>
                                        <Button
                                            size={{ initial: '1', md: '2' }}
                                            variant="soft"
                                            color="blue"
                                            onClick={() => loadLogs(pagination.current_page)}
                                            disabled={loading}
                                        >
                                            <ReloadIcon
                                                width={16}
                                                height={16}
                                                style={loading ? { animation: 'spin 1s linear infinite' } : {}}
                                            />
                                            {!isMobile && 'Refresh'}
                                        </Button>
                                        {selectedLogs.size > 0 && (
                                            <Button size={{ initial: '1', md: '2' }} variant="solid" color="red" onClick={bulkDelete}>
                                                <TrashIcon width={16} height={16} />
                                                Delete ({selectedLogs.size})
                                            </Button>
                                        )}
                                        <Button size={{ initial: '1', md: '2' }} variant="soft" color="red" onClick={() => setConfirmClearAll(true)}>
                                            <TrashIcon width={16} height={16} />
                                            {!isMobile && 'Clear All'}
                                        </Button>
                                    </Flex>
                                </Flex>
                            </Box>

                            <Separator size="4" mb="4" />

                            {/* ── Filters ── */}
                            <Box
                                p="3"
                                style={{
                                    background: 'var(--gray-a2)',
                                    borderRadius: 'var(--radius-3)',
                                    border: '1px solid var(--gray-a5)',
                                }}
                            >
                                <Flex gap="2" wrap="wrap" align="end">
                                    {/* Search */}
                                    <Box style={{ flex: '1 1 200px', minWidth: 180 }}>
                                        <Text size="1" color="gray" mb="1" as="div">Search URL</Text>
                                        <TextField.Root
                                            placeholder="Search URL or path..."
                                            value={filters.search}
                                            onChange={e => handleFilterChange('search', e.target.value)}
                                            onKeyDown={e => e.key === 'Enter' && applyFilters()}
                                        >
                                            <TextField.Slot>
                                                <MagnifyingGlassIcon width={14} height={14} />
                                            </TextField.Slot>
                                        </TextField.Root>
                                    </Box>

                                    {/* IP Address */}
                                    <Box style={{ flex: '1 1 140px', minWidth: 130 }}>
                                        <Text size="1" color="gray" mb="1" as="div">IP Address</Text>
                                        <TextField.Root
                                            placeholder="e.g. 192.168.1.1"
                                            value={filters.ip_address}
                                            onChange={e => handleFilterChange('ip_address', e.target.value)}
                                            onKeyDown={e => e.key === 'Enter' && applyFilters()}
                                        />
                                    </Box>

                                    {/* Method */}
                                    <Box style={{ flex: '0 1 130px', minWidth: 120 }}>
                                        <Text size="1" color="gray" mb="1" as="div">Method</Text>
                                        <Select.Root
                                            value={filters.method || 'all'}
                                            onValueChange={v => handleFilterChange('method', v === 'all' ? '' : v)}
                                        >
                                            <Select.Trigger placeholder="All Methods" style={{ width: '100%' }} />
                                            <Select.Content>
                                                <Select.Item value="all">All Methods</Select.Item>
                                                <Select.Separator />
                                                <Select.Item value="GET">GET</Select.Item>
                                                <Select.Item value="POST">POST</Select.Item>
                                                <Select.Item value="PUT">PUT</Select.Item>
                                                <Select.Item value="PATCH">PATCH</Select.Item>
                                                <Select.Item value="DELETE">DELETE</Select.Item>
                                            </Select.Content>
                                        </Select.Root>
                                    </Box>

                                    {/* Status */}
                                    <Box style={{ flex: '0 1 150px', minWidth: 140 }}>
                                        <Text size="1" color="gray" mb="1" as="div">Status</Text>
                                        <Select.Root
                                            value={filters.status || 'all'}
                                            onValueChange={v => handleFilterChange('status', v === 'all' ? '' : v)}
                                        >
                                            <Select.Trigger placeholder="All Status" style={{ width: '100%' }} />
                                            <Select.Content>
                                                <Select.Item value="all">All Status</Select.Item>
                                                <Select.Separator />
                                                <Select.Item value="200">200 OK</Select.Item>
                                                <Select.Item value="201">201 Created</Select.Item>
                                                <Select.Item value="204">204 No Content</Select.Item>
                                                <Select.Separator />
                                                <Select.Item value="400">400 Bad Request</Select.Item>
                                                <Select.Item value="401">401 Unauthorized</Select.Item>
                                                <Select.Item value="403">403 Forbidden</Select.Item>
                                                <Select.Item value="404">404 Not Found</Select.Item>
                                                <Select.Separator />
                                                <Select.Item value="500">500 Server Error</Select.Item>
                                            </Select.Content>
                                        </Select.Root>
                                    </Box>

                                    {/* Start Date */}
                                    <Box style={{ flex: '0 1 160px', minWidth: 150 }}>
                                        <Text size="1" color="gray" mb="1" as="div">From Date</Text>
                                        <TextField.Root
                                            type="date"
                                            value={filters.start_date}
                                            onChange={e => handleFilterChange('start_date', e.target.value)}
                                        />
                                    </Box>

                                    {/* End Date */}
                                    <Box style={{ flex: '0 1 160px', minWidth: 150 }}>
                                        <Text size="1" color="gray" mb="1" as="div">To Date</Text>
                                        <TextField.Root
                                            type="date"
                                            value={filters.end_date}
                                            onChange={e => handleFilterChange('end_date', e.target.value)}
                                        />
                                    </Box>

                                    {/* Filter Actions */}
                                    <Flex gap="2" align="end">
                                        <Button size="2" variant="solid" color="indigo" onClick={applyFilters}>
                                            <MagnifyingGlassIcon width={15} height={15} />
                                            Apply
                                        </Button>
                                        <Button size="2" variant="soft" color="gray" onClick={resetFilters}>
                                            <Cross2Icon width={15} height={15} />
                                            Reset
                                        </Button>
                                    </Flex>
                                </Flex>
                            </Box>

                            <Separator size="4" />

                            {/* ── Table ── */}
                            {loading ? (
                                <Flex justify="center" align="center" py="9" gap="3">
                                    <Spinner size="3" />
                                    <Text size="2" color="gray">Loading logs...</Text>
                                </Flex>
                            ) : logs.length === 0 ? (
                                <Flex
                                    direction="column"
                                    justify="center"
                                    align="center"
                                    py="9"
                                    gap="2"
                                >
                                    <Text size="4">🪵</Text>
                                    <Text size="3" color="gray" weight="medium">No logs found</Text>
                                    <Text size="2" color="gray">Try adjusting your filters or refreshing.</Text>
                                </Flex>
                            ) : (
                                <ScrollArea scrollbars="horizontal">
                                    <Table.Root variant="surface" style={{ minWidth: 700 }}>
                                        <Table.Header>
                                            <Table.Row>
                                                <Table.ColumnHeaderCell style={{ width: 36, paddingRight: 0 }}>
                                                    <input
                                                        type="checkbox"
                                                        checked={selectedLogs.size > 0 && selectedLogs.size === logs.length}
                                                        ref={el => {
                                                            if (el) el.indeterminate = selectedLogs.size > 0 && selectedLogs.size < logs.length;
                                                        }}
                                                        onChange={toggleAllSelection}
                                                        style={{ cursor: 'pointer' }}
                                                    />
                                                </Table.ColumnHeaderCell>
                                                <Table.ColumnHeaderCell style={{ whiteSpace: 'nowrap' }}>IP Address</Table.ColumnHeaderCell>
                                                <Table.ColumnHeaderCell style={{ width: 80 }}>Method</Table.ColumnHeaderCell>
                                                <Table.ColumnHeaderCell>URL</Table.ColumnHeaderCell>
                                                <Table.ColumnHeaderCell style={{ width: 80 }}>Status</Table.ColumnHeaderCell>
                                                {!isTablet && <Table.ColumnHeaderCell>User</Table.ColumnHeaderCell>}
                                                <Table.ColumnHeaderCell style={{ width: 90, whiteSpace: 'nowrap' }}>Duration</Table.ColumnHeaderCell>
                                                {!isMobile && <Table.ColumnHeaderCell style={{ whiteSpace: 'nowrap' }}>Time</Table.ColumnHeaderCell>}
                                                <Table.ColumnHeaderCell style={{ width: 72 }}>Actions</Table.ColumnHeaderCell>
                                            </Table.Row>
                                        </Table.Header>
                                        <Table.Body>
                                            {logs.map(log => (
                                                <Table.Row
                                                    key={log.id}
                                                    style={{
                                                        background: selectedLogs.has(log.id)
                                                            ? 'var(--accent-a3)'
                                                            : undefined,
                                                    }}
                                                >
                                                    <Table.Cell style={{ paddingRight: 0 }}>
                                                        <input
                                                            type="checkbox"
                                                            checked={selectedLogs.has(log.id)}
                                                            onChange={() => toggleLogSelection(log.id)}
                                                            style={{ cursor: 'pointer' }}
                                                        />
                                                    </Table.Cell>
                                                    <Table.Cell>
                                                        <Text size="1" style={{ fontFamily: 'monospace' }}>
                                                            {log.ip_address || '—'}
                                                        </Text>
                                                    </Table.Cell>
                                                    <Table.Cell>
                                                        <Badge
                                                            color={getMethodColor(log.method)}
                                                            variant="soft"
                                                            size="1"
                                                            style={{ fontFamily: 'monospace', fontWeight: 700 }}
                                                        >
                                                            {log.method}
                                                        </Badge>
                                                    </Table.Cell>
                                                    <Table.Cell>
                                                        <Text
                                                            size="1"
                                                            style={{
                                                                wordBreak: 'break-all',
                                                                maxWidth: isMobile ? 160 : isTablet ? 220 : 340,
                                                                display: 'block',
                                                                fontFamily: 'monospace',
                                                            }}
                                                        >
                                                            {log.url}
                                                        </Text>
                                                    </Table.Cell>
                                                    <Table.Cell>
                                                        <Badge
                                                            color={getStatusColor(log.response_status)}
                                                            variant="soft"
                                                            size="1"
                                                            style={{ fontFamily: 'monospace' }}
                                                        >
                                                            {log.response_status}
                                                        </Badge>
                                                    </Table.Cell>
                                                    {!isTablet && (
                                                        <Table.Cell>
                                                            <Text size="1">{log.user?.name || 'Guest'}</Text>
                                                        </Table.Cell>
                                                    )}
                                                    <Table.Cell>
                                                        <Text
                                                            size="1"
                                                            color={log.duration_ms > 1000 ? 'red' : log.duration_ms > 300 ? 'amber' : 'gray'}
                                                            style={{ fontFamily: 'monospace' }}
                                                        >
                                                            {log.duration_ms}ms
                                                        </Text>
                                                    </Table.Cell>
                                                    {!isMobile && (
                                                        <Table.Cell>
                                                            <Text size="1" color="gray" style={{ whiteSpace: 'nowrap' }}>
                                                                {new Date(log.created_at).toLocaleString()}
                                                            </Text>
                                                        </Table.Cell>
                                                    )}
                                                    <Table.Cell>
                                                        <Flex gap="1">
                                                            <Tooltip content="View details">
                                                                <IconButton
                                                                    size="1"
                                                                    variant="ghost"
                                                                    color="blue"
                                                                    onClick={() => viewDetails(log.id)}
                                                                >
                                                                    <EyeOpenIcon width={14} height={14} />
                                                                </IconButton>
                                                            </Tooltip>
                                                            <Tooltip content="Delete log">
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
                                </ScrollArea>
                            )}

                            {/* ── Pagination ── */}
                            {pagination.total > 0 && (
                                <Flex
                                    justify="between"
                                    align="center"
                                    gap="3"
                                    direction={{ initial: 'column', sm: 'row' }}
                                >
                                    <Text size="1" color="gray">
                                        Showing {from.toLocaleString()}–{to.toLocaleString()} of {pagination.total.toLocaleString()} logs
                                        {selectedLogs.size > 0 && (
                                            <Text as="span" color="indigo"> · {selectedLogs.size} selected</Text>
                                        )}
                                    </Text>
                                    <Flex gap="2" align="center">
                                        <Button
                                            size="1"
                                            variant="soft"
                                            disabled={pagination.current_page === 1}
                                            onClick={() => loadLogs(1)}
                                        >
                                            «
                                        </Button>
                                        <Button
                                            size="1"
                                            variant="soft"
                                            disabled={pagination.current_page === 1}
                                            onClick={() => loadLogs(pagination.current_page - 1)}
                                        >
                                            ‹ {!isMobile && 'Prev'}
                                        </Button>
                                        <Text size="1" color="gray" px="2">
                                            Page {pagination.current_page} of {totalPages}
                                        </Text>
                                        <Button
                                            size="1"
                                            variant="soft"
                                            disabled={pagination.current_page >= totalPages}
                                            onClick={() => loadLogs(pagination.current_page + 1)}
                                        >
                                            {!isMobile && 'Next'} ›
                                        </Button>
                                        <Button
                                            size="1"
                                            variant="soft"
                                            disabled={pagination.current_page >= totalPages}
                                            onClick={() => loadLogs(totalPages)}
                                        >
                                            »
                                        </Button>
                                    </Flex>
                                </Flex>
                            )}
                        </Card>
                    </Box>
                </Flex>

                {/* ── Details Dialog ── */}
                <Dialog.Root open={!!showDetails} onOpenChange={() => setShowDetails(null)}>
                    <Dialog.Content style={{ maxWidth: 760 }}>
                        <Dialog.Title>Request Log Details</Dialog.Title>
                        <Dialog.Description size="2" mb="4" color="gray">
                            Full request and response information
                        </Dialog.Description>

                        {showDetails && (
                            <ScrollArea style={{ maxHeight: '65vh' }}>
                                <Flex direction="column" gap="4" pr="2">

                                    {/* Meta row */}
                                    <Flex gap="3" wrap="wrap">
                                        <Box>
                                            <Text size="1" color="gray" as="div" mb="1">Method</Text>
                                            <Badge
                                                color={getMethodColor(showDetails.method)}
                                                variant="soft"
                                                size="2"
                                                style={{ fontFamily: 'monospace', fontWeight: 700 }}
                                            >
                                                {showDetails.method}
                                            </Badge>
                                        </Box>
                                        <Box>
                                            <Text size="1" color="gray" as="div" mb="1">Status</Text>
                                            <Badge
                                                color={getStatusColor(showDetails.response_status)}
                                                variant="soft"
                                                size="2"
                                                style={{ fontFamily: 'monospace' }}
                                            >
                                                {showDetails.response_status}
                                            </Badge>
                                        </Box>
                                        <Box>
                                            <Text size="1" color="gray" as="div" mb="1">Duration</Text>
                                            <Text size="2" style={{ fontFamily: 'monospace' }}>
                                                {showDetails.duration_ms}ms
                                            </Text>
                                        </Box>
                                        <Box>
                                            <Text size="1" color="gray" as="div" mb="1">IP Address</Text>
                                            <Text size="2" style={{ fontFamily: 'monospace' }}>
                                                {showDetails.ip_address}
                                            </Text>
                                        </Box>
                                        {showDetails.user && (
                                            <Box>
                                                <Text size="1" color="gray" as="div" mb="1">User</Text>
                                                <Text size="2">{showDetails.user.name}</Text>
                                            </Box>
                                        )}
                                    </Flex>

                                    <Separator size="4" />

                                    {/* URL */}
                                    <Box>
                                        <Text size="1" color="gray" as="div" mb="1" weight="medium">URL</Text>
                                        <Code
                                            size="2"
                                            style={{
                                                display: 'block',
                                                wordBreak: 'break-all',
                                                whiteSpace: 'pre-wrap',
                                                padding: '8px 10px',
                                            }}
                                        >
                                            {showDetails.url}
                                        </Code>
                                    </Box>

                                    {/* User Agent */}
                                    {showDetails.user_agent && (
                                        <Box>
                                            <Text size="1" color="gray" as="div" mb="1" weight="medium">User Agent</Text>
                                            <Code
                                                size="1"
                                                style={{
                                                    display: 'block',
                                                    wordBreak: 'break-all',
                                                    whiteSpace: 'pre-wrap',
                                                    padding: '8px 10px',
                                                }}
                                            >
                                                {showDetails.user_agent}
                                            </Code>
                                        </Box>
                                    )}

                                    {/* Headers */}
                                    {showDetails.headers && (
                                        <Box>
                                            <Text size="1" color="gray" as="div" mb="1" weight="medium">Request Headers</Text>
                                            <Code
                                                size="1"
                                                style={{
                                                    display: 'block',
                                                    whiteSpace: 'pre-wrap',
                                                    wordBreak: 'break-word',
                                                    padding: '8px 10px',
                                                    maxHeight: 180,
                                                    overflow: 'auto',
                                                }}
                                            >
                                                {JSON.stringify(showDetails.headers, null, 2)}
                                            </Code>
                                        </Box>
                                    )}

                                    {/* Request Body */}
                                    {showDetails.request_body && (
                                        <Box>
                                            <Text size="1" color="gray" as="div" mb="1" weight="medium">Request Body</Text>
                                            <Code
                                                size="1"
                                                style={{
                                                    display: 'block',
                                                    whiteSpace: 'pre-wrap',
                                                    wordBreak: 'break-word',
                                                    padding: '8px 10px',
                                                    maxHeight: 180,
                                                    overflow: 'auto',
                                                }}
                                            >
                                                {typeof showDetails.request_body === 'string'
                                                    ? showDetails.request_body
                                                    : JSON.stringify(showDetails.request_body, null, 2)}
                                            </Code>
                                        </Box>
                                    )}

                                    {/* Response Body */}
                                    {showDetails.response_body && (
                                        <Box>
                                            <Text size="1" color="gray" as="div" mb="1" weight="medium">Response Body</Text>
                                            <Code
                                                size="1"
                                                style={{
                                                    display: 'block',
                                                    whiteSpace: 'pre-wrap',
                                                    wordBreak: 'break-word',
                                                    padding: '8px 10px',
                                                    maxHeight: 200,
                                                    overflow: 'auto',
                                                }}
                                            >
                                                {showDetails.response_body}
                                            </Code>
                                        </Box>
                                    )}
                                </Flex>
                            </ScrollArea>
                        )}

                        <Flex gap="3" mt="4" justify="end">
                            <Dialog.Close>
                                <Button variant="soft" color="gray">Close</Button>
                            </Dialog.Close>
                        </Flex>
                    </Dialog.Content>
                </Dialog.Root>

                {/* ── Clear All Confirmation Dialog ── */}
                <Dialog.Root open={confirmClearAll} onOpenChange={setConfirmClearAll}>
                    <Dialog.Content style={{ maxWidth: 420 }}>
                        <Dialog.Title>Clear All Logs</Dialog.Title>
                        <Dialog.Description size="2" color="gray">
                            Are you sure you want to permanently delete <Text weight="bold" color="red">all</Text> request
                            logs? This action cannot be undone.
                        </Dialog.Description>
                        <Flex gap="3" mt="5" justify="end">
                            <Dialog.Close>
                                <Button variant="soft" color="gray">Cancel</Button>
                            </Dialog.Close>
                            <Button color="red" variant="solid" onClick={clearAllLogs}>
                                <TrashIcon width={14} height={14} />
                                Delete All Logs
                            </Button>
                        </Flex>
                    </Dialog.Content>
                </Dialog.Root>

                <style>{`
                    @keyframes spin {
                        from { transform: rotate(0deg); }
                        to { transform: rotate(360deg); }
                    }
                `}</style>
            </App>
        </>
    );
};

export default RequestLogs;