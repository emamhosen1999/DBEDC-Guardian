import React, { useEffect, useState, useCallback, useMemo, useRef } from 'react';
import { motion, AnimatePresence } from 'framer-motion';
import axios from 'axios';
import RealtimeService from '@/Services/RealtimeService.js';
import { showToast } from '@/utils/toastUtils';
import {
    BriefcaseIcon,
    PlusIcon,
    ChartBarIcon,
    DocumentArrowUpIcon,
    DocumentArrowDownIcon,
    MagnifyingGlassIcon,
    CheckCircleIcon,
    ClockIcon,
    ExclamationTriangleIcon,
    CalendarIcon,
    FunnelIcon,
    AdjustmentsHorizontalIcon,
    UserIcon,
    MapPinIcon,
    ArrowPathIcon,
    DocumentPlusIcon,
    TableCellsIcon,
    PresentationChartBarIcon
} from "@heroicons/react/24/outline";
import { Head } from "@inertiajs/react";
import App from "@/Layouts/App.jsx";
import DailyWorksTable from '@/Tables/DailyWorksTable.jsx';
import { 
    Card, 
    CardHeader, 
    CardBody, 
    Input, 
    Button,
    Spinner,
    ScrollShadow,
    Skeleton,
    Select,
    SelectItem,
    ButtonGroup
} from "@heroui/react";
import StatsCards from "@/Components/StatsCards.jsx";
import { useMediaQuery } from '@/Hooks/useMediaQuery.js';
import { getThemeRadius } from '@/Hooks/useThemeRadius.js';
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
    const userIsAdmin = auth.roles?.includes('Administrator') || auth.roles?.includes('Super Administratoristrator') || auth.roles?.includes('Daily Work Manager') || false;

    // AbortController ref for cancelling in-flight requests
    const abortControllerRef = useRef(null);
    
    // Pull-to-refresh state for mobile
    const [isRefreshing, setIsRefreshing] = useState(false);
    const pullStartY = useRef(0);
    const pullCurrentY = useRef(0);

    // Search suggestions state
    const [searchSuggestions, setSearchSuggestions] = useState([]);
    const [showSuggestions, setShowSuggestions] = useState(false);
    const searchInputRef = useRef(null);

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
            return <span className="text-default-400 text-xs">{placeholder}</span>;
        }

        const normalized = selectedIds.map(String);
        const labels = options
            ?.filter((option) => normalized.includes(String(option.id)))
            .map((option) => option[labelKey]) ?? [];

        if (labels.length === 0) {
            return <span className="text-default-400 text-xs">{placeholder}</span>;
        }

        return (
            <div className="flex flex-wrap gap-1">
                {labels.map((label) => (
                    <span key={label} className="px-2 py-0.5 text-xs rounded-full bg-primary/10 text-primary">
                        {label}
                    </span>
                ))}
            </div>
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
    
    // Real-time service for updates
    const [realtimeService, setRealtimeService] = useState(null);
    const [isConnected, setIsConnected] = useState(false);
    const [realtimeUpdates, setRealtimeUpdates] = useState([]);
    const [lastRealtimeUpdate, setLastRealtimeUpdate] = useState(null);

    // Real-time connection setup
    useEffect(() => {
        if (!auth.user) return;

        const service = new RealtimeService();
        setRealtimeService(service);

        // Set up event listeners
        service.on('connected', (data) => {
            console.log('Real-time service connected:', data);
            setIsConnected(true);
        });

        service.on('daily-work-updated', (data) => {
            console.log('Daily work updated:', data);
            setRealtimeUpdates(prev => [data, ...prev].slice(0, 50)); // Keep last 50 updates
            setLastRealtimeUpdate(data);
            
            // Invalidate cache and refresh data
            if (cacheService) {
                cacheService.invalidateDailyWorkCaches(data.daily_work?.id);
            }
            
            // Refresh current data
            if (data.action === 'created' || data.action === 'updated') {
                fetchDailyWorks();
            }
            
            // Refresh statistics on create/delete
            if (data.action === 'created' || data.action === 'deleted') {
                fetchStatistics();
            }
        });

        service.on('heartbeat', (data) => {
            console.log('Real-time heartbeat:', data);
        });

        // Connect to real-time updates
        service.connect(auth.user).catch(error => {
            console.error('Failed to connect to real-time updates:', error);
        });

        return () => {
            if (service) {
                service.disconnect();
            }
        };
    }, [auth.user]);

    // Handle real-time notifications
    useEffect(() => {
        if (lastRealtimeUpdate) {
            const { action, daily_work, user_id } = lastRealtimeUpdate;
            
            // Don't show notification for own actions
            if (user_id === auth.user?.id) return;
            
            const messages = {
                created: `New daily work ${daily_work?.number} created`,
                updated: `Daily work ${daily_work?.number} updated`,
                deleted: `Daily work ${daily_work?.number} deleted`,
                status_changed: `Status changed for ${daily_work?.number}`,
            };
            
            if (messages[action]) {
                showToast.info(messages[action]);
            }
        }
    }, [lastRealtimeUpdate, auth.user?.id]);

    // Debounce timer ref for search
    const searchDebounceRef = useRef(null);

    // Cancel any in-flight request before starting a new one
    const cancelPendingRequest = useCallback(() => {
        if (abortControllerRef.current) {
            abortControllerRef.current.abort();
        }
        abortControllerRef.current = new AbortController();
        requestIdRef.current += 1;
        return { signal: abortControllerRef.current.signal, requestId: requestIdRef.current };
    }, []);

    // Fetch search suggestions
    const fetchSearchSuggestions = useCallback(async (query) => {
        if (!query || query.length < 2) {
            setSearchSuggestions([]);
            setShowSuggestions(false);
            return;
        }

        try {
            // For now, we'll show some example suggestions
            // In a real implementation, you might call an API endpoint
            const exampleSuggestions = [
                { text: `${query} - K14+500`, value: query, type: 'location' },
                { text: `${query} - Structure work`, value: query, type: 'work_type' },
                { text: `${query} - Embankment work`, value: query, type: 'work_type' },
                { text: `RFI-${query}`, value: `RFI-${query}`, type: 'rfi_number' },
            ].filter(item => item.text.toLowerCase().includes(query.toLowerCase()));

            setSearchSuggestions(exampleSuggestions.slice(0, 5)); // Limit to 5 suggestions
            setShowSuggestions(exampleSuggestions.length > 0);
        } catch (error) {
            console.error('Error fetching search suggestions:', error);
            setSearchSuggestions([]);
            setShowSuggestions(false);
        }
    }, []);

    // Handle suggestion selection
    const handleSuggestionSelect = useCallback((suggestion) => {
        setSearch(suggestion.value);
        setShowSuggestions(false);
        setCurrentPage(1);
        // Trigger search immediately
        if (searchDebounceRef.current) {
            clearTimeout(searchDebounceRef.current);
        }
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
    const handleSearch = useCallback((event) => {
        const value = event.target.value;
        setSearch(value);
        setCurrentPage(1);

        // Fetch search suggestions
        fetchSearchSuggestions(value);

        // Clear existing debounce timer
        if (searchDebounceRef.current) {
            clearTimeout(searchDebounceRef.current);
        }

        // Set new debounce timer - immediate for empty search, delayed for non-empty
        const delay = value.trim() === '' ? 0 : 300; // 300ms delay for search, instant for clear

        searchDebounceRef.current = setTimeout(() => {
            // Debounced search will trigger via useEffect
            // Additional logic can be added here if needed
        }, delay);
    }, [fetchSearchSuggestions]);

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

        if (filterData.incharge.length > 0) {
            filters.inCharge = filterData.incharge;
        }

        if (filterData.jurisdiction.length > 0) {
            filters.jurisdiction = filterData.jurisdiction;
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
        // Statistics endpoint has been moved to the dedicated Daily Works Analytics page.
        // Stats here are computed from the local data (see useMemo below).
        return;
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
                    icon: <ChartBarIcon className="w-5 h-5" />,
                    color: 'text-blue-600',
                    bgColor: 'bg-blue-50 dark:bg-blue-900/20',
                    description: `${todayWorks} added today`,
                    trend: todayWorks > 0 ? 'up' : 'neutral'
                },
                {
                    title: 'Completion Rate',
                    value: `${completionRate}%`,
                    icon: <CheckCircleIcon className="w-5 h-5" />,
                    color: completionRate >= 80 ? 'text-green-600' : completionRate >= 50 ? 'text-yellow-600' : 'text-red-600',
                    bgColor: completionRate >= 80 ? 'bg-green-50 dark:bg-green-900/20' : completionRate >= 50 ? 'bg-yellow-50 dark:bg-yellow-900/20' : 'bg-red-50 dark:bg-red-900/20',
                    description: `${completedWorks.toLocaleString()} of ${totalWorks.toLocaleString()} completed`,
                    trend: completionRate >= 80 ? 'up' : completionRate >= 50 ? 'neutral' : 'down'
                },
                {
                    title: 'Pending Review',
                    value: pendingWorks.toLocaleString(),
                    icon: <ClockIcon className="w-5 h-5" />,
                    color: pendingWorks > 100 ? 'text-orange-600' : 'text-amber-600',
                    bgColor: 'bg-orange-50 dark:bg-orange-900/20',
                    description: pendingWorks > 0 ? 'Awaiting action' : 'All caught up!',
                    trend: pendingWorks > 100 ? 'down' : 'neutral'
                },
                {
                    title: 'Inspection Pass',
                    value: `${inspectionPassRate}%`,
                    icon: <DocumentArrowUpIcon className="w-5 h-5" />,
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
                icon: <ChartBarIcon className="w-5 h-5" />,
                color: 'text-blue-600',
                bgColor: 'bg-blue-50 dark:bg-blue-900/20',
                description: `Showing ${data.length} on page`
            },
            {
                title: 'Completion Rate',
                value: `${completionRate}%`,
                icon: <CheckCircleIcon className="w-5 h-5" />,
                color: completionRate >= 80 ? 'text-green-600' : completionRate >= 50 ? 'text-yellow-600' : 'text-red-600',
                bgColor: completionRate >= 80 ? 'bg-green-50 dark:bg-green-900/20' : completionRate >= 50 ? 'bg-yellow-50 dark:bg-yellow-900/20' : 'bg-red-50 dark:bg-red-900/20',
                description: `${completedWorks} completed`
            },
            {
                title: 'Pending',
                value: pendingWorks,
                icon: <ClockIcon className="w-5 h-5" />,
                color: 'text-orange-600',
                bgColor: 'bg-orange-50 dark:bg-orange-900/20',
                description: 'Awaiting action'
            },
            {
                title: 'Inspection Pass',
                value: totalInspected > 0 ? `${inspectionPassRate}%` : '-',
                icon: <DocumentArrowUpIcon className="w-5 h-5" />,
                color: inspectionPassRate >= 90 ? 'text-green-600' : 'text-purple-600',
                bgColor: 'bg-purple-50 dark:bg-purple-900/20',
                description: resubmissionWorks > 0 ? `${resubmissionWorks} resubmitted` : `${passedWorks} passed`
            }
        ];
    }, [apiStats, data, totalRows]);

    // Action buttons configuration
    const actionButtons = [
        {
            label: 'Add Work',
            icon: <PlusIcon className="w-4 h-4" />,
            color: 'primary',
            variant: 'solid',
            onPress: () => openModal('addDailyWork')
        },
        {
            label: 'Import',
            icon: <DocumentArrowUpIcon className="w-4 h-4" />,
            color: 'secondary',
            variant: 'flat',
            onPress: () => openModal('importDailyWorks')
        },
        {
            label: 'Export',
            icon: <DocumentArrowDownIcon className="w-4 h-4" />,
            color: 'success',
            variant: 'flat',
            onPress: () => openModal('exportDailyWorks')
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
                />
            )}

            <div className="flex justify-center p-4">
                <motion.div
                    initial={{ opacity: 0, y: 20 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.3 }}
                    className="w-full max-w-[2000px]"
                >
                    <Card 
                        className="transition-all duration-200"
                        style={{
                            border: `var(--borderWidth, 2px) solid transparent`,
                            borderRadius: `var(--borderRadius, 12px)`,
                            fontFamily: `var(--fontFamily, "Inter")`,
                            transform: `scale(var(--scale, 1))`,
                            background: `linear-gradient(135deg, 
                                var(--theme-content1, #FAFAFA) 20%, 
                                var(--theme-content2, #F4F4F5) 10%, 
                                var(--theme-content3, #F1F3F4) 20%)`,
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
                                                <BriefcaseIcon 
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
                                                    Project Work Management
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
                                                    Track daily work progress and project activities
                                                </p>
                                            </div>
                                        </div>
                                        {/* Action Buttons */}
                                        <div className="flex items-center gap-2 sm:gap-4 w-full lg:w-auto">
                                            <div className="flex items-center gap-1.5 sm:gap-2 flex-wrap justify-end w-full lg:w-auto">
                                                {actionButtons.map((button, index) => (
                                                    <Button
                                                        key={index}
                                                        size={isLargeScreen ? "md" : "sm"}
                                                        variant={button.variant || "flat"}
                                                        color={button.color || "primary"}
                                                        startContent={!isMobile && button.icon}
                                                        isIconOnly={isMobile}
                                                        onPress={button.onPress}
                                                        className={`${button.className || ''} font-medium ${isMobile ? 'min-w-9 h-9' : ''}`}
                                                        aria-label={button.label}
                                                        style={{
                                                            fontFamily: `var(--fontFamily, "Inter")`,
                                                            borderRadius: `var(--borderRadius, 12px)`,
                                                        }}
                                                    >
                                                        {isMobile ? button.icon : button.label}
                                                    </Button>
                                                ))}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </CardHeader>
                        
                        <CardBody className="pt-6">
                            <motion.div
                                key="table-view"
                                initial={{ opacity: 0 }}
                                animate={{ opacity: 1 }}
                                exit={{ opacity: 0 }}
                                transition={{ duration: 0.3 }}
                            >
                                        {/* Quick Stats */}
                                        <div className="relative">
                                            <StatsCards
                                                stats={stats}
                                                onRefresh={fetchStatistics}
                                                isLoading={statsLoading}
                                            />
                                        </div>

                                        {/* Search and Filters Section - Improved Layout */}
                                        <div className="mb-4 space-y-4">
                                {/* Row 1: Filter Toggle + Date Selector + Search */}
                                <div className="flex flex-col lg:flex-row gap-3 items-stretch lg:items-center">
                                    {/* Filter Toggle Button - First */}
                                    <Button
                                        size="sm"
                                        variant={showFilters ? 'solid' : 'bordered'}
                                        color={showFilters ? 'primary' : 'default'}
                                        onPress={() => setShowFilters(!showFilters)}
                                        radius={getThemeRadius()}
                                        className={`shrink-0 min-h-10 ${showFilters ? '' : 'border-divider/50'} ${isMobile ? 'min-w-10' : ''}`}
                                        startContent={!isMobile && <AdjustmentsHorizontalIcon className="w-4 h-4" />}
                                        isIconOnly={isMobile}
                                        aria-label="Toggle filters"
                                        style={{ fontFamily: `var(--fontFamily, "Inter")` }}
                                    >
                                        {isMobile ? <AdjustmentsHorizontalIcon className="w-4 h-4" /> : 'Filters'}
                                    </Button>

                                    {/* Date Selector - Second */}
                                    <div className="flex items-center gap-2 shrink-0">
                                        {!isMobile && <CalendarIcon className="w-4 h-4 text-default-500 shrink-0" />}
                                        {isMobile ? (
                                            <Input
                                                type="date"
                                                value={selectedDate}
                                                onChange={(e) => handleDateChange(e.target.value)}
                                                variant="bordered"
                                                size="sm"
                                                radius={getThemeRadius()}
                                                min={dateBounds.min}
                                                max={dateBounds.max}
                                                classNames={{
                                                    base: "w-full",
                                                    input: "text-foreground text-sm",
                                                    inputWrapper: "min-h-10 bg-content2/50 border-divider/50 hover:border-divider",
                                                }}
                                                style={{ fontFamily: `var(--fontFamily, "Inter")` }}
                                            />
                                        ) : (
                                            <div className="flex items-center gap-2">
                                                <Input
                                                    type="date"
                                                    labelPlacement="outside-left"
                                                    label="From"
                                                    value={dateRange.start}
                                                    onChange={(e) => handleDateRangeChange({ ...dateRange, start: e.target.value })}
                                                    variant="bordered"
                                                    size="sm"
                                                    radius={getThemeRadius()}
                                                    min={dateBounds.min}
                                                    max={dateBounds.max}
                                                    classNames={{
                                                        base: "w-auto",
                                                        label: "text-xs text-default-500 whitespace-nowrap",
                                                        input: "text-foreground text-sm",
                                                        inputWrapper: "min-h-10 bg-content2/50 border-divider/50 hover:border-divider",
                                                    }}
                                                    style={{ fontFamily: `var(--fontFamily, "Inter")` }}
                                                />
                                                <span className="text-default-400 text-sm">—</span>
                                                <Input
                                                    type="date"
                                                    labelPlacement="outside-left"
                                                    label="To"
                                                    value={dateRange.end}
                                                    onChange={(e) => handleDateRangeChange({ ...dateRange, end: e.target.value })}
                                                    variant="bordered"
                                                    size="sm"
                                                    radius={getThemeRadius()}
                                                    min={dateBounds.min}
                                                    max={dateBounds.max}
                                                    classNames={{
                                                        base: "w-auto",
                                                        label: "text-xs text-default-500 whitespace-nowrap",
                                                        input: "text-foreground text-sm",
                                                        inputWrapper: "min-h-10 bg-content2/50 border-divider/50 hover:border-divider",
                                                    }}
                                                    style={{ fontFamily: `var(--fontFamily, "Inter")` }}
                                                />
                                            </div>
                                        )}
                                    </div>

                                    {/* Search Field - Last */}
                                    <div className="flex-1 min-w-0 relative">
                                        <Input
                                            ref={searchInputRef}
                                            type="text"
                                            placeholder="Search by RFI number, location, description..."
                                            value={search}
                                            onChange={(e) => handleSearch(e)}
                                            onKeyDown={(e) => {
                                                if (e.key === 'Enter') {
                                                    setShowSuggestions(false);
                                                    fetchData(true);
                                                } else if (e.key === 'Escape') {
                                                    setShowSuggestions(false);
                                                } else if (e.key === 'ArrowDown' && showSuggestions) {
                                                    // Handle arrow navigation for suggestions
                                                    e.preventDefault();
                                                }
                                            }}
                                            onFocus={() => {
                                                if (searchSuggestions.length > 0) {
                                                    setShowSuggestions(true);
                                                }
                                            }}
                                            onBlur={() => {
                                                // Delay hiding suggestions to allow click events
                                                setTimeout(() => setShowSuggestions(false), 200);
                                            }}
                                            variant="bordered"
                                            size="sm"
                                            radius={getThemeRadius()}
                                            isClearable
                                            onClear={() => {
                                                setSearch('');
                                                setCurrentPage(1);
                                                setShowSuggestions(false);
                                            }}
                                            startContent={<MagnifyingGlassIcon className="w-4 h-4 text-default-400" />}
                                            endContent={
                                                tableLoading && search ? (
                                                    <Spinner size="sm" color="primary" />
                                                ) : search ? (
                                                    <button
                                                        type="button"
                                                        onClick={() => {
                                                            setSearch('');
                                                            setCurrentPage(1);
                                                            setShowSuggestions(false);
                                                        }}
                                                        className="text-default-400 hover:text-default-600 transition-colors"
                                                    >
                                                        <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                                                        </svg>
                                                    </button>
                                                ) : null
                                            }
                                            classNames={{
                                                input: "text-foreground text-sm",
                                                inputWrapper: "min-h-10 bg-content2/50 border-divider/50 hover:border-divider data-[focus]:border-primary",
                                            }}
                                            style={{ fontFamily: `var(--fontFamily, "Inter")` }}
                                            aria-label="Search daily works"
                                        />

                                        {/* Search Suggestions Dropdown */}
                                        {showSuggestions && searchSuggestions.length > 0 && (
                                            <div className="absolute top-full left-0 right-0 z-50 mt-1 bg-content1 border border-divider rounded-lg shadow-lg max-h-60 overflow-y-auto">
                                                {searchSuggestions.map((suggestion, index) => (
                                                    <button
                                                        key={index}
                                                        type="button"
                                                        onClick={() => handleSuggestionSelect(suggestion)}
                                                        className="w-full px-4 py-2 text-left hover:bg-primary/10 focus:bg-primary/10 focus:outline-none transition-colors"
                                                    >
                                                        <div className="flex items-center gap-2">
                                                            <div className="flex-1">
                                                                <span className="text-sm text-foreground">{suggestion.text}</span>
                                                            </div>
                                                            <span className={`text-xs px-2 py-0.5 rounded-full ${
                                                                suggestion.type === 'location' ? 'bg-blue-100 text-blue-700' :
                                                                suggestion.type === 'work_type' ? 'bg-green-100 text-green-700' :
                                                                'bg-purple-100 text-purple-700'
                                                            }`}>
                                                                {suggestion.type}
                                                            </span>
                                                        </div>
                                                    </button>
                                                ))}
                                            </div>
                                        )}
                                    </div>
                                </div>
                                
                                {/* Row 2: Advanced Filters Panel */}
                                <AnimatePresence>
                                    {showFilters && (
                                        <motion.div
                                            initial={{ opacity: 0, height: 0 }}
                                            animate={{ opacity: 1, height: 'auto' }}
                                            exit={{ opacity: 0, height: 0 }}
                                            transition={{ duration: 0.2 }}
                                            className="overflow-hidden"
                                        >
                                            <div 
                                                className="p-3 rounded-lg border"
                                                style={{
                                                    background: 'var(--theme-content2, rgba(244, 244, 245, 0.8))',
                                                    borderColor: 'var(--theme-divider, rgba(228, 228, 231, 0.5))',
                                                }}
                                            >
                                                <div className="flex flex-wrap gap-3 items-end">
                                                    {/* Status Filter */}
                                                    <div className="w-full sm:w-auto sm:min-w-[160px]">
                                                        <Select
                                                            label="Status"
                                                            placeholder="All"
                                                            selectedKeys={filterData.status ? [filterData.status] : ["all"]}
                                                            onSelectionChange={(keys) => {
                                                                const value = Array.from(keys)[0];
                                                                setFilterData(prev => ({ ...prev, status: value }));
                                                                setCurrentPage(1);
                                                            }}
                                                            variant="bordered"
                                                            size="sm"
                                                            radius={getThemeRadius()}
                                                            classNames={{
                                                                trigger: "min-h-10",
                                                                value: "text-foreground text-sm",
                                                            }}
                                                            style={{ fontFamily: `var(--fontFamily, "Inter")` }}
                                                        >
                                                            <SelectItem key="all" value="all">All Status</SelectItem>
                                                            <SelectItem key="new" value="new">New</SelectItem>
                                                            <SelectItem key="in-progress" value="in-progress">In Progress</SelectItem>
                                                            <SelectItem key="completed" value="completed">Completed</SelectItem>
                                                            <SelectItem key="rejected" value="rejected">Rejected</SelectItem>
                                                            <SelectItem key="resubmission" value="resubmission">Resubmission</SelectItem>
                                                            <SelectItem key="pending" value="pending">Pending</SelectItem>
                                                            <SelectItem key="emergency" value="emergency">Emergency</SelectItem>
                                                        </Select>
                                                    </div>

                                                    {/* In Charge Filter - Admin only */}
                                                    {userIsAdmin && (
                                                        <div className="w-full sm:w-auto sm:min-w-[200px]">
                                                            <Select
                                                                label="In Charge"
                                                                placeholder="Filter by in charge..."
                                                                selectionMode="multiple"
                                                                selectedKeys={new Set(filterData.incharge || [])}
                                                                onSelectionChange={(keys) => {
                                                                    const values = Array.from(keys).filter(key => key !== 'all');
                                                                    setFilterData(prev => ({
                                                                        ...prev,
                                                                        incharge: values,
                                                                        jurisdiction: values.length ? [] : prev.jurisdiction
                                                                    }));
                                                                    setCurrentPage(1);
                                                                }}
                                                                variant="bordered"
                                                                size="sm"
                                                                radius={getThemeRadius()}
                                                                startContent={<UserIcon className="w-4 h-4 text-default-400" />}
                                                                classNames={{
                                                                    trigger: "min-h-10",
                                                                    value: "text-foreground text-sm",
                                                                }}
                                                                renderValue={() => renderSelectedBadges(filterData.incharge, inchargeOptions, 'All')}
                                                                style={{ fontFamily: `var(--fontFamily, "Inter")` }}
                                                            >
                                                                {inchargeOptions?.map(inCharge => (
                                                                    <SelectItem key={String(inCharge.id)} value={String(inCharge.id)}>
                                                                        {inCharge.name}
                                                                    </SelectItem>
                                                                ))}
                                                            </Select>
                                                        </div>
                                                    )}

                                                    {/* Jurisdiction Filter - Admin only */}
                                                    {userIsAdmin && (
                                                        <div className="w-full sm:w-auto sm:min-w-[200px]">
                                                            <Select
                                                                label="Jurisdiction"
                                                                placeholder="Filter by jurisdiction..."
                                                                selectionMode="multiple"
                                                                selectedKeys={new Set(filterData.jurisdiction || [])}
                                                                onSelectionChange={(keys) => {
                                                                    const values = Array.from(keys).filter(key => key !== 'all');
                                                                    setFilterData(prev => ({
                                                                        ...prev,
                                                                        jurisdiction: values,
                                                                        incharge: values.length ? [] : prev.incharge
                                                                    }));
                                                                    setCurrentPage(1);
                                                                }}
                                                                variant="bordered"
                                                                size="sm"
                                                                radius={getThemeRadius()}
                                                                startContent={<MapPinIcon className="w-4 h-4 text-default-400" />}
                                                                classNames={{
                                                                    trigger: "min-h-10",
                                                                    value: "text-foreground text-sm",
                                                                }}
                                                                renderValue={() => renderSelectedBadges(filterData.jurisdiction, jurisdictionOptions, 'All', 'displayLabel')}
                                                                style={{ fontFamily: `var(--fontFamily, "Inter")` }}
                                                            >
                                                                {jurisdictionOptions?.map(jurisdiction => (
                                                                    <SelectItem key={String(jurisdiction.id)} value={String(jurisdiction.id)}>
                                                                        {jurisdiction.displayLabel}
                                                                    </SelectItem>
                                                                ))}
                                                            </Select>
                                                        </div>
                                                    )}

                                                    {/* Clear Filters */}
                                                    <Button
                                                        size="sm"
                                                        variant="flat"
                                                        color="danger"
                                                        radius={getThemeRadius()}
                                                        className="min-h-10"
                                                        onPress={() => {
                                                            setFilterData({
                                                                status: 'all',
                                                                incharge: [],
                                                                jurisdiction: [],
                                                                startDate: overallStartDate,
                                                                endDate: overallEndDate
                                                            });
                                                            setCurrentPage(1);
                                                        }}
                                                        style={{ fontFamily: `var(--fontFamily, "Inter")` }}
                                                    >
                                                        Clear
                                                    </Button>
                                                </div>
                                            </div>
                                        </motion.div>
                                    )}
                                </AnimatePresence>
                            </div>
                            
                            {/* Daily Works Table */}
                            <Card 
                                radius={getThemeRadius()}
                                className="bg-content2/50 backdrop-blur-md border border-divider/30"
                                style={{
                                    fontFamily: `var(--fontFamily, "Inter")`,
                                    borderRadius: `var(--borderRadius, 12px)`,
                                    backgroundColor: 'var(--theme-content2)',
                                    borderColor: 'var(--theme-divider)',
                                }}
                            >
                                <CardBody className="p-4">
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
                                     </CardBody>
                                 </Card>
                            </motion.div>
                          </CardBody>
                     </Card>
                 </motion.div>
             </div>
        </>
    );
};

DailyWorks.layout = (page) => <App>{page}</App>;

export default DailyWorks;
