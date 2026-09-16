import { Panel } from '@/Components/ui/Panel';
import { Box, Flex, Grid, Text, Heading, Button, IconButton, Separator, Select, TextField, Badge, Spinner } from '@radix-ui/themes';
import React, { useState, useEffect, useCallback, useMemo } from 'react';
import { Head, usePage } from '@inertiajs/react';
import { usePersistentPageState } from '@/Hooks/usePersistentPageState';
import { useQueryFilters, useClampPage } from '@/Hooks/useQueryFilters';
import {
    BuildingOffice2Icon,
    PlusIcon,
    MagnifyingGlassIcon,
    UserGroupIcon,
    CheckCircleIcon,
    XCircleIcon,
    Squares2X2Icon,
    TableCellsIcon,
    UsersIcon,
    MapPinIcon,
    CalendarIcon
} from '@heroicons/react/24/outline';
import StatsCards from '@/Components/StatsCards.jsx';
import App from '@/Layouts/App.jsx';
import DepartmentTable from '@/Tables/DepartmentTable.jsx';
import DepartmentForm from '@/Forms/DepartmentForm.jsx';
import DeleteDepartmentForm from '@/Forms/DeleteDepartmentForm.jsx';
import TablePagination from '@/Components/TablePagination.jsx';
import { PageLoadingSkeleton, TableLoadingSkeleton } from '@/Components/LoadingSkeleton.jsx';
import { useMediaQuery } from '@/Hooks/useMediaQuery.js';
import dayjs from 'dayjs';
import ErrorBoundary from '@/Components/ErrorBoundary/ErrorBoundary';
import * as useDepartmentsQuery from '@/api/queries/useDepartmentsQuery';

