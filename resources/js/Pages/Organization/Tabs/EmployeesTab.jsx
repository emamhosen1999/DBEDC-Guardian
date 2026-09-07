import React, { useState, useCallback, useMemo, useEffect } from 'react';
import { usePage, router } from "@inertiajs/react";
import { showToast } from '@/utils/toastUtils';
import axios from 'axios';
import {
    Box, Flex, Text, Button, Grid, Separator,
    TextField, Select, Badge, Spinner, IconButton, Checkbox
} from '@radix-ui/themes';
import {
    ChevronLeftIcon, ChevronRightIcon, Cross2Icon,
    MagnifyingGlassIcon, MixerHorizontalIcon, ReloadIcon,
    PlusIcon, TableIcon, StackIcon, EnvelopeClosedIcon, MobileIcon, Pencil1Icon
} from '@radix-ui/react-icons';
import { useMediaQuery } from '@/Hooks/useMediaQuery.js';
import * as useEmployeesQuery from '@/api/queries/useEmployeesQuery';
import QueryState from '@/Components/Common/QueryState';
import StatsCards from '@/Components/StatsCards';
import SearchFilterBar from '@/Components/SearchFilterBar';
import PageToolbar from '@/Components/PageToolbar';

import EmployeeTable from '../Tables/EmployeeTable.jsx';
import ProfileAvatar from '../../../Components/Profile/ProfileAvatar.jsx';
import AddEditUserFormRadix from '@/Forms/AddEditUserFormRadix.jsx';

/* ─── employee grid card ─── */
const EmployeeCard = ({ user, departments, designations, attendanceTypes }) => {
    const department = departments?.find(d => d.id === user.department_id);
    const designation = designations?.find(d => d.id === user.designation_id);
    const attendanceType = attendanceTypes?.find(a => a.id === user.attendance_type_id);

    return (
        <Box p="4" style={{
            background: 'var(--aero-surface, var(--color-background))',
            border: '1px solid var(--aero-surface-border, rgba(0,0,0,0.06))',
            borderRadius: 16,
            cursor: 'pointer',
            transition: 'transform 80ms ease, border-color 140ms ease'
        }} onClick={() => router.visit(route('profile', { user: user.id }), { preserveState: true, preserveScroll: true })}>
            <Flex direction="column" gap="3">
                <Flex align="center" gap="3">
                    <Box style={{ flexShrink: 0 }}>
                        <ProfileAvatar src={user?.profile_image_url || user?.profile_image} name={user?.name} size="md" />
                    </Box>
                    <Box style={{ minWidth: 0, flex: 1 }}>
                        <Text weight="bold" size="2" as="div" style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', fontFamily: `'Space Grotesk', system-ui, sans-serif`, color: 'var(--gray-12)' }}>
                            {user?.name}
                        </Text>
                        <Text size="1" style={{ color: 'var(--aero-color-subtle, var(--gray-9))', fontVariantNumeric: 'tabular-nums' }}>ID: {user?.employee_id || 'N/A'}</Text>
                    </Box>
                    <IconButton size="1" variant="ghost" color="gray" style={{ flexShrink: 0 }} onClick={e => { e.stopPropagation(); router.visit(route('profile', { user: user.id }), { preserveState: true, preserveScroll: true }); }}>
                        <Pencil1Icon />
                    </IconButton>
                </Flex>
                <Separator size="4" style={{ background: 'var(--dl-border-color, rgba(0,0,0,0.06))' }} />
                <Flex direction="column" gap="1">
                    <Flex align="center" gap="2">
                        <EnvelopeClosedIcon style={{ color: 'var(--aero-color-subtle, var(--gray-9))', flexShrink: 0, width: 14, height: 14 }} />
                        <Text size="1" style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', color: 'var(--aero-color-subtle, var(--gray-9))' }}>{user?.email}</Text>
                    </Flex>
                    {user?.phone && (
                        <Flex align="center" gap="2">
                            <MobileIcon style={{ color: 'var(--aero-color-subtle, var(--gray-9))', flexShrink: 0, width: 14, height: 14 }} />
                            <Text size="1" style={{ color: 'var(--aero-color-subtle, var(--gray-9))', fontVariantNumeric: 'tabular-nums' }}>{user?.phone}</Text>
                        </Flex>
                    )}
                </Flex>
                <Separator size="4" style={{ background: 'var(--dl-border-color, rgba(0,0,0,0.06))' }} />
                <Flex gap="1" wrap="wrap">
                    {department && <Badge color="blue" variant="soft" size="1" style={{ borderRadius: 999 }}>{department.name}</Badge>}
                    {designation && <Badge color="violet" variant="soft" size="1" style={{ borderRadius: 999 }}>{designation.title}</Badge>}
                    {attendanceType && <Badge color="gray" variant="outline" size="1" style={{ borderRadius: 999 }}>{attendanceType.name}</Badge>}
                </Flex>
            </Flex>
        </Box>
    );
};

