import React, { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import { Head, usePage } from '@inertiajs/react';
import { useMediaQuery } from '@/Hooks/useMediaQuery.js';
import {
    Badge, Box, Button, Callout, Card, Flex, Grid,
    Heading, Separator, Spinner, Text, TextField,
} from '@radix-ui/themes';
import {
    BarChartIcon, CalendarIcon, CheckCircledIcon, ClockIcon, Cross2Icon,
    CrossCircledIcon, DownloadIcon, ExclamationTriangleIcon,
    MagnifyingGlassIcon, MixerHorizontalIcon, PlusIcon, TableIcon,
} from '@radix-ui/react-icons';
import App from '@/Layouts/App.jsx';
import StatsCards from '@/Components/StatsCards.jsx';
import LeaveEmployeeTable from '@/Tables/LeaveEmployeeTable.jsx';
import LeaveForm from '@/Forms/LeaveForm.jsx';
import DeleteLeaveForm from '@/Forms/DeleteLeaveForm.jsx';
import BulkLeaveModal from '@/Components/BulkLeave/BulkLeaveModal.jsx';
import BulkDeleteModal from '@/Components/BulkDelete/BulkDeleteModal.jsx';
import dayjs from 'dayjs';
import axios from 'axios';
import { showToast } from '@/utils/toastUtils';


const LeavesAdmin = ({ title, allUsers }) => {
    const { auth } = usePage().props;
    
    const isMobile = useMediaQuery('(max-width: 640px)');
    const isTablet = useMediaQuery('(max-width: 768px)');

    // State management - Enhanced for admin view
    const [loading, setLoading] = useState(false);
    const [leavesData, setLeavesData] = useState([]);
    const [leaves, setLeaves] = useState();
    const [totalRows, setTotalRows] = useState(0);
    const [lastPage, setLastPage] = useState(0);
    const [currentLeave, setCurrentLeave] = useState();
    const [selectedLeavesForBulkDelete, setSelectedLeavesForBulkDelete] = useState([]);
    const [error, setError] = useState('');
    const [departments, setDepartments] = useState([]);

 


    // Pagination
    const [pagination, setPagination] = useState({
        perPage: 30,
        currentPage: 1
    });

    // Table-level loading spinner
    const [tableLoading, setTableLoading] = useState(false);

    // Show/Hide advanced filters panel
    const [showFilters, setShowFilters] = useState(false);


    const [filters, setFilters] = useState({
    employee: '',
    selectedMonth: dayjs().format('YYYY-MM'),
    status: [],
    leaveType: [],
    department: []
    });

    const handleFilterChange = useCallback((filterKey, filterValue) => {

    if (filterKey === 'year') {
        const year = Number(filterValue);
        if (year < 1900 || year > new Date().getFullYear()) {
        console.warn('Invalid year selected. Must be between 1900 and current year.');
        return;
        }
    }

    setFilters(prev => ({
        ...prev,
        [filterKey]: filterValue
    }));

    setPagination(prev => ({
        ...prev,
        currentPage: 1
    }));
    }, []);



    // Quick stats state
    const [leaveStats, setLeaveStats] = useState({
        pending: 0,
        approved: 0,
        rejected: 0,
        total: 0,
        thisMonth: 0,
        thisWeek: 0,
        totalDaysUsed: 0,
        totalDaysRemaining: 0
    });

    // Prepare stats data for StatsCards component
    const statsData = useMemo(() => [
        {
            title: "Total Leaves",
            value: leaveStats.total,
            icon: <CalendarIcon />,
            color: "text-primary",
            iconBg: "bg-primary/20",
            description: "All leave requests"
        },
        {
            title: "Pending",
            value: leaveStats.pending,
            icon: <ClockIcon />,
            color: "text-warning",
            iconBg: "bg-warning/20",
            description: "Awaiting approval"
        },
        {
            title: "Approved",
            value: leaveStats.approved,
            icon: <CheckCircledIcon />,
            color: "text-success",
            iconBg: "bg-success/20",
            description: "Approved requests"
        },
        {
            title: "Rejected",
            value: leaveStats.rejected,
            icon: <CrossCircledIcon />,
            color: "text-danger",
            iconBg: "bg-danger/20",
            description: "Rejected requests"
        },
        {
            title: "This Month",
            value: leaveStats.thisMonth,
            icon: <CalendarIcon />,
            color: "text-secondary",
            iconBg: "bg-secondary/20",
            description: "Current month"
        },
        {
            title: "This Week",
            value: leaveStats.thisWeek,
            icon: <CalendarIcon />,
            color: "text-primary",
            iconBg: "bg-primary/20",
            description: "Current week"
        }
    ], [leaveStats]);

    // Check permissions using new system
    const canManageLeaves = auth.permissions?.includes('leaves.view') || false;
    const canApproveLeaves = auth.permissions?.includes('leaves.approve') || false;
    const canCreateLeaves = auth.permissions?.includes('leaves.create') || false;
    const canEditLeaves = auth.permissions?.includes('leaves.update') || false;
    const canDeleteLeaves = auth.permissions?.includes('leaves.delete') || false;

  

    const leaveTypeOptions = useMemo(() => {
        const defaultOptions = [{ key: 'all', label: 'All Types', value: 'all' }];

        if (!leavesData.leaveTypes) return defaultOptions;

        const dynamicOptions = leavesData.leaveTypes.map(leaveType => ({
            key: leaveType.type.toLowerCase(),
            label: leaveType.type,
            value: leaveType.type.toLowerCase()
        }));

        return [...defaultOptions, ...dynamicOptions];
    }, [leavesData.leaveTypes]);

    


    // Modal handlers
    const openModal = useCallback((modalType) => {
        setModalStates(prev => ({ ...prev, [modalType]: true }));
    }, []);

    const handleClickOpen = useCallback((leaveId, modalType) => {
        setCurrentLeave({ id: leaveId });
        setModalStates(prev => ({ ...prev, [modalType]: true }));
    }, []);


    const handleSearch = useCallback((event) => {
        handleFilterChange('employee', event.target.value.toLowerCase());
    }, [handleFilterChange]);

    const handleMonthChange = useCallback((event) => {
        handleFilterChange('selectedMonth', event.target.value);
    }, [handleFilterChange]);

    // Pagination handlers
    const handlePageChange = useCallback((page) => {
   
        setPagination(prev => ({
            ...prev,
            currentPage: page
        }));
    }, []);

    const handlePerPageChange = useCallback((newPerPage) => {
        setPagination(prev => ({
            ...prev,
            perPage: newPerPage,
            currentPage: 1
        }));
    }, []);

    const fetchLeavesData = useCallback(async (targetPage = null, targetPerPage = null) => {
    setLoading(true);
    const pageToFetch = targetPage || pagination.currentPage;
    const perPageToFetch = targetPerPage || pagination.perPage;
    
    try {
        const response = await axios.get(route('leaves.paginate'), {
            params: {
                page: pageToFetch,
                perPage: perPageToFetch,
                employee: filters.employee,
                month: filters.selectedMonth,
                status: Array.isArray(filters.status) && filters.status.length > 0 ? filters.status : undefined,
                leave_type: Array.isArray(filters.leaveType) && filters.leaveType.length > 0 ? filters.leaveType : undefined,
                department: Array.isArray(filters.department) && filters.department.length > 0 ? filters.department : undefined,
                admin_view: true, // Indicate this is an admin view
                view_all: true    // Request all users' leaves
            },
        });

        if (response.status === 200) {
            const { leaves, leavesData, departments } = response.data;

            if (leaves?.data && Array.isArray(leaves.data)) {
                setLeaves(leaves.data);
                setTotalRows(leaves.total || leaves.data.length);
                setLastPage(leaves.last_page || 1);
                
                // Update pagination state if we used different parameters
                if (targetPage && targetPage !== pagination.currentPage) {
                    setPagination(prev => ({
                        ...prev,
                        currentPage: targetPage
                    }));
                }
                if (targetPerPage && targetPerPage !== pagination.perPage) {
                    setPagination(prev => ({
                        ...prev,
                        perPage: targetPerPage
                    }));
                }
            } else if (Array.isArray(leaves)) {
                setLeaves(leaves);
                setTotalRows(leaves.length);
                setLastPage(1);
            } else {
                console.error('Unexpected leaves data format:', leaves);
                setLeaves([]);
                setTotalRows(0);
                setLastPage(1);
            }

            setLeavesData(leavesData);
            setDepartments(departments || []);
            setError('');
        }
    } catch (error) {
        console.error('Error fetching leaves data:', error.response);
        if (error.response?.status === 404) {
            const { leavesData } = error.response.data || {};
            setLeavesData(leavesData || []);
            setError(error.response?.data?.message || 'No leaves found for the selected criteria.');
        } else {
            setError(error.response?.data?.message || 'Error retrieving leaves data. Please try again.');
        }
        setLeaves([]);
        setTotalRows(0);
        setLastPage(1);
    } finally {
        setLoading(false);
    }
}, [filters, pagination.currentPage, pagination.perPage]);

    const fetchLeavesStats = useCallback(async () => {
        try {
            const response = await axios.get(route('leaves.stats'), {
                params: {
                    month: filters.selectedMonth,
                    admin_view: true, // Indicate this is an admin view
                    view_all: true    // Request stats for all users
                },
            });

            if (response.status === 200) {
                const { stats } = response.data;
                setLeaveStats(stats);
            }

        } catch (error) {
            console.error('Error fetching leaves data:', error.response);
            if (error.response?.status === 404) {
                setError(error.response?.data?.message || 'No leaves found for the selected criteria.');
            } else {
                setError('Error retrieving leaves data. Please try again.');
            }
            setLoading(false);
        }
    }, [filters]);

    // Bulk actions for admin
    const handleBulkApprove = useCallback(async (selectedLeaves) => {
        if (!canApproveLeaves) return;

        try {
            const response = await axios.post(route('leaves.bulk-approve'), {
                leave_ids: selectedLeaves
            });

            if (response.status === 200) {
                fetchLeavesData();
                const toastPromise = Promise.resolve();
                showToast.promise(toastPromise, {
                    success: 'Selected leaves approved successfully'
                });
            }
        } catch (error) {
            console.error('Error bulk approving leaves:', error);
            const toastPromise = Promise.reject(error);
            showToast.promise(toastPromise, {
                error: 'Failed to approve selected leaves'
            });
        }
    }, [canApproveLeaves, fetchLeavesData]);

    const handleBulkReject = useCallback(async (selectedLeaves) => {
        if (!canApproveLeaves) return;

        try {
            const response = await axios.post(route('leaves.bulk-reject'), {
                leave_ids: selectedLeaves
            });

            if (response.status === 200) {
                fetchLeavesData();
                const toastPromise = Promise.resolve();
                showToast.promise(toastPromise, {
                    success: 'Selected leaves rejected successfully'
                });
            }
        } catch (error) {
            console.error('Error bulk rejecting leaves:', error);
            const toastPromise = Promise.reject(error);
            showToast.promise(toastPromise, {
                error: 'Failed to reject selected leaves'
            });
        }
    }, [canApproveLeaves, fetchLeavesData]);

    // Handle bulk delete
    const handleBulkDelete = useCallback((selectedLeaves) => {
        setSelectedLeavesForBulkDelete(selectedLeaves);
        setModalStates(prev => ({ ...prev, bulk_delete: true }));
    }, []);

    // Early return if no permissions
    if (!canManageLeaves) {
        return (
            <>
                <Head title={title} />
                <Flex justify="center" p="4">
                    <Box style={{ maxWidth: 440, width: '100%', textAlign: 'center' }}>
                        <Callout.Root color="orange">
                            <Callout.Icon><ExclamationTriangleIcon /></Callout.Icon>
                            <Callout.Text>You don't have permission to view leave management.</Callout.Text>
                        </Callout.Root>
                    </Box>
                </Flex>
            </>
        );
    }
    const [modalStates, setModalStates] = useState({
        add_leave: false,
        edit_leave: false,
        delete_leave: false,
        bulk_leave: false,
        bulk_delete: false,
    });
    const leaveTableRef = useRef(null);

    const openModalNew = useCallback((modalType) => {
        setModalStates(prev => ({ ...prev, [modalType]: true }));
    }, []);

    const closeModal = useCallback((modalType) => {
        setModalStates(prev => ({ ...prev, [modalType]: false }));
        // Clear selected leaves when closing bulk delete modal
        if (modalType === 'bulk_delete') {
            setSelectedLeavesForBulkDelete([]);
        }
    }, []);

    // Optimized data manipulation functions
    const sortLeavesByFromDate = useCallback((leavesArray) => {
        return [...leavesArray].sort((a, b) => new Date(b.from_date) - new Date(a.from_date));
    }, []);

     // Optimized pagination update without full reload
    const updatePaginationMetadata = useCallback((totalCount, affectedPage = null) => {
        // Update total rows
        setTotalRows(totalCount);
        
        // Calculate new last page
        const newLastPage = Math.max(1, Math.ceil(totalCount / pagination.perPage));
        setLastPage(newLastPage);
        
        // Ensure current page is valid
        if (pagination.currentPage > newLastPage) {
            setPagination(prev => ({
                ...prev,
                currentPage: newLastPage
            }));
        }
    }, [pagination.perPage, pagination.currentPage]);

  


    const leaveMatchesFilters = useCallback((leave) => {
        // Month filter
        const leaveMonth = dayjs(leave.from_date).format('YYYY-MM');
        if (filters.selectedMonth && leaveMonth !== filters.selectedMonth) return false;
        // Employee filter
        if (filters.employee) {
            const user = allUsers?.find(u => String(u.id) === String(leave.user_id));
            const filterValue = filters.employee.trim().toLowerCase();
            if (!user) {
                if (String(filters.employee) !== String(leave.user_id)) return false;
            } else if (
                String(user.id) !== filterValue &&
                !(user.name && user.name.trim().toLowerCase().includes(filterValue))
            ) {
                return false;
            }
        }
        // Status filter
        if (Array.isArray(filters.status) && filters.status.length > 0) {
            const matchesStatus = filters.status.some(status => String(leave.status).toLowerCase() === String(status).toLowerCase());
            if (!matchesStatus) return false;
        }
        // Leave type filter
        if (Array.isArray(filters.leaveType) && filters.leaveType.length > 0) {
            const matchesType = filters.leaveType.some(type => String(leave.leave_type).toLowerCase() === String(type).toLowerCase());
            if (!matchesType) return false;
        }
        // Department filter
        if (Array.isArray(filters.department) && filters.department.length > 0) {
            const user = allUsers?.find(u => String(u.id) === String(leave.user_id));
            if (!user) return false;
            const matchesDepartment = filters.department.some(depId => String(user.department_id) === String(depId));
            if (!matchesDepartment) return false;
        }
        return true;
    }, [filters, allUsers]);

    // Memoize leaves for table rendering
    const memoizedLeaves = useMemo(() => leaves || [], [leaves]);

    // Intelligently fetch additional items if needed without full reload
    const fetchAdditionalItemsIfNeeded = useCallback(async () => {
        // Only fetch if the number of displayed items is less than the perPage limit
        // This could happen after a deletion on any page, not just page 1.
        if (leaves && leaves.length < pagination.perPage && totalRows > leaves.length) {
            const itemsNeeded = Math.min(pagination.perPage - leaves.length, totalRows - leaves.length); // Don't fetch more than exist in total
            if (itemsNeeded <= 0) return;
            setTableLoading(true); // Show skeleton loader
            try {
                const response = await axios.get(route('leaves.paginate'), {
                    params: {
                        page: pagination.currentPage + 1, // Fetch the next page
                        perPage: itemsNeeded,          // Request only the needed items
                        employee: filters.employee,
                        month: filters.selectedMonth,
                        status: Array.isArray(filters.status) && filters.status.length > 0 ? filters.status : undefined,
                        leave_type: Array.isArray(filters.leaveType) && filters.leaveType.length > 0 ? filters.leaveType : undefined,
                        department: Array.isArray(filters.department) && filters.department.length > 0 ? filters.department : undefined,
                        admin_view: true, // Indicate this is an admin view
                        view_all: true    // Request all users' leaves
                    },
                });
                if (response.status === 200 && response.data.leaves.data) {
                    // Add these items to the current page, filtered
                    setLeaves(prevLeaves => {
                        const newItems = response.data.leaves.data.filter(leaveMatchesFilters);
                        const combinedLeaves = [...prevLeaves, ...newItems];
                        return sortLeavesByFromDate(combinedLeaves);
                    });
                }
            } catch (error) {
                const toastPromise = Promise.reject(error);
                showToast.promise(toastPromise, {
                    error: 'Error fetching additional items.'
                });
                console.error(`Error fetching additional items from page ${pagination.currentPage + 1}:`, error);
            } finally {
                setTableLoading(false);
            }
        }
    }, [pagination.currentPage, pagination.perPage, leaves, filters, sortLeavesByFromDate, leaveMatchesFilters]);


     // Unified post-operation update handler for all CRUD operations
    const handlePostOperationUpdate = useCallback((operation, responseData) => {
        if (!responseData || !responseData.success) {
            console.error(`Invalid ${operation} response data:`, responseData);
            return;
        }

        // Always refresh stats for any operation
        fetchLeavesStats();

        // Update global leaves data if provided
        if (responseData.leavesData) {
            setLeavesData(responseData.leavesData);
        }

        const itemsPerPage = pagination.perPage;
        const currentPage = pagination.currentPage;

        switch (operation) {
            case 'bulk_delete': {
                const deletedLeaves = responseData.deleted_leaves || [];
                const deletedCount = responseData.deleted_count || deletedLeaves.length;
                
                if (deletedCount === 0) return;

                const deletedLeaveIds = deletedLeaves.map(leave => leave.id);
                const newTotal = Math.max(0, totalRows - deletedCount);
                const remainingOnCurrentPage = leaves.filter(leave => !deletedLeaveIds.includes(leave.id)).length;
                
                // Determine refresh strategy based on operation impact
                const shouldFullRefresh = (
                    deletedCount > 5 || // Large bulk operation
                    remainingOnCurrentPage === 0 || // Current page becomes empty
                    (currentPage > 1 && remainingOnCurrentPage < itemsPerPage / 2) // Page significantly depleted
                );

                if (shouldFullRefresh) {
                    // Calculate appropriate target page
                    const newLastPage = Math.ceil(newTotal / itemsPerPage);
                    let targetPage = currentPage;
                    
                    if (remainingOnCurrentPage === 0 && newTotal > 0) {
                        // If current page is empty but data exists, go to previous page or page 1
                        targetPage = Math.max(1, currentPage - 1);
                    } else if (currentPage > newLastPage && newLastPage > 0) {
                        // If current page exceeds new total pages, go to last page
                        targetPage = newLastPage;
                    }

                    // Update pagination and fetch fresh data
                    setPagination(prev => ({ ...prev, currentPage: targetPage }));
                    fetchLeavesData(targetPage, itemsPerPage);
                } else {
                    // Optimistic update for small deletions
                    setTableLoading(true);
                    setLeaves(prevLeaves => prevLeaves.filter(leave => !deletedLeaveIds.includes(leave.id)));
                    setTotalRows(newTotal);
                    updatePaginationMetadata(newTotal);
                    setTableLoading(false);
                    
                    // Fill page if needed
                    fetchAdditionalItemsIfNeeded();
                }
                break;
            }

            case 'single_delete': {
                const deletedLeaveId = responseData.deleted_leave_id;
                if (!deletedLeaveId) return;

                const newTotal = Math.max(0, totalRows - 1);
                const remainingOnCurrentPage = leaves.filter(leave => leave.id !== deletedLeaveId).length;

                if (remainingOnCurrentPage === 0 && newTotal > 0 && currentPage > 1) {
                    // Navigate to previous page if current page becomes empty
                    const targetPage = currentPage - 1;
                    setPagination(prev => ({ ...prev, currentPage: targetPage }));
                    fetchLeavesData(targetPage, itemsPerPage);
                } else {
                    // Optimistic update
                    setTableLoading(true);
                    setLeaves(prevLeaves => prevLeaves.filter(leave => leave.id !== deletedLeaveId));
                    setTotalRows(newTotal);
                    updatePaginationMetadata(newTotal);
                    setTableLoading(false);
                    
                    // Fill page if needed
                    fetchAdditionalItemsIfNeeded();
                }
                break;
            }

            case 'bulk_add': {
                const addedCount = responseData.added_count || 1;
                const newTotal = totalRows + addedCount;
                
                // For bulk additions, always refresh data to ensure proper filtering
                setTotalRows(newTotal);
                updatePaginationMetadata(newTotal);
                fetchLeavesData(currentPage, itemsPerPage);
                break;
            }
            
            case 'single_add': {
                const addedCount = responseData.added_count || 1;
                const newTotal = totalRows + addedCount;
                const newLeave = responseData.leave;
                
                // Check if the new leave matches current filters
                const matchesCurrentFilters = newLeave && leaveMatchesFilters(newLeave);
                
                if (matchesCurrentFilters) {
                    // If leave matches filters and we're on the last page with room, add it directly
                    if (leaves.length < itemsPerPage) {
                        const updatedLeaves = sortLeavesByFromDate([...leaves, newLeave]);
                        setLeaves(updatedLeaves);
                        setTotalRows(newTotal);
                        updatePaginationMetadata(newTotal);
                    } else {
                        // If page is full, refresh to maintain proper pagination
                        setTotalRows(newTotal);
                        updatePaginationMetadata(newTotal);
                        fetchLeavesData(currentPage, itemsPerPage);
                    }
                } else {
                    // If leave doesn't match filters, just update total count without adding to view
                    setTotalRows(newTotal);
                    updatePaginationMetadata(newTotal);
                }
                break;
            }

            case 'edit': {
                // For edits, optimistically update the specific item
                const updatedLeave = responseData.updated_leave || responseData.leave;
                if (updatedLeave) {
                    setLeaves(prevLeaves => 
                        prevLeaves.map(leave => 
                            leave.id === updatedLeave.id ? updatedLeave : leave
                        )
                    );
                }
                break;
            }
        }
    }, [
        pagination.perPage, 
        pagination.currentPage, 
        totalRows, 
        leaves, 
        fetchLeavesStats, 
        updatePaginationMetadata, 
        fetchLeavesData, 
        fetchAdditionalItemsIfNeeded,
        leaveMatchesFilters,
        sortLeavesByFromDate
    ]);

    // Optimistic UI for add/edit
    // Single add handler using unified post-operation update
    const addLeaveOptimized = useCallback((newLeave) => {
        const responseData = {
            success: true,
            added_count: 1,
            leave: newLeave
        };
        handlePostOperationUpdate('single_add', responseData);
    }, [handlePostOperationUpdate]);

    // Update handler using unified post-operation update
    const updateLeaveOptimized = useCallback((updatedLeave) => {
        const responseData = {
            success: true,
            updated_leave: updatedLeave
        };
        handlePostOperationUpdate('edit', responseData);
        
        // Toast is handled by the LeaveForm component, no need for duplicate toast here
    }, [handlePostOperationUpdate]);

    // Bulk add handler using unified post-operation update
    const addBulkLeavesOptimized = useCallback((responseData) => {
        if (!responseData || !responseData.success) {
            console.error('Invalid bulk response data:', responseData);
            return;
        }

        // Calculate added count from response data
        const createdLeaves = responseData.created_leaves || [];
        const addedCount = responseData.summary?.successful || createdLeaves.length;
        
        const updatedResponseData = {
            ...responseData,
            added_count: addedCount
        };
        
        handlePostOperationUpdate('bulk_add', updatedResponseData);
    }, [handlePostOperationUpdate]);


    

    // Single delete handler using unified post-operation update
    const deleteLeaveOptimized = useCallback((leaveId) => {
        // Create response data structure for the unified handler
        const responseData = {
            success: true,
            deleted_leave_id: leaveId
        };
        handlePostOperationUpdate('single_delete', responseData);
    }, [handlePostOperationUpdate]);

    // Optimistic UI for bulk deletion
   

    // Simplified bulk delete handler
    const deleteBulkLeavesOptimized = useCallback((responseData) => {
        handlePostOperationUpdate('bulk_delete', responseData);
    }, [handlePostOperationUpdate]);

   

    
    useEffect(() => {
        if (canManageLeaves) {
            // Only fetch data when page changes or filters change, not for every internal state update
            fetchLeavesData();
        }
    }, [fetchLeavesData, canManageLeaves, pagination.currentPage, filters.employee, filters.selectedMonth, filters.status, filters.leaveType, filters.department]);

    useEffect(() => {
        if (canManageLeaves) {
            fetchLeavesStats();
        }
    }, [fetchLeavesData, canManageLeaves]);

    


    return (
        <>
            <Head title={title} />

            {modalStates.add_leave && (
                <LeaveForm
                    open={modalStates.add_leave}
                    closeModal={() => closeModal("add_leave")}
                    leavesData={leavesData}
                    setLeavesData={setLeavesData}
                    currentLeave={null}
                    allUsers={allUsers}
                    departments={departments}
                    setTotalRows={setTotalRows}
                    setLastPage={setLastPage}
                    setLeaves={setLeaves}
                    handleMonthChange={handleMonthChange}
                    employee={filters.employee}
                    selectedMonth={filters.selectedMonth}
                    addLeaveOptimized={addLeaveOptimized}
                    fetchLeavesStats={fetchLeavesStats}
                />
            )}
            {modalStates.edit_leave && (
                <LeaveForm
                    open={modalStates.edit_leave}
                    closeModal={() => closeModal("edit_leave")}
                    leavesData={leavesData}
                    setLeavesData={setLeavesData}
                    currentLeave={currentLeave}
                    allUsers={allUsers}
                    departments={departments}
                    setTotalRows={setTotalRows}
                    setLastPage={setLastPage}
                    setLeaves={setLeaves}
                    handleMonthChange={handleMonthChange}
                    employee={filters.employee}
                    selectedMonth={filters.selectedMonth}
                    updateLeaveOptimized={updateLeaveOptimized}
                    fetchLeavesStats={fetchLeavesStats}
                />
            )}
            {modalStates.delete_leave && (
                <DeleteLeaveForm
                    open={modalStates.delete_leave}
                    closeModal={() => closeModal("delete_leave")}
                    leaveId={currentLeave?.id}
                    setLeaves={setLeaves}
                    setTotalRows={setTotalRows}
                    setLastPage={setLastPage}
                    deleteLeaveOptimized={deleteLeaveOptimized}
                    fetchLeavesStats={fetchLeavesStats}
                />
            )}
            {modalStates.bulk_leave && (
                <BulkLeaveModal
                    open={modalStates.bulk_leave}
                    onClose={() => closeModal("bulk_leave")}
                    onSuccess={(responseData) => { addBulkLeavesOptimized(responseData); }}
                    allUsers={allUsers}
                    departments={departments}
                    leavesData={leavesData}
                    isAdmin={true}
                />
            )}
            {modalStates.bulk_delete && (
                <BulkDeleteModal
                    open={modalStates.bulk_delete}
                    onClose={() => closeModal("bulk_delete")}
                    onSuccess={(responseData) => { deleteBulkLeavesOptimized(responseData); }}
                    selectedLeaves={selectedLeavesForBulkDelete}
                    allUsers={allUsers}
                />
            )}

            <Box p="4">
                <Card size="3">
                    {/* Header */}
                    <Flex align="center" justify="between" wrap="wrap" gap="3" pb="4" mb="4"
                        style={{ borderBottom: '1px solid var(--gray-a4)' }}
                    >
                        <Flex align="center" gap="3">
                            <Box style={{
                                padding: 10, background: 'var(--accent-a3)', borderRadius: 'var(--radius-2)',
                                border: '1px solid var(--accent-a6)', display: 'flex', alignItems: 'center', justifyContent: 'center',
                            }}>
                                <BarChartIcon style={{ width: 22, height: 22, color: 'var(--accent-9)' }} />
                            </Box>
                            <Box>
                                <Heading size="5">Leave Management</Heading>
                                <Text size="2" color="gray">Manage employee leave requests and approvals</Text>
                            </Box>
                        </Flex>
                        <Flex gap="2" wrap="wrap">
                            {canCreateLeaves && (
                                <Button onClick={() => openModalNew('add_leave')} size="2" style={{ cursor: 'pointer' }}>
                                    <PlusIcon /> Add Leave
                                </Button>
                            )}
                            {canCreateLeaves && (
                                <Button variant="soft" color="gray" onClick={() => openModalNew('bulk_leave')} size="2" style={{ cursor: 'pointer' }}>
                                    <CalendarIcon /> Bulk Add
                                </Button>
                            )}
                            <Button variant="soft" color="gray" size="2">
                                <DownloadIcon /> Export
                            </Button>
                        </Flex>
                    </Flex>

                    {/* Stats */}
                    <StatsCards stats={statsData} className="mb-6" />

                    {/* Search + filter toggle */}
                    <Flex gap="2" mb="4" wrap="wrap">
                        <Box style={{ flex: 1, minWidth: 200 }}>
                            <TextField.Root
                                placeholder="Search employee..."
                                value={filters.employee}
                                onChange={e => handleFilterChange('employee', e.target.value)}
                                size="2"
                            >
                                <TextField.Slot><MagnifyingGlassIcon /></TextField.Slot>
                            </TextField.Root>
                        </Box>
                        <Button
                            variant={showFilters ? 'solid' : 'soft'}
                            color={showFilters ? undefined : 'gray'}
                            onClick={() => setShowFilters(v => !v)}
                            size="2"
                            style={{ cursor: 'pointer' }}
                        >
                            <MixerHorizontalIcon /> Filters
                        </Button>
                    </Flex>

                    {/* Advanced filters panel */}
                    {showFilters && (
                        <Box mb="4" p="3" style={{
                            background: 'var(--gray-a2)', borderRadius: 'var(--radius-2)',
                            border: '1px solid var(--gray-a4)',
                        }}>
                            <Grid columns={{ initial: '1', sm: '2', lg: '4' }} gap="3">
                                <Box>
                                    <Text as="label" size="1" weight="medium" style={{ display: 'block', marginBottom: 4 }}>Month / Year</Text>
                                    <input
                                        type="month"
                                        value={filters.selectedMonth}
                                        onChange={handleMonthChange}
                                        style={{
                                            width: '100%', padding: '6px 10px',
                                            background: 'var(--color-surface)', border: '1px solid var(--gray-a7)',
                                            borderRadius: 'var(--radius-2)', color: 'var(--gray-12)', fontSize: 14,
                                        }}
                                    />
                                </Box>
                                <Box>
                                    <Text as="label" size="1" weight="medium" style={{ display: 'block', marginBottom: 4 }}>Status</Text>
                                    <select
                                        multiple
                                        value={filters.status}
                                        onChange={e => handleFilterChange('status', Array.from(e.target.selectedOptions, o => o.value))}
                                        style={{
                                            width: '100%', padding: '6px 10px', minHeight: 80,
                                            background: 'var(--color-surface)', border: '1px solid var(--gray-a7)',
                                            borderRadius: 'var(--radius-2)', color: 'var(--gray-12)', fontSize: 14,
                                        }}
                                    >
                                        <option value="pending">Pending</option>
                                        <option value="approved">Approved</option>
                                        <option value="rejected">Rejected</option>
                                        <option value="new">New</option>
                                    </select>
                                </Box>
                                <Box>
                                    <Text as="label" size="1" weight="medium" style={{ display: 'block', marginBottom: 4 }}>Leave Type</Text>
                                    <select
                                        multiple
                                        value={filters.leaveType}
                                        onChange={e => handleFilterChange('leaveType', Array.from(e.target.selectedOptions, o => o.value))}
                                        style={{
                                            width: '100%', padding: '6px 10px', minHeight: 80,
                                            background: 'var(--color-surface)', border: '1px solid var(--gray-a7)',
                                            borderRadius: 'var(--radius-2)', color: 'var(--gray-12)', fontSize: 14,
                                        }}
                                    >
                                        {leaveTypeOptions.map(o => (
                                            <option key={o.key} value={o.value}>{o.label}</option>
                                        ))}
                                    </select>
                                </Box>
                                <Box>
                                    <Text as="label" size="1" weight="medium" style={{ display: 'block', marginBottom: 4 }}>Department</Text>
                                    <select
                                        multiple
                                        value={filters.department}
                                        onChange={e => handleFilterChange('department', Array.from(e.target.selectedOptions, o => o.value))}
                                        style={{
                                            width: '100%', padding: '6px 10px', minHeight: 80,
                                            background: 'var(--color-surface)', border: '1px solid var(--gray-a7)',
                                            borderRadius: 'var(--radius-2)', color: 'var(--gray-12)', fontSize: 14,
                                        }}
                                    >
                                        {departments.map(d => (
                                            <option key={d.id} value={d.id}>{d.name}</option>
                                        ))}
                                    </select>
                                </Box>
                            </Grid>

                            {/* Active filter badges */}
                            {(filters.employee ||
                              (Array.isArray(filters.status) && filters.status.length > 0) ||
                              (Array.isArray(filters.leaveType) && filters.leaveType.length > 0) ||
                              (Array.isArray(filters.department) && filters.department.length > 0)) && (
                                <Flex gap="2" wrap="wrap" mt="3" pt="3" style={{ borderTop: '1px solid var(--gray-a4)' }}>
                                    {filters.employee && (
                                        <Badge color="blue" style={{ cursor: 'pointer' }} onClick={() => handleFilterChange('employee', '')}>
                                            Employee: {filters.employee} <Cross2Icon />
                                        </Badge>
                                    )}
                                    {Array.isArray(filters.status) && filters.status.map(s => (
                                        <Badge key={s} color="violet" style={{ cursor: 'pointer' }} onClick={() => handleFilterChange('status', filters.status.filter(x => x !== s))}>
                                            Status: {s} <Cross2Icon />
                                        </Badge>
                                    ))}
                                    {Array.isArray(filters.leaveType) && filters.leaveType.map(t => (
                                        <Badge key={t} color="amber" style={{ cursor: 'pointer' }} onClick={() => handleFilterChange('leaveType', filters.leaveType.filter(x => x !== t))}>
                                            Type: {t} <Cross2Icon />
                                        </Badge>
                                    ))}
                                    {Array.isArray(filters.department) && filters.department.map(dId => {
                                        const dept = departments.find(d => String(d.id) === String(dId));
                                        return (
                                            <Badge key={dId} color="green" style={{ cursor: 'pointer' }} onClick={() => handleFilterChange('department', filters.department.filter(x => x !== dId))}>
                                                Dept: {dept?.name || dId} <Cross2Icon />
                                            </Badge>
                                        );
                                    })}
                                </Flex>
                            )}
                        </Box>
                    )}

                    {/* Table section */}
                    <Box>
                        <Flex align="center" gap="2" mb="3">
                            <TableIcon style={{ width: 16, height: 16 }} />
                            <Text size="3" weight="medium">Leave Requests Management</Text>
                        </Flex>
                        {loading ? (
                            <Flex direction="column" align="center" py="8" gap="3">
                                <Spinner size="3" />
                                <Text color="gray">Loading leave data...</Text>
                            </Flex>
                        ) : leaves && leaves.length > 0 ? (
                            <LeaveEmployeeTable
                                ref={leaveTableRef}
                                totalRows={totalRows}
                                lastPage={lastPage}
                                setCurrentPage={handlePageChange}
                                setPerPage={handlePerPageChange}
                                perPage={pagination.perPage}
                                currentPage={pagination.currentPage}
                                handleClickOpen={handleClickOpen}
                                setCurrentLeave={setCurrentLeave}
                                openModal={openModal}
                                leaves={memoizedLeaves}
                                allUsers={allUsers}
                                setLeaves={setLeaves}
                                employee={filters.employee}
                                selectedMonth={filters.selectedMonth}
                                isAdminView={true}
                                onBulkApprove={handleBulkApprove}
                                onBulkReject={handleBulkReject}
                                canApproveLeaves={canApproveLeaves}
                                canEditLeaves={canEditLeaves}
                                canDeleteLeaves={canDeleteLeaves}
                                fetchLeavesStats={fetchLeavesStats}
                                onBulkDelete={handleBulkDelete}
                            />
                        ) : error ? (
                            <Callout.Root color="orange">
                                <Callout.Icon><ExclamationTriangleIcon /></Callout.Icon>
                                <Callout.Text>{error}</Callout.Text>
                            </Callout.Root>
                        ) : (
                            <Flex direction="column" align="center" py="8" gap="2">
                                <CalendarIcon style={{ width: 40, height: 40, color: 'var(--gray-9)' }} />
                                <Heading size="3">No Leave Records Found</Heading>
                                <Text color="gray">No leave records found for the selected criteria.</Text>
                            </Flex>
                        )}
                    </Box>
                </Card>
            </Box>
        </>
    );
};
LeavesAdmin.layout = (page) => <App>{page}</App>;

export default LeavesAdmin;