// `filters` is still sent by the controller but is no longer read here: the URL
// is the source of truth for filter state, and useQueryFilters reads it directly.
const Departments = ({ title, departments: initialDepartments, managers, parentDepartments, stats: initialStats }) => {
    const { auth } = usePage().props;
    const isMobile = useMediaQuery('(max-width: 639px)');
    const isTablet = useMediaQuery('(max-width: 767px)');
    
    const [modalState, setModalState] = useState({
        type: null,
        department: null
    });
    
    /* ── server-driven state lives in the URL, so a refresh or a copied link
         reproduces exactly this list. Rows come from react-query, so filter
         changes update the URL client-side without a server round trip. ── */
    const f = useQueryFilters({
        mode: 'client',
        defaults: {
            search: '',
            status: 'all',
            parent_department: 'all',
            page: 1,
            per_page: 10,
        },
    });

    /* ── presentation-only, remembered across navigation ── */
    const [ui, setUi] = usePersistentPageState('Departments/Index', { viewMode: 'table' });
    const viewMode = ui.viewMode;
    const setViewMode = useCallback((mode) => setUi({ viewMode: mode }), [setUi]);

    const pagination = { currentPage: f.values.page, perPage: f.values.per_page };

    const { data: departmentsData, isLoading: loading, refetch } = useDepartmentsQuery.useDepartmentsList({
        page: f.values.page,
        per_page: f.values.per_page,
        search: f.values.search,
        status: f.values.status,
        parent_department: f.values.parent_department,
    });

    const { data: stats } = useDepartmentsQuery.useDepartmentStats();
    useClampPage(f, departmentsData?.last_page);
    
    const canCreateDepartment = auth?.permissions?.includes('departments.create') || false;
    const canEditDepartment = auth?.permissions?.includes('departments.update') || false;
    const canDeleteDepartment = auth?.permissions?.includes('departments.delete') || false;
    
    const handlePageChange = (page) => {
        f.setPage(page);
        window.scrollTo({ top: 0, behavior: 'smooth' });
    };

    const handleRowsPerPageChange = (rowsPerPage) => f.setPerPage(rowsPerPage);
    
    const openModal = (type, department = null) => {
        setModalState({ type, department });
    };
    
    const closeModal = () => {
        setModalState({ type: null, department: null });
    };
    
    const handleSuccess = () => {
        refetch();
        closeModal();
    };
    
    const statsCards = useMemo(() => [
        {
            title: 'Total Departments',
            value: stats?.total ?? initialStats?.total ?? 0,
            icon: <BuildingOffice2Icon className="w-5 h-5" />,
            color: 'text-blue-400',
            iconBg: 'bg-blue-500/20',
            description: 'All departments'
        },
        {
            title: 'Active',
            value: stats?.active ?? initialStats?.active ?? 0,
            icon: <CheckCircleIcon className="w-5 h-5" />,
            color: 'text-green-400',
            iconBg: 'bg-green-500/20',
            description: 'Active departments'
        },
        {
            title: 'Inactive',
            value: stats?.inactive ?? initialStats?.inactive ?? 0,
            icon: <XCircleIcon className="w-5 h-5" />,
            color: 'text-red-400',
            iconBg: 'bg-red-500/20',
            description: 'Inactive departments'
        },
        {
            title: 'Parent Departments',
            value: stats?.parent_departments ?? initialStats?.parent_departments ?? 0,
            icon: <UserGroupIcon className="w-5 h-5" />,
            color: 'text-purple-400',
            iconBg: 'bg-purple-500/20',
            description: 'Top-level departments'
        },
    ], [stats, initialStats]);

    const DepartmentCard = ({ department }) => {
        const parent = department.parent_id ? parentDepartments?.find(d => d.id === department.parent_id) : null;
        const manager = department.manager_id ? managers?.find(m => m.id === department.manager_id) : null;
        
        return (
            <Panel
                tinted
                style={{
                    borderRadius: 16,
                    border: '1px solid var(--aero-surface-border, rgba(0,0,0,0.06))',
                    padding: 16,
                    background: 'var(--aero-surface, var(--color-background))',
                    display: 'flex',
                    flexDirection: 'column',
                    justifyContent: 'space-between'
                }}
            >
                <Box>
                    <Flex justify="between" align="start" mb="3">
                        <Box style={{ flex: 1 }}>
                            <Heading size="3" weight="bold" style={{ fontFamily: `'Space Grotesk', system-ui, sans-serif` }}>
                                {department.name}
                            </Heading>
                            <Text size="1" color="gray" style={{ fontVariantNumeric: 'tabular-nums' }}>
                                Code: {department.code || 'N/A'}
                            </Text>
                        </Box>
                        <Badge
                            size="1"
                            variant="soft"
                            color={department.is_active ? "green" : "red"}
                            style={{ borderRadius: 999 }}
                        >
                            {department.is_active ? 'Active' : 'Inactive'}
                        </Badge>
                    </Flex>

                    {department.description && (
                        <Text size="2" color="gray" style={{ display: 'block', marginBottom: 12, lineHeight: 1.4 }}>
                            {department.description}
                        </Text>
                    )}

                    <Flex direction="column" gap="2" my="2">
                        {department.location && (
                            <Flex align="center" gap="2">
                                <MapPinIcon style={{ width: 14, height: 14, color: 'var(--gray-9)' }} />
                                <Text size="1" color="gray">{department.location}</Text>
                            </Flex>
                        )}
                        <Flex align="center" gap="2">
                            <UsersIcon style={{ width: 14, height: 14, color: 'var(--gray-9)' }} />
                            <Text size="1" style={{ fontVariantNumeric: 'tabular-nums' }}>
                                {department.employee_count || 0} {department.employee_count === 1 ? 'Employee' : 'Employees'}
                            </Text>
                        </Flex>
                        {parent && (
                            <Flex align="center" gap="2">
                                <BuildingOffice2Icon style={{ width: 14, height: 14, color: 'var(--blue-9)' }} />
                                <Text size="1" color="blue">Parent: {parent.name}</Text>
                            </Flex>
                        )}
                    </Flex>
                </Box>

                <Box mt="3" pt="3" style={{ borderTop: '1px solid var(--dl-border-color, rgba(0,0,0,0.06))' }}>
                    <Flex justify="between" align="center">
                        <Text size="1" color="gray" style={{ fontVariantNumeric: 'tabular-nums' }}>
                            {department.created_at ? dayjs(department.created_at).format('MMM DD, YYYY') : ''}
                        </Text>
                        <Flex gap="1">
                            {canEditDepartment && (
                                <Button size="1" variant="soft" color="gray" onClick={() => openModal('edit_department', department)} style={{ borderRadius: 8 }}>
                                    Edit
                                </Button>
                            )}
                            {canDeleteDepartment && (
                                <Button size="1" variant="soft" color="red" onClick={() => openModal('delete_department', department)} style={{ borderRadius: 8 }}>
                                    Delete
                                </Button>
                            )}
                        </Flex>
                    </Flex>
                </Box>
            </Panel>
        );
    };

    return (
        <App>
            <Head title={title || "Department Management"} />
            
            <Flex justify="center" p="4">
                <Box style={{ width: '100%', maxWidth: 2000 }}>
                    <Panel>
                        
                        <Box mb="4">
                            <Flex
                                direction={{ initial: 'column', sm: 'row' }}
                                align={{ initial: 'start', sm: 'center' }}
                                justify="between"
                                gap="4"
                            >
                                <Flex align="center" gap="3">
                                    <Box p="3" style={{
                                        background: 'var(--blue-a3)',
                                        borderRadius: 12,
                                        border: '1px solid var(--blue-a5)',
                                        display: 'flex', alignItems: 'center', justifyContent: 'center',
                                    }}>
                                        <BuildingOffice2Icon style={{ width: 22, height: 22, color: 'var(--blue-9)' }} />
                                    </Box>
                                    <Box>
                                        <Heading size="5" style={{ fontFamily: `'Space Grotesk', system-ui, sans-serif`, fontWeight: 800, letterSpacing: '-0.02em' }}>Department Management</Heading>
                                        <Text size="2" style={{ color: 'var(--aero-color-subtle, var(--gray-9))' }}>
                                            Manage company departments, hierarchies, and organizational structure
                                        </Text>
                                    </Box>
                                </Flex>

                                <Flex gap="2" align="center" wrap="wrap">
                                    {canCreateDepartment && (
                                        <Button color="blue" onClick={() => openModal('add_department')} style={{ borderRadius: 12, fontFamily: `'Space Grotesk', system-ui, sans-serif`, fontWeight: 600 }}>
                                            <PlusIcon style={{ width: 16, height: 16 }} />
                                            {!isMobile && 'Add Department'}
                                        </Button>
                                    )}
                                </Flex>
                            </Flex>
                        </Box>

                        <Separator size="4" mb="4" style={{ background: 'var(--dl-border-color, rgba(0,0,0,0.06))' }} />

                        <Box mb="4">
                            <ErrorBoundary>
                                <StatsCards stats={statsCards} />
                            </ErrorBoundary>
                        </Box>

                        <Flex gap="3" wrap="wrap" mb="4" align="center">
                            <Box style={{ flex: 1, minWidth: '240px' }}>
                                <TextField.Root
                                    placeholder="Search by name, code, or location..."
                                    value={f.draft.search}
                                    onChange={(e) => f.setDraft('search', e.target.value)}
                                    style={{ borderRadius: 10 }}
                                >
                                    <TextField.Slot><MagnifyingGlassIcon style={{ width: 16, height: 16, color: 'var(--gray-9)' }} /></TextField.Slot>
                                </TextField.Root>
                            </Box>

                            <Box style={{ minWidth: '150px' }}>
                                <Select.Root value={f.values.status} onValueChange={(v) => f.set('status', v)}>
                                    <Select.Trigger style={{ width: '100%', borderRadius: 10 }} />
                                    <Select.Content>
                                        <Select.Item value="all">All Statuses</Select.Item>
                                        <Select.Item value="active">Active Only</Select.Item>
                                        <Select.Item value="inactive">Inactive Only</Select.Item>
                                    </Select.Content>
                                </Select.Root>
                            </Box>

                            <Box style={{ minWidth: '180px' }}>
                                <Select.Root value={f.values.parent_department} onValueChange={(v) => f.set('parent_department', v)}>
                                    <Select.Trigger style={{ width: '100%', borderRadius: 10 }} />
                                    <Select.Content>
                                        <Select.Item value="all">All Parent Depts</Select.Item>
                                        <Select.Item value="none">Top-Level Depts</Select.Item>
                                        {parentDepartments?.map(dept => (
                                            <Select.Item key={dept.id} value={String(dept.id)}>
                                                {dept.name}
                                            </Select.Item>
                                        ))}
                                    </Select.Content>
                                </Select.Root>
                            </Box>

                            <Flex gap="1" style={{ background: 'var(--gray-a3)', padding: 3, borderRadius: 10 }}>
                                <IconButton
                                    size="2"
                                    variant={viewMode === 'table' ? 'solid' : 'ghost'}
                                    color={viewMode === 'table' ? 'blue' : 'gray'}
                                    onClick={() => setViewMode('table')}
                                    aria-label="Table view"
                                    style={{ borderRadius: 8 }}
                                >
                                    <TableCellsIcon style={{ width: 16, height: 16 }} />
                                </IconButton>
                                <IconButton
                                    size="2"
                                    variant={viewMode === 'grid' ? 'solid' : 'ghost'}
                                    color={viewMode === 'grid' ? 'blue' : 'gray'}
                                    onClick={() => setViewMode('grid')}
                                    aria-label="Grid view"
                                    style={{ borderRadius: 8 }}
                                >
                                    <Squares2X2Icon style={{ width: 16, height: 16 }} />
                                </IconButton>
                            </Flex>
                        </Flex>

                        {loading && (!departmentsData || !departmentsData.data) ? (
                            <TableLoadingSkeleton rows={pagination.perPage || 6} columns={5} />
                        ) : viewMode === 'table' ? (
                            <ErrorBoundary>
                                <DepartmentTable
                                    departments={departmentsData || initialDepartments}
                                    loading={loading}
                                    onEdit={canEditDepartment ? (department) => openModal('edit_department', department) : undefined}
                                    onDelete={canDeleteDepartment ? (department) => openModal('delete_department', department) : undefined}
                                    onView={(department) => openModal('view_department', department)}
                                    isMobile={isMobile}
                                    isTablet={isTablet}
                                    pagination={pagination}
                                    onPageChange={handlePageChange}
                                    onRowsPerPageChange={handleRowsPerPageChange}
                                    canEditDepartment={canEditDepartment}
                                    canDeleteDepartment={canDeleteDepartment}
                                />
                            </ErrorBoundary>
                        ) : (
                            <Box>
                                {departmentsData?.data && departmentsData.data.length > 0 ? (
                                    <Grid columns={{ initial: '1', sm: '2', md: '3', lg: '4' }} gap="4">
                                        {departmentsData.data.map((department) => (
                                            <DepartmentCard key={department.id} department={department} />
                                        ))}
                                    </Grid>
                                ) : (
                                    <Flex direction="column" align="center" justify="center" py="8" gap="2">
                                        <BuildingOffice2Icon style={{ width: 48, height: 48, color: 'var(--gray-8)', opacity: 0.5 }} />
                                        <Text size="3" weight="medium" color="gray">No departments found</Text>
                                        <Text size="2" color="gray">Try adjusting your search or filters</Text>
                                    </Flex>
                                )}
                                
                                {departmentsData?.total > pagination.perPage && (
                                    <Box mt="4">
                                        <TablePagination
                                            pagination={{
                                                currentPage: pagination.currentPage,
                                                perPage: pagination.perPage,
                                                total: departmentsData.total
                                            }}
                                            onPageChange={handlePageChange}
                                            onRowsPerPageChange={handleRowsPerPageChange}
                                            loading={loading}
                                        />
                                    </Box>
                                )}
                            </Box>
                        )}
                    </Panel>
                </Box>
            </Flex>
            
            {(modalState.type === 'add_department' || modalState.type === 'edit_department') && (
                <DepartmentForm
                    open={true}
                    onClose={closeModal}
                    onSuccess={handleSuccess}
                    department={modalState.type === 'edit_department' ? modalState.department : null}
                    managers={managers}
                    parentDepartments={parentDepartments}
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
            
            {modalState.type === 'view_department' && (
                <DepartmentForm
                    open={true}
                    onClose={closeModal}
                    onSuccess={() => {}}
                    department={modalState.department}
                    managers={managers}
                    parentDepartments={parentDepartments}
                    readOnly={true}
                />
            )}
        </App>
    );
};

export default Departments;
