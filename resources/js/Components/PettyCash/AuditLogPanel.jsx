import { Panel } from '@/Components/ui/Panel';
/**
 * AuditLogPanel.jsx
 * Timeline-style audit trail for petty cash actions.
 */
import React, { useState, useEffect } from 'react';
import { Box, Flex, Text, Badge, Button, Separator } from '@radix-ui/themes';
import { ActivityLogIcon } from '@radix-ui/react-icons';
import axios from 'axios';

const ACTION_COLORS = {
    created: 'green',
    updated: 'blue',
    deleted: 'red',
    approved: 'green',
    rejected: 'red',
    closed: 'gray',
};

const ACTION_LABELS = {
    created: 'Created',
    updated: 'Updated',
    deleted: 'Deleted',
    approved: 'Approved',
    rejected: 'Rejected',
    closed: 'Closed',
};

const AuditLogPanel = ({ loanId, isMobile }) => {
    const [logs, setLogs] = useState([]);
    const [loading, setLoading] = useState(true);
    const [page, setPage] = useState(1);
    const [totalPages, setTotalPages] = useState(1);
    const [expandedLog, setExpandedLog] = useState(null);

    useEffect(() => {
        if (!loanId) return;
        const fetchLogs = async () => {
            try {
                setLoading(true);
                const response = await axios.get('/petty-cash/audit-log', {
                    params: { loan_id: loanId, page, per_page: 20 }
                });
                if (response.data.success) {
                    setLogs(response.data.audit_log.data);
                    setTotalPages(response.data.audit_log.last_page);
                }
            } catch (error) {
                console.error('Failed to fetch audit log:', error);
            } finally {
                setLoading(false);
            }
        };
        fetchLogs();
    }, [loanId, page]);

    const formatDate = (dateStr) => {
        if (!dateStr) return '';
        const d = new Date(dateStr);
        return d.toLocaleDateString() + ' ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    };

    const renderChanges = (log) => {
        if (!log.old_values && !log.new_values) return null;

        return (
            <Box mt="2" p="2" style={{
                background: 'var(--gray-a2)',
                borderRadius: 'var(--radius-2)',
                fontSize: '12px',
                fontFamily: 'monospace',
                maxHeight: '200px',
                overflow: 'auto'
            }}>
                {log.old_values && (
                    <Box mb="1">
                        <Text size="1" weight="bold" color="red">Old:</Text>
                        <pre style={{ margin: 0, whiteSpace: 'pre-wrap', wordBreak: 'break-all' }}>
                            {JSON.stringify(log.old_values, null, 2)}
                        </pre>
                    </Box>
                )}
                {log.new_values && (
                    <Box>
                        <Text size="1" weight="bold" color="green">New:</Text>
                        <pre style={{ margin: 0, whiteSpace: 'pre-wrap', wordBreak: 'break-all' }}>
                            {JSON.stringify(log.new_values, null, 2)}
                        </pre>
                    </Box>
                )}
            </Box>
        );
    };

    if (loading) {
        return (
            <Box p="6" style={{ textAlign: 'center' }}>
                <Text color="gray">Loading audit log...</Text>
            </Box>
        );
    }

    if (logs.length === 0) {
        return (
            <Box p="6" style={{ textAlign: 'center' }}>
                <ActivityLogIcon style={{ width: 48, height: 48, color: 'var(--gray-7)', marginBottom: '12px' }} />
                <Text size="3" color="gray" as="div">No audit entries yet</Text>
                <Text size="2" color="gray" as="div">Actions on this fund will be logged here.</Text>
            </Box>
        );
    }

    return (
        <Box>
            <Flex align="center" gap="2" mb="4">
                <ActivityLogIcon style={{ width: 20, height: 20, color: 'var(--gray-9)' }} />
                <Text size="2" weight="bold" color="gray">ACTIVITY LOG</Text>
                <Badge variant="soft" size="1">{logs.length} entries</Badge>
            </Flex>

            <Flex direction="column" gap="2">
                {logs.map((log) => (
                    <Panel
                        key={log.id}
                        style={{
                            padding: '12px 16px',
                            cursor: 'pointer',
                            border: expandedLog === log.id ? '1px solid var(--accent-a6)' : '1px solid transparent',
                        }}
                        onClick={() => setExpandedLog(expandedLog === log.id ? null : log.id)}
                    >
                        <Flex justify="between" align="center" wrap="wrap" gap="2">
                            <Flex align="center" gap="2">
                                <Badge color={ACTION_COLORS[log.action] || 'gray'} variant="soft" size="1">
                                    {ACTION_LABELS[log.action] || log.action}
                                </Badge>
                                <Text size="2">
                                    <strong>{log.user_name}</strong>
                                    {' '}{log.action}{' '}
                                    <Badge variant="outline" size="1">{log.entity_type}</Badge>
                                    {' '}#{log.entity_id}
                                </Text>
                            </Flex>
                            <Text size="1" color="gray">
                                {formatDate(log.created_at)}
                            </Text>
                        </Flex>

                        {expandedLog === log.id && renderChanges(log)}
                    </Panel>
                ))}
            </Flex>

            {/* Pagination */}
            {totalPages > 1 && (
                <Flex justify="center" gap="2" mt="4">
                    <Button variant="soft" size="1" disabled={page === 1} onClick={() => setPage(p => p - 1)}>
                        Previous
                    </Button>
                    <Text size="2" color="gray" style={{ display: 'flex', alignItems: 'center' }}>
                        Page {page} of {totalPages}
                    </Text>
                    <Button variant="soft" size="1" disabled={page === totalPages} onClick={() => setPage(p => p + 1)}>
                        Next
                    </Button>
                </Flex>
            )}
        </Box>
    );
};

export default AuditLogPanel;
