import ProfileAvatar from "@/Components/ProfileAvatar.jsx";
import { useMediaQuery } from '@/Hooks/useMediaQuery.js';
import App from "@/Layouts/App.jsx";
import { showToast } from '@/utils/toastUtils';
import { Head, router } from "@inertiajs/react";
import {
  ChevronLeftIcon,
  ChevronRightIcon,
  Cross2Icon,
  EnvelopeClosedIcon,
  MagnifyingGlassIcon,
  MixerHorizontalIcon,
  MobileIcon,
  Pencil1Icon,
  PersonIcon,
  PlusIcon,
  ReloadIcon,
  StackIcon,
  TableIcon
} from '@radix-ui/react-icons';
import {
  Badge,
  Box,
  Button,
  Card,
  Flex,
  Grid,
  Heading,
  IconButton,
  Select,
  Separator,
  Spinner,
  Text,
  TextField
} from '@radix-ui/themes';
import axios from 'axios';
import { useCallback, useEffect, useMemo, useState } from 'react';

/* ─── helpers ─── */
function getInitials(name = '') {
    return name.split(' ').map(p => p[0]).join('').slice(0, 2).toUpperCase();
}

function statusColor(active) {
    return active ? 'green' : 'red';
}

/* ─── tiny stat pill (matches DailyWorks filter chips style) ─── */
function StatPill({ label, value, color = 'gray' }) {
    return (
        <Badge size="2" variant="soft" color={color} radius="full">
            <Text weight="bold">{value}</Text>
            <Text color={color} style={{ opacity: 0.7 }}> {label}</Text>
        </Badge>
    );
}

/* ─── Employee Grid Card ─── */
function EmployeeCard({ user, departments, designations, attendanceTypes }) {
    const department = departments?.find(d => d.id === user.department_id);
    const designation = designations?.find(d => d.id === user.designation_id);
    const attendanceType = attendanceTypes?.find(a => a.id === user.attendance_type_id);

    return (
        <Card
            size="2"
            style={{ cursor: 'pointer' }}
            onClick={() => router.visit(route('profile', { user: user.id }))}
        >
            <Flex direction="column" gap="3">
                {/* Avatar + name row */}
                <Flex align="start" gap="3">
                    <Box style={{ flexShrink: 0 }}>
                        <ProfileAvatar
                            src={user?.profile_image_url || user?.profile_image}
                            name={user?.name}
                            size="md"
                        />
                    </Box>
                    <Box style={{ minWidth: 0, flex: 1 }}>
                        <Text weight="bold" size="2" as="div" style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                            {user?.name}
                        </Text>
                        <Text size="1" color="gray">ID: {user?.employee_id || 'N/A'}</Text>
                    </Box>
                    <IconButton
                        size="1"
                        variant="ghost"
                        color="gray"
                        style={{ flexShrink: 0 }}
                        onClick={e => { e.stopPropagation(); router.visit(route('profile', { user: user.id })); }}
                    >
                        <Pencil1Icon />
                    </IconButton>
                </Flex>

                <Separator size="4" />

                {/* Contact */}
                <Flex direction="column" gap="1">
                    <Flex align="center" gap="2">
                        <EnvelopeClosedIcon style={{ color: 'var(--gray-9)', flexShrink: 0 }} />
                        <Text size="1" color="gray" style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                            {user?.email}
                        </Text>
                    </Flex>
                    {user?.phone && (
                        <Flex align="center" gap="2">
                            <MobileIcon style={{ color: 'var(--gray-9)', flexShrink: 0 }} />
                            <Text size="1" color="gray">{user?.phone}</Text>
                        </Flex>
                    )}
                </Flex>

                <Separator size="4" />

                {/* Badges */}
                <Flex gap="1" wrap="wrap">
                    {department && <Badge color="blue" variant="soft" size="1">{department.name}</Badge>}
                    {designation && <Badge color="violet" variant="soft" size="1">{designation.title}</Badge>}
                    {attendanceType && <Badge color="gray" variant="outline" size="1">{attendanceType.name}</Badge>}
                </Flex>
            </Flex>
        </Card>
    );
}

