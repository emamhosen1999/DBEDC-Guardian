import React, { useState, useEffect, useCallback, useMemo } from 'react';
import { usePage } from '@inertiajs/react';
import axios from 'axios';
import { showToast } from '@/utils/toastUtils';
import { useMediaQuery } from '@/Hooks/useMediaQuery.js';
import {
    Box, Flex, Text, Button, Grid, Separator, Card,
    TextField, Select, Badge, Spinner, IconButton
} from '@radix-ui/themes';
import {
    HomeIcon, CheckCircledIcon, CrossCircledIcon, PersonIcon,
    MagnifyingGlassIcon, PlusIcon, Cross2Icon, MixerHorizontalIcon,
    TableIcon, StackIcon, Pencil1Icon, SewingPinIcon
} from '@radix-ui/react-icons';

// Placeholder imports for next steps
import DepartmentTable from '../Tables/DepartmentTable.jsx';
import DepartmentForm from '../Components/DepartmentForm.jsx';
import DeleteDepartmentForm from '../Components/DeleteDepartmentForm.jsx';

/* ─── Stat Pill ─── */
const StatPill = ({ label, value, color = 'gray' }) => (
    <Badge size="2" variant="soft" color={color} radius="full">
        <Text weight="bold">{value}</Text>
        <Text color={color} style={{ opacity: 0.7 }}> {label}</Text>
    </Badge>
);

/* ─── Grid Card Component ─── */
const DepartmentCard = ({ department, onEdit, onView }) => {
    return (
        <Card size="2" style={{ cursor: 'pointer', transition: 'border-color 0.2s' }} onClick={() => onView(department)}>
            <Flex direction="column" gap="3">
                <Flex align="start" justify="between">
                    <Flex gap="3" align="center">
                        <Box p="2" style={{ background: 'var(--blue-a3)', borderRadius: 'var(--radius-2)' }}>
                            <HomeIcon style={{ color: 'var(--blue-9)', width: 20, height: 20 }} />
                        </Box>
                        <Box>
                            <Text weight="bold" size="2" as="div" style={{ lineHeight: 1.2 }}>{department.name}</Text>
                            <Text size="1" color="gray">{department.code || 'No Code'}</Text>
                        </Box>
                    </Flex>
                    <IconButton size="1" variant="ghost" color="gray" onClick={(e) => { e.stopPropagation(); onEdit(department); }}>
                        <Pencil1Icon />
                    </IconButton>
                </Flex>
                
                <Separator size="4" />
                
                <Flex direction="column" gap="2">
                    {department.location && (
                        <Flex align="center" gap="2">
                            <SewingPinIcon color="var(--gray-9)" />
                            <Text size="1" color="gray">{department.location}</Text>
                        </Flex>
                    )}
                    <Flex align="center" gap="2">
                        <PersonIcon color="var(--gray-9)" />
                        <Text size="1" color="gray">{department.employee_count || 0} Employees</Text>
                    </Flex>
                </Flex>
                
                <Flex gap="2" wrap="wrap" mt="2">
                    <Badge color={department.is_active ? 'green' : 'red'} variant="soft" size="1">
                        {department.is_active ? 'Active' : 'Inactive'}
                    </Badge>
                    {department.parent && <Badge color="indigo" variant="soft" size="1">{department.parent.name}</Badge>}
                </Flex>
            </Flex>
        </Card>
    );
};

