import React, { useState, useEffect, useCallback, useMemo } from 'react';
import { Head, usePage } from '@inertiajs/react';
import { 
    Box, Card, Flex, Grid, Heading, Text, 
    Button, TextField, Select, Separator, Spinner 
} from '@radix-ui/themes';
import { 
    LayersIcon, CheckCircledIcon, CrossCircledIcon, 
    PersonIcon, MagnifyingGlassIcon, PlusIcon 
} from '@radix-ui/react-icons';
import App from '@/Layouts/App.jsx';
import axios from 'axios';
import { showToast } from '@/utils/toastUtils';
import { useMediaQuery } from '@/Hooks/useMediaQuery.js';

import DesignationTable from '@/Tables/DesignationTable.jsx';
import DesignationForm from '@/Forms/DesignationForm.jsx';
import DeleteDesignationForm from '@/Forms/DeleteDesignationForm.jsx';

const Designations = ({ title, initialDesignations, departments, allDesignations, stats: initialStats, filters: initialFilters }) => {
    const { auth } = usePage().props;
    const isMobile = useMediaQuery('(max-width: 767px)');

    const [designationsData, setDesignationsData] = useState(initialDesignations || { data: [], total: 0 });
    const [loading, setLoading] = useState(false);
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

    const [filters, setFilters] = useState({
        search: initialFilters?.search || '',
        status: initialFilters?.status || 'all',
        department: initialFilters?.department || defaultDepartment,
    });
    
    const [pagination, setPagination] = useState({
        currentPage: initialDesignations?.current_page || 1,
        perPage: initialDesignations?.per_page || 10
    });

    const [stats, setStats] = useState(initialStats || {
        total: 0, active: 0, inactive: 0, parent_designations: 0
    });

    const canCreateDesignation = auth.permissions?.includes('designations.create') || false;
    const canEditDesignation = auth.permissions?.includes('designations.update') || false;
    const canDeleteDesignation = auth.permissions?.includes('designations.delete') || false;

    const fetchDesignations = useCallback(async () => {
        setLoading(true);
        try {
            const response = await axios.get(route('designations.json'), {
                params: {
                    page: pagination.currentPage,
                    per_page: pagination.perPage,
                    search: filters.search,
                    status: filters.status,
                    department: filters.department !== 'all' ? filters.department : undefined
                }
            });
            setDesignationsData(response.data.designations || response.data);
        } catch (error) {
            showToast.error('Failed to load designations data');
        } finally {
            setLoading(false);
        }
    }, [pagination, filters]);

    const fetchDesignationStats = useCallback(async () => {
        try {
            const response = await axios.get(route('designations.stats'));
            if (response.status === 200) {
                setStats(response.data.stats);
            }
        } catch (error) {
            console.error('Error fetching stats:', error);
        }
    }, []);

    useEffect(() => {
        fetchDesignations();
        fetchDesignationStats();
    }, [fetchDesignations, fetchDesignationStats]);

    const handleFilterChange = (key, value) => {
        setFilters(prev => ({ ...prev, [key]: value }));
        setPagination(prev => ({ ...prev, currentPage: 1 }));
    };

    const handlePageChange = (page) => {
        setPagination(prev => ({ ...prev, currentPage: page }));
        window.scrollTo({ top: 0, behavior: 'smooth' });
    };

    const handleRowsPerPageChange = (newPerPage) => {
        setPagination(prev => ({ ...prev, perPage: newPerPage, currentPage: 1 }));
    };

    const openModal = (type, designation = null) => setModalState({ type, designation });
    const closeModal = () => setModalState({ type: null, designation: null });

    const handleSuccess = (updatedDesignation = null, action = null) => {
        if (action === 'add' && updatedDesignation) {
            setDesignationsData(prev => ({ ...prev, data: [updatedDesignation, ...prev.data], total: (prev.total || 0) + 1 }));
        } else if (action === 'edit' && updatedDesignation) {
            setDesignationsData(prev => ({ ...prev, data: prev.data.map(d => d.id === updatedDesignation.id ? updatedDesignation : d) }));
        } else if (action === 'delete' && updatedDesignation) {
            setDesignationsData(prev => ({ ...prev, data: prev.data.filter(d => d.id !== updatedDesignation.id), total: (prev.total || 1) - 1 }));
        } else {
            fetchDesignations();
            fetchDesignationStats();
        }
    };

    // Radix Stats Cards Configuration
    const statsCards = [
        { title: 'Total Designations', value: stats.total, icon: <LayersIcon width="20" height="20" />, color: 'var(--blue-9)', bg: 'var(--blue-3)' },
        { title: 'Active', value: stats.active, icon: <CheckCircledIcon width="20" height="20" />, color: 'var(--green-9)', bg: 'var(--green-3)' },
        { title: 'Inactive', value: stats.inactive, icon: <CrossCircledIcon width="20" height="20" />, color: 'var(--red-9)', bg: 'var(--red-3)' },
        { title: 'Top-Level', value: stats.parent_designations, icon: <PersonIcon width="20" height="20" />, color: 'var(--purple-9)', bg: 'var(--purple-3)' }
    ];

    return (
        <App>
            <Head title={title || "Designations"} />
            
            <Flex justify="center" p="4">
                <Box style={{ width: '100%', maxWidth: 2000 }}>
                    <Card size="4">
                        {/* ── Page Header ── */}
                        <Box mb="5">
                            <Flex justify="between" align="center" direction={{ initial: 'column', sm: 'row' }} gap="4">
                                <Flex align="center" gap="3">
                                    <Box p="3" style={{ background: 'var(--accent-a3)', borderRadius: 'var(--radius-2)' }}>
                                        <LayersIcon style={{ width: 22, height: 22, color: 'var(--accent-9)' }} />
                                    </Box>
                                    <Box>
                                        <Heading size="5">Designation Management</Heading>
                                        <Text size="2" color="gray">Manage company designations and hierarchy</Text>
                                    </Box>
                                </Flex>
                                {canCreateDesignation && (
                                    <Button onClick={() => openModal('add_designation')} color="indigo">
                                        <PlusIcon /> {isMobile ? "Add" : "Add Designation"}
                                    </Button>
                                )}
                            </Flex>
                        </Box>

                        <Separator size="4" mb="5" />

                        {/* ── Stats Row ── */}
                        <Grid columns={{ initial: '1', sm: '2', md: '4' }} gap="4" mb="6">
                            {statsCards.map((stat, idx) => (
                                <Card key={idx} variant="surface">
                                    <Flex align="center" gap="3">
                                        <Box p="2" style={{ backgroundColor: stat.bg, color: stat.color, borderRadius: 'var(--radius-2)' }}>
                                            {stat.icon}
                                        </Box>
                                        <Box>
                                            <Text size="2" color="gray" as="div">{stat.title}</Text>
                                            <Text size="5" weight="bold">{stat.value}</Text>
                                        </Box>
                                    </Flex>
                                </Card>
                            ))}
                        </Grid>

                        {/* ── Filters ── */}
                        <Flex gap="3" wrap="wrap" mb="5" align="end">
                            <Box style={{ flexGrow: 1, minWidth: '250px' }}>
                                <Text size="2" weight="medium" mb="1" display="block">Search Designations</Text>
                                <TextField.Root 
                                    placeholder="Search by title..." 
                                    value={filters.search} 
                                    onChange={(e) => handleFilterChange('search', e.target.value)}
                                >
                                    <TextField.Slot><MagnifyingGlassIcon /></TextField.Slot>
                                </TextField.Root>
                            </Box>
                            
                            <Box style={{ minWidth: '200px' }}>
                                <Text size="2" weight="medium" mb="1" display="block">Department</Text>
                                <Select.Root value={filters.department} onValueChange={(v) => handleFilterChange('department', v)}>
                                    <Select.Trigger style={{ width: '100%' }} />
                                    <Select.Content>
                                        <Select.Item value="all">All Departments</Select.Item>
                                        {departments?.map(dept => (
                                            <Select.Item key={dept.id} value={String(dept.id)}>{dept.name}</Select.Item>
                                        ))}
                                    </Select.Content>
                                </Select.Root>
                            </Box>

                            <Box style={{ minWidth: '150px' }}>
                                <Text size="2" weight="medium" mb="1" display="block">Status</Text>
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

                        {/* ── Data Table ── */}
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
                                    onEdit={canEditDesignation ? (d) => openModal('edit_designation', d) : undefined}
                                    onDelete={canDeleteDesignation ? (d) => openModal('delete_designation', d) : undefined}
                                    pagination={pagination}
                                    onPageChange={handlePageChange}
                                    onRowsPerPageChange={handleRowsPerPageChange}
                                />
                            )}
                        </Box>

                    </Card>
                </Box>
            </Flex>

            {/* ── Modals ── */}
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
        </App>
    );
};

export default Designations;