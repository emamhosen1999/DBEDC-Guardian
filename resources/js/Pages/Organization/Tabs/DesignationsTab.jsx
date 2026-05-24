import React, { useState, useEffect, useCallback, useMemo } from 'react';
import { usePage } from '@inertiajs/react';
import axios from 'axios';
import { showToast } from '@/utils/toastUtils';
import { useMediaQuery } from '@/Hooks/useMediaQuery.js';
import { 
    Box, Flex, Grid, Text, Button, TextField, 
    Select, Separator, Spinner, Badge, IconButton
} from '@radix-ui/themes';
import { 
    LayersIcon, CheckCircledIcon, CrossCircledIcon, 
    PersonIcon, MagnifyingGlassIcon, PlusIcon, Cross2Icon
} from '@radix-ui/react-icons';
import * as useDesignationsQuery from '@/api/queries/useDesignationsQuery';

// Placeholder imports for next steps
import DesignationTable from '../Tables/DesignationTable.jsx';
import DesignationForm from '../Components/DesignationForm.jsx';
import DeleteDesignationForm from '../Components/DeleteDesignationForm.jsx';

const StatPill = ({ label, value, color = 'gray' }) => (
    <Badge size="2" variant="soft" color={color} radius="full">
        <Text weight="bold">{value}</Text>
        <Text color={color} style={{ opacity: 0.7 }}> {label}</Text>
    </Badge>
);

const DesignationsTab = ({ isActive }) => {
    const { auth, initialDesignations, departments, allDesignations, designationStats: initialStats } = usePage().props;
    const isMobile = useMediaQuery('(max-width: 767px)');

    const [modalState, setModalState] = useState({ type: null, designation: null });

    const defaultDepartment = useMemo(() => {
        if (!departments || departments.length === 0) return 'all';
        const deptCounts = {};
        allDesignations?.forEach(des => {
            if (des.department_id) {
                deptCounts[des.department_id] = (deptCounts[des.department_id] || 0) + 1;
            }
        });
        const maxDept = Object.entries(deptCounts).sort((a, b) => b[1] - a[1])[0];
        return maxDept ? String(maxDept[0]) : 'all';
    }, [departments, allDesignations]);

    const [filters, setFilters] = useState({ search: '', status: 'all', department: defaultDepartment });
    const [pagination, setPagination] = useState({ currentPage: 1, perPage: 10 });

    const canCreate = auth.permissions?.includes('designations.create') || false;
    const canEdit = auth.permissions?.includes('designations.update') || false;
    const canDelete = auth.permissions?.includes('designations.delete') || false;

    // React Query hooks
    const { data: designationsData, isLoading: loading, refetch } = useDesignationsQuery.useDesignationsList({
        page: pagination.currentPage,
        per_page: pagination.perPage,
        search: filters.search,
        status: filters.status,
        department: filters.department !== 'all' ? filters.department : undefined
    });

    const { data: stats } = useDesignationsQuery.useDesignationStats();

    // Auto-refetch when filters or pagination changes
    useEffect(() => {
        if (isActive) {
            refetch();
        }
    }, [pagination.currentPage, pagination.perPage, filters.search, filters.status, filters.department, isActive, refetch]);

    const handleFilterChange = (key, value) => { setFilters(prev => ({ ...prev, [key]: value })); setPagination(prev => ({ ...prev, currentPage: 1 })); };
    const clearFilters = () => { setFilters({ search: '', status: 'all', department: 'all' }); setPagination(p => ({ ...p, currentPage: 1 })); };

    const openModal = (type, designation = null) => setModalState({ type, designation });
    const closeModal = () => setModalState({ type: null, designation: null });

    const handleSuccess = () => {
        refetch();
    };

    return (
        <Box>
            {/* Quick Stats */}
            <Flex wrap="wrap" gap="2" mb="4">
                <StatPill label="Total" value={stats?.total || 0} color="blue" />
                <StatPill label="Active" value={stats?.active || 0} color="green" />
                <StatPill label="Inactive" value={stats?.inactive || 0} color="red" />
                <StatPill label="Top-Level" value={stats?.parent_designations || 0} color="purple" />
            </Flex>

            {/* Toolbar */}
            <Flex gap="3" wrap="wrap" mb="5" align="end" justify="between">
                <Flex gap="3" wrap="wrap" style={{ flex: 1 }}>
                    <Box style={{ flexGrow: 1, minWidth: '250px' }}>
                        <TextField.Root placeholder="Search designations..." value={filters.search} onChange={(e) => handleFilterChange('search', e.target.value)}>
                            <TextField.Slot><MagnifyingGlassIcon /></TextField.Slot>
                            {filters.search && (
                                <TextField.Slot side="right">
                                    <IconButton size="1" variant="ghost" color="gray" onClick={() => handleFilterChange('search', '')}><Cross2Icon /></IconButton>
                                </TextField.Slot>
                            )}
                        </TextField.Root>
                    </Box>
                    
                    <Box style={{ minWidth: '200px' }}>
                        <Select.Root value={filters.department} onValueChange={(v) => handleFilterChange('department', v)}>
                            <Select.Trigger style={{ width: '100%' }} />
                            <Select.Content>
                                <Select.Item value="all">All Departments</Select.Item>
                                {departments?.map(dept => <Select.Item key={dept.id} value={String(dept.id)}>{dept.name}</Select.Item>)}
                            </Select.Content>
                        </Select.Root>
                    </Box>

                    <Box style={{ minWidth: '150px' }}>
                        <Select.Root value={filters.status} onValueChange={(v) => handleFilterChange('status', v)}>
                            <Select.Trigger style={{ width: '100%' }} />
                            <Select.Content>
                                <Select.Item value="all">All Status</Select.Item>
                                <Select.Item value="active">Active</Select.Item>
                                <Select.Item value="inactive">Inactive</Select.Item>
                            </Select.Content>
                        </Select.Root>
                    </Box>
                </Flex>

                <Flex gap="2">
                    {canCreate && <Button color="indigo" onClick={() => openModal('add_designation')}><PlusIcon /> {!isMobile && "Add Designation"}</Button>}
                </Flex>
            </Flex>

            {/* Data Table */}
            <Box>
                {loading && designationsData.data.length === 0 ? (
                    <Flex justify="center" align="center" py="8" direction="column" gap="3">
                        <Spinner size="3" />
                        <Text color="gray">Loading designations...</Text>
                    </Flex>
                ) : (
                    <DesignationTable
                        designations={designationsData}
                        loading={loading}
                        onEdit={canEdit ? (d) => openModal('edit_designation', d) : undefined}
                        onDelete={canDelete ? (d) => openModal('delete_designation', d) : undefined}
                        pagination={pagination}
                        onPageChange={(page) => setPagination(p => ({ ...p, currentPage: page }))}
                        onRowsPerPageChange={(perPage) => setPagination(p => ({ ...p, perPage, currentPage: 1 }))}
                    />
                )}
            </Box>

            {/* Modals placeholders */}
            {(modalState.type === 'add_designation' || modalState.type === 'edit_designation') && (
                <DesignationForm
                    open={true}
                    departments={departments}
                    designations={allDesignations}
                    onClose={closeModal}
                    onSuccess={(d) => handleSuccess(d, modalState.type === 'add_designation' ? 'add' : 'edit')}
                    designation={modalState.designation}
                />
            )}

            {modalState.type === 'delete_designation' && (
                <DeleteDesignationForm
                    open={true}
                    onClose={closeModal}
                    onSuccess={(d) => handleSuccess(d, 'delete')}
                    designation={modalState.designation}
                />
            )}
        </Box>
    );
};

export default DesignationsTab;