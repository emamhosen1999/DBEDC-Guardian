import { Panel } from '@/Components/ui/Panel';
import React, { useState, useEffect, useCallback, useMemo } from 'react';
import { Head, usePage } from '@inertiajs/react';
import { Box, Flex, Grid, Heading, Text, Button, TextField, Select, Separator, Spinner } from '@radix-ui/themes';
import { 
    LayersIcon, CheckCircledIcon, CrossCircledIcon, 
    PersonIcon, MagnifyingGlassIcon, PlusIcon 
} from '@radix-ui/react-icons';
import App from '@/Layouts/App.jsx';
import axios from 'axios';
import { showToast } from '@/utils/toastUtils';
import { useMediaQuery } from '@/Hooks/useMediaQuery.js';
import { useQueryFilters, useClampPage } from '@/Hooks/useQueryFilters';

import DesignationTable from '@/Tables/DesignationTable.jsx';
import DesignationForm from '@/Forms/DesignationForm.jsx';
import DeleteDesignationForm from '@/Forms/DeleteDesignationForm.jsx';
import ErrorBoundary from '@/Components/ErrorBoundary/ErrorBoundary';
import * as useDesignationsQuery from '@/api/queries/useDesignationsQuery';

const Designations = ({ title, initialDesignations, departments, allDesignations, stats: initialStats, filters: initialFilters }) => {
    const { auth } = usePage().props;
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

    /* ── server-driven state lives in the URL, so a refresh or a copied link
         reproduces this list. Rows come from react-query, so filter changes
         update the URL client-side without a server round trip.
         `department` defaults to the busiest department, preserving the
         pre-existing preselection; that value is computed from props that are
         present on first render, so it is stable. ── */
    const f = useQueryFilters({
        mode: 'client',
        defaults: {
            search: '',
            status: 'all',
            department: defaultDepartment,
            page: 1,
            per_page: 10,
        },
    });

    const pagination = { currentPage: f.values.page, perPage: f.values.per_page };

    const { data: designationsData, isLoading: loading, refetch } = useDesignationsQuery.useDesignationsList({
        page: f.values.page,
        per_page: f.values.per_page,
        search: f.values.search,
        status: f.values.status,
        department: f.values.department !== 'all' ? f.values.department : undefined
    });

    const { data: stats } = useDesignationsQuery.useDesignationStats();
    useClampPage(f, designationsData?.last_page);

    const canCreateDesignation = auth.permissions?.includes('designations.create') || false;
    const canEditDesignation = auth.permissions?.includes('designations.update') || false;
    const canDeleteDesignation = auth.permissions?.includes('designations.delete') || false;

    const handlePageChange = (page) => {
        f.setPage(page);
        window.scrollTo({ top: 0, behavior: 'smooth' });
    };

    const handleRowsPerPageChange = (newPerPage) => f.setPerPage(newPerPage);

    const openModal = (type, designation = null) => setModalState({ type, designation });
    const closeModal = () => setModalState({ type: null, designation: null });

    const handleSuccess = () => {
        refetch();
    };

    // Radix Stats Cards Configuration
    const statsCards = [
        { title: 'Total Designations', value: stats?.total ?? initialStats?.total ?? 0, icon: <LayersIcon width="20" height="20" />, color: 'var(--blue-9)', bg: 'var(--blue-3)' },
        { title: 'Active', value: stats?.active ?? initialStats?.active ?? 0, icon: <CheckCircledIcon width="20" height="20" />, color: 'var(--green-9)', bg: 'var(--green-3)' },
        { title: 'Inactive', value: stats?.inactive ?? initialStats?.inactive ?? 0, icon: <CrossCircledIcon width="20" height="20" />, color: 'var(--red-9)', bg: 'var(--red-3)' },
        { title: 'Top-Level', value: stats?.parent_designations ?? initialStats?.parent_designations ?? 0, icon: <PersonIcon width="20" height="20" />, color: 'var(--purple-9)', bg: 'var(--purple-3)' }
    ];

    return (
        <App>
            <Head title={title || "Designations"} />
            
            <Flex justify="center" p="4">
                <Box style={{ width: '100%', maxWidth: 2000 }}>
                    <Panel>
                        {/* ── Page Header ── */}
                        <Box mb="4">
                            <Flex justify="between" align={{ initial: 'start', sm: 'center' }} direction={{ initial: 'column', sm: 'row' }} gap="4">
                                <Flex align="center" gap="3">
                                    <Box p="3" style={{ background: 'var(--blue-a3)', borderRadius: 12, border: '1px solid var(--blue-a5)', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                                        <LayersIcon style={{ width: 22, height: 22, color: 'var(--blue-9)' }} />
                                    </Box>
                                    <Box>
                                        <Heading size="5" style={{ fontFamily: `'Space Grotesk', system-ui, sans-serif`, fontWeight: 800, letterSpacing: '-0.02em' }}>Designation Management</Heading>
                                        <Text size="2" style={{ color: 'var(--aero-color-subtle, var(--gray-9))' }}>Manage company designations and hierarchy</Text>
                                    </Box>
                                </Flex>
                                {canCreateDesignation && (
                                    <Button onClick={() => openModal('add_designation')} color="blue" style={{ borderRadius: 12, fontFamily: `'Space Grotesk', system-ui, sans-serif`, fontWeight: 600 }}>
                                        <PlusIcon /> {isMobile ? "Add" : "Add Designation"}
                                    </Button>
                                )}
                            </Flex>
                        </Box>

                        <Separator size="4" mb="4" style={{ background: 'var(--dl-border-color, rgba(0,0,0,0.06))' }} />

                        {/* ── Stats Row ── */}
                        <Box mb="4">
                            <Grid columns={{ initial: '1', sm: '2', md: '4' }} gap="3">
                                {statsCards.map((stat, idx) => (
                                    <Panel key={idx} tinted style={{ borderRadius: 16, border: '1px solid var(--aero-surface-border, rgba(0,0,0,0.06))', padding: '18px 16px', background: 'var(--aero-surface, var(--color-background))' }}>
                                        <Flex align="center" justify="between">
                                            <Box>
                                                <Text size="1" weight="bold" style={{ color: 'var(--aero-color-subtle, var(--gray-9))', fontSize: 11, letterSpacing: '0.05em', textTransform: 'uppercase' }} as="div">{stat.title}</Text>
                                                <Heading size="6" mt="1" style={{ fontFamily: `'Space Grotesk', system-ui, sans-serif`, fontVariantNumeric: 'tabular-nums' }}>{stat.value}</Heading>
                                            </Box>
                                            <Box p="2" style={{ backgroundColor: stat.bg, color: stat.color, borderRadius: 12, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                                                {stat.icon}
                                            </Box>
                                        </Flex>
                                    </Panel>
                                ))}
                            </Grid>
                        </Box>

                        {/* ── Filters ── */}
                        <Flex gap="3" wrap="wrap" mb="4" align="center">
                            <Box style={{ flex: 1, minWidth: '240px' }}>
                                <TextField.Root 
                                    placeholder="Search by title..." 
                                    value={f.draft.search} 
                                    onChange={(e) => f.setDraft('search', e.target.value)}
                                    style={{ borderRadius: 10 }}
                                >
                                    <TextField.Slot><MagnifyingGlassIcon style={{ width: 16, height: 16, color: 'var(--gray-9)' }} /></TextField.Slot>
                                </TextField.Root>
                            </Box>
                            
                            <Box style={{ minWidth: '180px' }}>
                                <Select.Root value={f.values.department} onValueChange={(v) => f.set('department', v)}>
                                    <Select.Trigger style={{ width: '100%', borderRadius: 10 }} />
                                    <Select.Content>
                                        <Select.Item value="all">All Departments</Select.Item>
                                        {departments?.map(dept => (
                                            <Select.Item key={dept.id} value={String(dept.id)}>{dept.name}</Select.Item>
                                        ))}
                                    </Select.Content>
                                </Select.Root>
                            </Box>

                            <Box style={{ minWidth: '150px' }}>
                                <Select.Root value={f.values.status} onValueChange={(v) => f.set('status', v)}>
                                    <Select.Trigger style={{ width: '100%', borderRadius: 10 }} />
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
                            {loading && (!designationsData || !designationsData.data) ? (
                                <TableLoadingSkeleton rows={pagination.perPage || 6} columns={5} />
                            ) : (
                                <ErrorBoundary>
                                    <DesignationTable
                                        designations={designationsData || initialDesignations}
                                        loading={loading}
                                        onEdit={canEditDesignation ? (d) => openModal('edit_designation', d) : undefined}
                                        onDelete={canDeleteDesignation ? (d) => openModal('delete_designation', d) : undefined}
                                        pagination={pagination}
                                        onPageChange={handlePageChange}
                                        onRowsPerPageChange={handleRowsPerPageChange}
                                    />
                                </ErrorBoundary>
                            )}
                        </Box>

                    </Panel>
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