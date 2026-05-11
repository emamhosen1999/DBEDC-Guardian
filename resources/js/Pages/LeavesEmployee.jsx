import React, { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import { Head, usePage, router } from '@inertiajs/react';
import dayjs from 'dayjs';
import { useMediaQuery } from '@/Hooks/useMediaQuery.js';
import {
    Badge, Box, Button, Callout, Card, Flex, Grid,
    Heading, Separator, Spinner, Text,
} from '@radix-ui/themes';
import {
    BarChartIcon, CalendarIcon, ExclamationTriangleIcon,
    PlusIcon, ReloadIcon, TableIcon,
} from '@radix-ui/react-icons';
import App from '@/Layouts/App.jsx';

import LeaveEmployeeTable from '@/Tables/LeaveEmployeeTable.jsx';
import LeaveForm from '@/Forms/LeaveForm.jsx';
import DeleteLeaveForm from '@/Forms/DeleteLeaveForm.jsx';
import BulkLeaveModal from '@/Components/BulkLeave/BulkLeaveModal.jsx';
import BulkDeleteModal from '@/Components/BulkDelete/BulkDeleteModal.jsx';
import { showToast } from '@/utils/toastUtils';
import axios from 'axios';

const LeavesEmployee = ({ title, allUsers }) => {
  const { auth } = usePage().props;

  const isMobile = useMediaQuery('(max-width: 640px)');
  const isTablet = useMediaQuery('(min-width: 641px) and (max-width: 1024px)');
    const [totalRows, setTotalRows] = useState(0);
      const [lastPage, setLastPage] = useState(0);
  // State management
  const [loading, setLoading] = useState(false);
  const [leaves, setLeaves] = useState([]);
  const [leavesData, setLeavesData] = useState({ 
    leaveTypes: [], 
    leaveCountsByUser: {} 
  });
    // Table-level loading spinner
  const [tableLoading, setTableLoading] = useState(false);

  const [error, setError] = useState('');
  const [pagination, setPagination] = useState({ 
    page: 1, 
    perPage: 30, 
    total: 0, 
    lastPage: 0 
  });
  const [filters, setFilters] = useState({ 
    employee: '', 
    selectedMonth: dayjs().format('YYYY-MM'),
    year: new Date().getFullYear() 
  });

  // Function to update pagination metadata
  const updatePaginationMetadata = useCallback((metadata) => {
    if (metadata) {
      setTotalRows(metadata.total || 0);
      setLastPage(metadata.last_page || 1);
      setPagination(prev => ({
        ...prev,
        total: metadata.total || 0,
        lastPage: metadata.last_page || 1
      }));
    }
  }, []);
   // Fetch leaves data with error handling
  const fetchLeaves = useCallback(async () => {
 setTableLoading(true); // Use tableLoading for table refresh
    try {
      const { page, perPage } = pagination;
      const { year } = filters;
      
      
      const response = await axios.get(route('leaves.paginate'), {
        params: { 
          page, 
          perPage, 
          year,
          user_id: auth.user.id // Explicitly pass the current user ID
        },
        timeout: 10000, // 10 second timeout
      });

      if (response.status === 200) {
        const { leaves, leavesData } = response.data;

        if (leaves.data && Array.isArray(leaves.data)) {
                setLeaves(leaves.data);
                // Update pagination metadata
                updatePaginationMetadata({
                  total: leaves.total || leaves.data.length,
                  last_page: leaves.last_page || 1,
                  current_page: leaves.current_page || 1,
                  per_page: leaves.per_page || pagination.perPage
                });
            } else if (Array.isArray(leaves)) {
                // Handle direct array response
                setLeaves(leaves);
                updatePaginationMetadata({
                  total: leaves.length,
                  last_page: 1,
                  current_page: 1,
                  per_page: pagination.perPage
                });
            } else {
                console.error('Unexpected leaves data format:', leaves);
                setLeaves([]);
                updatePaginationMetadata({
                  total: 0,
                  last_page: 1,
                  current_page: 1,
                  per_page: pagination.perPage
                });
            }
            
            setLeavesData(leavesData);
            setError('');
      }
    } catch (error) {
      console.error('Error fetching leaves:', error);
      if (error.response?.status === 404) {
        const { leavesData } = error.response.data;
        setLeavesData(leavesData);
        setError(error.response?.data?.message || 'No leaves found for the selected criteria.');
      } else {
        setError('Error retrieving leaves data. Please try again.');
        
        // Use toast promise pattern for safety
        const promise = Promise.reject('Failed to load leave data. Please try again.');
        showToast.promise(
          promise,
          {
            error: {
              render() {
                return <div>Failed to load leave data. Please try again.</div>;
              },
              icon: '❌'
            }
          }
        );
      }
      setLeaves([]);
      updatePaginationMetadata({
        total: 0,
        last_page: 1,
        current_page: 1,
        per_page: pagination.perPage
      });
    } finally {
 setTableLoading(false); // Reset tableLoading
    }
  }, [pagination.page, pagination.perPage, filters, auth.user.id, updatePaginationMetadata]);

  // Function to fetch additional items if needed after operations
  const fetchAdditionalItemsIfNeeded = useCallback((currentItems, totalItems, operation) => {
    const { page, perPage } = pagination;
    
    // If we're not on the last page, or we have exactly enough items to fill the current page,
    // we don't need to fetch more data
    if (currentItems.length >= perPage || page < lastPage) {
      return;
    }
    
    // If we're on the last page and have fewer items than perPage after an operation,
    // fetch new data to fill the gap
    fetchLeaves();
  }, [pagination, lastPage, fetchLeaves]);

  
 // Memoized year options
  // Memoized year options following ISO standard (1900-current year)
  const yearOptions = useMemo(() => {
    const currentYear = new Date().getFullYear();
    return Array.from({ length: currentYear - 1900 + 1 }, (_, index) => {
      const year = 1900 + index;
      return { key: year.toString(), label: year.toString(), value: year };
    }).reverse();
  }, []);

  // Filter change handler with ISO-compliant validation
  const handleFilterChange = useCallback((filterKey, filterValue) => {
    // Validate year input according to ISO 8601
    if (filterKey === 'year') {
      const year = Number(filterValue);
      if (year < 1900 || year > new Date().getFullYear()) {
        console.warn('Invalid year selected. Must be between 1900 and current year.');
        return;
      }
    }

    setFilters(previousFilters => ({ 
      ...previousFilters, 
      [filterKey]: filterValue 
    }));

    // Reset pagination when year filter changes
    if (filterKey === 'year') {
      setPagination(previousPagination => ({ 
        ...previousPagination, 
        page: 1 
      }));
    }
  }, []);

  // Modal state
  const [modalStates, setModalStates] = useState({
    add_leave: false,
    edit_leave: false,
    delete_leave: false,
    bulk_leave: false,
    bulk_delete: false,
  });

  // Modal handlers
  const handleOpenModal = useCallback((modalType, itemId = null) => {
    setModalStates(prev => ({ ...prev, [modalType]: true }));
  }, []);

  const closeModal = useCallback(() => {
    setModalStates({ add_leave: false, edit_leave: false, delete_leave: false, bulk_leave: false, bulk_delete: false });
  }, []);

  const openModal = useCallback((modalType) => {
    setModalStates(prev => ({ ...prev, [modalType]: true }));
  }, []);

  const handleClickOpen = useCallback((leaveId, modalType) => {
    setCurrentLeave({ id: leaveId });
    setModalStates(prev => ({ ...prev, [modalType]: true }));
  }, []);

  const [currentLeave, setCurrentLeave] = useState(null);
  const [selectedLeavesForBulkDelete, setSelectedLeavesForBulkDelete] = useState([]);

  const handleSetCurrentLeave = useCallback((leave) => {
    setCurrentLeave(leave);
  }, []);

  // Handle bulk delete
  const handleBulkDelete = useCallback((selectedLeaves) => {
    setSelectedLeavesForBulkDelete(selectedLeaves);
    openModal('bulk_delete');
  }, [openModal]);

 

  // Fetch leave statistics
  const fetchLeavesStats = useCallback(async () => {
    try {
      const response = await axios.get(route('leaves.stats'), {
        params: {
          year: filters.year,
        },
      });

      if (response.status === 200) {
        // Update the leave counts data to reflect the changes
        const { stats, leaveCounts } = response.data;
        
        if (leaveCounts) {
          // Update the leave counts in the leavesData
          setLeavesData(prevData => ({
            ...prevData,
            leaveCountsByUser: {
              ...prevData.leaveCountsByUser,
              [auth.user.id]: leaveCounts
            }
          }));
        }
      }
    } catch (error) {
      console.error('Error fetching leaves stats:', error.response);
      // We don't want to disrupt the user experience if stats fail to load
      // so we just log the error and don't show an error message
    }
  }, [filters.year, auth.user.id]);

  // Handle pagination changes
  const handlePageChange = useCallback((newPage) => {
    // Only change page if it's different from the current page
    if (newPage !== pagination.page) {
      setPagination(prev => ({ ...prev, page: newPage }));
    }
  }, [pagination.page]);

  // Effect for data fetching
  useEffect(() => {
    fetchLeaves();
  }, [fetchLeaves]);
  
  // Separate effect for fetching leave stats to avoid unnecessary refetches
  useEffect(() => {
    fetchLeavesStats();
  }, [fetchLeavesStats]);
  // Extract user-specific leave counts and calculate stats
  const userLeaveCounts = useMemo(() => {
    return leavesData.leaveCountsByUser[auth.user.id] || [];
  }, [leavesData.leaveCountsByUser, auth.user.id]);

  // Optimized data manipulation functions
  const sortLeavesByFromDate = useCallback((leavesArray) => {
    return [...leavesArray].sort((a, b) => new Date(a.from_date) - new Date(b.from_date));
  }, []);

  const leaveMatchesFilters = useCallback((leave) => {
    // Year filter
    const leaveYear = new Date(leave.from_date).getFullYear();
    if (filters.year && leaveYear !== filters.year) return false;
    // Employee filter (for future extensibility, currently always own)
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
    // Month filter
    if (filters.selectedMonth) {
      const leaveMonth = dayjs(leave.from_date).format('YYYY-MM');
      if (leaveMonth !== filters.selectedMonth) return false;
    }
    // Status filter
    if (filters.status && filters.status !== 'all' && String(leave.status).toLowerCase() !== String(filters.status).toLowerCase()) return false;
    // Leave type filter
    if (filters.leaveType && filters.leaveType !== 'all' && String(leave.leave_type).toLowerCase() !== String(filters.leaveType).toLowerCase()) return false;
    // Department filter
    if (filters.department && filters.department !== 'all') {
      const user = allUsers?.find(u => String(u.id) === String(leave.user_id));
      if (!user || String(user.department).toLowerCase() !== String(filters.department).toLowerCase()) return false;
    }
    return true;
  }, [filters, allUsers]);

  // Memoize leaves for table rendering
  const memoizedLeaves = useMemo(() => leaves || [], [leaves]);

  // Optimistic UI for add/edit
  const addLeaveOptimized = useCallback((newLeave) => {
    if (!leaveMatchesFilters(newLeave)) return;
    setTableLoading(true);
    setLeaves(prevLeaves => {
      const updatedLeaves = [...prevLeaves, newLeave];
      return sortLeavesByFromDate(updatedLeaves).slice(0, pagination.perPage);
    });
    updatePaginationMetadata({
      total: totalRows + 1,
      last_page: Math.ceil((totalRows + 1) / pagination.perPage),
      current_page: pagination.page,
      per_page: pagination.perPage
    });

    setTableLoading(false);
    if (pagination.page !== 1) {
      fetchLeaves();
    }
    fetchLeavesStats();
  }, [leaveMatchesFilters, sortLeavesByFromDate, pagination, totalRows, updatePaginationMetadata, fetchLeaves, fetchLeavesStats]);

  // Optimistic UI for bulk add - optimized to handle only created leaves
  const addBulkLeavesOptimized = useCallback((responseData) => {
    // Handle the response data from backend (now returns only created leaves)
    if (!responseData || !responseData.success) {
      console.error('Invalid bulk response data:', responseData);
      return;
    }
    
    // Update leaves data with the fresh data from backend
    if (responseData.leavesData) {
      setLeavesData(responseData.leavesData);
    }
    
    // Get the created leaves from the response
    const createdLeaves = responseData.created_leaves || [];
    if (createdLeaves.length === 0) {
      // Just refresh stats if no leaves were created
      fetchLeavesStats();
      return;
    }
    
    // Filter created leaves that match current filters
    const filteredNewLeaves = createdLeaves.filter(leave => leaveMatchesFilters(leave));
    
    if (filteredNewLeaves.length === 0) {
      // Even if no leaves match filters, update stats
      fetchLeavesStats();
      return;
    }

    setTableLoading(true);
    
    // Add the new leaves to the current page if they match filters
    setLeaves(prevLeaves => {
      const updatedLeaves = [...prevLeaves, ...filteredNewLeaves];
      return sortLeavesByFromDate(updatedLeaves).slice(0, pagination.perPage);
    });
    
    // Update pagination metadata based on successful creations
    const successfulCount = responseData.summary?.successful || createdLeaves.length;
    updatePaginationMetadata({
      total: totalRows + successfulCount,
      last_page: Math.ceil((totalRows + successfulCount) / pagination.perPage),
      current_page: pagination.page,
      per_page: pagination.perPage
    });

    setTableLoading(false);
    
    // If not on first page, might need to refresh to see all new data
    if (pagination.page !== 1) {
      fetchLeaves();
    }
    
    // Refresh leave stats to update balance cards
    fetchLeavesStats();
  }, [leaveMatchesFilters, sortLeavesByFromDate, pagination, totalRows, updatePaginationMetadata, fetchLeaves, fetchLeavesStats]);

  const updateLeaveOptimized = useCallback((updatedLeave) => {
    const leaveExistsInCurrentPage = leaves.some(leave => leave.id === updatedLeave.id);
    setTableLoading(true);
    if (!leaveMatchesFilters(updatedLeave) && leaveExistsInCurrentPage) {
      setLeaves(prevLeaves => {
        return prevLeaves.filter(leave => leave.id !== updatedLeave.id);
      });
      
      // Use toast promise pattern for safety
      const promise = Promise.resolve();
      showToast.promise(
        promise,
        {
          success: {
            render() {
              return <div>Leave removed from filtered view.</div>;
            },
            icon: 'ℹ️'
          }
        }
      );
      
      setTableLoading(false);
      return;
    }
    if (!leaveExistsInCurrentPage && !leaveMatchesFilters(updatedLeave)) {
      setTableLoading(false);
      return;
    }
    setLeaves(prevLeaves => {
      const exists = prevLeaves.some(leave => leave.id === updatedLeave.id);
      let updatedLeaves;
      if (exists) {
        updatedLeaves = prevLeaves.map(leave =>
          leave.id === updatedLeave.id ? updatedLeave : leave
        );
      } else {
        updatedLeaves = [...prevLeaves, updatedLeave];
      }
      return sortLeavesByFromDate(updatedLeaves).slice(0, pagination.perPage);
    });
    
    // Use toast promise pattern for safety
    const promise = Promise.resolve();
    showToast.promise(
      promise,
      {
        success: {
          render() {
            return <div>Leave updated!</div>;
          },
          icon: '✅'
        }
      }
    );
    
    setTableLoading(false);
    if (pagination.page !== 1) {
      fetchLeaves();
    }
    fetchLeavesStats();
  }, [leaveMatchesFilters, sortLeavesByFromDate, leaves, pagination, fetchLeaves, fetchLeavesStats]);

  const deleteLeaveOptimized = useCallback((leaveId) => {
    setLeaves(prevLeaves => {
      const updatedLeaves = prevLeaves.filter(leave => leave.id !== leaveId);
      // After removing a leave, check if we need to fetch more data
      const newTotal = Math.max(0, totalRows - 1);
      fetchAdditionalItemsIfNeeded(updatedLeaves, newTotal, 'delete');
      return updatedLeaves;
    });
    // Update pagination metadata
    const newTotal = Math.max(0, totalRows - 1);
    updatePaginationMetadata({
      total: newTotal,
      last_page: Math.max(1, Math.ceil(newTotal / pagination.perPage)),
      current_page: pagination.page,
      per_page: pagination.perPage
    });
    // Only fetch leave stats to update the balance cards
    fetchLeavesStats();
  }, [fetchLeavesStats, totalRows, pagination, updatePaginationMetadata, fetchAdditionalItemsIfNeeded]);

  // Optimistic UI for bulk deletion
  const deleteBulkLeavesOptimized = useCallback((responseData) => {
    // Handle the response data from backend
    if (!responseData || !responseData.success) {
      console.error('Invalid bulk delete response data:', responseData);
      return;
    }
    
    // Update leaves data with the fresh data from backend
    if (responseData.leavesData) {
      setLeavesData(responseData.leavesData);
    }
    
    // Get the deleted leave IDs from the response
    const deletedLeaves = responseData.deleted_leaves || [];
    const deletedLeaveIds = deletedLeaves.map(leave => leave.id);
    
    if (deletedLeaveIds.length === 0) {
      // Just refresh stats if no leaves were deleted
      fetchLeavesStats();
      return;
    }

    setTableLoading(true);
    
    // Remove the deleted leaves from the current page
    setLeaves(prevLeaves => {
      return prevLeaves.filter(leave => !deletedLeaveIds.includes(leave.id));
    });
    
    // Update pagination metadata based on successful deletions
    const deletedCount = responseData.deleted_count || deletedLeaves.length;
    const newTotal = Math.max(0, totalRows - deletedCount);
    updatePaginationMetadata({
      total: newTotal,
      last_page: Math.max(1, Math.ceil(newTotal / pagination.perPage)),
      current_page: pagination.page,
      per_page: pagination.perPage
    });

    setTableLoading(false);
    
    // Fetch additional items if needed to fill the page
    fetchAdditionalItemsIfNeeded(leaves.filter(leave => !deletedLeaveIds.includes(leave.id)), newTotal, 'delete');
    
    // Refresh leave stats to update balance cards
    fetchLeavesStats();
  }, [updatePaginationMetadata, pagination, totalRows, leaves, fetchAdditionalItemsIfNeeded, fetchLeavesStats]);

  // Action buttons for the header
  const actionButtons = [
    {
      label: "Add Leave",
      icon: <PlusIcon className="w-4 h-4" />,
      onPress: () => openModal('add_leave'),
      className: "bg-linear-to-r from-(--theme-primary) to-(--theme-secondary) text-white font-medium hover:opacity-90"
    },
    {
      label: "Add Bulk",
      icon: <CalendarIcon className="w-4 h-4" />,
      onPress: () => openModal('bulk_leave'),
      className: "bg-linear-to-r from-(--theme-primary) to-(--theme-secondary) text-white font-medium hover:opacity-90"
    },
    {
      label: "Current Year",
      icon: <CalendarIcon className="w-4 h-4" />,
      onPress: () => handleFilterChange('year', new Date().getFullYear()),
      className: "bg-linear-to-r from-(--theme-primary) to-(--theme-secondary) text-white font-medium hover:opacity-90"
    },
    {
      label: "Refresh",
      icon: <ArrowPathIcon className="w-4 h-4" />,
      onPress: fetchLeaves,
      className: "bg-linear-to-r from-[rgba(var(--theme-success-rgb),0.8)] to-[rgba(var(--theme-success-rgb),1)] text-white font-medium hover:opacity-90"
    }
  ];

  // Render leave type balance cards
  const renderLeaveTypeCards = () => {
    if (!leavesData.leaveTypes.length) {
      return (
        <Flex direction="column" align="center" py="6" gap="2">
          <BarChartIcon style={{ width: 40, height: 40, color: 'var(--gray-8)' }} />
          <Text color="gray">No leave types available</Text>
        </Flex>
      );
    }
    return (
      <Grid columns={{ initial: '1', sm: '2', lg: '4' }} gap="3">
        {leavesData.leaveTypes.map(({ type }) => {
          const leaveCount = userLeaveCounts.find(count => count.leave_type === type) || {};
          const usedDays = leaveCount.days_used || 0;
          const remainingDays = leaveCount.remaining_days || 0;
          const totalDays = usedDays + remainingDays;
          return (
            <Card key={type} size="2">
              <Flex align="center" gap="2" mb="3">
                <CalendarIcon style={{ color: 'var(--accent-9)' }} />
                <Text weight="medium" style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{type}</Text>
              </Flex>
              <Flex justify="between" align="center" mb="2">
                <Box style={{ textAlign: 'center' }}>
                  <Text size="1" color="gray">Used</Text>
                  <Text size="5" weight="bold" color="red" style={{ display: 'block' }}>{usedDays}</Text>
                </Box>
                <Separator orientation="vertical" size="2" />
                <Box style={{ textAlign: 'center' }}>
                  <Text size="1" color="gray">Remaining</Text>
                  <Text size="5" weight="bold" color="green" style={{ display: 'block' }}>{remainingDays}</Text>
                </Box>
              </Flex>
              {totalDays > 0 && (
                <Box style={{ height: 6, borderRadius: 'var(--radius-1)', background: 'var(--gray-a4)', overflow: 'hidden' }}>
                  <Box style={{ height: '100%', width: `${(usedDays / totalDays) * 100}%`, background: 'var(--red-9)', borderRadius: 'var(--radius-1)' }} />
                </Box>
              )}
            </Card>
          );
        })}
      </Grid>
    );
  };

  const leaveTableRef = useRef(null);

  return (
    <>
      <Head title={title} />

      {modalStates.add_leave && (
        <LeaveForm
          open={modalStates.add_leave}
          closeModal={() => closeModal()}
          leavesData={leavesData}
          setLeavesData={setLeavesData}
          currentLeave={null}
          allUsers={allUsers}
          setTotalRows={setTotalRows}
          setLastPage={setLastPage}
          setLeaves={setLeaves}
          employee={''}
          selectedMonth={filters.selectedMonth}
          addLeaveOptimized={addLeaveOptimized}
          updatePaginationMetadata={updatePaginationMetadata}
          fetchLeavesStats={fetchLeavesStats}
        />
      )}
      {modalStates.edit_leave && (
        <LeaveForm
          open={modalStates.edit_leave}
          closeModal={() => closeModal()}
          leavesData={leavesData}
          setLeavesData={setLeavesData}
          currentLeave={currentLeave}
          allUsers={allUsers}
          setTotalRows={setTotalRows}
          setLastPage={setLastPage}
          setLeaves={setLeaves}
          employee={''}
          selectedMonth={filters.selectedMonth}
          updateLeaveOptimized={updateLeaveOptimized}
          updatePaginationMetadata={updatePaginationMetadata}
          fetchLeavesStats={fetchLeavesStats}
        />
      )}
      {modalStates.delete_leave && (
        <DeleteLeaveForm
          open={modalStates.delete_leave}
          closeModal={() => closeModal()}
          leaveId={currentLeave?.id}
          setLeaves={setLeaves}
          setTotalRows={setTotalRows}
          setLastPage={setLastPage}
          deleteLeaveOptimized={deleteLeaveOptimized}
          updatePaginationMetadata={updatePaginationMetadata}
          fetchLeavesStats={fetchLeavesStats}
        />
      )}
      {modalStates.bulk_leave && (
        <BulkLeaveModal
          open={modalStates.bulk_leave}
          onClose={() => closeModal()}
          onSuccess={(responseData) => { addBulkLeavesOptimized(responseData); }}
          allUsers={allUsers}
          leavesData={leavesData}
          isAdmin={false}
          existingLeaves={leaves || []}
          publicHolidays={leavesData?.publicHolidays || []}
        />
      )}
      {modalStates.bulk_delete && (
        <BulkDeleteModal
          open={modalStates.bulk_delete}
          onClose={() => closeModal()}
          onSuccess={(responseData) => { deleteBulkLeavesOptimized(responseData); }}
          selectedLeaves={selectedLeavesForBulkDelete}
          allUsers={allUsers}
        />
      )}

      <Box p="4">
        <Card size="3">
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
                <Heading size="5">My Leaves</Heading>
                <Text size="2" color="gray">Your leave requests and balances</Text>
              </Box>
            </Flex>
            <Flex gap="2">
              <Button onClick={() => openModal('add_leave')} size="2" style={{ cursor: 'pointer' }}>
                <PlusIcon /> Add Leave
              </Button>
              <Button variant="soft" color="gray" onClick={() => openModal('bulk_leave')} size="2" style={{ cursor: 'pointer' }}>
                <CalendarIcon /> Bulk Add
              </Button>
            </Flex>
          </Flex>

          <Flex gap="3" mb="5" align="center" wrap="wrap">
            <Box>
              <Text as="label" size="1" weight="medium" style={{ display: 'block', marginBottom: 4 }}>Year</Text>
              <select
                value={String(filters.year)}
                onChange={e => handleFilterChange('year', Number(e.target.value))}
                style={{
                  padding: '6px 10px', background: 'var(--color-surface)',
                  border: '1px solid var(--gray-a7)', borderRadius: 'var(--radius-2)',
                  color: 'var(--gray-12)', fontSize: 14,
                }}
              >
                {yearOptions.map(y => (
                  <option key={y.key} value={y.key}>{y.label}</option>
                ))}
              </select>
            </Box>
            <Box style={{ alignSelf: 'flex-end' }}>
              <Button variant="soft" color="gray" onClick={fetchLeaves} disabled={tableLoading} size="2" style={{ cursor: 'pointer' }}>
                {tableLoading ? <Spinner size="1" /> : <ReloadIcon />} Refresh
              </Button>
            </Box>
          </Flex>

          <Box mb="5">
            <Flex align="center" gap="2" mb="3">
              <BarChartIcon />
              <Text size="3" weight="medium">Leave Balance Summary</Text>
            </Flex>
            {renderLeaveTypeCards()}
          </Box>

          <Separator size="4" mb="4" />

          <Box>
            <Flex align="center" gap="2" mb="3">
              <TableIcon style={{ width: 16, height: 16 }} />
              <Text size="3" weight="medium">Leave History</Text>
            </Flex>
            {tableLoading ? (
              <Flex direction="column" align="center" py="8" gap="3">
                <Spinner size="3" />
                <Text color="gray">Loading leave data...</Text>
              </Flex>
            ) : error ? (
              <Callout.Root color="red">
                <Callout.Icon><ExclamationTriangleIcon /></Callout.Icon>
                <Callout.Text>{error}</Callout.Text>
              </Callout.Root>
            ) : leaves.length > 0 ? (
              <LeaveEmployeeTable
                ref={leaveTableRef}
                leaves={leaves}
                allUsers={allUsers || []}
                handleClickOpen={handleClickOpen}
                setCurrentLeave={handleSetCurrentLeave}
                openModal={openModal}
                setLeaves={setLeaves}
                setCurrentPage={handlePageChange}
                currentPage={pagination.page}
                totalRows={totalRows}
                lastPage={lastPage}
                perPage={pagination.perPage}
                selectedMonth={filters.selectedMonth}
                employee={''}
                isAdminView={false}
                fetchLeavesStats={fetchLeavesStats}
                updatePaginationMetadata={updatePaginationMetadata}
                onBulkDelete={handleBulkDelete}
                canDeleteLeaves={true}
              />
            ) : (
              <Flex direction="column" align="center" py="8" gap="2">
                <CalendarIcon style={{ width: 40, height: 40, color: 'var(--gray-9)' }} />
                <Heading size="3">No Leave Records Found</Heading>
                <Text color="gray">You haven't submitted any leave requests for {filters.year}.</Text>
              </Flex>
            )}
          </Box>
        </Card>
      </Box>
    </>
  );
};

LeavesEmployee.layout = (page) => <App>{page}</App>;

export default LeavesEmployee;