const EmployeesTab = ({ isActive }) => {
    const { auth, departments, designations, attendanceTypes, roles, workLocations } = usePage().props;
    const isMobile = useMediaQuery('(max-width: 640px)');
    const isTablet = useMediaQuery('(max-width: 768px)');
    
    const canCreate = auth?.permissions?.includes('users.create') || false;

    /* ── dialog state ── */
    const [addDialogOpen, setAddDialogOpen] = useState(false);

    /* ── view ── */
    const [viewMode, setViewMode] = useState('table');
    const [showFilters, setShowFilters] = useState(false);

    /* ── filters ── */
    const isGlobalUser = auth?.roles?.includes('Super Administrator') || auth?.roles?.includes('Administrator') || auth?.roles?.includes('HR Manager');
    const userDeptId = auth?.user?.department_id;
    const isNonGlobalManager = !isGlobalUser && userDeptId !== null && auth?.roles?.includes('Department Manager');

    const [filters, setFilters] = useState({ 
        search: typeof window === 'undefined' ? '' : (new URLSearchParams(window.location.search).get('search') || ''),
        department: isNonGlobalManager ? String(userDeptId) : 'all', 
        designation: 'all', 
        attendanceType: 'all', 
        role: 'all', 
        status: 'all', 
        showDeleted: false 
    });
    const [pagination, setPagination] = useState({ currentPage: 1, perPage: 10, total: 0 });

    /* ── React Query hooks ── */
    const { data: employeesResponse, isLoading: loading, isError, error, refetch } = useEmployeesQuery.useEmployeesList({
        page: pagination.currentPage,
        perPage: pagination.perPage,
        ...filters
    });

    const { data: statsData, refetch: refetchStats } = useEmployeesQuery.useEmployeeStats();

    /* ── local state ── */
    const [employees, setEmployees] = useState([]);
    const [totalRows, setTotalRows] = useState(0);

    /* ── Derived state ── */
    const allManagers = employeesResponse?.allManagers || [];
    const stats = statsData?.stats || {
        overview: { total_employees: 0, active_employees: 0, inactive_employees: 0, total_departments: 0, total_designations: 0 },
        distribution: { by_department: [], by_designation: [], by_attendance_type: [] },
        hiring_trends: { recent_hires: { last_30_days: 0, last_90_days: 0, last_year: 0 }, monthly_growth_rate: 0 },
        workforce_health: { status_ratio: { active_percentage: 0 }, retention_rate: 0, turnover_rate: 0 },
    };

    /* ── Update local state and pagination when data changes ── */
    useEffect(() => {
        if (employeesResponse) {
            setEmployees(employeesResponse.data || []);
            setTotalRows(employeesResponse.total || 0);
            setPagination(prev => ({ ...prev, total: employeesResponse.total || 0 }));
        }
    }, [employeesResponse]);

    /* ── Auto-refetch when filters or pagination changes ── */
    useEffect(() => {
        if (isActive) {
            refetch();
        }
    }, [pagination.currentPage, pagination.perPage, filters.search, filters.department, filters.designation, filters.attendanceType, filters.role, filters.status, filters.showDeleted, isActive, refetch]);

    /* ── filter helpers ── */
    const handleSearchChange = (value) => { setFilters(p => ({ ...p, search: value })); setPagination(p => ({ ...p, currentPage: 1 })); };
    const handleDeptChange = (value) => { setFilters(p => ({ ...p, department: value, designation: 'all' })); setPagination(p => ({ ...p, currentPage: 1 })); };
    const clearFilters = () => { setFilters({ search: '', department: 'all', designation: 'all', attendanceType: 'all', role: 'all', status: 'all', showDeleted: false }); setPagination(p => ({ ...p, currentPage: 1 })); };
    const hasActiveFilters = filters.search || filters.department !== 'all' || filters.designation !== 'all' || filters.attendanceType !== 'all' || filters.role !== 'all' || filters.status !== 'all' || filters.showDeleted;

    /* ── optimistic updates ── */
    const updateEmployeeOptimized = useCallback((id, fields) => {
        setEmployees(prev => prev.map(e => e.id === id ? { ...e, ...fields } : e));
    }, []);
    const deleteEmployeeOptimized = useCallback((id) => {
        setEmployees(prev => prev.filter(e => e.id !== id));
        setTotalRows(prev => Math.max(0, prev - 1));
        setPagination(prev => ({ ...prev, total: Math.max(0, prev.total - 1) }));
        refetchStats();
        refetch();
    }, [refetch, refetchStats]);

    const filteredDesignations = useMemo(() => {
        if (filters.department === 'all') return designations;
        return designations?.filter(d => d.department_id === parseInt(filters.department));
    }, [designations, filters.department]);

    const totalPages = Math.ceil(pagination.total / pagination.perPage);
    const startRow = ((pagination.currentPage - 1) * pagination.perPage) + 1;
    const endRow = Math.min(pagination.currentPage * pagination.perPage, pagination.total);

    const statPills = [
        { key: 'total', label: 'Total', value: stats.overview?.total_employees || 0, color: 'blue' },
        { key: 'active', label: 'Active', value: stats.overview?.active_employees || 0, color: 'green' },
        { key: 'inactive', label: 'Inactive', value: stats.overview?.inactive_employees || 0, color: 'red' },
        { key: 'departments', label: 'Departments', value: stats.overview?.total_departments || 0, color: 'violet' },
        { key: 'retention', label: 'Retention', value: `${stats.workforce_health?.retention_rate || 0}%`, color: 'teal' },
    ];

    const activeFilterChips = useMemo(() => {
        const chips = [];
        if (filters.search) chips.push({ label: 'Search', value: filters.search, onRemove: () => handleSearchChange('') });
        if (filters.department !== 'all') {
            const d = departments?.find(item => String(item.id) === String(filters.department));
            chips.push({ label: 'Department', value: d?.name || filters.department, onRemove: () => handleDeptChange('all') });
        }
        if (filters.designation !== 'all') {
            const d = designations?.find(item => String(item.id) === String(filters.designation));
            chips.push({ label: 'Designation', value: d?.title || filters.designation, onRemove: () => setFilters(p => ({ ...p, designation: 'all' })) });
        }
        if (filters.attendanceType !== 'all') {
            const a = attendanceTypes?.find(item => String(item.id) === String(filters.attendanceType));
            chips.push({ label: 'Attendance', value: a?.name || filters.attendanceType, onRemove: () => setFilters(p => ({ ...p, attendanceType: 'all' })) });
        }
        if (filters.role !== 'all') {
            chips.push({ label: 'Role', value: filters.role, onRemove: () => setFilters(p => ({ ...p, role: 'all' })) });
        }
        if (filters.status !== 'all') {
            chips.push({ label: 'Status', value: filters.status === 'active' ? 'Active' : 'Inactive', onRemove: () => setFilters(p => ({ ...p, status: 'all' })) });
        }
        if (filters.showDeleted) {
            chips.push({ label: 'Deleted', value: 'Included', onRemove: () => setFilters(p => ({ ...p, showDeleted: false })) });
        }
        return chips;
    }, [filters, departments, designations, attendanceTypes]);

    return (
        <Box>
            {/* Quick Stats Pills */}
            <StatsCards stats={statPills} variant="pill" mb="4" />

            {/* Toolbar & Search/Filter Bar */}
            <PageToolbar
                showViewToggle
                viewMode={viewMode}
                onViewModeChange={setViewMode}
                onRefresh={() => refetch()}
                refreshLoading={loading}
                canAdd={canCreate}
                onAdd={() => setAddDialogOpen(true)}
                addLabel={!isMobile ? 'Add Employee' : 'Add'}
                leftSlot={
                    <SearchFilterBar
                        searchValue={filters.search}
                        onSearchChange={handleSearchChange}
                        searchPlaceholder="Search employee name, ID, email..."
                        showFilterToggle
                        showFilters={showFilters}
                        onToggleFilters={() => setShowFilters(v => !v)}
                        activeFiltersCount={activeFilterChips.length}
                        activeFilterChips={activeFilterChips}
                        onClearFilters={hasActiveFilters ? clearFilters : null}
                        mb="0"
                    >
                        <Grid columns={{ initial: '1', sm: '2', md: '3', lg: '6' }} gap="4" align="end">
                            {!isNonGlobalManager && (
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
                            )}
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
                            <Box>
                                <Text size="2" color="gray" mb="1" as="div">System Role</Text>
                                <Select.Root size="2" value={filters.role} onValueChange={v => { setFilters(p => ({ ...p, role: v })); setPagination(p => ({ ...p, currentPage: 1 })); }}>
                                    <Select.Trigger style={{ width: '100%' }} placeholder="All Roles" />
                                    <Select.Content>
                                        <Select.Item value="all">All Roles</Select.Item>
                                        {roles?.map(r => <Select.Item key={r.id || r.name} value={r.name}>{r.name}</Select.Item>)}
                                    </Select.Content>
                                </Select.Root>
                            </Box>
                            <Box>
                                <Text size="2" color="gray" mb="1" as="div">Status</Text>
                                <Select.Root size="2" value={filters.status} onValueChange={v => { setFilters(p => ({ ...p, status: v })); setPagination(p => ({ ...p, currentPage: 1 })); }}>
                                    <Select.Trigger style={{ width: '100%' }} placeholder="Active / Inactive" />
                                    <Select.Content>
                                        <Select.Item value="all">All Statuses</Select.Item>
                                        <Select.Item value="active">Active Only</Select.Item>
                                        <Select.Item value="inactive">Inactive Only</Select.Item>
                                    </Select.Content>
                                </Select.Root>
                            </Box>
                            <Flex align="center" gap="2" style={{ height: '36px' }}>
                                <Checkbox 
                                    id="showDeletedEmployees"
                                    checked={filters.showDeleted}
                                    onCheckedChange={checked => { setFilters(p => ({ ...p, showDeleted: !!checked })); setPagination(p => ({ ...p, currentPage: 1 })); }}
                                />
                                <Text size="2" color="gray" htmlFor="showDeletedEmployees" as="label" style={{ cursor: 'pointer', userSelect: 'none' }}>Include Deleted</Text>
                            </Flex>
                        </Grid>
                    </SearchFilterBar>
                }
            />

            {/* Header info */}
            <Flex align="center" justify="between" mb="3">
                <Text size="3" weight="medium">Directory Data</Text>
                {!loading && pagination.total > 0 && <Text size="1" color="gray">Showing {startRow}–{endRow} of {pagination.total}</Text>}
            </Flex>

            {/* Content area */}
            <QueryState
                isLoading={loading}
                isError={isError}
                error={error}
                isEmpty={!loading && !isError && employees.length === 0}
                emptyMessage="No employees match your filters."
                onRetry={() => refetch()}
                minHeight={200}
            >
            {viewMode === 'grid' ? (
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
                    workLocations={workLocations}
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
                    auth={auth}
                    roles={roles}
                />
            )}
            {addDialogOpen && (
                <AddEditUserFormRadix
                    open={addDialogOpen}
                    closeModal={() => setAddDialogOpen(false)}
                    departments={departments}
                    designations={designations}
                    roles={roles}
                    allUsers={allManagers}
                    setUsers={null}
                    onSuccess={() => {
                        setAddDialogOpen(false);
                        refetch();
                        refetchStats();
                    }}
                />
            )}
            </QueryState>
        </Box>
    );
};

export default EmployeesTab;
