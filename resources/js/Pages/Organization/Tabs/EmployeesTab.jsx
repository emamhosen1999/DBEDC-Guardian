import React, { useState, useCallback, useMemo, useEffect } from 'react';
import { usePage, router } from "@inertiajs/react";
import { showToast } from '@/utils/toastUtils';
import axios from 'axios';
import {
    Box, Flex, Text, Button, Grid, Separator,
    TextField, Select, Badge, Spinner, IconButton
} from '@radix-ui/themes';
import {
    ChevronLeftIcon, ChevronRightIcon, Cross2Icon,
    MagnifyingGlassIcon, MixerHorizontalIcon, ReloadIcon,
    PlusIcon, TableIcon, StackIcon, EnvelopeClosedIcon, MobileIcon, Pencil1Icon
} from '@radix-ui/react-icons';
import { useMediaQuery } from '@/Hooks/useMediaQuery.js';

import EmployeeTable from '../Tables/EmployeeTable.jsx';
import ProfileAvatar from '../../../Components/Profile/ProfileAvatar.jsx';

/* ─── stat pill component ─── */
const StatPill = ({ label, value, color = 'gray' }) => (
    <Badge size="2" variant="soft" color={color} radius="full">
        <Text weight="bold">{value}</Text>
        <Text color={color} style={{ opacity: 0.7 }}> {label}</Text>
    </Badge>
);

/* ─── employee grid card ─── */
const EmployeeCard = ({ user, departments, designations, attendanceTypes }) => {
    const department = departments?.find(d => d.id === user.department_id);
    const designation = designations?.find(d => d.id === user.designation_id);
    const attendanceType = attendanceTypes?.find(a => a.id === user.attendance_type_id);

    return (
        <Box p="3" style={{ border: '1px solid var(--gray-a4)', borderRadius: 'var(--radius-3)', cursor: 'pointer' }} onClick={() => router.visit(route('profile', { user: user.id }))}>
            <Flex direction="column" gap="3">
                <Flex align="start" gap="3">
                    <Box style={{ flexShrink: 0 }}>
                        <ProfileAvatar src={user?.profile_image_url || user?.profile_image} name={user?.name} size="md" />
                    </Box>
                    <Box style={{ minWidth: 0, flex: 1 }}>
                        <Text weight="bold" size="2" as="div" style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                            {user?.name}
                        </Text>
                        <Text size="1" color="gray">ID: {user?.employee_id || 'N/A'}</Text>
                    </Box>
                    <IconButton size="1" variant="ghost" color="gray" style={{ flexShrink: 0 }} onClick={e => { e.stopPropagation(); router.visit(route('profile', { user: user.id })); }}>
                        <Pencil1Icon />
                    </IconButton>
                </Flex>
                <Separator size="4" />
                <Flex direction="column" gap="1">
                    <Flex align="center" gap="2">
                        <EnvelopeClosedIcon style={{ color: 'var(--gray-9)', flexShrink: 0 }} />
                        <Text size="1" color="gray" style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{user?.email}</Text>
                    </Flex>
                    {user?.phone && (
                        <Flex align="center" gap="2">
                            <MobileIcon style={{ color: 'var(--gray-9)', flexShrink: 0 }} />
                            <Text size="1" color="gray">{user?.phone}</Text>
                        </Flex>
                    )}
                </Flex>
                <Separator size="4" />
                <Flex gap="1" wrap="wrap">
                    {department && <Badge color="blue" variant="soft" size="1">{department.name}</Badge>}
                    {designation && <Badge color="violet" variant="soft" size="1">{designation.title}</Badge>}
                    {attendanceType && <Badge color="gray" variant="outline" size="1">{attendanceType.name}</Badge>}
                </Flex>
            </Flex>
        </Box>
    );
};

