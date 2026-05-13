import React, { useEffect, useState, useCallback, useMemo, useRef } from 'react';
import axios from 'axios';
import { showToast } from '@/utils/toastUtils';
import {
    LayersIcon,
    PlusIcon,
    ActivityLogIcon,
    UploadIcon,
    DownloadIcon,
    MagnifyingGlassIcon,
    CheckCircledIcon,
    CountdownTimerIcon,
    ExclamationTriangleIcon,
    CalendarIcon,
    MixerHorizontalIcon,
    PersonIcon,
    TargetIcon,
    ReloadIcon,
    Cross2Icon,
} from '@radix-ui/react-icons';
import { Head } from "@inertiajs/react";
import App from "@/Layouts/App.jsx";
import DailyWorksTable from '@/Tables/DailyWorksTable.jsx';
import {
    Card,
    Box,
    Flex,
    Text,
    Button,
    TextField,
    Badge,
} from '@radix-ui/themes';
import StatsCards from "@/Components/StatsCards.jsx";
import { useMediaQuery } from '@/Hooks/useMediaQuery.js';
import DailyWorkForm from "@/Forms/DailyWorkForm.jsx";
import DeleteDailyWorkForm from "@/Forms/DeleteDailyWorkForm.jsx";
import EnhancedDailyWorksExportForm from "@/Forms/EnhancedDailyWorksExportForm.jsx";
import DailyWorksUploadForm from "@/Forms/DailyWorksUploadForm.jsx";
import ErrorBoundary from "@/Components/Common/ErrorBoundary.jsx";