/* ─── Main Page ─── */
const EmployeesList = ({ title, departments, designations, attendanceTypes }) => {
    const isMobile = useMediaQuery('(max-width: 640px)');
    const isTablet = useMediaQuery('(max-width: 768px)');

    /* ── server state ── */
    const [employees, setEmployees] = useState([]);
    const [allManagers, setAllManagers] = useState([]);
    const [biometricDevices, setBiometricDevices] = useState([]);
    const [loading, setLoading] = useState(true);
    const [totalRows, setTotalRows] = useState(0);

    /* ── view ── */
    const [viewMode, setViewMode] = useState('table');
    const [showFilters, setShowFilters] = useState(false);

    /* ── filters ── */
    const [filters, setFilters] = useState({
        search: '',
        department: 'all',
        designation: 'all',
        attendanceType: 'all',
    });

    /* ── pagination ── */
    const [pagination, setPagination] = useState({ currentPage: 1, perPage: 10, total: 0 });

    /* ── stats ── */
    const [stats, setStats] = useState({
        overview: { total_employees: 0, active_employees: 0, inactive_employees: 0, total_departments: 0, total_designations: 0, total_attendance_types: 0 },
        distribution: { by_department: [], by_designation: [], by_attendance_type: [] },
        hiring_trends: { recent_hires: { last_30_days: 0, last_90_days: 0, last_year: 0 }, monthly_growth_rate: 0 },
        workforce_health: { status_ratio: { active_percentage: 0 }, retention_rate: 0, turnover_rate: 0 },
    });

    /* ── fetch ── */
    const fetchEmployees = useCallback(async () => {
        setLoading(true);
        try {
            const { data } = await axios.get(route('employees.paginate'), {
                params: {
                    page: pagination.currentPage,
                    perPage: pagination.perPage,
                    search: filters.search,
                    department: filters.department,
                    designation: filters.designation,
                    attendanceType: filters.attendanceType,
                },
            });
            setEmployees(data.employees.data);
            setTotalRows(data.employees.total);
            setPagination(prev => ({ ...prev, total: data.employees.total }));
            if (data.allManagers) setAllManagers(data.allManagers);
            if (data.stats) setStats(data.stats);
        } catch {
            showToast.error('Failed to load employees.');
        } finally {
            setLoading(false);
        }
    }, [pagination.currentPage, pagination.perPage, filters]);

    const fetchStats = useCallback(async () => {
        try {
            const { data } = await axios.get(route('employees.stats'));
            if (data.stats) setStats(data.stats);
        } catch { /* silent */ }
    }, []);

    const fetchBiometricDevices = useCallback(async () => {
        try {
            const { data } = await axios.get(route('biometric-devices.active'));
            if (data.devices) setBiometricDevices(data.devices);
        } catch { /* silent */ }
    }, []);

    useEffect(() => { fetchEmployees(); fetchStats(); fetchBiometricDevices(); }, [fetchEmployees]);

    /* ── filter helpers ── */
    const handleSearchChange = useCallback((value) => {
        setFilters(p => ({ ...p, search: value }));
        setPagination(p => ({ ...p, currentPage: 1 }));
    }, []);

    const handleDeptChange = useCallback((value) => {
        setFilters(p => ({ ...p, department: value, designation: 'all' }));
        setPagination(p => ({ ...p, currentPage: 1 }));
    }, []);

    const clearFilters = useCallback(() => {
        setFilters({ search: '', department: 'all', designation: 'all', attendanceType: 'all' });
        setPagination(p => ({ ...p, currentPage: 1 }));
    }, []);

    const hasActiveFilters = filters.search || filters.department !== 'all' || filters.designation !== 'all' || filters.attendanceType !== 'all';

    /* ── optimistic updates ── */
    const updateEmployeeOptimized = useCallback((id, fields) => {
        setEmployees(prev => prev.map(e => e.id === id ? { ...e, ...fields } : e));
    }, []);

    const deleteEmployeeOptimized = useCallback((id) => {
        setEmployees(prev => prev.filter(e => e.id !== id));
        setTotalRows(prev => prev - 1);
        setPagination(prev => ({ ...prev, total: prev.total - 1 }));
        fetchStats();
    }, [fetchStats]);

    /* ── derived ── */
    const filteredDesignations = useMemo(() => {
        if (filters.department === 'all') return designations;
        return designations.filter(d => d.department_id === parseInt(filters.department));
    }, [designations, filters.department]);

    const totalPages = Math.ceil(pagination.total / pagination.perPage);
    const startRow = ((pagination.currentPage - 1) * pagination.perPage) + 1;
    const endRow = Math.min(pagination.currentPage * pagination.perPage, pagination.total);

    /* ─────────────── RENDER ─────────────── */
    return (
        <>
            <Head title={title || "Employee Directory"} />

            <Flex justify="center" p="4">
                <Box style={{ width: '100%', maxWidth: 2000 }}>
                    <Card>

                        {/* ── Page Header ── */}
                        <Box mb="4">
                            <Flex
                                direction={{ initial: 'column', sm: 'row' }}
                                align={{ initial: 'start', sm: 'center' }}
                                justify="between"
                                gap="4"
                            >
                                <Flex align="center" gap="3">
                                    <Box p="3" style={{
                                        background: 'var(--accent-a3)',
                                        borderRadius: 'var(--radius-2)',
                                        border: '1px solid var(--accent-a6)',
                                        display: 'flex',
                                        alignItems: 'center',
                                        justifyContent: 'center',
                                    }}>
                                        <PersonIcon style={{ width: 22, height: 22, color: 'var(--accent-9)' }} />
                                    </Box>
                                    <Box>
                                        <Heading size="5">Employee Directory</Heading>
                                        <Text size="2" color="gray">Manage employee information and organizational structure</Text>
                                    </Box>
                                </Flex>

                                <Flex gap="2" align="center" wrap="wrap">
                                    <Button
                                        size="2"
                                        variant="soft"
                                        color="gray"
                                        onClick={fetchEmployees}
                                        aria-label="Refresh"
                                    >
                                        <ReloadIcon />
                                        {!isMobile && 'Refresh'}
                                    </Button>
                                    <Button size="2">
                                        <PlusIcon />
                                        {!isMobile ? 'Add Employee' : 'Add'}
                                    </Button>
                                </Flex>
                            </Flex>
                        </Box>

                        <Separator size="4" mb="4" />

                        {/* ── Quick Stats Row ── */}
                        <Flex wrap="wrap" gap="2" mb="4">
                            <StatPill label="Total" value={stats.overview?.total_employees || 0} color="blue" />
                            <StatPill label="Active" value={stats.overview?.active_employees || 0} color="green" />
                            <StatPill label="Inactive" value={stats.overview?.inactive_employees || 0} color="red" />
                            <StatPill label="Departments" value={stats.overview?.total_departments || 0} color="violet" />
                            <StatPill label="Designations" value={stats.overview?.total_designations || 0} color="orange" />
                            <StatPill label="Retention" value={`${stats.workforce_health?.retention_rate || 0}%`} color="teal" />
                            <StatPill label="New (30d)" value={stats.hiring_trends?.recent_hires?.last_30_days || 0} color="cyan" />
                            <StatPill label="Growth" value={`${stats.hiring_trends?.monthly_growth_rate || 0}%`} color="pink" />
                        </Flex>

                        {/* ── Analytics Cards ── */}
                        <Grid columns={{ initial: '1', lg: '2' }} gap="4" mb="5">
                            {/* Department Distribution */}
                            <Box p="4" style={{ background: 'var(--gray-a2)', borderRadius: 'var(--radius-2)', border: '1px solid var(--gray-a4)' }}>
                                <Text size="3" weight="medium" mb="3" as="div">Department Distribution</Text>
                                <Flex direction="column" gap="2">
                                    {(stats.distribution?.by_department || []).slice(0, 5).map((dept, i) => (
                                        <Flex key={i} align="center" justify="between">
                                            <Text size="2" color="gray">{dept.name}</Text>
                                            <Flex align="center" gap="2">
                                                <Box style={{ width: 80, height: 6, borderRadius: 'var(--radius-1)', background: 'var(--gray-a4)', overflow: 'hidden' }}>
                                                    <Box style={{ height: '100%', width: `${dept.percentage || 0}%`, background: 'var(--accent-9)', borderRadius: 'var(--radius-1)' }} />
                                                </Box>
                                                <Text size="1" color="gray" style={{ width: 24, textAlign: 'right' }}>{dept.count}</Text>
                                            </Flex>
                                        </Flex>
                                    ))}
                                    {!(stats.distribution?.by_department?.length) && (
                                        <Text size="2" color="gray">No data available</Text>
                                    )}
                                </Flex>
                            </Box>

                            {/* Hiring Trends */}
                            <Box p="4" style={{ background: 'var(--gray-a2)', borderRadius: 'var(--radius-2)', border: '1px solid var(--gray-a4)' }}>
                                <Text size="3" weight="medium" mb="3" as="div">Hiring Trends</Text>
                                <Flex direction="column" gap="2">
                                    {[
                                        ['Last 30 Days', stats.hiring_trends?.recent_hires?.last_30_days || 0],
                                        ['Last 90 Days', stats.hiring_trends?.recent_hires?.last_90_days || 0],
                                        ['This Year', stats.hiring_trends?.recent_hires?.last_year || 0],
                                    ].map(([label, val]) => (
                                        <Flex key={label} justify="between">
                                            <Text size="2" color="gray">{label}</Text>
                                            <Text size="2" weight="medium">{val}</Text>
                                        </Flex>
                                    ))}
                                    <Separator size="4" />
                                    <Flex justify="between">
                                        <Text size="2" color="gray">Monthly Growth</Text>
                                        <Text size="2" weight="medium" color={(stats.hiring_trends?.monthly_growth_rate || 0) >= 0 ? 'green' : 'red'}>
                                            {stats.hiring_trends?.monthly_growth_rate || 0}%
                                        </Text>
                                    </Flex>
                                </Flex>
                            </Box>

                            {/* Workforce Health */}
                            <Box p="4" style={{ background: 'var(--gray-a2)', borderRadius: 'var(--radius-2)', border: '1px solid var(--gray-a4)' }}>
                                <Text size="3" weight="medium" mb="3" as="div">Workforce Health</Text>
                                <Flex direction="column" gap="2">
                                    {[
                                        ['Retention Rate', `${stats.workforce_health?.retention_rate || 0}%`, 'green'],
                                        ['Turnover Rate', `${stats.workforce_health?.turnover_rate || 0}%`, 'orange'],
                                        ['Active %', `${stats.workforce_health?.status_ratio?.active_percentage || 0}%`, 'blue'],
                                    ].map(([label, val, color]) => (
                                        <Flex key={label} justify="between">
                                            <Text size="2" color="gray">{label}</Text>
                                            <Text size="2" weight="medium" color={color}>{val}</Text>
                                        </Flex>
                                    ))}
                                </Flex>
                            </Box>

                            {/* Attendance Types */}
                            <Box p="4" style={{ background: 'var(--gray-a2)', borderRadius: 'var(--radius-2)', border: '1px solid var(--gray-a4)' }}>
                                <Text size="3" weight="medium" mb="3" as="div">Attendance Types</Text>
                                <Flex direction="column" gap="2">
                                    {(stats.distribution?.by_attendance_type || []).map((type, i) => (
                                        <Flex key={i} align="center" justify="between">
                                            <Text size="2" color="gray">{type.name}</Text>
                                            <Flex align="center" gap="2">
                                                <Box style={{ width: 64, height: 6, borderRadius: 'var(--radius-1)', background: 'var(--gray-a4)', overflow: 'hidden' }}>
                                                    <Box style={{ height: '100%', width: `${type.percentage || 0}%`, background: 'var(--green-9)', borderRadius: 'var(--radius-1)' }} />
                                                </Box>
                                                <Text size="1" color="gray" style={{ width: 24, textAlign: 'right' }}>{type.count}</Text>
                                            </Flex>
                                        </Flex>
                                    ))}
                                    {!(stats.distribution?.by_attendance_type?.length) && (
                                        <Text size="2" color="gray">No data available</Text>
                                    )}
                                </Flex>
                            </Box>
                        </Grid>

                        <Separator size="4" mb="4" />

                        {/* ── Toolbar Row ── */}
                        <Flex
                            direction={{ initial: 'column', sm: 'row' }}
                            gap="3"
                            align={{ initial: 'stretch', sm: 'center' }}
                            mb="4"
                        >
                            {/* Search */}
                            <Box style={{ flex: 1, minWidth: 200 }}>
                                <TextField.Root
                                    placeholder="Search by name, email, or employee ID..."
                                    value={filters.search}
                                    onChange={e => handleSearchChange(e.target.value)}
                                    size="2"
                                >
                                    <TextField.Slot>
                                        <MagnifyingGlassIcon />
                                    </TextField.Slot>
                                    {filters.search && (
                                        <TextField.Slot side="right">
                                            <IconButton size="1" variant="ghost" color="gray" onClick={() => handleSearchChange('')}>
                                                <Cross2Icon />
                                            </IconButton>
                                        </TextField.Slot>
                                    )}
                                </TextField.Root>
                            </Box>

                            {/* View toggles + filter toggle */}
                            <Flex gap="2">
                                <Button
                                    size="2"
                                    variant={viewMode === 'table' ? 'solid' : 'soft'}
                                    color={viewMode === 'table' ? undefined : 'gray'}
                                    onClick={() => setViewMode('table')}
                                >
                                    <TableIcon />
                                    {!isMobile && 'Table'}
                                </Button>
                                <Button
                                    size="2"
                                    variant={viewMode === 'grid' ? 'solid' : 'soft'}
                                    color={viewMode === 'grid' ? undefined : 'gray'}
                                    onClick={() => setViewMode('grid')}
                                >
                                    <StackIcon />
                                    {!isMobile && 'Grid'}
                                </Button>
                                <Button
                                    size="2"
                                    variant={showFilters ? 'solid' : 'surface'}
                                    color={showFilters ? 'indigo' : 'gray'}
                                    onClick={() => setShowFilters(v => !v)}
                                    aria-label="Toggle filters"
                                >
                                    <MixerHorizontalIcon />
                                    {!isMobile && 'Filters'}
                                </Button>
                            </Flex>
                        </Flex>

                        {/* ── Filter Panel ── */}
                        {showFilters && (
                            <Card size="2" variant="surface" mb="4">
                                <Grid columns={{ initial: '1', sm: '2', lg: '4' }} gap="4" align="end">
                                    {/* Department */}
                                    <Box>
                                        <Text size="2" color="gray" weight="medium" as="div" mb="1">Department</Text>
                                        <Select.Root
                                            size="2"
                                            value={filters.department}
                                            onValueChange={handleDeptChange}
                                        >
                                            <Select.Trigger style={{ width: '100%' }} placeholder="All Departments" />
                                            <Select.Content>
                                                <Select.Item value="all">All Departments</Select.Item>
                                                {departments?.map(d => (
                                                    <Select.Item key={d.id} value={String(d.id)}>{d.name}</Select.Item>
                                                ))}
                                            </Select.Content>
                                        </Select.Root>
                                    </Box>

                                    {/* Designation */}
                                    <Box>
                                        <Text size="2" color="gray" weight="medium" as="div" mb="1">Designation</Text>
                                        <Select.Root
                                            size="2"
                                            value={filters.designation}
                                            onValueChange={v => { setFilters(p => ({ ...p, designation: v })); setPagination(p => ({ ...p, currentPage: 1 })); }}
                                            disabled={filters.department === 'all'}
                                        >
                                            <Select.Trigger
                                                style={{ width: '100%', opacity: filters.department === 'all' ? 0.5 : 1 }}
                                                placeholder={filters.department === 'all' ? 'Select Department First' : 'All Designations'}
                                            />
                                            <Select.Content>
                                                <Select.Item value="all">All Designations</Select.Item>
                                                {filteredDesignations?.map(d => (
                                                    <Select.Item key={d.id} value={String(d.id)}>{d.title}</Select.Item>
                                                ))}
                                            </Select.Content>
                                        </Select.Root>
                                    </Box>

                                    {/* Attendance Type */}
                                    <Box>
                                        <Text size="2" color="gray" weight="medium" as="div" mb="1">Attendance Type</Text>
                                        <Select.Root
                                            size="2"
                                            value={filters.attendanceType}
                                            onValueChange={v => { setFilters(p => ({ ...p, attendanceType: v })); setPagination(p => ({ ...p, currentPage: 1 })); }}
                                        >
                                            <Select.Trigger style={{ width: '100%' }} placeholder="All Types" />
                                            <Select.Content>
                                                <Select.Item value="all">All Attendance Types</Select.Item>
                                                {attendanceTypes?.map(t => (
                                                    <Select.Item key={t.id} value={String(t.id)}>{t.name}</Select.Item>
                                                ))}
                                            </Select.Content>
                                        </Select.Root>
                                    </Box>

                                    {/* Clear */}
                                    <Flex justify={{ initial: 'start', sm: 'end' }} align="end">
                                        <Button
                                            size="2"
                                            variant="soft"
                                            color="red"
                                            disabled={!hasActiveFilters}
                                            onClick={clearFilters}
                                            style={{ width: '100%' }}
                                        >
                                            <Cross2Icon />
                                            Clear Filters
                                        </Button>
                                    </Flex>
                                </Grid>
                            </Card>
                        )}

                        {/* ── Active Filter Chips ── */}
                        {showFilters && hasActiveFilters && (
                            <Flex wrap="wrap" gap="2" align="center" mb="4">
                                <Text size="2" color="gray">Active:</Text>
                                {filters.search && (
                                    <Badge size="2" variant="soft" color="gray" radius="full">
                                        Search: "{filters.search}"
                                    </Badge>
                                )}
                                {filters.department !== 'all' && (
                                    <Badge size="2" variant="soft" color="blue" radius="full">
                                        Dept: {departments?.find(d => String(d.id) === filters.department)?.name}
                                    </Badge>
                                )}
                                {filters.designation !== 'all' && (
                                    <Badge size="2" variant="soft" color="violet" radius="full">
                                        Role: {designations?.find(d => String(d.id) === filters.designation)?.title}
                                    </Badge>
                                )}
                                {filters.attendanceType !== 'all' && (
                                    <Badge size="2" variant="soft" color="teal" radius="full">
                                        Attendance: {attendanceTypes?.find(a => String(a.id) === filters.attendanceType)?.name}
                                    </Badge>
                                )}
                            </Flex>
                        )}

                        {/* ── Section header ── */}
                        <Flex align="center" justify="between" mb="3">
                            <Flex align="center" gap="2">
                                <TableIcon style={{ width: 16, height: 16 }} />
                                <Text size="3" weight="medium">Employee Directory</Text>
                                {!loading && (
                                    <Badge size="1" variant="soft" color="gray" radius="full">{totalRows}</Badge>
                                )}
                            </Flex>
                            {!loading && pagination.total > 0 && (
                                <Text size="1" color="gray">
                                    Showing {startRow}–{endRow} of {pagination.total}
                                </Text>
                            )}
                        </Flex>

                        {/* ── Content area ── */}
                        {loading ? (
                            <Flex direction="column" align="center" justify="center" py="9" gap="3">
                                <Spinner size="3" />
                                <Text color="gray" size="2">Loading employee data…</Text>
                            </Flex>
                        ) : employees.length === 0 ? (
                            <Flex direction="column" align="center" justify="center" py="9" gap="2">
                                <PersonIcon style={{ width: 40, height: 40, color: 'var(--gray-9)' }} />
                                <Heading size="3">No Employees Found</Heading>
                                <Text color="gray" size="2">Try adjusting your search or filters.</Text>
                                {hasActiveFilters && (
                                    <Button size="2" variant="soft" color="gray" onClick={clearFilters} mt="2">
                                        <Cross2Icon /> Clear Filters
                                    </Button>
                                )}
                            </Flex>
                        ) : viewMode === 'grid' ? (
                            <>
                                <Grid columns={{ initial: '1', sm: '2', lg: '3', xl: '4' }} gap="4" mb="4">
                                    {employees.map(user => (
                                        <EmployeeCard
                                            key={user.id}
                                            user={user}
                                            departments={departments}
                                            designations={designations}
                                            attendanceTypes={attendanceTypes}
                                        />
                                    ))}
                                </Grid>

                                {/* Grid Pagination */}
                                <Flex justify="between" align="center" pt="3" style={{ borderTop: '1px solid var(--gray-a4)' }}>
                                    <Text size="2" color="gray">{startRow}–{endRow} of {pagination.total}</Text>
                                    <Flex gap="2">
                                        <Button
                                            size="2"
                                            variant="soft"
                                            color="gray"
                                            disabled={pagination.currentPage <= 1}
                                            onClick={() => setPagination(p => ({ ...p, currentPage: p.currentPage - 1 }))}
                                        >
                                            <ChevronLeftIcon /> Prev
                                        </Button>
                                        <Button
                                            size="2"
                                            variant="soft"
                                            color="gray"
                                            disabled={pagination.currentPage >= totalPages}
                                            onClick={() => setPagination(p => ({ ...p, currentPage: p.currentPage + 1 }))}
                                        >
                                            Next <ChevronRightIcon />
                                        </Button>
                                    </Flex>
                                </Flex>
                            </>
                        ) : (
                            /* ── TABLE VIEW ── */
                            <EmployeeTable
                                employees={employees}
                                departments={departments}
                                designations={designations}
                                attendanceTypes={attendanceTypes}
                                allManagers={allManagers}
                                biometricDevices={biometricDevices}
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
                    </Card>
                </Box>
            </Flex>
        </>
    );
};

EmployeesList.layout = (page) => <App>{page}</App>;
export default EmployeesList;