import { Panel } from '@/Components/ui/Panel';
/**
 * SummaryPanel.jsx
 * "Summary" tab — per-employee pivot + per-department breakdown.
 * * UX Improvements added:
 * - Aligned StatPill design with AdminLeavesPanel (Cards + Skeletons).
 * - Horizontal ScrollAreas for Stats, Tabs, and Tables to guarantee mobile responsiveness.
 * - Loading spinners injected into export buttons for immediate feedback.
 */
import React, { useState, useCallback, useMemo, useEffect, useRef } from 'react';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { Badge, Box, Button, Flex, Select, Spinner, Table, Tabs, Text, TextField, ScrollArea, Skeleton } from '@radix-ui/themes';
import {
    HomeIcon, CalendarIcon, CheckCircledIcon, ClockIcon,
    DownloadIcon, MagnifyingGlassIcon, PersonIcon,
    TableIcon, BarChartIcon 
} from '@radix-ui/react-icons';
import { showToast } from '@/utils/toastUtils';

/* ── Responsive Stat Pill (Aligned with AdminLeavesPanel) ── */
function StatPill({ label, value, color = 'gray', icon: Icon, loading = false }) {
    return (
        <Panel size="1" style={{ 
            minWidth: '150px', 
            flex: '1 1 auto', 
            background: `linear-gradient(135deg, var(--${color}-a2) 0%, var(--color-surface) 100%)`,
            border: `1px solid var(--${color}-a4)`,
            boxShadow: 'var(--shadow-1)'
        }}>
            <Flex align="center" gap="3" p="1">
                <Box p="2" style={{ backgroundColor: `var(--${color}-a3)`, borderRadius: 'var(--radius-2)', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                    {Icon ? <Icon style={{ color: `var(--${color}-9)`, width: 16, height: 16 }} /> : <TableIcon style={{ color: `var(--${color}-9)`, width: 16, height: 16 }} />}
                </Box>
                <Box>
                    <Skeleton loading={loading}>
                        <Text size="4" weight="bold" style={{ display: 'block', lineHeight: 1, color: 'var(--gray-12)' }}>{value}</Text>
                    </Skeleton>
                    <Text size="1" color="gray" weight="medium" style={{ display: 'block', marginTop: 4 }}>{label}</Text>
                </Box>
            </Flex>
        </Panel>
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
    const [downloading, setDownloading] = useState('');
    const [currentYear, setCurrentYear] = useState(initialYear);
    const [deptId,      setDeptId]      = useState('');
    const [searchVal,   setSearchVal]   = useState('');
    const isFirstRender = useRef(true);

    /* ── Reload summaryData when year or department changes ── */
    useEffect(() => {
        if (isFirstRender.current) { isFirstRender.current = false; return; }
        router.reload({
            data: {
                summary_year: currentYear,
                summary_dept: deptId || undefined,
            },
            only: ['summaryData'],
            preserveState: true,
            preserveScroll: true,
        });
    }, [currentYear, deptId]);

    /* ── Normalise columns: backend sends flat strings, we need {key, label, status} ── */
    const FIXED_COLS = new Set(['employee_name', 'department']);
    const MONTH_KEYS = new Set(['JAN','FEB','MAR','APR','MAY','JUN','JUL','AUG','SEP','OCT','NOV','DEC']);
    const dynamicColumns = useMemo(() => {
        return columns
            .filter(col => {
                const k = typeof col === 'string' ? col : col.key;
                return !FIXED_COLS.has(k);
            })
            .map(col => {
                if (typeof col === 'object' && col.key) return col;
                const key = col;
                const isPending  = key.includes('pending');
                const isRejected = key.includes('rejected') || key.includes('declined');
                const isMonth    = MONTH_KEYS.has(key);
                const label = isMonth
                    ? key
                    : key.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
                const status = isPending ? 'pending' : isRejected ? 'declined' : 'approved';
                return { key, label, status };
            });
    }, [columns]);

    /* ── Normalise stats: backend keys differ from expected keys ── */
    const normStats = useMemo(() => ({
        total_leaves:    stats.total_leaves    ?? stats.total_leaves_taken    ?? 0,
        total_approved:  stats.total_approved  ?? stats.total_approved_leaves ?? 0,
        total_pending:   stats.total_pending   ?? stats.total_pending_leaves  ?? 0,
        total_employees: stats.total_employees ?? 0,
        avg_per_employee: stats.avg_per_employee
            ?? (stats.total_employees > 0
                ? +((stats.total_leaves ?? stats.total_leaves_taken ?? 0) / stats.total_employees).toFixed(1)
                : 0),
    }), [stats]);

    /* ── Live filter on client data ── */
    const filteredEmployeeData = useMemo(() => {
        if (!searchVal) return data;
        return data.filter(row =>
            row.employee_name?.toLowerCase().includes(searchVal.toLowerCase()) ||
            row.department?.toLowerCase().includes(searchVal.toLowerCase())
        );
    }, [data, searchVal]);

    /* ── Download Helpers ── */
    const download = useCallback(async (type) => {
        setDownloading(type);
        const routeName = type === 'excel' ? 'leave.summary.exportExcel' : 'leave.summary.exportPdf';
        try {
            const res = await axios.get(route(routeName), {
                params: { year: currentYear, department_id: deptId || undefined },
                responseType: 'blob',
            });
            const url  = URL.createObjectURL(new Blob([res.data]));
            const link = document.createElement('a');
            link.href  = url;
            link.download = `leave-summary-${currentYear}.${type === 'excel' ? 'xlsx' : 'pdf'}`;
            link.click();
            URL.revokeObjectURL(url);
        } catch { 
            showToast.error(`Failed to download ${type.toUpperCase()} report.`); 
        } finally { 
            setDownloading(''); 
        }
    }, [currentYear, deptId]);

    /* ── Header Actions ── */
    useEffect(() => {
        if (!isActive) return;
        onSetHeaderActions?.(
            <Flex gap="2" wrap="wrap" style={{ width: '100%', justifyContent: isMobile ? 'flex-start' : 'flex-end' }}>
                <Button size="2" variant="soft" color="green"
                    onClick={() => download('excel')}
                    disabled={!!downloading}>
                    {downloading === 'excel' ? <Spinner size="1" /> : <DownloadIcon />} 
                    {!isMobile && 'Export Excel'}
                </Button>
                <Button size="2" variant="soft" color="red"
                    onClick={() => download('pdf')}
                    disabled={!!downloading}>
                    {downloading === 'pdf' ? <Spinner size="1" /> : <DownloadIcon />} 
                    {!isMobile && 'Export PDF'}
                </Button>
            </Flex>
        );
    }, [isActive, isMobile, downloading, download]);

    /* ── Render ── */
    return (
        <Box>
            {/* ── Top Stats (Scrollable on Mobile) ── */}
            <ScrollArea type="auto" scrollbars="horizontal" style={{ width: '100%', marginBottom: '16px' }}>
                <Flex gap="3" style={{ minWidth: '100%', paddingBottom: '4px' }}>
                    <StatPill label="Total Leaves"      value={normStats.total_leaves}    color="blue"   icon={CalendarIcon} />
                    <StatPill label="Approved"          value={normStats.total_approved}  color="green"  icon={CheckCircledIcon} />
                    <StatPill label="Pending"           value={normStats.total_pending}   color="amber"  icon={ClockIcon} />
                    <StatPill label="Total Employees"   value={normStats.total_employees} color="violet" icon={PersonIcon} />
                    <StatPill label="Avg / Employee"    value={normStats.avg_per_employee}color="teal"   icon={BarChartIcon} />
                </Flex>
            </ScrollArea>

            {/* ── Filter Bar ── */}
            <Flex direction={{ initial: 'column', sm: 'row' }} gap="3" align={{ initial: 'stretch', sm: 'center' }} mb="4">
                <Box style={{ flex: 1 }}>
                    <TextField.Root size="2"
                        placeholder="Search employee or department…"
                        value={searchVal}
                        onChange={e => setSearchVal(e.target.value)}>
                        <TextField.Slot><MagnifyingGlassIcon /></TextField.Slot>
                    </TextField.Root>
                </Box>
                
                <Flex gap="3">
                    <Select.Root size="2" value={String(currentYear)} onValueChange={v => setCurrentYear(Number(v))}>
                        <Select.Trigger style={{ minWidth: isMobile ? '100%' : '120px' }} />
                        <Select.Content>
                            {Array.from({ length: 5 }, (_, i) => new Date().getFullYear() - i).map(y => (
                                <Select.Item key={y} value={String(y)}>{y}</Select.Item>
                            ))}
                        </Select.Content>
                    </Select.Root>

                    <Select.Root size="2" value={deptId || 'all'} onValueChange={v => setDeptId(v === 'all' ? '' : v)}>
                        <Select.Trigger style={{ minWidth: isMobile ? '100%' : '180px' }} placeholder="All Departments" />
                        <Select.Content>
                            <Select.Item value="all">All Departments</Select.Item>
                            {departments.map(d => (
                                <Select.Item key={d.id} value={String(d.id)}>{d.name}</Select.Item>
                            ))}
                        </Select.Content>
                    </Select.Root>
                </Flex>
            </Flex>

            {/* ── Sub-tabs ── */}
            <Tabs.Root value={subTab} onValueChange={setSubTab}>
                <ScrollArea type="auto" scrollbars="horizontal" style={{ width: '100%', marginBottom: '16px' }}>
                    <Tabs.List size="2" style={{ whiteSpace: 'nowrap', width: 'max-content', minWidth: '100%' }}>
                        <Tabs.Trigger value="employee" style={{ cursor: 'pointer' }}>
                            <Flex align="center" gap="2"><PersonIcon /> Employee View</Flex>
                        </Tabs.Trigger>
                        <Tabs.Trigger value="department" style={{ cursor: 'pointer' }}>
                            <Flex align="center" gap="2"><HomeIcon style={{ width: 14, height: 14 }} /> Department View</Flex>
                        </Tabs.Trigger>
                    </Tabs.List>
                </ScrollArea>

                {/* ── Employee Pivot Table ── */}
                <Tabs.Content value="employee">
                    {filteredEmployeeData.length === 0 ? (
                        <Flex direction="column" align="center" py="9" gap="3" style={{ border: '1px dashed var(--gray-a6)', borderRadius: 'var(--radius-3)' }}>
                            <PersonIcon style={{ width: 48, height: 48, color: 'var(--gray-8)' }} />
                            <Text size="4" weight="bold">No Employee Data Found</Text>
                            <Text size="2" color="gray">Try adjusting your search or filters.</Text>
                        </Flex>
                    ) : (
                        <Panel variant="surface" p="0" style={{ overflow: 'hidden' }}>
                            <ScrollArea type="auto" scrollbars="both" style={{ width: '100%', maxHeight: '600px' }}>
                                <Table.Root size="2">
                                    <Table.Header style={{ backgroundColor: 'var(--gray-a2)', position: 'sticky', top: 0, zIndex: 1 }}>
                                        <Table.Row>
                                            <Table.ColumnHeaderCell style={{ minWidth: 200 }}>Employee</Table.ColumnHeaderCell>
                                            <Table.ColumnHeaderCell style={{ minWidth: 150 }}>Department</Table.ColumnHeaderCell>
                                            {dynamicColumns.map(col => (
                                                <Table.ColumnHeaderCell key={col.key} style={{ textAlign: 'center', minWidth: 100 }}>
                                                    {col.label}
                                                </Table.ColumnHeaderCell>
                                            ))}
                                            <Table.ColumnHeaderCell style={{ textAlign: 'center', minWidth: 80 }}>Total</Table.ColumnHeaderCell>
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
                                                {dynamicColumns.map(col => {
                                                    const cell = row[col.key];
                                                    return (
                                                        <Table.Cell key={col.key} style={{ textAlign: 'center' }}>
                                                            {cell != null && cell !== 0 && cell !== ''
                                                                ? <Badge size="1" variant="soft"
                                                                    color={statusColor(col.status)}>{cell}</Badge>
                                                                : <Text size="1" color="gray">—</Text>}
                                                        </Table.Cell>
                                                    );
                                                })}
                                                <Table.Cell style={{ textAlign: 'center' }}>
                                                    <Badge size="2" variant="solid" color="indigo">{row.total_used ?? row.total ?? 0}</Badge>
                                                </Table.Cell>
                                            </Table.Row>
                                        ))}
                                    </Table.Body>
                                </Table.Root>
                            </ScrollArea>
                        </Panel>
                    )}
                </Tabs.Content>

                {/* ── Department Breakdown Table ── */}
                <Tabs.Content value="department">
                    {department_summary.length === 0 ? (
                        <Flex direction="column" align="center" py="9" gap="3" style={{ border: '1px dashed var(--gray-a6)', borderRadius: 'var(--radius-3)' }}>
                            <HomeIcon style={{ width: 48, height: 48, color: 'var(--gray-8)' }} />
                            <Text size="4" weight="bold">No Department Data Found</Text>
                            <Text size="2" color="gray">No leave records match the current filters.</Text>
                        </Flex>
                    ) : (
                        <Panel variant="surface" p="0" style={{ overflow: 'hidden' }}>
                            <ScrollArea type="auto" scrollbars="both" style={{ width: '100%', maxHeight: '600px' }}>
                                <Table.Root size="2">
                                    <Table.Header style={{ backgroundColor: 'var(--gray-a2)', position: 'sticky', top: 0, zIndex: 1 }}>
                                        <Table.Row>
                                            <Table.ColumnHeaderCell style={{ minWidth: 200 }}>Department</Table.ColumnHeaderCell>
                                            <Table.ColumnHeaderCell style={{ textAlign: 'center', minWidth: 100 }}>Employees</Table.ColumnHeaderCell>
                                            <Table.ColumnHeaderCell style={{ textAlign: 'center', minWidth: 100 }}>Total Leaves</Table.ColumnHeaderCell>
                                            <Table.ColumnHeaderCell style={{ textAlign: 'center', minWidth: 100 }}>Approved</Table.ColumnHeaderCell>
                                            <Table.ColumnHeaderCell style={{ textAlign: 'center', minWidth: 100 }}>Pending</Table.ColumnHeaderCell>
                                            <Table.ColumnHeaderCell style={{ textAlign: 'right', minWidth: 160 }}>Avg / Employee</Table.ColumnHeaderCell>
                                        </Table.Row>
                                    </Table.Header>
                                    <Table.Body>
                                        {(() => {
                                            const maxAvg = Math.max(...department_summary.map(d => Number(d.avg_leaves_per_employee) || 0), 1);
                                            return department_summary.map((dept, i) => {
                                                const avg = Number(dept.avg_leaves_per_employee) || 0;
                                                const pct = Math.min(100, (avg / maxAvg) * 100);
                                                return (
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
                                                            <Text size="2" weight="bold">{dept.total_leaves}</Text>
                                                        </Table.Cell>
                                                        <Table.Cell style={{ textAlign: 'center' }}>
                                                            <Badge size="1" variant="soft" color="green">{dept.total_approved}</Badge>
                                                        </Table.Cell>
                                                        <Table.Cell style={{ textAlign: 'center' }}>
                                                            <Badge size="1" variant="soft" color="amber">{dept.total_pending}</Badge>
                                                        </Table.Cell>
                                                        <Table.Cell>
                                                            <Flex align="center" gap="3" justify="end">
                                                                <Text size="2" color="gray" weight="bold">{dept.avg_leaves_per_employee}</Text>
                                                                <Box style={{ width: 80, height: 6, background: 'var(--gray-a4)', borderRadius: 'var(--radius-full)', overflow: 'hidden', display: 'inline-block' }}>
                                                                    <Box style={{ height: '100%', width: `${pct}%`, background: 'var(--accent-9)', borderRadius: 'var(--radius-full)', boxShadow: '0 0 4px var(--accent-a4)' }} />
                                                                </Box>
                                                            </Flex>
                                                        </Table.Cell>
                                                    </Table.Row>
                                                );
                                            });
                                        })()}
                                    </Table.Body>
                                </Table.Root>
                            </ScrollArea>
                        </Panel>
                    )}
                </Tabs.Content>
            </Tabs.Root>
        </Box>
    );
}