const DailyWorks = ({ auth, title, allData, jurisdictions, users, reports, reports_with_daily_works, overallEndDate, overallStartDate }) => {
    const isLargeScreen = useMediaQuery('(min-width: 1025px)');
    const isMediumScreen = useMediaQuery('(min-width: 641px) and (max-width: 1024px)');
    const isMobile = useMediaQuery('(max-width: 640px)');
    
    // Role-based access control - includes Daily Work Manager role
    const userIsAdmin = auth.roles?.includes('Administrator') || auth.roles?.includes('Super Administrator') || auth.roles?.includes('Daily Work Manager') || false;

    // AbortController ref for cancelling in-flight requests
    const abortControllerRef = useRef(null);
    
    // Pull-to-refresh state for mobile
    const [isRefreshing, setIsRefreshing] = useState(false);
    const pullStartY = useRef(0);
    const pullCurrentY = useRef(0);

    const [data, setData] = useState([]);
    const [loading, setLoading] = useState(false);
    const [tableLoading, setTableLoading] = useState(true); // Start as true to show skeleton on initial load
    const [deleteLoading, setDeleteLoading] = useState(false);
    const [modeSwitch, setModeSwitch] = useState(false); // Track mode switching
    const [totalRows, setTotalRows] = useState(0);
    const [lastPage, setLastPage] = useState(0);
    const [filteredData, setFilteredData] = useState([]);
    const [currentRow, setCurrentRow] = useState();
    const [taskIdToDelete, setTaskIdToDelete] = useState(null);
    const [openModalType, setOpenModalType] = useState(null);
    const [search, setSearch] = useState('');
    const [perPage, setPerPage] = useState(30);
    const [currentPage, setCurrentPage] = useState(1);
    
    // Date state management
    const [selectedDate, setSelectedDate] = useState(overallEndDate); // Set to last date
    const [dateRange, setDateRange] = useState({
        start: overallStartDate,
        end: overallEndDate
    });
    
    // Dynamic date bounds - updated after import
    const [dateBounds, setDateBounds] = useState({
        min: overallStartDate,
        max: overallEndDate
    });
    
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

    const inchargeOptions = useMemo(() => allData?.allInCharges ?? [], [allData?.allInCharges]);
    const jurisdictionOptions = useMemo(() => {
        return jurisdictions?.map((j) => ({
            ...j,
            displayLabel: `${j.start_chainage} - ${j.end_chainage}`,
        })) ?? [];
    }, [jurisdictions]);

    const [filterData, setFilterData] = useState({
        status: 'all',
        incharge: [],
        jurisdiction: [],
        startDate: overallStartDate,
        endDate: overallEndDate
    });

    // Show/Hide advanced filters panel
    const [showFilters, setShowFilters] = useState(false);
    
    // Request ID counter for tracking active requests
    const requestIdRef = useRef(0);
    
    // Cancel any in-flight request before starting a new one
    const cancelPendingRequest = useCallback(() => {
        if (abortControllerRef.current) {
            abortControllerRef.current.abort();
        }
        abortControllerRef.current = new AbortController();
        requestIdRef.current += 1;
        return { signal: abortControllerRef.current.signal, requestId: requestIdRef.current };
    }, []);
    
    // Mobile data fetching - fetch all data for selected date without pagination
    const fetchMobileData = async (showLoader = true) => {
        const { signal, requestId } = cancelPendingRequest();
        
        if (showLoader && !modeSwitch) {
            setTableLoading(true);
        }
        
        try {
            const params = {
                search,
                ...buildFilterParams(),
                startDate: selectedDate,
                endDate: selectedDate,
            };
            
            // Use the /daily-works-all endpoint to get all data without pagination
            const response = await axios.get('/daily-works-all', { params, signal });
            
            // Check if this request is still the active one
            if (requestId !== requestIdRef.current) return;
            
            const dailyWorks = response.data.dailyWorks || [];
            setData(Array.isArray(dailyWorks) ? dailyWorks : []);
            setTotalRows(dailyWorks.length);
            setLastPage(1);
            
            // Always set loading to false on success
            setTableLoading(false);
            setLoading(false);
            setIsRefreshing(false);
        } catch (error) {
            // Ignore aborted requests
            if (axios.isCancel(error) || error.name === 'AbortError' || error.name === 'CanceledError') {
                return;
            }
            
            console.error('Error fetching mobile data:', error);
            setData([]);
            showToast.error('Failed to fetch data.');
            
            // Set loading to false on error
            setTableLoading(false);
            setLoading(false);
            setIsRefreshing(false);
        }
    };

    // Desktop data fetching - use pagination for date range
    const fetchDesktopData = async (showLoader = true) => {
        const { signal, requestId } = cancelPendingRequest();
        
        if (showLoader && !modeSwitch) {
            setTableLoading(true);
        }
        
        try {
            const params = {
                search,
                ...buildFilterParams(),
                startDate: dateRange.start,
                endDate: dateRange.end,
                page: currentPage,
                perPage,
            };
            
            const response = await axios.get('/daily-works-paginate', { params, signal });
            
            // Check if this request is still the active one
            if (requestId !== requestIdRef.current) return;
            
            setData(Array.isArray(response.data.data) ? response.data.data : []);
            setTotalRows(response.data.total || 0);
            setLastPage(response.data.last_page || 1);
            
            // Always set loading to false on success
            setTableLoading(false);
            setLoading(false);
        } catch (error) {
            // Ignore aborted requests
            if (axios.isCancel(error) || error.name === 'AbortError' || error.name === 'CanceledError') {
                return;
            }
            
            console.error('Error fetching desktop data:', error);
            setData([]);
            showToast.error('Failed to fetch data.');
            
            // Set loading to false on error
            setTableLoading(false);
            setLoading(false);
        }
    };

    // Main fetch function that delegates to mobile or desktop
    const fetchData = useCallback(async (showLoader = true) => {
        if (isMobile) {
            await fetchMobileData(showLoader);
        } else {
            await fetchDesktopData(showLoader);
        }
    }, [isMobile, search, filterData, selectedDate, dateRange, currentPage, perPage]);

    // Enhanced refresh function that handles mobile/desktop modes
    const refreshData = useCallback(() => {
        setCurrentPage(1);
        fetchData();
        fetchStatistics();
    }, [fetchData]);

    // Cleanup abort controller on unmount
    useEffect(() => {
        return () => {
            if (abortControllerRef.current) {
                abortControllerRef.current.abort();
            }
        };
    }, []);

    // Enhanced event handlers for mobile/desktop differences
    const handleSearch = (event) => {
        setSearch(event.target.value);
        setCurrentPage(1);
        // Will trigger via useEffect
    };

    const handlePageChange = (page) => {
        setCurrentPage(page);
        // Will trigger via useEffect
    };

    const handleDateChange = (date) => {
        setSelectedDate(date);
        setCurrentPage(1);
        // Will trigger via useEffect
    };

    const handleDateRangeChange = (range) => {
        setDateRange(range);
        setCurrentPage(1);
        // Will trigger via useEffect
    };

    const buildFilterParams = () => {
        const filters = {
            status: filterData.status !== 'all' ? filterData.status : '',
        };

        // Only admins can use incharge/jurisdiction filters
        if (userIsAdmin) {
            if (filterData.incharge.length > 0) {
                filters.inCharge = filterData.incharge;
            }

            if (filterData.jurisdiction.length > 0) {
                filters.jurisdiction = filterData.jurisdiction;
            }
        }

        return filters;
    };

    // Fetch additional items if needed after deletion
    const fetchAdditionalItemsIfNeeded = async () => {
        if (data && data.length < perPage && totalRows > data.length) {
            const itemsNeeded = Math.min(perPage - data.length, totalRows - data.length);
            if (itemsNeeded <= 0) return;
            
            setTableLoading(true);
            try {
                const params = {
                    search,
                    ...buildFilterParams(),
                    startDate: isMobile ? selectedDate : dateRange.start,
                    endDate: isMobile ? selectedDate : dateRange.end,
                    page: currentPage + 1,
                    perPage: itemsNeeded,
                };

                const response = await axios.get('/daily-works-paginate', { params });
                
                if (response.status === 200 && response.data.data) {
                    setData(prevData => {
                        const newItems = response.data.data;
                        return [...prevData, ...newItems];
                    });
                }
            } catch (error) {
                console.error('Error fetching additional items:', error);
            } finally {
                setTableLoading(false);
            }
        }
    };

    const handleDelete = () => {
 
        
        if (!taskIdToDelete) {
            showToast.error('No task selected for deletion');
            return;
        }
        
        setDeleteLoading(true);
        
        const promise = new Promise(async (resolve, reject) => {
            try {
            
                
                // Use axios for delete operation with automatic CSRF handling
                const response = await axios.delete('/delete-daily-work', {
                    data: {
                        id: taskIdToDelete,
                        page: currentPage,
                        perPage,
                    }
                });

                
                // Optimistic update - immediately remove from local state
                const newTotal = Math.max(0, totalRows - 1);
                const remainingOnCurrentPage = data.filter(item => item.id !== taskIdToDelete).length;

                if (remainingOnCurrentPage === 0 && newTotal > 0 && currentPage > 1) {
                    // Navigate to previous page if current page becomes empty
                    const targetPage = currentPage - 1;
                    setCurrentPage(targetPage);
                    // The useEffect will trigger fetchData for the new page
                } else {
                    // Optimistic update - remove item from local state immediately
                    setData(prevData => prevData.filter(item => item.id !== taskIdToDelete));
                    setTotalRows(newTotal);
                    
                    // Calculate new last page
                    const newLastPage = Math.ceil(newTotal / perPage);
                    setLastPage(newLastPage);
                    
                    // Fill page if needed
                    setTimeout(() => fetchAdditionalItemsIfNeeded(), 100);
                }
                
                // Close the modal and reset state
                handleClose();
                
                // Update statistics only
                fetchStatistics();
                
                resolve('Daily work deleted successfully!');
                
            } catch (error) {
                console.error('Delete error:', error);
                
                // On error, refresh data to restore correct state
                fetchData();
                
                if (error.response) {
                    const status = error.response.status;
                    const errorData = error.response.data;
                    
                    if (status === 403) {
                        reject('You do not have permission to delete daily works.');
                    } else if (status === 404) {
                        reject('Daily work not found.');
                    } else if (status === 422 && errorData.message) {
                        reject(errorData.message);
                    } else {
                        reject(`Failed to delete daily work. Status: ${status}`);
                    }
                } else if (error.request) {
                    reject('Network error. Please check your connection.');
                } else {
                    reject(`Failed to delete daily work: ${error.message}`);
                }
            } finally {
                setDeleteLoading(false);
            }
        });

        showToast.promise(promise, {
            loading: 'Deleting daily work...',
            success: (data) => data,
            error: (data) => data,
        });
    };

    const handleClickOpen = (taskId, modalType) => {
        setTaskIdToDelete(taskId);
        setOpenModalType(modalType);
    };

    const handleClose = () => {
        setOpenModalType(null);
        setTaskIdToDelete(null);
    };

    const openModal = (modalType) => {
        setOpenModalType(modalType);
    };

    const closeModal = () => {
        setOpenModalType(null);
    };

    // Optimized success callbacks for forms
    const handleAddSuccess = (newItem) => {

        
        if (newItem) {
            // Optimistic update - add to local state immediately
            setData(prevData => [newItem, ...prevData]);
            setTotalRows(prev => prev + 1);
            
            // Update last page calculation
            const newLastPage = Math.ceil((totalRows + 1) / perPage);
            setLastPage(newLastPage);
            
            // Update statistics
            fetchStatistics();
        } else {
            // Fallback: refresh current page only
            fetchData();
        }
        closeModal();
    };

    const handleEditSuccess = (updatedItem) => {

        
        if (updatedItem) {
            // Optimistic update - update item in local state immediately
            setData(prevData => 
                prevData.map(item => 
                    item.id === updatedItem.id ? { ...item, ...updatedItem } : item
                )
            );
            
            // Update statistics only if status changed
            if (updatedItem.status) {
                fetchStatistics();
            }
        } else {
            // Fallback: refresh current page only
            fetchData();
        }
        closeModal();
    };

    const handleImportSuccess = (importResults) => {
   
        
        // Close the modal first
        closeModal();
        
        // Extract the latest date from import results
        if (importResults && Array.isArray(importResults) && importResults.length > 0) {
            // Find the latest date from all imported sheets
            const importedDates = importResults
                .filter(result => result.date)
                .map(result => result.date);
            
            if (importedDates.length > 0) {
                // Sort dates to find the latest one
                const sortedDates = importedDates.sort((a, b) => new Date(b) - new Date(a));
                const latestImportDate = sortedDates[0];
                
             
                
                // Update date range to include the imported date
                if (isMobile) {
                    // For mobile: set selectedDate to the latest imported date
                    setSelectedDate(latestImportDate);
                } else {
                    // For desktop: update dateRange end if imported date is newer
                    const currentEnd = new Date(dateRange.end);
                    const importDate = new Date(latestImportDate);
                    
                    if (importDate > currentEnd) {
                        setDateRange(prev => ({
                            ...prev,
                            end: latestImportDate
                        }));
                        // Update date bounds to allow selecting the new date
                        setDateBounds(prev => ({
                            ...prev,
                            max: latestImportDate
                        }));
                    } else {
                        // If within range, just set end to the imported date to show new data
                        setDateRange(prev => ({
                            ...prev,
                            end: latestImportDate
                        }));
                    }
                }
            }
        }
        
        // Reset to first page and refresh data
        setCurrentPage(1);
        
        // Use setTimeout to ensure state updates have propagated
        setTimeout(() => {
            fetchData(true);
            fetchStatistics();
        }, 100);
    };

    // Simple statistics
    const [apiStats, setApiStats] = useState(null);
    const [statsLoading, setStatsLoading] = useState(false);

    const fetchStatistics = async (withFilters = false) => {
        setStatsLoading(true);
        try {
            const params = withFilters ? {
                startDate: isMobile ? selectedDate : dateRange.start,
                endDate: isMobile ? selectedDate : dateRange.end,
                ...(filterData.status !== 'all' && { status: filterData.status }),
                ...(filterData.incharge.length > 0 && { inCharge: filterData.incharge }),
                ...(filterData.jurisdiction.length > 0 && { jurisdiction: filterData.jurisdiction }),
            } : {};
            
            const response = await axios.get('/daily-works/statistics', { params });
            setApiStats(response.data);
        } catch (error) {
            console.error('Error fetching statistics:', error);
            // Don't show error toast for stats - it's not critical
        } finally {
            setStatsLoading(false);
        }
    };

    // Enhanced statistics calculation with more actionable insights
    const stats = useMemo(() => {
        if (apiStats) {
            const totalWorks = apiStats.overview?.totalWorks || 0;
            const completedWorks = apiStats.overview?.completedWorks || 0;
            const pendingWorks = apiStats.overview?.pendingWorks || 0;
            const rfiSubmissions = apiStats.qualityMetrics?.rfiSubmissions || 0;
            const passedInspections = apiStats.qualityMetrics?.passedInspections || 0;
            const failedInspections = apiStats.qualityMetrics?.failedInspections || 0;
            const totalResubmissions = apiStats.qualityMetrics?.totalResubmissions || 0;
            const completionRate = apiStats.performanceIndicators?.completionRate || 0;
            const todayWorks = apiStats.recentActivity?.todayWorks || 0;
            
            // Calculate inspection pass rate
            const totalInspected = passedInspections + failedInspections;
            const inspectionPassRate = totalInspected > 0 
                ? Math.round((passedInspections / totalInspected) * 100) 
                : 0;
            
            // RFI submission rate
            const rfiRate = totalWorks > 0 
                ? Math.round((rfiSubmissions / totalWorks) * 100) 
                : 0;

            return [
                {
                    title: 'Total Works',
                    value: totalWorks.toLocaleString(),
                    icon: <ActivityLogIcon style={{ width: 20, height: 20 }} />,
                    color: 'text-blue-600',
                    bgColor: 'bg-blue-50 dark:bg-blue-900/20',
                    description: `${todayWorks} added today`,
                    trend: todayWorks > 0 ? 'up' : 'neutral'
                },
                {
                    title: 'Completion Rate',
                    value: `${completionRate}%`,
                    icon: <CheckCircledIcon style={{ width: 20, height: 20 }} />,
                    color: completionRate >= 80 ? 'text-green-600' : completionRate >= 50 ? 'text-yellow-600' : 'text-red-600',
                    bgColor: completionRate >= 80 ? 'bg-green-50 dark:bg-green-900/20' : completionRate >= 50 ? 'bg-yellow-50 dark:bg-yellow-900/20' : 'bg-red-50 dark:bg-red-900/20',
                    description: `${completedWorks.toLocaleString()} of ${totalWorks.toLocaleString()} completed`,
                    trend: completionRate >= 80 ? 'up' : completionRate >= 50 ? 'neutral' : 'down'
                },
                {
                    title: 'Pending Review',
                    value: pendingWorks.toLocaleString(),
                    icon: <CountdownTimerIcon style={{ width: 20, height: 20 }} />,
                    color: pendingWorks > 100 ? 'text-orange-600' : 'text-amber-600',
                    bgColor: 'bg-orange-50 dark:bg-orange-900/20',
                    description: pendingWorks > 0 ? 'Awaiting action' : 'All caught up!',
                    trend: pendingWorks > 100 ? 'down' : 'neutral'
                },
                {
                    title: 'Inspection Pass',
                    value: `${inspectionPassRate}%`,
                    icon: <UploadIcon style={{ width: 20, height: 20 }} />,
                    color: inspectionPassRate >= 90 ? 'text-green-600' : inspectionPassRate >= 70 ? 'text-purple-600' : 'text-red-600',
                    bgColor: inspectionPassRate >= 90 ? 'bg-green-50 dark:bg-green-900/20' : 'bg-purple-50 dark:bg-purple-900/20',
                    description: totalResubmissions > 0 ? `${totalResubmissions} resubmissions` : `${passedInspections} passed`,
                    trend: inspectionPassRate >= 90 ? 'up' : inspectionPassRate >= 70 ? 'neutral' : 'down'
                }
            ];
        }
        
        // Fallback: Calculate from current page data when API stats not available
        const totalWorks = totalRows || data.length;
        const completedWorks = data.filter(work => work.status === 'completed').length;
        const pendingWorks = data.filter(work => ['new', 'resubmission', 'pending', 'in-progress'].includes(work.status)).length;
        const passedWorks = data.filter(work => work.inspection_result === 'pass' || work.inspection_result === 'approved').length;
        const failedWorks = data.filter(work => work.inspection_result === 'fail' || work.inspection_result === 'rejected').length;
        const resubmissionWorks = data.filter(work => work.resubmission_count > 0).length;
        
        const completionRate = data.length > 0 ? Math.round((completedWorks / data.length) * 100) : 0;
        const totalInspected = passedWorks + failedWorks;
        const inspectionPassRate = totalInspected > 0 ? Math.round((passedWorks / totalInspected) * 100) : 0;

        return [
            {
                title: 'Total Works',
                value: totalWorks.toLocaleString(),
                icon: <ActivityLogIcon style={{ width: 20, height: 20 }} />,
                color: 'text-blue-600',
                bgColor: 'bg-blue-50 dark:bg-blue-900/20',
                description: `Showing ${data.length} on page`
            },
            {
                title: 'Completion Rate',
                value: `${completionRate}%`,
                icon: <CheckCircledIcon style={{ width: 20, height: 20 }} />,
                color: completionRate >= 80 ? 'text-green-600' : completionRate >= 50 ? 'text-yellow-600' : 'text-red-600',
                bgColor: completionRate >= 80 ? 'bg-green-50 dark:bg-green-900/20' : completionRate >= 50 ? 'bg-yellow-50 dark:bg-yellow-900/20' : 'bg-red-50 dark:bg-red-900/20',
                description: `${completedWorks} completed`
            },
            {
                title: 'Pending',
                value: pendingWorks,
                icon: <CountdownTimerIcon style={{ width: 20, height: 20 }} />,
                color: 'text-orange-600',
                bgColor: 'bg-orange-50 dark:bg-orange-900/20',
                description: 'Awaiting action'
            },
            {
                title: 'Inspection Pass',
                value: totalInspected > 0 ? `${inspectionPassRate}%` : '-',
                icon: <UploadIcon style={{ width: 20, height: 20 }} />,
                color: inspectionPassRate >= 90 ? 'text-green-600' : 'text-purple-600',
                bgColor: 'bg-purple-50 dark:bg-purple-900/20',
                description: resubmissionWorks > 0 ? `${resubmissionWorks} resubmitted` : `${passedWorks} passed`
            }
        ];
    }, [apiStats, data, totalRows]);

    const actionButtons = [
        {
            label: 'Add Work',
            icon: <PlusIcon style={{ width: 16, height: 16 }} />,
            color: 'indigo',
            variant: 'solid',
            onClick: () => openModal('addDailyWork')
        },
        ...(userIsAdmin ? [{
            label: 'Import',
            icon: <UploadIcon style={{ width: 16, height: 16 }} />,
            color: 'violet',
            variant: 'soft',
            onClick: () => openModal('importDailyWorks')
        }] : []),
        {
            label: 'Export',
            icon: <DownloadIcon style={{ width: 16, height: 16 }} />,
            color: 'green',
            variant: 'soft',
            onClick: () => openModal('exportDailyWorks')
        }
    ];

    // Enhanced useEffect for mobile/desktop mode switching and initial load
    const isInitialMount = useRef(true);
    
    useEffect(() => {
        // When switching to mobile, ensure selectedDate is set (default to end date if not already set)
        if (isMobile && !selectedDate) {
            setSelectedDate(overallEndDate);
        }
        
        setModeSwitch(true);
        setTableLoading(true);
        setCurrentPage(1);
        
        // fetchData handles its own loading state, but we need to reset modeSwitch
        fetchData(true).finally(() => {
            setModeSwitch(false);
            isInitialMount.current = false;
        });
    }, [isMobile]);

    // Debounced data fetching effect for search, filter, pagination
    useEffect(() => {
        // Skip on initial mount - handled by the isMobile effect
        if (isInitialMount.current) return;
        
        // Skip if mode is switching
        if (modeSwitch) return;
        
        // Debounce search (150ms for faster response), instant for others
        const timeoutId = search ? setTimeout(() => {
            fetchData(true); // Show table loading for search
        }, 150) : null;
        
        if (!search) {
            fetchData(true); // Show table loading for pagination/filter changes
        }
        
        return () => {
            if (timeoutId) clearTimeout(timeoutId);
        };
    }, [currentPage, perPage, search, filterData, selectedDate, dateRange, isMobile]);

    // Load statistics on mount
    useEffect(() => {
        fetchStatistics();
    }, []);

    return (
        <>
            <Head title={title} />

            {/* Modals */}
            {openModalType === 'addDailyWork' && (
                <DailyWorkForm
                    modalType="add"
                    open={openModalType === 'addDailyWork'}
                    setData={setData}
                    closeModal={closeModal}
                    onSuccess={handleAddSuccess}
                />
            )}
            {openModalType === 'editDailyWork' && (
                <DailyWorkForm
                    modalType="update"
                    open={openModalType === 'editDailyWork'}
                    currentRow={currentRow}
                    setData={setData}
                    closeModal={closeModal}
                    onSuccess={handleEditSuccess}
                />
            )}
            {openModalType === 'deleteDailyWork' && (
                <DeleteDailyWorkForm
                    open={openModalType === 'deleteDailyWork'}
                    handleClose={handleClose}
                    handleDelete={handleDelete}
                    isLoading={deleteLoading}
                    setData={setData}
                />
            )}
            {openModalType === 'importDailyWorks' && (
                <DailyWorksUploadForm
                    open={openModalType === 'importDailyWorks'}
                    closeModal={closeModal}
                    setData={setData}
                    setTotalRows={setTotalRows}
                    refreshData={refreshData}
                    onSuccess={handleImportSuccess}
                />
            )}
            {openModalType === 'exportDailyWorks' && (
                <EnhancedDailyWorksExportForm
                    open={openModalType === 'exportDailyWorks'}
                    closeModal={closeModal}
                    filterData={{
                        ...filterData,
                        startDate: isMobile ? selectedDate : dateRange.start,
                        endDate: isMobile ? selectedDate : dateRange.end,
                        search: search
                    }}
                    users={users}
                    inCharges={allData.allInCharges}
                    auth={auth}
                />
            )}

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
                                {/* Title */}
                                <Flex align="center" gap="3">
                                    <Box
                                        p={isLargeScreen ? '3' : '2'}
                                        style={{ background: 'var(--accent-a3)', borderRadius: 'var(--radius-2)' }}
                                    >
                                        <LayersIcon style={{ width: isLargeScreen ? 32 : 24, height: isLargeScreen ? 32 : 24, color: 'var(--accent-9)' }} />
                                    </Box>
                                    <Box>
                                        <Text size={isLargeScreen ? '6' : isMediumScreen ? '5' : '4'} weight="bold" as="p">Project Work Management</Text>
                                        <Text size={isLargeScreen ? '2' : '1'} color="gray" as="p">Track daily work progress and project activities</Text>
                                    </Box>
                                </Flex>
                                {/* Action Buttons */}
                                <Flex align="center" gap="2" wrap="wrap">
                                    {actionButtons.map((button, index) => (
                                        <Button
                                            key={index}
                                            size={isLargeScreen ? '2' : '1'}
                                            variant={button.variant}
                                            color={button.color}
                                            onClick={button.onClick}
                                            aria-label={button.label}
                                        >
                                            {button.icon}
                                            {!isMobile && button.label}
                                        </Button>
                                    ))}
                                </Flex>
                            </Flex>
                        </Box>

                        {/* Body */}
                        <Box pt="6" px="4" pb="4">
                            {/* Stats */}
                            <Box mb="4">
                                <StatsCards stats={stats} onRefresh={fetchStatistics} isLoading={statsLoading} />
                            </Box>

                            {/* Search and Filters */}
                            <Box mb="4">
                                {/* Row 1: Filter Toggle + Date + Search */}
                                <Flex
                                    direction={isLargeScreen ? 'row' : 'column'}
                                    gap="3"
                                    align={isLargeScreen ? 'center' : 'stretch'}
                                    mb="3"
                                >
                                    {/* Filter Toggle */}
                                    <Button
                                        size="2"
                                        variant={showFilters ? 'solid' : 'outline'}
                                        color={showFilters ? 'indigo' : 'gray'}
                                        onClick={() => setShowFilters(!showFilters)}
                                        aria-label="Toggle filters"
                                        style={{ flexShrink: 0, minHeight: 40 }}
                                    >
                                        <MixerHorizontalIcon style={{ width: 16, height: 16 }} />
                                        {!isMobile && ' Filters'}
                                    </Button>

                                    {/* Date Selector */}
                                    <Flex align="center" gap="2" style={{ flexShrink: 0 }}>
                                        {!isMobile && <CalendarIcon style={{ width: 16, height: 16, color: 'var(--gray-9)' }} />}
                                        {isMobile ? (
                                            <input
                                                type="date"
                                                value={selectedDate}
                                                onChange={(e) => handleDateChange(e.target.value)}
                                                min={dateBounds.min}
                                                max={dateBounds.max}
                                                style={{ fontSize: 13, border: '1px solid var(--gray-5)', borderRadius: 'var(--radius-1)', padding: '6px 8px', background: 'var(--color-panel-solid)', minHeight: 40, width: '100%' }}
                                            />
                                        ) : (
                                            <Flex align="end" gap="2">
                                                <Box>
                                                    <Text size="1" color="gray" as="p" mb="1">From</Text>
                                                    <input
                                                        type="date"
                                                        value={dateRange.start}
                                                        onChange={(e) => handleDateRangeChange({ ...dateRange, start: e.target.value })}
                                                        min={dateBounds.min}
                                                        max={dateBounds.max}
                                                        style={{ fontSize: 13, border: '1px solid var(--gray-5)', borderRadius: 'var(--radius-1)', padding: '6px 8px', background: 'var(--color-panel-solid)', minHeight: 40 }}
                                                    />
                                                </Box>
                                                <Text color="gray" mb="1">—</Text>
                                                <Box>
                                                    <Text size="1" color="gray" as="p" mb="1">To</Text>
                                                    <input
                                                        type="date"
                                                        value={dateRange.end}
                                                        onChange={(e) => handleDateRangeChange({ ...dateRange, end: e.target.value })}
                                                        min={dateBounds.min}
                                                        max={dateBounds.max}
                                                        style={{ fontSize: 13, border: '1px solid var(--gray-5)', borderRadius: 'var(--radius-1)', padding: '6px 8px', background: 'var(--color-panel-solid)', minHeight: 40 }}
                                                    />
                                                </Box>
                                            </Flex>
                                        )}
                                    </Flex>

                                    {/* Search */}
                                    <Box style={{ flex: 1, minWidth: 0 }}>
                                        <TextField.Root
                                            placeholder="Search by description, location, or notes..."
                                            value={search}
                                            onChange={(e) => handleSearch(e)}
                                            size="2"
                                            style={{ minHeight: 40 }}
                                        >
                                            <TextField.Slot>
                                                <MagnifyingGlassIcon style={{ width: 16, height: 16 }} />
                                            </TextField.Slot>
                                            <TextField.Slot side="right">
                                                {tableLoading && search ? (
                                                    <Box style={{ width: 16, height: 16, border: '2px solid var(--accent-a6)', borderTopColor: 'var(--accent-9)', borderRadius: '50%', animation: 'spin 0.75s linear infinite' }} />
                                                ) : search ? (
                                                    <Button size="1" variant="ghost" color="gray" onClick={() => { setSearch(''); setCurrentPage(1); }} aria-label="Clear search">
                                                        <Cross2Icon style={{ width: 14, height: 14 }} />
                                                    </Button>
                                                ) : null}
                                            </TextField.Slot>
                                        </TextField.Root>
                                    </Box>
                                </Flex>

                                {/* Filter Panel */}
                                {showFilters && (
                                    <Box p="3" style={{ background: 'var(--gray-a2)', borderRadius: 'var(--radius-2)', border: '1px solid var(--gray-a4)' }}>
                                        <Flex wrap="wrap" gap="3" align="end">
                                            {/* Status */}
                                            <Box style={{ minWidth: 160 }}>
                                                <Text size="1" color="gray" as="p" mb="1">Status</Text>
                                                <select
                                                    value={filterData.status}
                                                    onChange={(e) => { setFilterData(prev => ({ ...prev, status: e.target.value })); setCurrentPage(1); }}
                                                    style={{ fontSize: 13, border: '1px solid var(--gray-5)', borderRadius: 'var(--radius-1)', padding: '6px 8px', background: 'var(--color-panel-solid)', minHeight: 40, width: '100%' }}
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
                                            </Box>

                                            {/* In Charge - Admin only */}
                                            {userIsAdmin && (
                                                <Box style={{ minWidth: 200 }}>
                                                    <Flex align="center" gap="1" mb="1">
                                                        <PersonIcon style={{ width: 12, height: 12, color: 'var(--gray-9)' }} />
                                                        <Text size="1" color="gray">In Charge</Text>
                                                    </Flex>
                                                    <select
                                                        multiple
                                                        value={filterData.incharge}
                                                        onChange={(e) => {
                                                            const values = Array.from(e.target.selectedOptions).map(o => o.value);
                                                            setFilterData(prev => ({ ...prev, incharge: values, jurisdiction: values.length ? [] : prev.jurisdiction }));
                                                            setCurrentPage(1);
                                                        }}
                                                        style={{ fontSize: 13, border: '1px solid var(--gray-5)', borderRadius: 'var(--radius-1)', padding: '4px 8px', background: 'var(--color-panel-solid)', minHeight: 40, width: '100%', maxHeight: 120 }}
                                                    >
                                                        {inchargeOptions?.map(u => <option key={u.id} value={String(u.id)}>{u.name}</option>)}
                                                    </select>
                                                </Box>
                                            )}

                                            {/* Jurisdiction - Admin only */}
                                            {userIsAdmin && (
                                                <Box style={{ minWidth: 200 }}>
                                                    <Flex align="center" gap="1" mb="1">
                                                        <TargetIcon style={{ width: 12, height: 12, color: 'var(--gray-9)' }} />
                                                        <Text size="1" color="gray">Jurisdiction</Text>
                                                    </Flex>
                                                    <select
                                                        multiple
                                                        value={filterData.jurisdiction}
                                                        onChange={(e) => {
                                                            const values = Array.from(e.target.selectedOptions).map(o => o.value);
                                                            setFilterData(prev => ({ ...prev, jurisdiction: values, incharge: values.length ? [] : prev.incharge }));
                                                            setCurrentPage(1);
                                                        }}
                                                        style={{ fontSize: 13, border: '1px solid var(--gray-5)', borderRadius: 'var(--radius-1)', padding: '4px 8px', background: 'var(--color-panel-solid)', minHeight: 40, width: '100%', maxHeight: 120 }}
                                                    >
                                                        {jurisdictionOptions?.map(j => <option key={j.id} value={String(j.id)}>{j.displayLabel}</option>)}
                                                    </select>
                                                </Box>
                                            )}

                                            {/* Clear */}
                                            <Button
                                                size="2"
                                                variant="soft"
                                                color="red"
                                                style={{ minHeight: 40 }}
                                                onClick={() => {
                                                    setFilterData({ status: 'all', incharge: [], jurisdiction: [], startDate: overallStartDate, endDate: overallEndDate });
                                                    setCurrentPage(1);
                                                }}
                                            >
                                                Clear
                                            </Button>
                                        </Flex>
                                    </Box>
                                )}
                            </Box>

                            {/* Daily Works Table */}
                            <Card style={{ background: 'var(--gray-a1)' }}>
                                <Box p="4">
                                    <ErrorBoundary
                                        fallbackTitle="Unable to load daily works"
                                        fallbackDescription="There was an error loading the daily works table. Please try refreshing."
                                        onRetry={refreshData}
                                    >
                                        {isMobile ? (
                                            <DailyWorksTable
                                                setData={setData}
                                                filteredData={filteredData}
                                                setFilteredData={setFilteredData}
                                                reports={reports}
                                                setCurrentRow={setCurrentRow}
                                                currentPage={currentPage}
                                                setCurrentPage={setCurrentPage}
                                                onPageChange={handlePageChange}
                                                setLoading={setTableLoading}
                                                refreshStatistics={fetchStatistics}
                                                handleClickOpen={handleClickOpen}
                                                openModal={openModal}
                                                juniors={allData.juniors}
                                                totalRows={totalRows}
                                                lastPage={lastPage}
                                                loading={tableLoading}
                                                allData={data}
                                                allInCharges={allData.allInCharges}
                                                jurisdictions={jurisdictions}
                                                users={users}
                                                reports_with_daily_works={reports_with_daily_works}
                                                isMobile={isMobile}
                                                isRefreshing={isRefreshing}
                                                searchTerm={search}
                                            />
                                        ) : (
                                            <DailyWorksTable
                                                setData={setData}
                                                filteredData={filteredData}
                                                setFilteredData={setFilteredData}
                                                reports={reports}
                                                setCurrentRow={setCurrentRow}
                                                currentPage={currentPage}
                                                setCurrentPage={setCurrentPage}
                                                onPageChange={handlePageChange}
                                                setLoading={setTableLoading}
                                                refreshStatistics={fetchStatistics}
                                                handleClickOpen={handleClickOpen}
                                                openModal={openModal}
                                                juniors={allData.juniors}
                                                totalRows={totalRows}
                                                lastPage={lastPage}
                                                loading={tableLoading}
                                                allData={data}
                                                allInCharges={allData.allInCharges}
                                                jurisdictions={jurisdictions}
                                                users={users}
                                                reports_with_daily_works={reports_with_daily_works}
                                                isMobile={isMobile}
                                                searchTerm={search}
                                            />
                                        )}
                                    </ErrorBoundary>
                                </Box>
                            </Card>
                        </Box>
                    </Card>
                </Box>
            </Flex>
        </>
    );
};

DailyWorks.layout = (page) => <App>{page}</App>;

export default DailyWorks;
