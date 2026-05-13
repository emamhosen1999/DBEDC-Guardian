import React, { useEffect, useState, useCallback, useMemo, useRef } from 'react';
import { Head } from "@inertiajs/react";
import { route } from 'ziggy-js';
import {
    Button,
    Card,
    Box,
    Flex,
    Text,
    TextField,
    Tabs,
    Select,
    Badge,
} from '@radix-ui/themes';
import {
    CalendarIcon,
    ActivityLogIcon,
    CountdownTimerIcon,
    PersonIcon,
    PlusIcon,
    DownloadIcon,
    CheckCircledIcon,
    ExclamationTriangleIcon,
    LayersIcon,
    HomeIcon,
    FileTextIcon,
    ReloadIcon,
    MixerHorizontalIcon,
    TargetIcon,
    MagnifyingGlassIcon,
    TableIcon,
    BarChartIcon,
} from '@radix-ui/react-icons';
import App from "@/Layouts/App.jsx";
import DailyWorkSummaryTable from '@/Tables/DailyWorkSummaryTable.jsx';
import StatsCards from "@/Components/StatsCards.jsx";
import DailyWorkSummaryAnalytics from "@/Components/DailyWorkSummaryAnalytics.jsx";
import EnhancedDailyWorkSummaryExportForm from "@/Forms/EnhancedDailyWorkSummaryExportForm.jsx";

import { useMediaQuery } from '@/Hooks/useMediaQuery.js';
import dayjs from 'dayjs';
import minMax from 'dayjs/plugin/minMax';
import isBetween from 'dayjs/plugin/isBetween';
import axios from 'axios';
import { showToast } from '@/utils/toastUtils';

dayjs.extend(minMax);
dayjs.extend(isBetween);

