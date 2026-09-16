import { Panel } from '@/Components/ui/Panel';
import React, { useState, useEffect, useCallback, useMemo } from 'react';
import { usePage } from '@inertiajs/react';
import axios from 'axios';
import { showToast } from '@/utils/toastUtils';
import { useMediaQuery } from '@/Hooks/useMediaQuery.js';
import { Box, Flex, Text, Button, Grid, Separator, TextField, Select, Badge, Spinner, IconButton } from '@radix-ui/themes';
import {
    HomeIcon, CheckCircledIcon, CrossCircledIcon, PersonIcon,
    MagnifyingGlassIcon, PlusIcon, Cross2Icon, MixerHorizontalIcon,
    TableIcon, StackIcon, Pencil1Icon, SewingPinIcon
} from '@radix-ui/react-icons';
import * as useDepartmentsQuery from '@/api/queries/useDepartmentsQuery';
import { useQueryFilters, useClampPage } from '@/Hooks/useQueryFilters';
import { usePersistentPageState } from '@/Hooks/usePersistentPageState';
import QueryState from '@/Components/Common/QueryState';
import StatsCards from '@/Components/StatsCards';
import SearchFilterBar from '@/Components/SearchFilterBar';
import PageToolbar from '@/Components/PageToolbar';

import DepartmentTable from '../Tables/DepartmentTable.jsx';
import DepartmentForm from '../Components/DepartmentForm.jsx';
import DeleteDepartmentForm from '../Components/DeleteDepartmentForm.jsx';

