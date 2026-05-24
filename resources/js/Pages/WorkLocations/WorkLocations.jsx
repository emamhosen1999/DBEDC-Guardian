import {
    Box, Flex, Grid, Text, Heading, Button, IconButton, Card, Separator,
    Dialog, AlertDialog, Select, TextField, TextArea, Checkbox, Switch,
    RadioGroup, Radio, Badge, Spinner, Skeleton, ScrollArea, Table,
    Tabs, Tooltip, DropdownMenu, Progress, Callout, Inset,
} from '@radix-ui/themes';
import React, { useEffect, useState, useCallback, useMemo } from 'react';

import { 
    MapPinIcon, 
    PlusIcon,
    ChartBarIcon,
    MagnifyingGlassIcon,
    CheckCircleIcon,
    ClockIcon,
    ExclamationTriangleIcon,
    UserIcon
} from "@heroicons/react/24/outline";
import { Head } from "@inertiajs/react";
import App from "@/Layouts/App.jsx";
import WorkLocationsTable from '@/Tables/WorkLocationsTable.jsx';

import StatsCards from "@/Components/StatsCards.jsx";
import { useMediaQuery } from '@/Hooks/useMediaQuery.js';
import WorkLocationForm from "@/Forms/WorkLocationForm.jsx";
import DeleteWorkLocationForm from "@/Forms/DeleteWorkLocationForm.jsx";
import axios from "axios";
import { showToast } from "@/utils/toastUtils";