const DailyWorkSummary = ({ auth, title, summary, jurisdictions, inCharges, overallStartDate, overallEndDate }) => {
    const isLargeScreen = useMediaQuery('(min-width: 1025px)');
    const isMediumScreen = useMediaQuery('(min-width: 641px) and (max-width: 1024px)');
    const isMobile = useMediaQuery('(max-width: 640px)');

    const [dailyWorkSummary] = useState(summary);
    const [filteredData, setFilteredData] = useState(summary);
    const [loading, setLoading] = useState(false);
    const [openModalType, setOpenModalType] = useState(null);
    const [search, setSearch] = useState('');
    
    // Show/Hide advanced filters panel
    const [showFilters, setShowFilters] = useState(false);

    // Active tab: 'table' or 'analytics'
    const [activeTab, setActiveTab] = useState('table');

    // Ref to the analytics component for displaying analytics
    const analyticsRef = useRef(null);

    const openModal = useCallback((modalType) => {
        console.log('Opening modal:', modalType);
        setOpenModalType(modalType);
    }, []);

    const closeModal = useCallback(() => {
        setOpenModalType(null);
    }, []);

    const renderSelectedBadges = useCallback((selectedIds, options, placeholder, labelKey = 'name') => {
        if (!selectedIds || selectedIds.length === 0) {
            return <Text size="1" color="gray">{placeholder}</Text>;
        }
        const normalized = selectedIds.map(String);
        const labels = options
            ?.filter((option) => normalized.includes(String(option.id)))
            .map((option) => option[labelKey]) ?? [];
        if (labels.length === 0) {
            return <Text size="1" color="gray">{placeholder}</Text>;
        }
        return (
            <Flex wrap="wrap" gap="1">
                {labels.map((label) => (
                    <Badge key={label} size="1" variant="soft">{label}</Badge>
                ))}
            </Flex>
        );
    }, []);

    const jurisdictionOptions = useMemo(() => {
        return jurisdictions?.map((j) => ({
            ...j,
            displayLabel: `${j.start_chainage} - ${j.end_chainage}`,
        })) ?? [];
    }, [jurisdictions]);

    // Use backend-provided date boundaries (consistent with DailyWorks)
    const [filterData, setFilterData] = useState({
        startDate: overallStartDate || dayjs().subtract(30, 'days').format('YYYY-MM-DD'),
        endDate: overallEndDate || dayjs().format('YYYY-MM-DD'),
        status: 'all',
        type: 'all',
        incharge: [], // Array for multi-select
        jurisdiction: [], // Array for multi-select
    });

    const fetchFilteredSummaries = useCallback(async () => {
        setLoading(true);
        try {
            const payload = {
                startDate: filterData.startDate,
                endDate: filterData.endDate,
                status: filterData.status,
                type: filterData.type,
            };

            // Include search if provided
            if (search && search.trim()) {
                payload.search = search.trim();
            }

            // Only admins can use incharge/jurisdiction filters
            const isAdmin = auth.roles.includes('Administrator') || auth.roles.includes('Super Administrator') || auth.designation === 'Supervision Engineer';
            if (isAdmin) {
                if (filterData.incharge?.length) {
                    payload.incharge = filterData.incharge;
                }

                if (filterData.jurisdiction?.length) {
                    payload.jurisdiction = filterData.jurisdiction;
                }
            }

            const response = await axios.post(route('daily-works-summary.filter'), payload);
            const summaries = response.data?.summaries ?? [];
            setFilteredData(summaries);

            return true;
        } catch (error) {
            console.error('Failed to load filtered summary:', error);

            const message = error.response?.data?.error || 'Failed to load summary data';
            showToast.error(message);

            return false;
        } finally {
            setLoading(false);
        }
    }, [filterData, search]);

    const handleRefresh = useCallback(async () => {
        const success = await fetchFilteredSummaries();
        if (success) {
            showToast.success('Summary data refreshed successfully');
        }
    }, [fetchFilteredSummaries]);

   

    const handleFilterChange = useCallback((key, value) => {
        setFilterData(prevState => ({
            ...prevState,
            [key]: value,
        }));
    }, []);

    const stats = useMemo(() => {
        const totalWorks = filteredData.reduce((sum, work) => sum + work.totalDailyWorks, 0);
        const totalCompleted = filteredData.reduce((sum, work) => sum + work.completed, 0);
        const totalPending = filteredData.reduce((sum, work) => sum + work.pending, 0);
        const totalRFI = filteredData.reduce((sum, work) => sum + work.rfiSubmissions, 0);
        const avgCompletion = totalWorks > 0 ? ((totalCompleted / totalWorks) * 100).toFixed(1) : 0;

        return [
            {
                title: 'Total Works',
                value: totalWorks,
                icon: <ActivityLogIcon style={{ width: 20, height: 20 }} />,
                color: 'text-blue-600',
                description: 'All logged works'
            },
            {
                title: 'Completed',
                value: totalCompleted,
                icon: <CheckCircledIcon style={{ width: 20, height: 20 }} />,
                color: 'text-green-600',
                description: `${avgCompletion}% completion rate`
            },
            {
                title: 'Pending',
                value: totalPending,
                icon: <CountdownTimerIcon style={{ width: 20, height: 20 }} />,
                color: 'text-orange-600',
                description: 'In progress'
            },
            {
                title: 'RFI Submissions',
                value: totalRFI,
                icon: <FileTextIcon style={{ width: 20, height: 20 }} />,
                color: 'text-purple-600',
                description: 'Ready for inspection'
            }
        ];
    }, [filteredData]);

    // Action buttons configuration
    const canExport = auth.roles.includes('Administrator') || auth.roles.includes('Super Administrator') || auth.designation === 'Supervision Engineer';
    console.log('Export permission check:', { canExport, roles: auth.roles, designation: auth.designation });
    
    const actionButtons = [
        {
            label: "Refresh",
            icon: <ReloadIcon style={{ width: 16, height: 16 }} />,
            variant: "soft",
            color: "indigo",
            onClick: handleRefresh,
            disabled: loading,
            ariaLabel: "Refresh daily work summary data"
        },
        ...(canExport ? [{
            label: "Export",
            icon: <DownloadIcon style={{ width: 16, height: 16 }} />,
            variant: "soft",
            color: "green",
            onClick: () => {
                openModal('export');
            },
            ariaLabel: "Export daily work summary data"
        }] : [])
    ];

    useEffect(() => {
        fetchFilteredSummaries();
    }, [fetchFilteredSummaries]);

    return (
        <>
            <Head title="Daily Work Summary" />
            
            <EnhancedDailyWorkSummaryExportForm
                open={openModalType === 'export'}
                closeModal={closeModal}
                filteredData={filteredData}
                inCharges={inCharges}
                currentFilters={filterData}
                auth={auth}
            />

            <Flex justify="center" p="4">
                <Box style={{ width: '100%', maxWidth: 2000 }}>
                    <Card>
                        {/* Header */}
                        <Box
                            px={isLargeScreen ? '6' : isMediumScreen ? '4' : '3'}
                            py={isLargeScreen ? '5' : isMediumScreen ? '4' : '3'}
                            style={{ borderBottom: '1px solid var(--gray-a4)' }}
                        >
                            <Flex
                                direction={isLargeScreen ? 'row' : 'column'}
                                align={isLargeScreen ? 'center' : 'start'}
                                justify="between"
                                gap="4"
                            >
                                <Flex align="center" gap="3">
                                    <Box
                                        p={isLargeScreen ? '3' : '2'}
                                        style={{ background: 'var(--accent-a3)', borderRadius: 'var(--radius-2)' }}
                                    >
                                        <ActivityLogIcon style={{ width: isLargeScreen ? 32 : 24, height: isLargeScreen ? 32 : 24, color: 'var(--accent-9)' }} />
                                    </Box>
                                    <Box>
                                        <Text size={isLargeScreen ? '6' : isMediumScreen ? '5' : '4'} weight="bold" as="p">Daily Work Summary</Text>
                                        <Text size={isLargeScreen ? '2' : '1'} color="gray" as="p">Overview of daily work statistics and progress</Text>
                                    </Box>
                                </Flex>
                                <Flex align="center" gap="2">
                                    {actionButtons.map((button, index) => (
                                        <Button
                                            key={index}
                                            size={isLargeScreen ? '2' : '1'}
                                            variant={button.variant}
                                            color={button.color}
                                            onClick={button.onClick}
                                            disabled={button.disabled}
                                            aria-label={button.ariaLabel || button.label}
                                        >
                                            {button.icon}
                                            {button.label}
                                        </Button>
                                    ))}
                                </Flex>
                            </Flex>
                        </Box>

                        {/* Body */}
                        <Box pt="6" px="4" pb="4">
                            <StatsCards stats={stats} isLoading={loading} />
                            
                            {/* Filters Section */}
                            <Box mb="6">
                                <Flex direction="column" gap="4">
                                    <Flex
                                        direction={isLargeScreen ? 'row' : 'column'}
                                        gap="4"
                                        align={isLargeScreen ? 'center' : 'start'}
                                        justify="between"
                                    >
                                        <Box style={{ flex: 1, maxWidth: 512 }}>
                                            <TextField.Root
                                                placeholder="Search by number, location, description..."
                                                value={search}
                                                onChange={(e) => setSearch(e.target.value)}
                                                onKeyDown={(e) => {
                                                    if (e.key === 'Enter') {
                                                        fetchFilteredSummaries();
                                                    }
                                                }}
                                                size="2"
                                                aria-label="Search daily works"
                                            >
                                                <TextField.Slot>
                                                    <MagnifyingGlassIcon style={{ width: 16, height: 16 }} />
                                                </TextField.Slot>
                                                {search && (
                                                    <TextField.Slot side="right">
                                                        <Button size="1" variant="ghost" color="gray" onClick={() => setSearch('')} aria-label="Clear search">
                                                            <Cross2Icon style={{ width: 14, height: 14 }} />
                                                        </Button>
                                                    </TextField.Slot>
                                                )}
                                            </TextField.Root>
                                        </Box>
                                        <Button
                                            size="2"
                                            variant={showFilters ? 'solid' : 'outline'}
                                            color={showFilters ? 'indigo' : 'gray'}
                                            onClick={() => setShowFilters(!showFilters)}
                                            aria-label={showFilters ? 'Hide advanced filters' : 'Show advanced filters'}
                                        >
                                            <MixerHorizontalIcon style={{ width: 16, height: 16 }} />
                                            {!isMobile && ' Advanced Filters'}
                                        </Button>
                                    </Flex>
                                    
                                    {/* Advanced Filters Panel */}
                                    {showFilters && (
                                        <Box p="4" style={{ background: 'var(--gray-a2)', borderRadius: 'var(--radius-2)', border: '1px solid var(--gray-a4)' }}>
                                            <Flex wrap="wrap" gap="4">
                                                {/* Start Date */}
                                                <Flex direction="column" gap="1" style={{ minWidth: 160 }}>
                                                    <Text size="1">Start Date</Text>
                                                    <input
                                                        type="date"
                                                        value={filterData.startDate}
                                                        onChange={(e) => handleFilterChange('startDate', e.target.value)}
                                                        style={{ fontSize: 13, border: '1px solid var(--gray-5)', borderRadius: 'var(--radius-1)', padding: '6px 8px', background: 'var(--color-panel-solid)', minHeight: 40 }}
                                                    />
                                                </Flex>

                                                {/* End Date */}
                                                <Flex direction="column" gap="1" style={{ minWidth: 160 }}>
                                                    <Text size="1">End Date</Text>
                                                    <input
                                                        type="date"
                                                        value={filterData.endDate}
                                                        onChange={(e) => handleFilterChange('endDate', e.target.value)}
                                                        style={{ fontSize: 13, border: '1px solid var(--gray-5)', borderRadius: 'var(--radius-1)', padding: '6px 8px', background: 'var(--color-panel-solid)', minHeight: 40 }}
                                                    />
                                                </Flex>

                                                {/* Status */}
                                                <Flex direction="column" gap="1" style={{ minWidth: 160 }}>
                                                    <Text size="1">Status</Text>
                                                    <select
                                                        value={filterData.status}
                                                        onChange={(e) => handleFilterChange('status', e.target.value)}
                                                        style={{ fontSize: 13, border: '1px solid var(--gray-5)', borderRadius: 'var(--radius-1)', padding: '6px 8px', background: 'var(--color-panel-solid)', minHeight: 40 }}
                                                    >
                                                        <option value="all">All Status</option>
                                                        <option value="new">New</option>
                                                        <option value="in-progress">In Progress</option>
                                                        <option value="completed">Completed</option>
                                                        <option value="rejected">Rejected</option>
                                                        <option value="resubmission">Resubmission</option>
                                                        <option value="pending">Pending</option>
                                                        <option value="emergency">Emergency</option>
                                                    </select>
                                                </Flex>

                                                {/* Type */}
                                                <Flex direction="column" gap="1" style={{ minWidth: 160 }}>
                                                    <Text size="1">Work Type</Text>
                                                    <select
                                                        value={filterData.type}
                                                        onChange={(e) => handleFilterChange('type', e.target.value)}
                                                        style={{ fontSize: 13, border: '1px solid var(--gray-5)', borderRadius: 'var(--radius-1)', padding: '6px 8px', background: 'var(--color-panel-solid)', minHeight: 40 }}
                                                    >
                                                        <option value="all">All Types</option>
                                                        <option value="Embankment">Embankment</option>
                                                        <option value="Structure">Structure</option>
                                                        <option value="Pavement">Pavement</option>
                                                    </select>
                                                </Flex>

                                                {/* In Charge - Admin only */}
                                                {(auth.roles.includes('Administrator') || auth.roles.includes('Super Administrator') || auth.designation === 'Supervision Engineer') && (
                                                    <Flex direction="column" gap="1" style={{ minWidth: 200 }}>
                                                        <Text size="1">In Charge</Text>
                                                        <select
                                                            multiple
                                                            value={filterData.incharge}
                                                            onChange={(e) => {
                                                                const values = Array.from(e.target.selectedOptions).map(o => o.value);
                                                                handleFilterChange('incharge', values);
                                                                if (values.length > 0) handleFilterChange('jurisdiction', []);
                                                            }}
                                                            style={{ fontSize: 13, border: '1px solid var(--gray-5)', borderRadius: 'var(--radius-1)', padding: '4px 8px', background: 'var(--color-panel-solid)', minHeight: 40, maxHeight: 120 }}
                                                        >
                                                            {inCharges.map(u => <option key={u.id} value={String(u.id)}>{u.name}</option>)}
                                                        </select>
                                                    </Flex>
                                                )}

                                                {/* Jurisdiction - Admin only */}
                                                {(auth.roles.includes('Administrator') || auth.roles.includes('Super Administrator') || auth.designation === 'Supervision Engineer') && (
                                                    <Flex direction="column" gap="1" style={{ minWidth: 200 }}>
                                                        <Text size="1">Jurisdiction</Text>
                                                        <select
                                                            multiple
                                                            value={filterData.jurisdiction}
                                                            onChange={(e) => {
                                                                const values = Array.from(e.target.selectedOptions).map(o => o.value);
                                                                handleFilterChange('jurisdiction', values);
                                                                if (values.length > 0) handleFilterChange('incharge', []);
                                                            }}
                                                            style={{ fontSize: 13, border: '1px solid var(--gray-5)', borderRadius: 'var(--radius-1)', padding: '4px 8px', background: 'var(--color-panel-solid)', minHeight: 40, maxHeight: 120 }}
                                                        >
                                                            {jurisdictionOptions?.map(j => <option key={j.id} value={String(j.id)}>{j.displayLabel}</option>)}
                                                        </select>
                                                    </Flex>
                                                )}

                                                {/* Clear */}
                                                <Flex align="end">
                                                    <Button
                                                        size="2"
                                                        variant="soft"
                                                        color="red"
                                                        onClick={() => {
                                                            setFilterData({
                                                                startDate: overallStartDate || dayjs().subtract(30, 'days').format('YYYY-MM-DD'),
                                                                endDate: overallEndDate || dayjs().format('YYYY-MM-DD'),
                                                                status: 'all',
                                                                type: 'all',
                                                                incharge: [],
                                                                jurisdiction: [],
                                                            });
                                                            setSearch('');
                                                        }}
                                                    >
                                                        Clear Filters
                                                    </Button>
                                                </Flex>
                                            </Flex>
                                        </Box>
                                    )}
                                </Flex>
                            </Box>

                            {/* Tabs */}
                            <Box mb="4">
                                <Tabs.Root value={activeTab} onValueChange={setActiveTab}>
                                    <Tabs.List style={{ background: 'var(--gray-a2)', borderRadius: 'var(--radius-2)', padding: 4 }}>
                                        <Tabs.Trigger value="table" style={{ padding: '6px 12px', borderRadius: 'var(--radius-1)' }}>
                                            <Flex align="center" gap="2">
                                                <TableIcon style={{ width: 16, height: 16 }} />
                                                <Text size="2">Table View</Text>
                                            </Flex>
                                        </Tabs.Trigger>
                                        <Tabs.Trigger value="analytics" style={{ padding: '6px 12px', borderRadius: 'var(--radius-1)' }}>
                                            <Flex align="center" gap="2">
                                                <BarChartIcon style={{ width: 16, height: 16 }} />
                                                <Text size="2">Analytics</Text>
                                            </Flex>
                                        </Tabs.Trigger>
                                    </Tabs.List>
                                </Tabs.Root>
                            </Box>

                            {activeTab === 'table' && (
                                <Card style={{ background: 'var(--gray-a1)' }}>
                                    <DailyWorkSummaryTable
                                        filteredData={filteredData}
                                        onRefresh={handleRefresh}
                                        loading={loading}
                                    />
                                </Card>
                            )}

                            {activeTab === 'analytics' && (
                                <DailyWorkSummaryAnalytics
                                    ref={analyticsRef}
                                    filters={filterData}
                                    isVisible={activeTab === 'analytics'}
                                />
                            )}
                        </Box>
                    </Card>
                </Box>
            </Flex>
        </>
    );
};
DailyWorkSummary.layout = (page) => <App>{page}</App>;
export default DailyWorkSummary;