/* ─── Grid Card Component ─── */
const DepartmentCard = ({ department, onEdit, onView }) => {
    return (
        <Panel tinted style={{ borderRadius: 16, border: '1px solid var(--aero-surface-border, rgba(0,0,0,0.06))', padding: '18px 16px', cursor: 'pointer', transition: 'border-color 0.2s' }} onClick={() => onView(department)}>
            <Flex direction="column" gap="3">
                <Flex align="start" justify="between">
                    <Flex gap="3" align="center">
                        <Box p="2" style={{ background: 'var(--blue-a3)', border: '1px solid var(--blue-a5)', borderRadius: 10, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                            <HomeIcon style={{ color: 'var(--blue-9)', width: 18, height: 18 }} />
                        </Box>
                        <Box>
                            <Text weight="bold" size="2" as="div" style={{ lineHeight: 1.2, fontFamily: `'Space Grotesk', system-ui, sans-serif`, color: 'var(--gray-12)' }}>{department.name}</Text>
                            <Text size="1" style={{ color: 'var(--aero-color-subtle, var(--gray-9))', fontVariantNumeric: 'tabular-nums' }}>{department.code || 'No Code'}</Text>
                        </Box>
                    </Flex>
                    <IconButton size="1" variant="ghost" color="gray" onClick={(e) => { e.stopPropagation(); onEdit(department); }}>
                        <Pencil1Icon />
                    </IconButton>
                </Flex>
                
                <Separator size="4" style={{ background: 'var(--dl-border-color, rgba(0,0,0,0.06))' }} />
                
                <Flex direction="column" gap="2">
                    {department.location && (
                        <Flex align="center" gap="2">
                            <SewingPinIcon style={{ color: 'var(--aero-color-subtle, var(--gray-9))', width: 14, height: 14 }} />
                            <Text size="1" style={{ color: 'var(--aero-color-subtle, var(--gray-9))' }}>{department.location}</Text>
                        </Flex>
                    )}
                    <Flex align="center" gap="2">
                        <PersonIcon style={{ color: 'var(--aero-color-subtle, var(--gray-9))', width: 14, height: 14 }} />
                        <Text size="1" style={{ color: 'var(--aero-color-subtle, var(--gray-9))', fontVariantNumeric: 'tabular-nums' }}>{department.employee_count || 0} Employees</Text>
                    </Flex>
                </Flex>
                
                <Flex gap="2" wrap="wrap" mt="1">
                    <Badge color={department.is_active ? 'jade' : 'red'} variant="soft" size="1" style={{ borderRadius: 999, fontWeight: 700 }}>
                        {department.is_active ? 'Active' : 'Inactive'}
                    </Badge>
                    {department.parent && <Badge color="blue" variant="soft" size="1" style={{ borderRadius: 999 }}>{department.parent.name}</Badge>}
                </Flex>
            </Flex>
        </Panel>
    );
};

const DepartmentsTab = ({ isActive }) => {
    const { auth, departmentsData: initialDepartments, managers, parentDepartments, stats: initialStats } = usePage().props;
    const isMobile = useMediaQuery('(max-width: 639px)');
    const isTablet = useMediaQuery('(max-width: 767px)');

    const [modalState, setModalState] = useState({ type: null, department: null });

    /* ── view: presentation only, remembered across navigation ── */
    const [ui, setUi] = usePersistentPageState('Departments/List', { viewMode: 'table', showFilters: false });
    const viewMode = ui.viewMode;
    const setViewMode = useCallback((value) => setUi({ viewMode: value }), [setUi]);
    const showFilters = ui.showFilters;
    const setShowFilters = useCallback(
        (value) => setUi((prev) => ({ showFilters: typeof value === 'function' ? value(prev.showFilters) : value })),
        [setUi],
    );

    /* ── filters: server state, so they live in the URL. Rows come from
         react-query, so a change updates the URL without a server round trip. ── */
    const f = useQueryFilters({
        mode: 'client',
        defaults: { search: '', status: 'all', parentDepartment: 'all', page: 1, per_page: 10 },
    });
    const filters = useMemo(
        () => ({ search: f.values.search, status: f.values.status, parentDepartment: f.values.parentDepartment }),
        [f.values.search, f.values.status, f.values.parentDepartment],
    );
    const pagination = useMemo(
        () => ({ currentPage: f.values.page, perPage: f.values.per_page }),
        [f.values.page, f.values.per_page],
    );
    const { set: setFilter, setPage, setPerPage } = f;

    const canCreate = auth?.permissions?.includes('departments.create') || false;
    const canEdit = auth?.permissions?.includes('departments.update') || false;
    const canDelete = auth?.permissions?.includes('departments.delete') || false;

    // React Query hooks
    const { data: departmentsData, isLoading: loading, isError, error, refetch } = useDepartmentsQuery.useDepartmentsList({
        page: pagination.currentPage,
        per_page: pagination.perPage,
        search: filters.search,
        status: filters.status,
        parent_department: filters.parentDepartment
    });

    const { data: stats } = useDepartmentsQuery.useDepartmentStats();
    useClampPage(f, departmentsData?.last_page);

    // Search goes through the debounced draft; selects commit immediately. The
    // hook returns to page 1 on any filter change.
    const handleFilterChange = (key, value) => (key === 'search' ? f.setDraft('search', value) : setFilter(key, value));
    const clearFilters = () => f.reset();
    const hasActiveFilters = f.isFiltered;

    const openModal = (type, department = null) => setModalState({ type, department });
    const closeModal = () => setModalState({ type: null, department: null });

    const handleSuccess = () => {
        refetch();
    };

    const departmentRows = departmentsData?.data ?? [];
    const isEmpty = !loading && !isError && departmentRows.length === 0;

    const statPills = [
        { key: 'total', label: 'Total', value: stats?.total ?? 0, color: 'blue' },
        { key: 'active', label: 'Active', value: stats?.active ?? 0, color: 'green' },
        { key: 'inactive', label: 'Inactive', value: stats?.inactive ?? 0, color: 'red' },
        { key: 'parent', label: 'Top-Level', value: stats?.parent_departments ?? 0, color: 'indigo' },
    ];

    const activeFilterChips = useMemo(() => {
        const chips = [];
        if (filters.search) chips.push({ label: 'Search', value: filters.search, onRemove: () => handleFilterChange('search', '') });
        if (filters.status !== 'all') chips.push({ label: 'Status', value: filters.status === 'active' ? 'Active' : 'Inactive', onRemove: () => handleFilterChange('status', 'all') });
        if (filters.parentDepartment !== 'all') {
            const p = parentDepartments?.find(item => String(item.id) === String(filters.parentDepartment));
            chips.push({ label: 'Parent Dept', value: filters.parentDepartment === 'none' ? 'Top-Level Only' : (p?.name || filters.parentDepartment), onRemove: () => handleFilterChange('parentDepartment', 'all') });
        }
        return chips;
    }, [filters, parentDepartments]);

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
                onAdd={() => openModal('add_department')}
                addLabel={!isMobile ? 'Add Department' : 'Add'}
                leftSlot={
                    <SearchFilterBar
                        searchValue={f.draft.search}
                        onSearchChange={val => handleFilterChange('search', val)}
                        searchPlaceholder="Search departments..."
                        showFilterToggle
                        showFilters={showFilters}
                        onToggleFilters={() => setShowFilters(v => !v)}
                        activeFiltersCount={activeFilterChips.length}
                        activeFilterChips={activeFilterChips}
                        onClearFilters={hasActiveFilters ? clearFilters : null}
                        mb="0"
                    >
                        <Grid columns={{ initial: '1', sm: '2', lg: '2' }} gap="4" align="end">
                            <Box>
                                <Text size="2" color="gray" mb="1" as="div">Status</Text>
                                <Select.Root size="2" value={filters.status} onValueChange={v => handleFilterChange('status', v)}>
                                    <Select.Trigger style={{ width: '100%' }} />
                                    <Select.Content>
                                        <Select.Item value="all">All Statuses</Select.Item>
                                        <Select.Item value="active">Active Only</Select.Item>
                                        <Select.Item value="inactive">Inactive Only</Select.Item>
                                    </Select.Content>
                                </Select.Root>
                            </Box>
                            <Box>
                                <Text size="2" color="gray" mb="1" as="div">Parent Department</Text>
                                <Select.Root size="2" value={filters.parentDepartment} onValueChange={v => handleFilterChange('parentDepartment', v)}>
                                    <Select.Trigger style={{ width: '100%' }} />
                                    <Select.Content>
                                        <Select.Item value="all">All Parent Departments</Select.Item>
                                        <Select.Item value="none">Top-Level Only</Select.Item>
                                        {parentDepartments?.map(d => <Select.Item key={d.id} value={String(d.id)}>{d.name}</Select.Item>)}
                                    </Select.Content>
                                </Select.Root>
                            </Box>
                        </Grid>
                    </SearchFilterBar>
                }
            />

            {/* Content Area */}
            <QueryState
                isLoading={loading}
                isError={isError}
                error={error}
                isEmpty={isEmpty}
                emptyMessage="No departments match your filters."
                onRetry={() => refetch()}
                minHeight={200}
            >
                {viewMode === 'grid' ? (
                    <Grid columns={{ initial: '1', sm: '2', lg: '3', xl: '4' }} gap="4" mb="4">
                        {departmentRows.map(dept => (
                            <DepartmentCard key={dept.id} department={dept} onEdit={(d) => openModal('edit_department', d)} onView={(d) => openModal('view_department', d)} />
                        ))}
                    </Grid>
                ) : (
                    <DepartmentTable
                        departments={departmentsData}
                        loading={loading}
                        onEdit={canEdit ? (d) => openModal('edit_department', d) : undefined}
                        onDelete={canDelete ? (d) => openModal('delete_department', d) : undefined}
                        onView={(d) => openModal('view_department', d)}
                        isMobile={isMobile}
                        isTablet={isTablet}
                        pagination={pagination}
                        onPageChange={setPage}
                        onRowsPerPageChange={setPerPage}
                    />
                )}
            </QueryState>

            {/* Modals placeholders */}
            {(modalState.type === 'add_department' || modalState.type === 'edit_department' || modalState.type === 'view_department') && (
                <DepartmentForm
                    open={true}
                    onClose={closeModal}
                    onSuccess={handleSuccess}
                    department={modalState.department}
                    managers={managers}
                    parentDepartments={parentDepartments}
                    readOnly={modalState.type === 'view_department'}
                />
            )}

            {modalState.type === 'delete_department' && (
                <DeleteDepartmentForm
                    open={true}
                    onClose={closeModal}
                    onSuccess={handleSuccess}
                    department={modalState.department}
                />
            )}
        </Box>
    );
};

export default DepartmentsTab;