const DepartmentsTab = ({ isActive }) => {
    const { auth, departments: initialDepartments, managers, parentDepartments, stats: initialStats } = usePage().props;
    const isMobile = useMediaQuery('(max-width: 639px)');
    const isTablet = useMediaQuery('(max-width: 767px)');

    const [departmentsData, setDepartmentsData] = useState(initialDepartments || { data: [], total: 0 });
    const [loading, setLoading] = useState(false);
    const [stats, setStats] = useState(initialStats || { total: 0, active: 0, inactive: 0, parent_departments: 0 });
    const [modalState, setModalState] = useState({ type: null, department: null });

    const [viewMode, setViewMode] = useState('table');
    const [showFilters, setShowFilters] = useState(false);
    const [filters, setFilters] = useState({ search: '', status: 'all', parentDepartment: 'all' });
    const [pagination, setPagination] = useState({ currentPage: 1, perPage: 10 });

    const canCreate = auth.permissions?.includes('departments.create') || false;
    const canEdit = auth.permissions?.includes('departments.update') || false;
    const canDelete = auth.permissions?.includes('departments.delete') || false;

    const fetchDepartments = useCallback(async () => {
        if (!isActive) return;
        setLoading(true);
        try {
            const { data } = await axios.get(route('api.departments'), {
                params: { page: pagination.currentPage, per_page: pagination.perPage, search: filters.search, status: filters.status, parent_department: filters.parentDepartment }
            });
            setDepartmentsData(data.departments);
        } catch { showToast.error('Failed to load departments data'); }
        finally { setLoading(false); }
    }, [pagination.currentPage, pagination.perPage, filters, isActive]);

    const fetchDepartmentStats = useCallback(async () => {
        if (!isActive) return;
        try {
            const { data } = await axios.get(route('departments.stats'));
            setStats(data.stats);
        } catch { /* silent */ }
    }, [isActive]);

    useEffect(() => { fetchDepartments(); fetchDepartmentStats(); }, [fetchDepartments, fetchDepartmentStats]);

    const handleFilterChange = (key, value) => { setFilters(p => ({ ...p, [key]: value })); setPagination(p => ({ ...p, currentPage: 1 })); };
    const clearFilters = () => { setFilters({ search: '', status: 'all', parentDepartment: 'all' }); setPagination(p => ({ ...p, currentPage: 1 })); };
    const hasActiveFilters = filters.search || filters.status !== 'all' || filters.parentDepartment !== 'all';

    const openModal = (type, department = null) => setModalState({ type, department });
    const closeModal = () => setModalState({ type: null, department: null });

    const handleSuccess = () => {
        fetchDepartments();
        fetchDepartmentStats();
    };

    return (
        <Box>
            {/* Quick Stats */}
            <Flex wrap="wrap" gap="2" mb="4">
                <StatPill label="Total" value={stats.total} color="blue" />
                <StatPill label="Active" value={stats.active} color="green" />
                <StatPill label="Inactive" value={stats.inactive} color="red" />
                <StatPill label="Top-Level" value={stats.parent_departments} color="indigo" />
            </Flex>

            {/* Toolbar */}
            <Flex direction={{ initial: 'column', sm: 'row' }} gap="3" align={{ initial: 'stretch', sm: 'center' }} mb="4" justify="between">
                <Flex gap="3" align="center" wrap="wrap" style={{ flex: 1 }}>
                    <Box style={{ flex: 1, maxWidth: 300 }}>
                        <TextField.Root placeholder="Search departments..." value={filters.search} onChange={e => handleFilterChange('search', e.target.value)} size="2">
                            <TextField.Slot><MagnifyingGlassIcon /></TextField.Slot>
                            {filters.search && (
                                <TextField.Slot side="right">
                                    <IconButton size="1" variant="ghost" color="gray" onClick={() => handleFilterChange('search', '')}><Cross2Icon /></IconButton>
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
                    {canCreate && <Button size="2" color="indigo" onClick={() => openModal('add_department')}><PlusIcon />{!isMobile && 'Add Department'}</Button>}
                </Flex>
            </Flex>

            {/* Filter Panel */}
            {showFilters && (
                <Box p="4" mb="4" style={{ background: 'var(--gray-a2)', borderRadius: 'var(--radius-3)', border: '1px solid var(--gray-a4)' }}>
                    <Grid columns={{ initial: '1', sm: '2', lg: '3' }} gap="4" align="end">
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
                        <Button size="2" variant="soft" color="red" disabled={!hasActiveFilters} onClick={clearFilters} style={{ width: '100%' }}>
                            <Cross2Icon />Clear Filters
                        </Button>
                    </Grid>
                </Box>
            )}

            {/* Content Area */}
            {loading && departmentsData.data?.length === 0 ? (
                <Flex direction="column" align="center" justify="center" py="9" gap="3">
                    <Spinner size="3" />
                    <Text color="gray" size="2">Loading departments…</Text>
                </Flex>
            ) : viewMode === 'grid' ? (
                <Grid columns={{ initial: '1', sm: '2', lg: '3', xl: '4' }} gap="4" mb="4">
                    {departmentsData.data?.map(dept => (
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
                    onPageChange={(page) => setPagination(p => ({ ...p, currentPage: page }))}
                    onRowsPerPageChange={(perPage) => setPagination(p => ({ ...p, perPage, currentPage: 1 }))}
                />
            )}

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