const EmployeesTab = ({ isActive }) => {
    const { departments, designations, attendanceTypes } = usePage().props;
    const isMobile = useMediaQuery('(max-width: 640px)');
    const isTablet = useMediaQuery('(max-width: 768px)');

    /* ── server state ── */
    const [employees, setEmployees] = useState([]);
    const [allManagers, setAllManagers] = useState([]);
    const [loading, setLoading] = useState(true);
    const [totalRows, setTotalRows] = useState(0);

    /* ── view ── */
    const [viewMode, setViewMode] = useState('table');
    const [showFilters, setShowFilters] = useState(false);

    /* ── filters ── */
    const [filters, setFilters] = useState({ search: '', department: 'all', designation: 'all', attendanceType: 'all' });
    const [pagination, setPagination] = useState({ currentPage: 1, perPage: 10, total: 0 });
    const [stats, setStats] = useState({
        overview: { total_employees: 0, active_employees: 0, inactive_employees: 0, total_departments: 0, total_designations: 0 },
        distribution: { by_department: [], by_designation: [], by_attendance_type: [] },
        hiring_trends: { recent_hires: { last_30_days: 0, last_90_days: 0, last_year: 0 }, monthly_growth_rate: 0 },
        workforce_health: { status_ratio: { active_percentage: 0 }, retention_rate: 0, turnover_rate: 0 },
    });

    /* ── fetch ── */
    const fetchEmployees = useCallback(async () => {
        if (!isActive) return;
        setLoading(true);
        try {
            const params = { page: pagination.currentPage, perPage: pagination.perPage, ...filters };
            const { data } = await axios.get(route('employees.paginate'), { params });
            setEmployees(data.employees.data.filter(e => !e.deleted_at));
            setTotalRows(data.employees.total);
            setPagination(prev => ({ ...prev, total: data.employees.total }));
            if (data.allManagers) setAllManagers(data.allManagers);
            if (data.stats) setStats(data.stats);
        } catch { showToast.error('Failed to load employees.'); }
        finally { setLoading(false); }
    }, [pagination.currentPage, pagination.perPage, filters, isActive]);

    const fetchStats = useCallback(async () => {
        if (!isActive) return;
        try {
            const { data } = await axios.get(route('employees.stats'));
            if (data.stats) setStats(data.stats);
        } catch { /* silent */ }
    }, [isActive]);

    useEffect(() => { fetchEmployees(); fetchStats(); }, [fetchEmployees, fetchStats]);

    /* ── filter helpers ── */
    const handleSearchChange = (value) => { setFilters(p => ({ ...p, search: value })); setPagination(p => ({ ...p, currentPage: 1 })); };
    const handleDeptChange = (value) => { setFilters(p => ({ ...p, department: value, designation: 'all' })); setPagination(p => ({ ...p, currentPage: 1 })); };
    const clearFilters = () => { setFilters({ search: '', department: 'all', designation: 'all', attendanceType: 'all' }); setPagination(p => ({ ...p, currentPage: 1 })); };
    const hasActiveFilters = filters.search || filters.department !== 'all' || filters.designation !== 'all' || filters.attendanceType !== 'all';

    /* ── optimistic updates ── */
    const updateEmployeeOptimized = useCallback((id, fields) => setEmployees(prev => prev.map(e => e.id === id ? { ...e, ...fields } : e)), []);
    const deleteEmployeeOptimized = useCallback((id) => {
        setEmployees(prev => prev.filter(e => e.id !== id));
        setTotalRows(prev => prev - 1);
        setPagination(prev => ({ ...prev, total: prev.total - 1 }));
        fetchStats();
    }, [fetchStats]);

    const filteredDesignations = useMemo(() => {
        if (filters.department === 'all') return designations;
        return designations?.filter(d => d.department_id === parseInt(filters.department));
    }, [designations, filters.department]);

    const totalPages = Math.ceil(pagination.total / pagination.perPage);
    const startRow = ((pagination.currentPage - 1) * pagination.perPage) + 1;
    const endRow = Math.min(pagination.currentPage * pagination.perPage, pagination.total);

    return (
        <Box>
            {/* Quick Stats Row */}
            <Flex wrap="wrap" gap="2" mb="4">
                <StatPill label="Total" value={stats.overview?.total_employees || 0} color="blue" />
                <StatPill label="Active" value={stats.overview?.active_employees || 0} color="green" />
                <StatPill label="Inactive" value={stats.overview?.inactive_employees || 0} color="red" />
                <StatPill label="Departments" value={stats.overview?.total_departments || 0} color="violet" />
                <StatPill label="Retention" value={`${stats.workforce_health?.retention_rate || 0}%`} color="teal" />
            </Flex>

            {/* Toolbar Row */}
            <Flex direction={{ initial: 'column', sm: 'row' }} gap="3" align={{ initial: 'stretch', sm: 'center' }} mb="4" justify="between">
                <Flex gap="3" align="center" wrap="wrap" style={{ flex: 1 }}>
                    <Box style={{ flex: 1, maxWidth: 300 }}>
                        <TextField.Root placeholder="Search employee..." value={filters.search} onChange={e => handleSearchChange(e.target.value)} size="2">
                            <TextField.Slot><MagnifyingGlassIcon /></TextField.Slot>
                            {filters.search && (
                                <TextField.Slot side="right">
                                    <IconButton size="1" variant="ghost" color="gray" onClick={() => handleSearchChange('')}><Cross2Icon /></IconButton>
                                </TextField.Slot>
                            )}
                        </TextField.Root>
                    </Box>
                    <Button size="2" variant={viewMode === 'table' ? 'solid' : 'soft'} color={viewMode === 'table' ? undefined : 'gray'} onClick={() => setViewMode('table')}>
                        <TableIcon />{!isMobile && 'Table'}
                    </Button>
                    <Button size="2" variant={viewMode === 'grid' ? 'solid' : 'soft'} color={viewMode === 'grid' ? undefined : 'gray'} onClick={() => setViewMode('grid')}>
                        <StackIcon />{!isMobile && 'Grid'}
                    </Button>
                    <Button size="2" variant={showFilters ? 'solid' : 'surface'} color={showFilters ? 'indigo' : 'gray'} onClick={() => setShowFilters(v => !v)}>
                        <MixerHorizontalIcon />{!isMobile && 'Filters'}
                    </Button>
                </Flex>
                
                <Flex gap="2">
                    <Button size="2" variant="soft" color="gray" onClick={fetchEmployees}><ReloadIcon /></Button>
                    <Button size="2"><PlusIcon />{!isMobile && 'Add Employee'}</Button>
                </Flex>
            </Flex>

            {/* Filter Panel */}
            {showFilters && (
                <Box p="4" mb="4" style={{ background: 'var(--gray-a2)', borderRadius: 'var(--radius-3)', border: '1px solid var(--gray-a4)' }}>
                    <Grid columns={{ initial: '1', sm: '2', lg: '4' }} gap="4" align="end">
                        <Box>
                            <Text size="2" color="gray" mb="1" as="div">Department</Text>
                            <Select.Root size="2" value={filters.department} onValueChange={handleDeptChange}>
                                <Select.Trigger style={{ width: '100%' }} placeholder="All Departments" />
                                <Select.Content>
                                    <Select.Item value="all">All Departments</Select.Item>
                                    {departments?.map(d => <Select.Item key={d.id} value={String(d.id)}>{d.name}</Select.Item>)}
                                </Select.Content>
                            </Select.Root>
                        </Box>
                        <Box>
                            <Text size="2" color="gray" mb="1" as="div">Designation</Text>
                            <Select.Root size="2" value={filters.designation} onValueChange={v => { setFilters(p => ({ ...p, designation: v })); setPagination(p => ({ ...p, currentPage: 1 })); }} disabled={filters.department === 'all'}>
                                <Select.Trigger style={{ width: '100%' }} placeholder={filters.department === 'all' ? 'Select Department First' : 'All Designations'} />
                                <Select.Content>
                                    <Select.Item value="all">All Designations</Select.Item>
                                    {filteredDesignations?.map(d => <Select.Item key={d.id} value={String(d.id)}>{d.title}</Select.Item>)}
                                </Select.Content>
                            </Select.Root>
                        </Box>
                        <Box>
                            <Text size="2" color="gray" mb="1" as="div">Attendance Type</Text>
                            <Select.Root size="2" value={filters.attendanceType} onValueChange={v => { setFilters(p => ({ ...p, attendanceType: v })); setPagination(p => ({ ...p, currentPage: 1 })); }}>
                                <Select.Trigger style={{ width: '100%' }} placeholder="All Types" />
                                <Select.Content>
                                    <Select.Item value="all">All Attendance Types</Select.Item>
                                    {attendanceTypes?.map(t => <Select.Item key={t.id} value={String(t.id)}>{t.name}</Select.Item>)}
                                </Select.Content>
                            </Select.Root>
                        </Box>
                        <Button size="2" variant="soft" color="red" disabled={!hasActiveFilters} onClick={clearFilters} style={{ width: '100%' }}>
                            <Cross2Icon />Clear Filters
                        </Button>
                    </Grid>
                </Box>
            )}

            {/* Header info */}
            <Flex align="center" justify="between" mb="3">
                <Text size="3" weight="medium">Directory Data</Text>
                {!loading && pagination.total > 0 && <Text size="1" color="gray">Showing {startRow}–{endRow} of {pagination.total}</Text>}
            </Flex>

            {/* Content area */}
            {loading ? (
                <Flex direction="column" align="center" justify="center" py="9" gap="3">
                    <Spinner size="3" />
                    <Text color="gray" size="2">Loading employee data…</Text>
                </Flex>
            ) : viewMode === 'grid' ? (
                <>
                    <Grid columns={{ initial: '1', sm: '2', lg: '3', xl: '4' }} gap="4" mb="4">
                        {employees.map(user => (
                            <EmployeeCard key={user.id} user={user} departments={departments} designations={designations} attendanceTypes={attendanceTypes} />
                        ))}
                    </Grid>
                    <Flex justify="between" align="center" pt="3" style={{ borderTop: '1px solid var(--gray-a4)' }}>
                        <Text size="2" color="gray">{startRow}–{endRow} of {pagination.total}</Text>
                        <Flex gap="2">
                            <Button size="2" variant="soft" color="gray" disabled={pagination.currentPage <= 1} onClick={() => setPagination(p => ({ ...p, currentPage: p.currentPage - 1 }))}>
                                <ChevronLeftIcon /> Prev
                            </Button>
                            <Button size="2" variant="soft" color="gray" disabled={pagination.currentPage >= totalPages} onClick={() => setPagination(p => ({ ...p, currentPage: p.currentPage + 1 }))}>
                                Next <ChevronRightIcon />
                            </Button>
                        </Flex>
                    </Flex>
                </>
            ) : (
                <EmployeeTable
                    employees={employees}
                    departments={departments}
                    designations={designations}
                    attendanceTypes={attendanceTypes}
                    allManagers={allManagers}
                    isMobile={isMobile}
                    isTablet={isTablet}
                    pagination={pagination}
                    totalRows={totalRows}
                    loading={loading}
                    updateEmployeeOptimized={updateEmployeeOptimized}
                    deleteEmployeeOptimized={deleteEmployeeOptimized}
                    onPageChange={(page) => setPagination(p => ({ ...p, currentPage: page }))}
                    onRowsPerPageChange={(perPage) => setPagination(p => ({ ...p, perPage, currentPage: 1 }))}
                />
            )}
        </Box>
    );
};

export default EmployeesTab;