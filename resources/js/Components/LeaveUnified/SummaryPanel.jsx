/**
 * SummaryPanel.jsx
 * "Summary" tab — per-employee pivot + per-department breakdown.
 * Migrated from LeaveSummary.jsx (was HeroUI + motion) → pure Radix UI.
 */
import React, { useState, useCallback, useMemo, useEffect } from 'react';
import axios from 'axios';
import {
    Badge, Box, Button, Card, Flex, Grid,
    IconButton, Select, Separator, Spinner, Table,
    Tabs, Text, TextField,
} from '@radix-ui/themes';
import {
    BarChartIcon, HomeIcon, CalendarIcon,
    DownloadIcon, MagnifyingGlassIcon, PersonIcon,
    ReloadIcon,
} from '@radix-ui/react-icons';
import { showToast } from '@/utils/toastUtils';

/* ── helpers ── */
function StatPill({ label, value, color = 'gray' }) {
    return (
        <Badge size="2" variant="soft" color={color} radius="full">
            <Text weight="bold">{value}</Text>
            <Text style={{ opacity: 0.7 }}> {label}</Text>
        </Badge>
    );
}

function statusColor(status) {
    return {
        approved: 'green', pending: 'amber', rejected: 'red',
    }[status] || 'gray';
}

export default function SummaryPanel({ summaryData, isMobile, isActive, onSetHeaderActions }) {
    const {
        users = [], departments = [], leave_types = [],
        columns = [], data = [], department_summary = [],
        stats = {}, year: initialYear = new Date().getFullYear(),
    } = summaryData || {};

    const [subTab,      setSubTab]      = useState('employee');
    const [loading,     setLoading]     = useState(false);
    const [downloading, setDownloading] = useState('');
    const [currentYear, setCurrentYear] = useState(initialYear);
    const [deptId,      setDeptId]      = useState('');
    const [searchVal,   setSearchVal]   = useState('');

    /* ── live filter on client data ── */
    const filteredEmployeeData = useMemo(() => {
        if (!searchVal) return data;
        return data.filter(row =>
            row.employee_name?.toLowerCase().includes(searchVal.toLowerCase()) ||
            row.department?.toLowerCase().includes(searchVal.toLowerCase())
        );
    }, [data, searchVal]);

    /* ── download helpers ── */
    const download = async (type) => {
        setDownloading(type);
        try {
            const res = await axios.get(route('leaves.summary.export'), {
                params: { year: currentYear, department_id: deptId || undefined, format: type },
                responseType: 'blob',
            });
            const url  = URL.createObjectURL(new Blob([res.data]));
            const link = document.createElement('a');
            link.href  = url;
            link.download = `leave-summary-${currentYear}.${type === 'excel' ? 'xlsx' : 'pdf'}`;
            link.click();
            URL.revokeObjectURL(url);
        } catch { showToast.error('Download failed.'); }
        finally { setDownloading(''); }
    };

    /* ── header actions ── */
    useEffect(() => {
        if (!isActive) return;
        onSetHeaderActions?.(
            <Flex gap="2" wrap="wrap">
                <Button size="2" variant="soft" color="green"
                    onClick={() => download('excel')}
                    disabled={!!downloading}>
                    <DownloadIcon /> {!isMobile && 'Excel'}
                </Button>
                <Button size="2" variant="soft" color="red"
                    onClick={() => download('pdf')}
                    disabled={!!downloading}>
                    <DownloadIcon /> {!isMobile && 'PDF'}
                </Button>
            </Flex>
        );
    }, [isActive, isMobile, downloading, currentYear, deptId]);

    /* ── render ── */
    return (
        <Box>
            {/* ── Top stats ── */}
            <Flex wrap="wrap" gap="2" mb="4">
                <StatPill label="Total Leaves"   value={stats.total_leaves ?? 0}    color="blue"   />
                <StatPill label="Approved"        value={stats.total_approved ?? 0}  color="green"  />
                <StatPill label="Pending"         value={stats.total_pending ?? 0}   color="amber"  />
                <StatPill label="Employees"       value={stats.total_employees ?? 0} color="violet" />
                <StatPill label="Avg per Employee"value={stats.avg_per_employee ?? 0}color="teal"   />
            </Flex>

            {/* ── Filter bar ── */}
            <Flex direction={{ initial: 'column', sm: 'row' }} gap="3" align={{ initial: 'stretch', sm: 'center' }} mb="4">
                {/* Year */}
                <Select.Root size="2" value={String(currentYear)}
                    onValueChange={v => setCurrentYear(Number(v))}>
                    <Select.Trigger style={{ minWidth: 100 }} />
                    <Select.Content>
                        {Array.from({ length: 5 }, (_, i) => new Date().getFullYear() - i).map(y => (
                            <Select.Item key={y} value={String(y)}>{y}</Select.Item>
                        ))}
                    </Select.Content>
                </Select.Root>

                {/* Department */}
                <Select.Root size="2" value={deptId || 'all'}
                    onValueChange={v => setDeptId(v === 'all' ? '' : v)}>
                    <Select.Trigger style={{ minWidth: 160 }} placeholder="All Departments" />
                    <Select.Content>
                        <Select.Item value="all">All Departments</Select.Item>
                        {departments.map(d => (
                            <Select.Item key={d.id} value={String(d.id)}>{d.name}</Select.Item>
                        ))}
                    </Select.Content>
                </Select.Root>

                {/* Search */}
                <Box style={{ flex: 1, minWidth: 180 }}>
                    <TextField.Root size="2"
                        placeholder="Search employee or department…"
                        onChange={e => setSearchVal(e.target.value)}>
                        <TextField.Slot><MagnifyingGlassIcon /></TextField.Slot>
                    </TextField.Root>
                </Box>
            </Flex>

            {/* ── Sub-tabs ── */}
            <Tabs.Root value={subTab} onValueChange={setSubTab}>
                <Tabs.List mb="4">
                    <Tabs.Trigger value="employee">
                        <Flex align="center" gap="2"><PersonIcon /> {!isMobile && 'Employee View'}</Flex>
                    </Tabs.Trigger>
                    <Tabs.Trigger value="department">
                        <Flex align="center" gap="2"><HomeIcon style={{ width: 14, height: 14 }} /> {!isMobile && 'Department View'}</Flex>
                    </Tabs.Trigger>
                </Tabs.List>

                {/* ── Employee pivot table ── */}
                <Tabs.Content value="employee">
                    {filteredEmployeeData.length === 0 ? (
                        <Flex direction="column" align="center" py="9" gap="2">
                            <PersonIcon style={{ width: 40, height: 40, color: 'var(--gray-9)' }} />
                            <Text size="3" weight="medium">No employee leave data</Text>
                            <Text size="2" color="gray">Try adjusting your filters.</Text>
                        </Flex>
                    ) : (
                        <Box style={{ overflowX: 'auto' }}>
                            <Table.Root variant="surface">
                                <Table.Header>
                                    <Table.Row>
                                        <Table.ColumnHeaderCell>Employee</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell>Department</Table.ColumnHeaderCell>
                                        {columns.map(col => (
                                            <Table.ColumnHeaderCell key={col.key} style={{ textAlign: 'center', minWidth: 80 }}>
                                                {col.label}
                                            </Table.ColumnHeaderCell>
                                        ))}
                                        <Table.ColumnHeaderCell style={{ textAlign: 'center' }}>Total</Table.ColumnHeaderCell>
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    {filteredEmployeeData.map((row, i) => (
                                        <Table.Row key={i}>
                                            <Table.Cell>
                                                <Text weight="medium" size="2">{row.employee_name}</Text>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Text size="2" color="gray">{row.department || '—'}</Text>
                                            </Table.Cell>
                                            {columns.map(col => {
                                                const cell = row[col.key];
                                                return (
                                                    <Table.Cell key={col.key} style={{ textAlign: 'center' }}>
                                                        {cell != null && cell !== 0
                                                            ? <Badge size="1" variant="soft"
                                                                color={statusColor(col.status)}>{cell}</Badge>
                                                            : <Text size="1" color="gray">—</Text>}
                                                    </Table.Cell>
                                                );
                                            })}
                                            <Table.Cell style={{ textAlign: 'center' }}>
                                                <Badge size="1" variant="solid" color="blue">{row.total ?? 0}</Badge>
                                            </Table.Cell>
                                        </Table.Row>
                                    ))}
                                </Table.Body>
                            </Table.Root>
                        </Box>
                    )}
                </Tabs.Content>

                {/* ── Department breakdown ── */}
                <Tabs.Content value="department">
                    {department_summary.length === 0 ? (
                        <Flex direction="column" align="center" py="9" gap="2">
                            <HomeIcon style={{ width: 40, height: 40, color: 'var(--gray-9)' }} />
                            <Text size="3" weight="medium">No department data</Text>
                        </Flex>
                    ) : (
                        <Box style={{ overflowX: 'auto' }}>
                            <Table.Root variant="surface">
                                <Table.Header>
                                    <Table.Row>
                                        <Table.ColumnHeaderCell>Department</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell style={{ textAlign: 'center' }}>Employees</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell style={{ textAlign: 'center' }}>Total</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell style={{ textAlign: 'center' }}>Approved</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell style={{ textAlign: 'center' }}>Pending</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell style={{ textAlign: 'center' }}>Avg / Employee</Table.ColumnHeaderCell>
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    {department_summary.map((dept, i) => (
                                        <Table.Row key={i}>
                                            <Table.Cell>
                                                <Flex align="center" gap="2">
                                                    <HomeIcon style={{ width: 14, height: 14, color: 'var(--gray-9)', flexShrink: 0 }} />
                                                    <Text weight="medium" size="2">{dept.department}</Text>
                                                </Flex>
                                            </Table.Cell>
                                            <Table.Cell style={{ textAlign: 'center' }}>
                                                <Badge size="1" variant="soft" color="gray">{dept.employee_count}</Badge>
                                            </Table.Cell>
                                            <Table.Cell style={{ textAlign: 'center' }}>
                                                <Text size="2" weight="medium">{dept.total_leaves}</Text>
                                            </Table.Cell>
                                            <Table.Cell style={{ textAlign: 'center' }}>
                                                <Badge size="1" variant="soft" color="green">{dept.total_approved}</Badge>
                                            </Table.Cell>
                                            <Table.Cell style={{ textAlign: 'center' }}>
                                                <Badge size="1" variant="soft" color="amber">{dept.total_pending}</Badge>
                                            </Table.Cell>
                                            <Table.Cell style={{ textAlign: 'center' }}>
                                                <Text size="2" color="gray">{dept.avg_leaves_per_employee}</Text>
                                            </Table.Cell>
                                        </Table.Row>
                                    ))}
                                </Table.Body>
                            </Table.Root>
                        </Box>
                    )}
                </Tabs.Content>
            </Tabs.Root>
        </Box>
    );
}
