import { Panel } from '@/Components/ui/Panel';
import React, { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import { Head, usePage, router } from '@inertiajs/react';
import dayjs from 'dayjs';
import { useMediaQuery } from '@/Hooks/useMediaQuery.js';
import { Badge, Box, Button, Callout, Flex, Grid, Heading, Separator, Spinner, Text } from '@radix-ui/themes';
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
import ErrorBoundary from '@/Components/ErrorBoundary/ErrorBoundary';
import * as useLeavesQuery from '@/api/queries/useLeavesQuery';
import LeaveBalanceCards from '@/Components/Leaves/LeaveBalanceCards.jsx';

const LeavesEmployee = ({ title, allUsers }) => {
  const { auth } = usePage().props;

  const isMobile = useMediaQuery('(max-width: 640px)');
  const isTablet = useMediaQuery('(min-width: 641px) and (max-width: 1024px)');
    const [totalRows, setTotalRows] = useState(0);
      const [lastPage, setLastPage] = useState(0);
  // State management
  const [leavesData, setLeavesData] = useState({ 
    leaveTypes: [], 
    leaveCountsByUser: {} 
  });
    // Table-level loading spinner
  const [tableLoading, setTableLoading] = useState(false);

  const [error, setError] = useState('');
  const [filters, setFilters] = useState({ 
    employee: '', 
    selectedMonth: dayjs().format('YYYY-MM'),
    year: new Date().getFullYear() 
  });

  // React Query hooks
  const { data: leavesResponse, isLoading, refetch } = useLeavesQuery.useLeaves({
    page: 1,
    perPage: 30,
    year: filters.year,
    user_id: auth.user.id
  });

  const { data: statsData, refetch: refetchStats } = useLeavesQuery.useLeavesStats({
    year: filters.year
  });

  // Local state for leaves and pagination to support optimistic updates
  const [leaves, setLeaves] = useState(leavesResponse?.data || []);
  const [pagination, setPagination] = useState({
    page: leavesResponse?.current_page || 1,
    perPage: leavesResponse?.per_page || 30,
    total: leavesResponse?.total || 0,
    lastPage: leavesResponse?.last_page || 1,
  });

  // Keep local state in sync with server responses
  useEffect(() => {
    setLeaves(leavesResponse?.data || []);
    setPagination({
      page: leavesResponse?.current_page || 1,
      perPage: leavesResponse?.per_page || 30,
      total: leavesResponse?.total || 0,
      lastPage: leavesResponse?.last_page || 1,
    });
    if (leavesResponse?.leavesData) {
      setLeavesData(leavesResponse.leavesData);
    }
  }, [leavesResponse]);

  // Function to update pagination metadata
  const updatePaginationMetadata = useCallback((metadata) => {
    if (metadata) {
      setTotalRows(metadata.total || 0);
      setLastPage(metadata.last_page || 1);
    }
  }, []);

  // Auto-refetch when filters change
  useEffect(() => {
    refetch();
  }, [filters.year, refetch]);

  // Update pagination metadata when data changes
  useEffect(() => {
    updatePaginationMetadata({
      total: pagination.total,
      last_page: pagination.lastPage,
      current_page: pagination.page,
      per_page: pagination.perPage
    });
  }, [pagination, updatePaginationMetadata]);

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
    refetch();
  }, [pagination, lastPage, refetch]);

  
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
    refetch();
  }, [openModal, refetch]);

  // Update leave counts when stats data changes
  useEffect(() => {
    if (statsData?.leaveCounts) {
      setLeavesData(prevData => ({
        ...prevData,
        leaveCountsByUser: {
          ...prevData.leaveCountsByUser,
          [auth.user.id]: statsData.leaveCounts
        }
      }));
    }
  }, [statsData, auth.user.id]);

  // Handle pagination changes
  const handlePageChange = useCallback((newPage) => {
    // Only change page if it's different from the current page
    if (newPage !== pagination.page) {
      setPagination(prev => ({ ...prev, page: newPage }));
    }
  }, [pagination.page]);

  const handleRowsPerPageChange = useCallback((newPerPage) => {
    setPagination(prev => ({ ...prev, perPage: newPerPage, page: 1 }));
  }, []);

  // Separate effect for fetching leave stats to avoid unnecessary refetches
  useEffect(() => {
    refetchStats();
  }, [refetchStats]);
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
      refetch();
    }
    refetchStats();
  }, [leaveMatchesFilters, sortLeavesByFromDate, pagination, totalRows, updatePaginationMetadata, refetch, refetchStats]);

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
      refetchStats();
      return;
    }
    
    // Filter created leaves that match current filters
    const filteredNewLeaves = createdLeaves.filter(leave => leaveMatchesFilters(leave));
    
    if (filteredNewLeaves.length === 0) {
      // Even if no leaves match filters, update stats
      refetchStats();
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
      refetch();
    }
    
    // Refresh leave stats to update balance cards
    refetchStats();
  }, [leaveMatchesFilters, sortLeavesByFromDate, pagination, totalRows, updatePaginationMetadata, refetch, refetchStats]);

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
      refetch();
    }
    refetchStats();
  }, [leaveMatchesFilters, sortLeavesByFromDate, leaves, pagination, refetch, refetchStats]);

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
    refetchStats();
  }, [refetchStats, totalRows, pagination, updatePaginationMetadata, fetchAdditionalItemsIfNeeded]);

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
      refetchStats();
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
    refetchStats();
  }, [updatePaginationMetadata, pagination, totalRows, leaves, fetchAdditionalItemsIfNeeded, refetchStats]);

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
      icon: <ReloadIcon className="w-4 h-4" />,
      onPress: refetch,
      className: "bg-linear-to-r from-[rgba(var(--theme-success-rgb),0.8)] to-[rgba(var(--theme-success-rgb),1)] text-white font-medium hover:opacity-90"
    }
  ];


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
          refetchStats={refetchStats}
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
          refetchStats={refetchStats}
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
          refetchStats={refetchStats}
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

      <Box p={{ initial: '3', md: '5' }}>
        <Panel>
          <Panel.Header
            actions={
              <Flex gap="2">
                <Button
                  onClick={() => openModal('add_leave')}
                  size="2"
                  style={{
                      cursor: 'pointer',
                      background: 'linear-gradient(135deg, var(--accent-9) 0%, var(--accent-10) 100%)',
                      boxShadow: '0 2px 8px var(--accent-a3)'
                  }}
                >
                  <PlusIcon /> Add Leave
                </Button>
                <Button variant="soft" color="gray" onClick={() => openModal('bulk_leave')} size="2" style={{ cursor: 'pointer' }}>
                  <CalendarIcon /> Bulk Add
                </Button>
              </Flex>
            }
          >
            <Flex align="center" gap="3">
              <Box style={{
                padding: 12,
                background: 'linear-gradient(135deg, var(--accent-a3) 0%, var(--accent-a2) 100%)',
                borderRadius: 'var(--radius-3)',
                border: '1px solid var(--accent-a5)',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                boxShadow: '0 4px 12px var(--accent-a2)'
              }}>
                <CalendarIcon style={{ width: 24, height: 24, color: 'var(--accent-9)' }} />
              </Box>
              <Box>
                <Heading size="5" style={{ letterSpacing: '-0.02em', color: 'var(--gray-12)' }}>My Leaves</Heading>
                <Text size="2" color="gray" style={{ display: 'block', mt: 0.5 }}>Your leave requests and balances</Text>
              </Box>
            </Flex>
          </Panel.Header>

          <Panel tinted mb="5">
            <Flex gap="3" align="end" wrap="wrap">
            <Box style={{ minWidth: 120 }}>
              <Text as="label" size="1" weight="bold" color="gray" style={{ display: 'block', marginBottom: 6, textTransform: 'uppercase', letterSpacing: '0.03em' }}>Select Year</Text>
              <select
                value={String(filters.year)}
                onChange={e => handleFilterChange('year', Number(e.target.value))}
                style={{
                  padding: '8px 12px', background: 'var(--color-surface)',
                  border: '1px solid var(--gray-a7)', borderRadius: 'var(--radius-2)',
                  color: 'var(--gray-12)', fontSize: 13, fontWeight: 500, width: '100%',
                  outline: 'none', cursor: 'pointer'
                }}
              >
                {yearOptions.map(y => (
                  <option key={y.key} value={y.key}>{y.label}</option>
                ))}
              </select>
            </Box>
            <Box style={{ marginLeft: 'auto' }}>
              <Button variant="soft" color="gray" onClick={refetch} disabled={isLoading} size="2" style={{ cursor: 'pointer' }}>
                {isLoading ? <Spinner size="1" /> : <ReloadIcon />} Refresh
              </Button>
            </Box>
            </Flex>
          </Panel>

          <Box mb="5">
            <Flex align="center" gap="2" mb="3">
              <BarChartIcon />
              <Text size="3" weight="medium">Leave Balance Summary</Text>
            </Flex>
            <LeaveBalanceCards 
              leaveTypes={leavesData.leaveTypes} 
              userLeaveCounts={userLeaveCounts} 
            />
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
              <ErrorBoundary>
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
                  onRowsPerPageChange={handleRowsPerPageChange}
                  selectedMonth={filters.selectedMonth}
                  employee={''}
                  isAdminView={false}
                  refetchStats={refetchStats}
                  updatePaginationMetadata={updatePaginationMetadata}
                  onBulkDelete={handleBulkDelete}
                  canDeleteLeaves={true}
                />
              </ErrorBoundary>
            ) : (
              <Flex direction="column" align="center" py="8" gap="2">
                <CalendarIcon style={{ width: 40, height: 40, color: 'var(--gray-9)' }} />
                <Heading size="3">No Leave Records Found</Heading>
                <Text color="gray">You haven't submitted any leave requests for {filters.year}.</Text>
              </Flex>
            )}
          </Box>
        </Panel>
      </Box>
    </>
  );
};

LeavesEmployee.layout = (page) => <App>{page}</App>;

export default LeavesEmployee;
