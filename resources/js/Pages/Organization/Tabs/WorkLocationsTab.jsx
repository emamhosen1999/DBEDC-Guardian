import React, { useState, useEffect, useCallback, useMemo } from 'react';
import { usePage } from "@inertiajs/react";
import axios from "axios";
import { showToast } from "@/utils/toastUtils";
import { useMediaQuery } from '@/Hooks/useMediaQuery.js';
import { useQueryFilters } from '@/Hooks/useQueryFilters';
import { Box, Flex, Text, Button, TextField, Spinner, Badge, IconButton, Grid, Separator } from '@radix-ui/themes';
import { 
    SewingPinIcon, PlusIcon, MagnifyingGlassIcon, 
    Cross2Icon, ReloadIcon
} from '@radix-ui/react-icons';
import * as useWorkLocationsQuery from '@/api/queries/useWorkLocationsQuery';
import StatsCards from '@/Components/StatsCards';
import SearchFilterBar from '@/Components/SearchFilterBar';
import PageToolbar from '@/Components/PageToolbar';

import WorkLocationsTable from '../Tables/WorkLocationsTable.jsx';
import WorkLocationForm from '../Components/WorkLocationForm.jsx';
import DeleteWorkLocationForm from '../Components/DeleteWorkLocationForm.jsx';

const EMPTY_ARRAY = [];

const WorkLocationsTab = ({ isActive }) => {
    const { auth, users, attendanceTypes } = usePage().props;
    const isMobile = useMediaQuery('(max-width: 767px)');

    // Modal states
    const [modalType, setModalType] = useState(null); // 'add', 'update', 'delete', null
    const [currentRow, setCurrentRow] = useState(null);
    /* The search narrows the locations already on screen, but it is worth
       surviving a refresh and sharing, so it goes in the URL. Client-side only —
       no request is issued when it changes. */
    const f = useQueryFilters({ mode: 'client', defaults: { search: '' } });
    const search = f.values.search;
    const setSearch = (value) => f.setDraft('search', value);

    const canCreate = auth.permissions?.includes('attendance.settings') || auth.roles?.includes('Super Administrator') || false;

    // React Query hooks — use a stable empty array so dependent memos don't loop
    const { data, isLoading: loading, refetch } = useWorkLocationsQuery.useWorkLocationsList();
    const allData = data ?? EMPTY_ARRAY;

    // Auto-refetch when tab becomes active
    useEffect(() => {
        if (isActive) {
            refetch();
        }
    }, [isActive, refetch]);

    // Client-side filtering (derived, no state — avoids render loops)
    const filteredData = useMemo(() => {
        if (!search) return allData;
        const lowerSearch = search.toLowerCase();
        return allData.filter(loc => loc.name && loc.name.toLowerCase().includes(lowerSearch));
    }, [search, allData]);

    const openModal = (type, row = null) => {
        setModalType(type);
        setCurrentRow(row);
    };

    const closeModal = () => {
        setModalType(null);
        setCurrentRow(null);
    };

    const handleSuccess = () => {
        refetch();
        closeModal();
    };

    const statPills = [
        { key: 'total', label: 'Total Locations', value: allData?.length || 0, color: 'blue' },
        { key: 'rules', label: 'With Rules Set', value: allData?.filter(d => d.attendance_type_id).length || 0, color: 'green' },
    ];

    const activeFilterChips = useMemo(() => {
        if (!search) return [];
        return [{ label: 'Search', value: search, onRemove: () => setSearch('') }];
    }, [search]);

    return (
        <Box>
            {/* Quick Stats Pills */}
            <StatsCards stats={statPills} variant="pill" mb="4" />

            {/* Toolbar & Search Bar */}
            <PageToolbar
                onRefresh={() => refetch()}
                refreshLoading={loading}
                canAdd={canCreate}
                onAdd={() => openModal('add')}
                addLabel={!isMobile ? 'Add Location' : 'Add'}
                leftSlot={
                    <SearchFilterBar
                        searchValue={f.draft.search}
                        onSearchChange={setSearch}
                        searchPlaceholder="Search locations by name..."
                        showFilterToggle={false}
                        activeFilterChips={activeFilterChips}
                        onClearFilters={search ? () => setSearch('') : null}
                        mb="0"
                    />
                }
            />

            {/* Data Table */}
            <Box>
                <WorkLocationsTable
                    allData={filteredData}
                    loading={loading}
                    onEdit={(row) => openModal('update', row)}
                    onDelete={(row) => openModal('delete', row)}
                    isMobile={isMobile}
                    auth={auth}
                />
            </Box>

            {/* Modals */}
            {(modalType === 'add' || modalType === 'update') && (
                <WorkLocationForm
                    modalType={modalType}
                    open={true}
                    closeModal={closeModal}
                    onSuccess={handleSuccess}
                    currentRow={currentRow}
                    users={users}
                    attendanceTypes={attendanceTypes}
                />
            )}

            {modalType === 'delete' && (
                <DeleteWorkLocationForm
                    open={true}
                    handleClose={closeModal}
                    handleDelete={handleSuccess} 
                    currentRow={currentRow}
                />
            )}
        </Box>
    );
};

export default WorkLocationsTab;