import React, { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import axios from 'axios';
import { showToast } from '@/utils/toastUtils';
import {
    LayersIcon,
    PlusIcon,
  
    UploadIcon,
    DownloadIcon,
   
  

    ExclamationTriangleIcon,
   
 

  
    ReloadIcon,
 
    BarChartIcon,
    TableIcon,
 
    MixerHorizontalIcon,
    CalendarIcon,
    MagnifyingGlassIcon,
    Cross2Icon,
    PersonIcon,
    TargetIcon,
    ChevronDownIcon
} from '@radix-ui/react-icons';
import { Head, router } from "@inertiajs/react";
import App from "@/Layouts/App.jsx";
import {
    Box,
    Button, 
    Tabs,
    Flex,
    TextField,
    Text,
    Grid,
    Select,
    DropdownMenu,
    Badge,
    Card,
    IconButton,
    Spinner,
    Separator
} from '@radix-ui/themes';
import { useMediaQuery } from '@/Hooks/useMediaQuery.js';
import DailyWorksTable from '@/Tables/DailyWorksTable.jsx';
import DailyWorkForm from "@/Forms/DailyWorkForm.jsx";
import DeleteDailyWorkForm from "@/Forms/DeleteDailyWorkForm.jsx";
import EnhancedDailyWorksExportForm from "@/Forms/EnhancedDailyWorksExportForm.jsx";
import DailyWorksUploadForm from "@/Forms/DailyWorksUploadForm.jsx";
import ImportPreviewModalRadix from "@/Forms/ImportPreviewModalRadix.jsx";
import ErrorBoundary from "@/Components/Common/ErrorBoundary.jsx";
import DailyWorkSummaryTable from '@/Tables/DailyWorkSummaryTable.jsx';
import DailyWorkSummaryAnalytics from "@/Components/DailyWorkSummaryAnalytics.jsx";
import EnhancedDailyWorkSummaryExportForm from "@/Forms/EnhancedDailyWorkSummaryExportForm.jsx";
import dayjs from 'dayjs';
import minMax from 'dayjs/plugin/minMax';
import isBetween from 'dayjs/plugin/isBetween';

dayjs.extend(minMax);
dayjs.extend(isBetween);

const DailyWorksUnified = ({ auth, title, allData, jurisdictions, users, reports, reports_with_daily_works, overallEndDate, overallStartDate, summary }) => {
    const isExtraSmallScreen = useMediaQuery('(max-width: 480px)');
    const isSmallScreen = useMediaQuery('(min-width: 481px) and (max-width: 640px)');
    const isMediumScreen = useMediaQuery('(min-width: 641px) and (max-width: 768px)');
    const isLargeScreen = useMediaQuery('(min-width: 769px) and (max-width: 1024px)');
    const isExtraLargeScreen = useMediaQuery('(min-width: 1025px) and (max-width: 1280px)');
    const isExtraExtraLargeScreen = useMediaQuery('(min-width: 1281px)');
    const isMobile = useMediaQuery('(max-width: 640px)');
    const isTablet = useMediaQuery('(min-width: 641px) and (max-width: 1024px)');
    const isDesktop = useMediaQuery('(min-width: 1025px)');
    
    // Role-based access control
    const userIsAdmin = auth.roles?.includes('Administrator') || auth.roles?.includes('Super Administrator') || auth.roles?.includes('Daily Work Manager') || false;

    // Tab state
    const [activeTab, setActiveTab] = useState('works'); // 'works' | 'summary' | 'objections'

    // Import file state for preservation when preview modal opens
    const [importFile, setImportFile] = useState(null);
    const [previewData, setPreviewData] = useState(null);
    const [showImportPreview, setShowImportPreview] = useState(false);
    const [isImporting, setIsImporting] = useState(false);
    const [importProgress, setImportProgress] = useState(0);

    // Works tab state (from DailyWorks.jsx)
    const [data, setData] = useState([]);
    const [loading, setLoading] = useState(false);
    const [tableLoading, setTableLoading] = useState(true);
    const [deleteLoading, setDeleteLoading] = useState(false);
    const [modeSwitch, setModeSwitch] = useState(false);
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
    const [selectedDate, setSelectedDate] = useState(overallEndDate);
    const [dateRange, setDateRange] = useState({
        start: overallStartDate,
        end: overallEndDate
    });
    
    const [dateBounds, setDateBounds] = useState({
        min: overallStartDate,
        max: overallEndDate
    });
    
    // Filter state
    const [filterData, setFilterData] = useState({
        status: 'all',
        incharge: [],
        jurisdiction: [],
        startDate: overallStartDate,
        endDate: overallEndDate
    });
    
    const [showFilters, setShowFilters] = useState(false);

    // Summary tab state
    const [summaryFilteredData, setSummaryFilteredData] = useState([]);
    const [summaryLoading, setSummaryLoading] = useState(false);
    const [summarySubTab, setSummarySubTab] = useState('table'); // 'table' or 'analytics'

    const fetchFilteredSummaries = useCallback(async () => {
        try {
            setSummaryLoading(true);
            const payload = {
                startDate: dateRange.start,
                endDate: dateRange.end,
                status: filterData.status !== 'all' ? filterData.status : '',
                incharge: filterData.incharge,
                jurisdiction: filterData.jurisdiction,
            };

            console.log('Fetching filtered summaries with payload:', payload);

            const response = await axios.post('/daily-works-summary/filter', payload);
            const summaries = response.data?.summaries ?? [];
            console.log('Received summaries:', summaries);
            setSummaryFilteredData(summaries);
            return true;
        } catch (error) {
            console.error('Failed to load filtered summary:', error);
            const message = error.response?.data?.error || 'Failed to load summary data';
            showToast.error(message);
            return false;
        } finally {
            setSummaryLoading(false);
        }
    }, [filterData, dateRange]);

    const handleSummaryRefresh = useCallback(async () => {
        const success = await fetchFilteredSummaries();
        if (success) {
            showToast.success('Summary data refreshed successfully');
        }
    }, [fetchFilteredSummaries]);

    const canExportSummary = auth.roles.includes('Administrator') || auth.roles.includes('Super Administrator') || auth.designation === 'Supervision Engineer';

    // Load summary data when summary tab is active or when filters change
    useEffect(() => {
        if (activeTab === 'summary') {
            fetchFilteredSummaries();
        }
    }, [activeTab, filterData, dateRange, fetchFilteredSummaries]);

    // Objections tab state
    const [objectionsData, setObjectionsData] = useState([]);
    const [objectionsLoading, setObjectionsLoading] = useState(false);

    // AbortController ref for cancelling in-flight requests
    const abortControllerRef = useRef(null);
    
    // Pull-to-refresh state for mobile
    const [isRefreshing, setIsRefreshing] = useState(false);
    const pullStartY = useRef(0);
    const pullCurrentY = useRef(0);

    // Request ID counter for tracking active requests
    const requestIdRef = useRef(0);
    const isInitialMount = useRef(true);

    // Helper function to render selected badges
    const renderSelectedBadges = useCallback((selectedIds, options, placeholder, labelKey = 'name') => {
        if (!selectedIds || selectedIds.length === 0) {
            return <Text size="2" color="gray">{placeholder}</Text>;
        }
        const normalized = selectedIds.map(String);
        const labels = options
            ?.filter((option) => normalized.includes(String(option.id)))
            .map((option) => option[labelKey]) ?? [];
        if (labels.length === 0) {
            return <Text size="2" color="gray">{placeholder}</Text>;
        }
        return (
            <Flex wrap="wrap" gap="3">
                {labels.map((label) => (
                    <Badge key={label} size="2" variant="soft">{label}</Badge>
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

    // Action buttons for works tab
    const worksActionButtons = [
        {
            label: 'Add Work',
            icon: <PlusIcon style={{ width: 16, height: 16 }} />,
            color: 'indigo',
            variant: 'solid',
            onClick: () => setOpenModalType('addDailyWork')
        },
        ...(userIsAdmin ? [{
            label: 'Import',
            icon: <UploadIcon style={{ width: 16, height: 16 }} />,
            color: 'violet',
            variant: 'soft',
            onClick: () => setOpenModalType('importDailyWorks')
        }] : []),
        {
            label: 'Export',
            icon: <DownloadIcon style={{ width: 16, height: 16 }} />,
            color: 'green',
            variant: 'soft',
            onClick: () => setOpenModalType('exportDailyWorks')
        }
    ];

    // Modal handlers
    const openModal = (modalType) => {
        setOpenModalType(modalType);
    };

    const closeModal = () => {
        setOpenModalType(null);
    };

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
            
            const response = await axios.get('/daily-works-all', { params, signal });
            
            if (requestId !== requestIdRef.current) return;
            
            const dailyWorks = response.data.dailyWorks || [];
            setData(Array.isArray(dailyWorks) ? dailyWorks : []);
            setTotalRows(dailyWorks.length);
            setLastPage(1);
            
            setTableLoading(false);
            setLoading(false);
            setIsRefreshing(false);
        } catch (error) {
            if (axios.isCancel(error) || error.name === 'AbortError' || error.name === 'CanceledError') {
                return;
            }
            
            console.error('Error fetching mobile data:', error);
            setData([]);
            showToast.error('Failed to fetch data.');
            
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
            
            if (requestId !== requestIdRef.current) return;
            
            setData(Array.isArray(response.data.data) ? response.data.data : []);
            setTotalRows(response.data.total || 0);
            setLastPage(response.data.last_page || 1);
            
            setTableLoading(false);
            setLoading(false);
        } catch (error) {
            if (axios.isCancel(error) || error.name === 'AbortError' || error.name === 'CanceledError') {
                return;
            }
            
            console.error('Error fetching desktop data:', error);
            setData([]);
            showToast.error('Failed to fetch data.');
            
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

    // Enhanced refresh function
    const refreshData = useCallback(() => {
        setCurrentPage(1);
        fetchData();
    }, [fetchData]);

    // Cleanup abort controller on unmount
    useEffect(() => {
        return () => {
            if (abortControllerRef.current) {
                abortControllerRef.current.abort();
            }
        };
    }, []);

    // Event handlers
    const handleSearch = (event) => {
        setSearch(event.target.value);
        setCurrentPage(1);
    };

    const handlePageChange = (page) => {
        setCurrentPage(page);
    };

    const handleDateChange = (date) => {
        setSelectedDate(date);
        setCurrentPage(1);
    };

    const handleDateRangeChange = (range) => {
        setDateRange(range);
        setCurrentPage(1);
    };

    const buildFilterParams = () => {
        const filters = {
            status: filterData.status !== 'all' ? filterData.status : '',
        };

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
                const response = await axios.delete('/delete-daily-work', {
                    data: {
                        id: taskIdToDelete,
                        page: currentPage,
                        perPage,
                    }
                });

                const newTotal = Math.max(0, totalRows - 1);
                const remainingOnCurrentPage = data.filter(item => item.id !== taskIdToDelete).length;

                if (remainingOnCurrentPage === 0 && newTotal > 0 && currentPage > 1) {
                    const targetPage = currentPage - 1;
                    setCurrentPage(targetPage);
                } else {
                    setData(prevData => prevData.filter(item => item.id !== taskIdToDelete));
                    setTotalRows(newTotal);
                    
                    const newLastPage = Math.ceil(newTotal / perPage);
                    setLastPage(newLastPage);
                    
                    setTimeout(() => fetchAdditionalItemsIfNeeded(), 100);
                }
                
                handleClose();
                resolve('Daily work deleted successfully!');
                
            } catch (error) {
                console.error('Delete error:', error);
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

    const handleAddSuccess = (newItem) => {
        if (newItem) {
            setData(prevData => [newItem, ...prevData]);
            setTotalRows(prev => prev + 1);
            
            const newLastPage = Math.ceil((totalRows + 1) / perPage);
        }
        closeModal();
    };

    const handleEditSuccess = (updatedItem) => {
        if (updatedItem) {
            setData(prevData => 
                prevData.map(item => 
                    item.id === updatedItem.id ? { ...item, ...updatedItem } : item
                )
            );
        } else {
            fetchData();
        }
        closeModal();
    };

    const handleImportSuccess = (importResults) => {
        closeModal();
        setImportFile(null); // Clear file after successful import
        setPreviewData(null);
        
        if (importResults && Array.isArray(importResults) && importResults.length > 0) {
            const importedDates = importResults
                .filter(result => result.date)
                .map(result => result.date);
            
            if (importedDates.length > 0) {
                const sortedDates = importedDates.sort((a, b) => new Date(b) - new Date(a));
                const latestImportDate = sortedDates[0];
                
                if (isMobile) {
                    setSelectedDate(latestImportDate);
                } else {
                    const currentEnd = new Date(dateRange.end);
                    const importDate = new Date(latestImportDate);
                    
                    if (importDate > currentEnd) {
                        setDateRange(prev => ({
                            ...prev,
                            end: latestImportDate
                        }));
                        setDateBounds(prev => ({
                            ...prev,
                            max: latestImportDate
                        }));
                    } else {
                        setDateRange(prev => ({
                            ...prev,
                            end: latestImportDate
                        }));
                    }
                }
            }
        }
        
        setCurrentPage(1);
        
        setTimeout(() => {
            fetchData(true);
        }, 100);
    };

    const handlePreviewReady = (file, data) => {
        setImportFile(file);
        setPreviewData(data);
        setOpenModalType(null); // Close upload dialog
        setShowImportPreview(true); // Open preview modal
    };

    const handlePreviewCancel = () => {
        setShowImportPreview(false);
        setPreviewData(null);
        setOpenModalType('importDailyWorks'); // Reopen upload dialog
    };

    const handlePreviewConfirm = async (overrides) => {
        setIsImporting(true);
        setImportProgress(0);

        const csrfToken = document.head.querySelector('meta[name="csrf-token"]')?.content
            || document.querySelector('input[name="_token"]')?.value
            || window.Laravel?.csrfToken;

        const formData = new FormData();
        formData.append('file', importFile);
        formData.append('incharge_overrides', JSON.stringify(overrides || {}));

        try {
            const response = await axios.post(route('dailyWorks.import'), formData, {
                headers: {
                    'Content-Type': 'multipart/form-data',
                    ...(csrfToken && { 'X-CSRF-TOKEN': csrfToken }),
                },
                onUploadProgress: (progressEvent) => {
                    if (!progressEvent.total) return;
                    const percentCompleted = Math.round((progressEvent.loaded * 100) / progressEvent.total);
                    setImportProgress(percentCompleted);
                }
            });

            if (response.status === 200) {
                setShowImportPreview(false);
                setPreviewData(null);
                setImportFile(null);
                setIsImporting(false);
                setImportProgress(0);

                // Call onSuccess with import results
                if (typeof handleImportSuccess === 'function') {
                    handleImportSuccess(response.data.results);
                }

                showToast.success('Daily works imported successfully');
            }
        } catch (error) {
            console.error('Import error:', error);
            setIsImporting(false);
            setImportProgress(0);
            showToast.error('Failed to import daily works');
        }
    };

    // Enhanced useEffect for mobile/desktop mode switching and initial load
    useEffect(() => {
        if (isMobile && !selectedDate) {
            setSelectedDate(overallEndDate);
        }
        
        setModeSwitch(true);
        setTableLoading(true);
        setCurrentPage(1);
        
        fetchData(true).finally(() => {
            setModeSwitch(false);
            isInitialMount.current = false;
        });
    }, [isMobile]);

    // Debounced data fetching effect for search, filter, pagination
    useEffect(() => {
        if (isInitialMount.current) return;
        if (modeSwitch) return;
        
        const timeoutId = search ? setTimeout(() => {
            fetchData(true);
        }, 150) : null;
        
        if (!search) {
            fetchData(true);
        }
        
        return () => {
            if (timeoutId) clearTimeout(timeoutId);
        };
    }, [currentPage, perPage, search, filterData, selectedDate, dateRange, isMobile]);
    const hasActiveFilters = filterData.status !== 'all' || filterData.incharge.length > 0 || filterData.jurisdiction.length > 0;

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
                    file={importFile}
                    onPreviewReady={handlePreviewReady}
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
            {openModalType === 'exportSummary' && (
                <EnhancedDailyWorkSummaryExportForm
                    open={openModalType === 'exportSummary'}
                    closeModal={closeModal}
                    filteredData={summaryFilteredData}
                    inCharges={allData.allInCharges}
                    currentFilters={{
                        ...filterData,
                        startDate: dateRange.start,
                        endDate: dateRange.end
                    }}
                    auth={auth}
                />
            )}

            {/* Import Preview Modal */}
            {showImportPreview && (
                <ImportPreviewModalRadix
                    isOpen={showImportPreview}
                    onClose={() => {
                        if (!isImporting) {
                            setShowImportPreview(false);
                            setPreviewData(null);
                            setImportFile(null);
                        }
                    }}
                    onCancel={handlePreviewCancel}
                    previewData={previewData}
                    onConfirm={handlePreviewConfirm}
                    isImporting={isImporting}
                    importProgress={importProgress}
                />
            )}

            <Flex justify="center" p="4">
                <Box style={{ width: '100%', maxWidth: 2000 }}>
                    <Card>
                        <Box mb="4">
                            <Flex
                                direction={{ initial: 'column', md: 'row' }}
                                align={{ initial: 'start', md: 'center' }}
                                justify="between"
                                gap="4"
                            >
                                {/* Title */}
                                <Flex align="center" gap="4">
                                    <Box 
                                        p={{ initial: '2', md: '3' }} 
                                        style={{ backgroundColor: 'var(--accent-a3)', borderRadius: 'var(--radius-2)' }}
                                    >
                                        <LayersIcon 
                                            width={isDesktop ? 32 : 24} 
                                            height={isDesktop ? 32 : 24} 
                                            color="var(--accent-9)" 
                                        />
                                    </Box>
                                    <Box>
                                        <Text 
                                            size={{ initial: '4', sm: '5', md: '6' }} 
                                            weight="bold" 
                                            as="div"
                                        >
                                            Daily Works Unified
                                        </Text>
                                        <Text 
                                            size={{ initial: '1', md: '2' }} 
                                            color="gray" 
                                            as="div"
                                        >
                                            Manage daily works, summaries, and objections in one place
                                        </Text>
                                    </Box>
                                </Flex>
                                
                                {/* Action Buttons */}
                                <Flex align="center" gap="3" wrap="wrap">
                                    {activeTab === 'works' && worksActionButtons.map((button, index) => (
                                        <Button
                                            key={index}
                                            size={{ initial: '1', md: '2' }}
                                            variant={button.variant}
                                            color={button.color}
                                            onClick={button.onClick}
                                            aria-label={button.label}
                                        >
                                            {button.icon}
                                            {/* If you want to hide text on mobile entirely via Radix instead of JS, 
                                                you could use a visually hidden component, but JS conditionally rendering is fine here */}
                                            {!isMobile && button.label}
                                        </Button>
                                    ))}
                                </Flex>
                            </Flex>
                        </Box>

                        {/* Radix Themes uses <Separator /> directly, not Separator.Root */}
                        <Separator size="4" mb="4" />

                        {/* Body */}
                        <Box>
                            <Box>
                                {/* Row 1: Filter Toggle + Date */}
                                <Flex
                                    direction={{ initial: 'column', md: 'row' }}
                                    gap="4"
                                    align={isDesktop ? 'center' : 'stretch'}
                                    mb="4"
                                >
                                   

                                    {/* Date Selector */}
                                    <Flex align="center" gap="3" flexGrow="1">
                                       
                                        {isMobile ? (
                                            <TextField.Root
                                                type="date"
                                                value={selectedDate}
                                                onChange={(e) => handleDateChange(e.target.value)}
                                                min={dateBounds.min}
                                                max={dateBounds.max}
                                                size="2"
                                                style={{ width: '100%' }}
                                            />
                                        ) : (
                                            <Flex align="end" gap="3">
                                                <Box>
                                                    <Text size="2" color="gray" as="div" mb="1">From</Text>
                                                    <TextField.Root
                                                        type="date"
                                                        value={dateRange.start}
                                                        onChange={(e) => handleDateRangeChange({ ...dateRange, start: e.target.value })}
                                                        min={dateBounds.min}
                                                        max={dateBounds.max}
                                                        size="2"
                                                    />
                                                </Box>
                                                <Text color="gray" mb="1">—</Text>
                                                <Box>
                                                    <Text size="2" color="gray" as="div" mb="1">To</Text>
                                                    <TextField.Root
                                                        type="date"
                                                        value={dateRange.end}
                                                        onChange={(e) => handleDateRangeChange({ ...dateRange, end: e.target.value })}
                                                        min={dateBounds.min}
                                                        max={dateBounds.max}
                                                        size="2"
                                                    />
                                                </Box>
                                            </Flex>
                                        )}
                                    </Flex>
                                     {/* Filter Toggle */}
                                    <Button
                                        size="2"
                                        variant={showFilters ? 'solid' : 'surface'}
                                        color={showFilters ? 'indigo' : 'gray'}
                                        onClick={() => setShowFilters(!showFilters)}
                                        aria-label="Toggle filters"
                                    >
                                        <MixerHorizontalIcon width="16" height="16" />
                                        {!isMobile && ' Filters'}
                                    </Button>
                                </Flex>

                                {/* Filter Selection Panel */}
                                {showFilters && (
                                    <Card size="2" variant="surface" mb="4">
                                        <Grid columns={{ initial: '1', sm: '2', md: userIsAdmin ? '4' : '2' }} gap="4" align="end">
                                            
                                            {/* Status Select */}
                                            <Box>
                                                <Text size="2" color="gray" weight="medium" as="div" mb="2">Status</Text>
                                                <Select.Root
                                                    size="2"
                                                    value={filterData.status}
                                                    onValueChange={(value) => {
                                                        setFilterData(prev => ({ ...prev, status: value }));
                                                        setCurrentPage(1);
                                                    }}
                                                >
                                                    <Select.Trigger style={{ width: '100%' }} placeholder="Select status..." />
                                                    <Select.Content>
                                                        <Select.Item value="all">All Status</Select.Item>
                                                        <Select.Item value="new">New</Select.Item>
                                                        <Select.Item value="in-progress">In Progress</Select.Item>
                                                        <Select.Item value="completed">Completed</Select.Item>
                                                        <Select.Item value="rejected">Rejected</Select.Item>
                                                        <Select.Item value="resubmission">Resubmission</Select.Item>
                                                        <Select.Item value="pending">Pending</Select.Item>
                                                        <Select.Item value="emergency">Emergency</Select.Item>
                                                    </Select.Content>
                                                </Select.Root>
                                            </Box>

                                            {/* In Charge - Admin only */}
                                            {userIsAdmin && (
                                                <Box>
                                                    <Flex align="center" gap="2" mb="2">
                                                        <PersonIcon width="14" height="14" color="var(--gray-9)" />
                                                        <Text size="2" color="gray" weight="medium">In Charge</Text>
                                                    </Flex>
                                                    <DropdownMenu.Root>
                                                        <DropdownMenu.Trigger>
                                                            <Button variant="surface" color="gray" size="2" style={{ width: '100%', justifyContent: 'space-between' }}>
                                                                {filterData.incharge.length ? `${filterData.incharge.length} selected` : 'Select personnel'}
                                                                <ChevronDownIcon />
                                                            </Button>
                                                        </DropdownMenu.Trigger>
                                                        <DropdownMenu.Content size="2" style={{ maxHeight: '250px', overflowY: 'auto' }}>
                                                            {inchargeOptions?.map(u => (
                                                                <DropdownMenu.CheckboxItem
                                                                    key={u.id}
                                                                    checked={filterData.incharge.includes(String(u.id))}
                                                                    onCheckedChange={(checked) => {
                                                                        const newValues = checked 
                                                                            ? [...filterData.incharge, String(u.id)]
                                                                            : filterData.incharge.filter(id => id !== String(u.id));
                                                                        
                                                                        setFilterData(prev => ({
                                                                            ...prev,
                                                                            incharge: newValues,
                                                                            jurisdiction: newValues.length ? [] : prev.jurisdiction
                                                                        }));
                                                                    }}
                                                                >
                                                                    {u.name}
                                                                </DropdownMenu.CheckboxItem>
                                                            ))}
                                                        </DropdownMenu.Content>
                                                    </DropdownMenu.Root>
                                                </Box>
                                            )}

                                            {/* Jurisdiction - Admin only */}
                                            {userIsAdmin && (
                                                <Box>
                                                    <Flex align="center" gap="2" mb="2">
                                                        <TargetIcon width="14" height="14" color="var(--gray-9)" />
                                                        <Text size="2" color="gray" weight="medium">Jurisdiction</Text>
                                                    </Flex>
                                                    <DropdownMenu.Root>
                                                        <DropdownMenu.Trigger>
                                                            <Button variant="surface" color="gray" size="2" style={{ width: '100%', justifyContent: 'space-between' }}>
                                                                {filterData.jurisdiction.length ? `${filterData.jurisdiction.length} selected` : 'Select jurisdiction'}
                                                                <ChevronDownIcon />
                                                            </Button>
                                                        </DropdownMenu.Trigger>
                                                        <DropdownMenu.Content size="2" style={{ maxHeight: '250px', overflowY: 'auto' }}>
                                                            {jurisdictionOptions?.map(j => (
                                                                <DropdownMenu.CheckboxItem
                                                                    key={j.id}
                                                                    checked={filterData.jurisdiction.includes(String(j.id))}
                                                                    onCheckedChange={(checked) => {
                                                                        const newValues = checked 
                                                                            ? [...filterData.jurisdiction, String(j.id)]
                                                                            : filterData.jurisdiction.filter(id => id !== String(j.id));
                                                                            
                                                                        setFilterData(prev => ({
                                                                            ...prev,
                                                                            jurisdiction: newValues,
                                                                            incharge: newValues.length ? [] : prev.incharge
                                                                        }));
                                                                    }}
                                                                >
                                                                    {j.displayLabel}
                                                                </DropdownMenu.CheckboxItem>
                                                            ))}
                                                        </DropdownMenu.Content>
                                                    </DropdownMenu.Root>
                                                </Box>
                                            )}

                                            {/* Clear Button inside grid */}
                                            <Flex justify={{ initial: 'start', md: 'end' }} style={{ height: '32px' }}>
                                                <Button
                                                    size="2"
                                                    variant="soft"
                                                    color="red"
                                                    onClick={() => {
                                                        setFilterData({ status: 'all', incharge: [], jurisdiction: [], startDate: overallStartDate, endDate: overallEndDate });
                                                        setCurrentPage(1);
                                                    }}
                                                >
                                                    Clear Filters
                                                </Button>
                                            </Flex>
                                        </Grid>
                                    </Card>
                                )}

                                {/* Applied Filter Chips (Badges) Row - Passive Indicators */}
                                {showFilters && hasActiveFilters && (
                                    <Flex wrap="wrap" gap="2" align="center" mb="5">
                                        <Text size="2" color="gray" mr="1">Active:</Text>

                                        {/* Status Chip */}
                                        {filterData.status !== 'all' && (
                                            <Badge size="2" variant="soft" color="orange" style={{ textTransform: 'capitalize' }}>
                                                Status: {filterData.status}
                                            </Badge>
                                        )}

                                        {/* In Charge Chips */}
                                        {filterData.incharge.map(id => {
                                            const option = inchargeOptions?.find(u => String(u.id) === id);
                                            if (!option) return null;
                                            return (
                                                <Badge key={id} size="2" variant="soft" color="indigo">
                                                    In Charge: {option.name}
                                                </Badge>
                                            );
                                        })}

                                        {/* Jurisdiction Chips */}
                                        {filterData.jurisdiction.map(id => {
                                            const option = jurisdictionOptions?.find(j => String(j.id) === id);
                                            if (!option) return null;
                                            return (
                                                <Badge key={id} size="2" variant="soft" color="teal">
                                                    Jurisdiction: {option.displayLabel}
                                                </Badge>
                                            );
                                        })}
                                    </Flex>
                                )}
                            </Box>

                           

                            {/* Tabs */}
                            <Tabs.Root value={activeTab} onValueChange={setActiveTab}>
                                <Tabs.List>
                                    <Tabs.Trigger value="works">
                                        <TableIcon style={{ width: 16, height: 16, marginRight: 8 }} />
                                        Works
                                    </Tabs.Trigger>
                                    <Tabs.Trigger value="summary">
                                        <BarChartIcon style={{ width: 16, height: 16, marginRight: 8 }} />
                                        Summary
                                    </Tabs.Trigger>
                                    <Tabs.Trigger value="objections">
                                        <ExclamationTriangleIcon style={{ width: 16, height: 16, marginRight: 8 }} />
                                        Objections
                                    </Tabs.Trigger>
                                </Tabs.List>

                                {/* Works Tab */}
                                <Tabs.Content value="works">
                                   
                                           
                                        
                                    <Box mt="4">
                                         {/* Search Bar - Works tab only */}
                                        <Box mb="4">
                                            <TextField.Root
                                                placeholder="Search by description, location..."
                                                value={search}
                                                onChange={(e) => handleSearch(e)}
                                                size="2"
                                            >
                                                <TextField.Slot>
                                                    <MagnifyingGlassIcon width="16" height="16" />
                                                </TextField.Slot>
                                                <TextField.Slot side="right">
                                                    {tableLoading && search ? (
                                                        <Spinner size="2" />
                                                    ) : search ? (
                                                        <IconButton 
                                                            size="1" 
                                                            variant="ghost" 
                                                            color="gray" 
                                                            onClick={() => { setSearch(''); setCurrentPage(1); }} 
                                                            aria-label="Clear search"
                                                        >
                                                            <Cross2Icon width="14" height="14" />
                                                        </IconButton>
                                                    ) : null}
                                                </TextField.Slot>
                                            </TextField.Root>
                                        </Box>
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
                                           
                                    
                                </Tabs.Content>

                                {/* Summary Tab */}
                                <Tabs.Content value="summary">
                                    <Box mt="4">
                                        {/* Header with Refresh and Export buttons only - no search or filters */}
                                        <Flex justify="end" gap="3" mb='4'>
                                            <Button
                                                size="2"
                                                variant="soft"
                                                color="indigo"
                                                onClick={handleSummaryRefresh}
                                                aria-label="Refresh summary data"
                                            >
                                                <ReloadIcon style={{ width: 16, height: 16 }} />
                                                {!isMobile && ' Refresh'}
                                            </Button>
                                            {canExportSummary && (
                                                <Button
                                                    size="2"
                                                    variant="soft"
                                                    color="green"
                                                    onClick={() => setOpenModalType('exportSummary')}
                                                    aria-label="Export summary data"
                                                >
                                                    <DownloadIcon style={{ width: 16, height: 16 }} />
                                                    {!isMobile && ' Export'}
                                                </Button>
                                            )}
                                        </Flex>

                                        {/* Summary Sub-tabs */}
                                        <Box mb="4">
                                            <Tabs.Root value={summarySubTab} onValueChange={setSummarySubTab}>
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

                                        {summarySubTab === 'table' && (
                                            <DailyWorkSummaryTable
                                                filteredData={summaryFilteredData}
                                                onRefresh={handleSummaryRefresh}
                                                loading={summaryLoading}
                                            />
                                        )}

                                        {summarySubTab === 'analytics' && (
                                            <DailyWorkSummaryAnalytics
                                                filters={filterData}
                                                data={summaryFilteredData}
                                                isVisible={summarySubTab === 'analytics'}
                                            />
                                        )}
                                    </Box>
                                </Tabs.Content>

                                {/* Objections Tab */}
                                <Tabs.Content value="objections">
                                    <Box mt="5">
                                        <Text size="4" weight="bold" mb="2">Objections Management</Text>
                                        <Text size="2" color="gray" mb="5">Review, resolve, and manage RFI objections with file attachments.</Text>
                                        
                                        {/* Placeholder for Objections content - needs HeroUI to Radix UI migration */}
                                        <Card style={{ minHeight: 400, display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'var(--gray-a2)' }}>
                                            <Flex direction="column" align="center" gap="4" p="6">
                                                <ExclamationTriangleIcon style={{ width: 64, height: 64, color: 'var(--accent-9)' }} />
                                                <Text size="5" weight="bold" color="gray">Objections Tab Coming Soon</Text>
                                                <Text size="2" color="gray">
                                                    The Objections management functionality is currently being migrated from HeroUI to Radix UI. 
                                                    In the meantime, you can access the original objections page through the navigation menu.
                                                </Text>
                                                <Button 
                                                    variant="soft" 
                                                    color="indigo"
                                                    onClick={() => router.visit('/objections')}
                                                >
                                                    Go to Original Objections Page
                                                </Button>
                                            </Flex>
                                        </Card>
                                    </Box>
                                </Tabs.Content>
                            </Tabs.Root>
                        </Box>
                    </Card>
                </Box>
            </Flex>
        </>
    );
};

DailyWorksUnified.layout = (page) => <App>{page}</App>;

export default DailyWorksUnified;
