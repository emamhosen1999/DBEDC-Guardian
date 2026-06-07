import React, { useState, useEffect, useCallback, useMemo } from 'react';
import { usePage } from "@inertiajs/react";
import axios from "axios";
import { showToast } from "@/utils/toastUtils";
import { useMediaQuery } from '@/Hooks/useMediaQuery.js';
import { 
    Box, Flex, Text, Button, TextField, 
    Spinner, Badge, IconButton, Grid, Card, Separator 
} from '@radix-ui/themes';
import { 
    SewingPinIcon, PlusIcon, MagnifyingGlassIcon, 
    Cross2Icon, ReloadIcon, MixIcon
} from '@radix-ui/react-icons';
import * as useWorkLocationsQuery from '@/api/queries/useWorkLocationsQuery';

import WorkLocationsTable from '../Tables/WorkLocationsTable.jsx';
// Placeholders for your modal components
import WorkLocationForm from '../Components/WorkLocationForm.jsx';
import DeleteWorkLocationForm from '../Components/DeleteWorkLocationForm.jsx';

const StatPill = ({ label, value, color = 'gray' }) => (
    <Badge size="2" variant="soft" color={color} radius="full">
        <Text weight="bold">{value}</Text>
        <Text color={color} style={{ opacity: 0.7 }}> {label}</Text>
    </Badge>
);

const WorkLocationsTab = ({ isActive }) => {
    const { auth, users } = usePage().props;
    const isMobile = useMediaQuery('(max-width: 767px)');

    // Modal states
    const [modalType, setModalType] = useState(null); // 'add', 'update', 'delete', null
    const [currentRow, setCurrentRow] = useState(null);
    const [search, setSearch] = useState('');
    const [filteredData, setFilteredData] = useState([]);

    const canCreate = auth.permissions?.includes('work_locations.create') || false;

    // React Query hooks
    const { data: allData = [], isLoading: loading, refetch } = useWorkLocationsQuery.useWorkLocationsList();

    // Auto-refetch when tab becomes active
    useEffect(() => {
        if (isActive) {
            refetch();
        }
    }, [isActive, refetch]);

    // Client-side filtering
    useEffect(() => {
        if (!search) {
            setFilteredData(allData);
        } else {
            const lowerSearch = search.toLowerCase();
            const filtered = allData.filter(loc => 
                (loc.location && loc.location.toLowerCase().includes(lowerSearch)) ||
                (loc.incharge_user?.name && loc.incharge_user.name.toLowerCase().includes(lowerSearch))
            );
            setFilteredData(filtered);
        }
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

    return (
        <Box>
            {/* Quick Stats */}
            <Flex wrap="wrap" gap="2" mb="4">
                <StatPill label="Total Locations" value={allData?.length || 0} color="blue" />
                <StatPill label="With In-Charge" value={allData?.filter(d => d.incharge_id).length || 0} color="green" />
            </Flex>

            {/* Toolbar */}
            <Flex gap="3" wrap="wrap" mb="5" align="center" justify="between">
                <Flex gap="3" align="center" style={{ flex: 1, maxWidth: 400 }}>
                    <TextField.Root 
                        placeholder="Search locations or in-charge..." 
                        value={search} 
                        onChange={(e) => setSearch(e.target.value)}
                        style={{ width: '100%' }}
                    >
                        <TextField.Slot><MagnifyingGlassIcon /></TextField.Slot>
                        {search && (
                            <TextField.Slot side="right">
                                <IconButton size="1" variant="ghost" color="gray" onClick={() => setSearch('')}>
                                    <Cross2Icon />
                                </IconButton>
                            </TextField.Slot>
                        )}
                    </TextField.Root>
                </Flex>

                <Flex gap="2">
                    <Button size="2" variant="soft" color="gray" onClick={() => refetch()}>
                        <ReloadIcon />
                    </Button>
                    {canCreate && (
                        <Button color="indigo" onClick={() => openModal('add')}>
                            <PlusIcon /> {!isMobile && "Add Location"}
                        </Button>
                    )}
                </Flex>
            </Flex>

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