const WorkLocations = React.memo(({ auth, title, jurisdictions, users }) => {
    const isLargeScreen = useMediaQuery('(min-width: 1025px)');
    const isMediumScreen = useMediaQuery('(min-width: 641px) and (max-width: 1024px)');
    const isMobile = useMediaQuery('(max-width: 640px)');

    // Helper function to convert theme borderRadius to HeroUI radius values
    const getThemeRadius = () => {
        if (typeof window === 'undefined') return 'lg';
        
        const rootStyles = getComputedStyle(document.documentElement);
        const borderRadius = rootStyles.getPropertyValue('--borderRadius')?.trim() || '12px';
        
        const radiusValue = parseInt(borderRadius);
        if (radiusValue === 0) return 'none';
        if (radiusValue <= 4) return 'sm';
        if (radiusValue <= 8) return 'md';
        if (radiusValue <= 16) return 'lg';
        return 'full';
    };

    const [data, setData] = useState(jurisdictions || []);
    const [loading, setLoading] = useState(false);
    const [currentRow, setCurrentRow] = useState();
    const [locationIdToDelete, setLocationIdToDelete] = useState(null);
    const [openModalType, setOpenModalType] = useState(null);
    const [search, setSearch] = useState('');

    const handleSearch = useCallback((event) => {
        setSearch(event.target.value);
    }, []);

    const handleDelete = () => {
        // Optimistic UI: remove from local state immediately
        const previousData = [...data];
        setData(prevData => prevData.filter(location => location.id !== locationIdToDelete));

        router.delete(route('work-locations.destroy', { id: locationIdToDelete }), {
            preserveState: true,
            preserveScroll: true,
            onSuccess: (page) => {
                showToast.success('Work location deleted successfully!');
                setData(page.props.work_locations || []);
            },
            onError: (errors) => {
                // Revert optimistic update on error
                setData(previousData);
                showToast.error('Failed to delete work location. Please try again.');
            }
        });
    };

    const handleClickOpen = useCallback((locationId, modalType) => {
        setLocationIdToDelete(locationId);
        setOpenModalType(modalType);
    }, []);

    const handleClose = useCallback(() => {
        setOpenModalType(null);
        setLocationIdToDelete(null);
    }, []);

    const openModal = useCallback((modalType) => {
        setOpenModalType(modalType);
    }, []);

    const closeModal = useCallback(() => {
        setOpenModalType(null);
    }, []);

    // Statistics
    const stats = useMemo(() => {
        const totalLocations = data.length;
        const activeLocations = data.filter(location => location.incharge_user).length;
        const pendingLocations = data.filter(location => !location.incharge_user).length;

        return [
            {
                title: 'Total Locations',
                value: totalLocations,
                icon: <ChartBarIcon className="w-5 h-5" />,
                color: 'text-blue-600',
                description: 'All work locations'
            },
            {
                title: 'Active',
                value: activeLocations,
                icon: <CheckCircleIcon className="w-5 h-5" />,
                color: 'text-green-600',
                description: 'With assigned staff'
            },
            {
                title: 'Pending',
                value: pendingLocations,
                icon: <ClockIcon className="w-5 h-5" />,
                color: 'text-orange-600',
                description: 'Needs assignment'
            },
            {
                title: 'Staff',
                value: users?.length || 0,
                icon: <UserIcon className="w-5 h-5" />,
                color: 'text-purple-600',
                description: 'Available staff'
            }
        ];
    }, [data, users]);

    // Action buttons configuration
    const actionButtons = [
        ...(auth.roles.includes('Administrator') || auth.permissions.includes('jurisdiction.create') ? [{
            label: "Add Location",
            icon: <PlusIcon className="w-4 h-4" />,
            onPress: () => openModal('addWorkLocation'),
            className: "bg-linear-to-r from-blue-500 to-purple-500 text-white font-medium"
        }] : []),
    ];

    // Filter data based on search
    const filteredData = useMemo(() => {
        if (!search) return data;
        return data.filter(location => 
            location.location?.toLowerCase().includes(search.toLowerCase()) ||
            location.start_chainage?.toLowerCase().includes(search.toLowerCase()) ||
            location.end_chainage?.toLowerCase().includes(search.toLowerCase()) ||
            location.incharge_user?.name?.toLowerCase().includes(search.toLowerCase())
        );
    }, [data, search]);

    return (
        <>
            <Head title={title} />

            {/* Modals */}
            {openModalType === 'addWorkLocation' && (
                <WorkLocationForm
                    modalType="add"
                    open={openModalType === 'addWorkLocation'}
                    setData={setData}
                    closeModal={closeModal}
                    users={users}
                />
            )}
            {openModalType === 'editWorkLocation' && (
                <WorkLocationForm
                    modalType="update"
                    open={openModalType === 'editWorkLocation'}
                    currentRow={currentRow}
                    setData={setData}
                    closeModal={closeModal}
                    users={users}
                />
            )}
            {openModalType === 'deleteWorkLocation' && (
                <DeleteWorkLocationForm
                    open={openModalType === 'deleteWorkLocation'}
                    handleClose={handleClose}
                    handleDelete={handleDelete}
                />
            )}

            <div className="flex justify-center p-4">
                <div
                    initial={{ opacity: 0, y: 20 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.3 }}
                    className="w-full max-w-[2000px]"
                >
                    <Card
                        className="transition-all duration-200"
                        style={{
                            borderRadius: `var(--borderRadius, 12px)`,
                            fontFamily: `var(--fontFamily, "Inter")`,
                            transform: `scale(var(--scale, 1))`,
                        }}
                    >
                        {/* Main Card Content */}
                        <CardHeader 
                            className="border-b p-0"
                            style={{
                                borderColor: `var(--theme-divider, #E4E4E7)`,
                                background: `linear-gradient(135deg, 
                                    color-mix(in srgb, var(--theme-content1) 50%, transparent) 20%, 
                                    color-mix(in srgb, var(--theme-content2) 30%, transparent) 10%)`,
                            }}
                        >
                            <div className={`${isLargeScreen ? 'p-6' : isMediumScreen ? 'p-4' : 'p-3'} w-full`}>
                                <div className="flex flex-col space-y-4">
                                    {/* Main Header Content */}
                                    <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                                        {/* Title Section */}
                                        <div className="flex items-center gap-3 lg:gap-4">
                                            <div 
                                                className={`
                                                    ${isLargeScreen ? 'p-3' : isMediumScreen ? 'p-2.5' : 'p-2'} 
                                                    rounded-xl flex items-center justify-center
                                                `}
                                                style={{
                                                    background: `color-mix(in srgb, var(--theme-primary) 15%, transparent)`,
                                                    borderColor: `color-mix(in srgb, var(--theme-primary) 25%, transparent)`,
                                                    borderWidth: `var(--borderWidth, 2px)`,
                                                    borderRadius: `var(--borderRadius, 12px)`,
                                                }}
                                            >
                                                <MapPinIcon 
                                                    className={`
                                                        ${isLargeScreen ? 'w-8 h-8' : isMediumScreen ? 'w-6 h-6' : 'w-5 h-5'}
                                                    `}
                                                    style={{ color: 'var(--theme-primary)' }}
                                                />
                                            </div>
                                            <div className="min-w-0 flex-1">
                                                <h4 
                                                    className={`
                                                        ${isLargeScreen ? 'text-2xl' : isMediumScreen ? 'text-xl' : 'text-lg'}
                                                        font-bold text-foreground
                                                        ${!isLargeScreen ? 'truncate' : ''}
                                                    `}
                                                    style={{
                                                        fontFamily: `var(--fontFamily, "Inter")`,
                                                    }}
                                                >
                                                    Work Locations Management
                                                </h4>
                                                <p 
                                                    className={`
                                                        ${isLargeScreen ? 'text-sm' : 'text-xs'} 
                                                        text-default-500
                                                        ${!isLargeScreen ? 'truncate' : ''}
                                                    `}
                                                    style={{
                                                        fontFamily: `var(--fontFamily, "Inter")`,
                                                    }}
                                                >
                                                    Manage jurisdictions and work location assignments
                                                </p>
                                            </div>
                                        </div>
                                        {/* Action Buttons */}
                                        <div className="flex items-center gap-4">
                                            <div className="flex items-center gap-2">
                                                {actionButtons.map((button, index) => (
                                                    <Button
                                                        key={index}
                                                        size={isLargeScreen ? "md" : "sm"}
                                                        variant={button.variant || "flat"}
                                                        color={button.color || "primary"}
                                                        startContent={button.icon}
                                                        onClick={button.onPress}
                                                        className={`${button.className || ''} font-medium`}
                                                        style={{
                                                            fontFamily: `var(--fontFamily, "Inter")`,
                                                            borderRadius: `var(--borderRadius, 12px)`,
                                                        }}
                                                    >
                                                        {button.label}
                                                    </Button>
                                                ))}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </CardHeader>
                        
                        <CardBody className="pt-6">
                            {/* Quick Stats */}
                            <StatsCards stats={stats} />
                            
                            {/* Search Section */}
                            <div className="mb-6">
                                <div className="w-full sm:w-auto sm:min-w-[300px]">
                                    <TextField.Root
                                        type="text"
                                        placeholder="Search by location, chainage, or incharge..."
                                        value={search}
                                        onChange={(e) => handleSearch(e)}
                                        variant="outline"
                                        size={isMobile ? "sm" : "md"}
                                        radius={getThemeRadius()}
                                        startContent={
                                            <MagnifyingGlassIcon className="w-4 h-4 text-default-400" />
                                        }
                                        classNames={{
                                            input: "text-foreground",
                                            inputWrapper: `bg-content2/50 hover:bg-content2/70 
                                                         focus-within:bg-content2/90 border-divider/50 
                                                         hover:border-divider data-[focus]:border-primary`,
                                        }}
                                        style={{
                                            fontFamily: `var(--fontFamily, "Inter")`,
                                            borderRadius: `var(--borderRadius, 12px)`,
                                        }}
                                    />
                                </div>
                            </div>

                            {/* Work Locations Table */}
                            <Card
                                radius={getThemeRadius()}
                                style={{
                                    fontFamily: `var(--fontFamily, "Inter")`,
                                    borderRadius: `var(--borderRadius, 12px)`,
                                }}
                            >
                                <CardBody className="p-4">
                                    <WorkLocationsTable
                                        allData={filteredData}
                                        setData={setData}
                                        loading={loading}
                                        setLoading={setLoading}
                                        handleClickOpen={handleClickOpen}
                                        openModal={openModal}
                                        setCurrentRow={setCurrentRow}
                                        users={users}
                                        auth={auth}
                                    />
                                </CardBody>
                            </Card>
                        </CardBody>
                    </Card>
                </div>
            </div>
        </>
    );
});

WorkLocations.layout = (page) => <App>{page}</App>;

export default WorkLocations;
