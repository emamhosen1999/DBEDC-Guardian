import React, { useCallback, useEffect, useRef, useState, useMemo } from 'react';
import {
    Box, Card, Flex, Grid, Text, Heading, Badge, Separator,
    Skeleton, Avatar, Button, TextField, ScrollArea, Table,
} from '@radix-ui/themes';
import {
    MagnifyingGlassIcon,
    CalendarIcon,
    ClockIcon,
    PersonIcon,
    ExclamationTriangleIcon,
    CheckCircledIcon,
    CrossCircledIcon,
    DownloadIcon,
    ChevronLeftIcon,
    ChevronRightIcon,
    MobileIcon,
} from '@radix-ui/react-icons';
import { usePage } from "@inertiajs/react";
import dayjs from "dayjs";
import axios from 'axios';

import { AbsentUsersInlineCard } from '@/Components/TimeSheet/AbsentUsersInlineCard';

const TimeSheetTable = ({ handleDateChange, selectedDate, updateTimeSheet, externalFilterData, externalEmployee, onMarkAsPresent }) => {
    const { auth } = usePage().props;
    const { url } = usePage();
   


    const [attendances, setAttendances] = useState([]);
    const [leaves, setLeaves] = useState([]);
    const [absentUsers, setAbsentUsers] = useState([]);
    const [error, setError] = useState('');
    const [totalRows, setTotalRows] = useState(0);
    const [lastPage, setLastPage] = useState(0);
    const [perPage, setPerPage] = useState(10);
    const [currentPage, setCurrentPage] = useState(1);
    const [employee, setEmployee] = useState(externalEmployee || '');
    const [isLoaded, setIsLoaded] = useState(false);
    const [refreshKey, setRefreshKey] = useState(0);
    const [lastUpdate, setLastUpdate] = useState(null);
    const [isPolling, setIsPolling] = useState(true);
    const [lastChecked, setLastChecked] = useState(new Date());
    const [downloading, setDownloading] = useState('');
    const prevUpdateRef = useRef(null);
    const prevFilterData = useRef({
        currentMonth: dayjs().format('YYYY-MM'),
        employee: externalEmployee || '',
        filterData: externalFilterData || { currentMonth: dayjs().format('YYYY-MM') },
        selectedDate: selectedDate,
        perPage: 10,
        updateTimeSheet: updateTimeSheet,
        refreshKey: 0,
        currentPage: 1
    });
    
    
    const [filterData, setFilterData] = useState(externalFilterData || {
        currentMonth: dayjs().format('YYYY-MM'),
    });

    // Handle manual refresh and data fetching for both present and absent users
    const handleRefresh = useCallback(async () => {
        try {
            setIsLoaded(false);
            await getPresentUsersForDate(selectedDate, currentPage, perPage, employee, filterData, true);
            // Also refresh absent users
            if (url !== '/attendance-employee') {
                await getAbsentUsersForDate(selectedDate, employee);
            }
            setLastChecked(new Date());
            return true;
        } catch (error) {
            console.error('Error refreshing timesheet:', error);
            return false;
        } finally {
            setIsLoaded(true);
        }
    }, [selectedDate, currentPage, perPage, employee, filterData, url]);


    // Function to check for timesheet updates
    const checkForTimesheetUpdates = useCallback(async () => {
        // Don't check if no date is selected
        if (!selectedDate) return;

        try {
            const endpoint = route('check-timesheet-updates', { 
                date: dayjs(selectedDate).format('YYYY-MM-DD'),
                month: filterData.currentMonth
            });
            
            const response = await fetch(endpoint);
            
            if (!response.ok) {
                const errorData = await response.json().catch(() => ({}));
                throw new Error(`HTTP ${response.status}: ${errorData.message || 'Failed to check for updates'}`);
            }

            const data = await response.json();
            
            // Only update if we have a new update timestamp
            if (data.success && data.last_updated !== prevUpdateRef.current) {
                if (data.last_updated) {
                    prevUpdateRef.current = data.last_updated;
                    await handleRefresh(); // This now updates both present and absent users
                    setLastUpdate(new Date());
                }
            }
            
            setLastChecked(new Date());
        } catch (error) {
            console.error('Error checking for timesheet updates:', error);
            // Don't set an error state here to avoid disrupting the UI on background checks
        }
    }, [selectedDate, filterData.currentMonth, handleRefresh]);



    // Format the last checked time for display
    const lastCheckedText = useMemo(() => {
        if (!lastChecked) return null;
        return lastChecked.toLocaleTimeString('en-US', {
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            hour12: true
        });
    }, [lastChecked]);

    
   

    // Fetch attendance data for present users
    const getPresentUsersForDate = async (selectedDate, page, perPage, employee, filterData, forceRefresh = false) => {
        if (!selectedDate) {
            setIsLoaded(true);
            setError('No date selected');
            return;
        }
        
        const attendanceRoute = (url !== '/attendance-employee')
            ? route('admin.getPresentUsersForDate')
            : route('getCurrentUserAttendanceForDate');
        
        try {
            setIsLoaded(false);
            setError('');
            
            const response = await axios.get(attendanceRoute, {
                params: {
                    page,
                    perPage,
                    employee,
                    date: dayjs(selectedDate).format('YYYY-MM-DD'), // Ensure consistent date format
                    currentYear: filterData?.currentMonth ? dayjs(filterData.currentMonth).year() : '',
                    currentMonth: filterData?.currentMonth ? dayjs(filterData.currentMonth).format('MM') : '',
                    _t: forceRefresh ? Date.now() : undefined
                }
            });
        
         
            if (response.status === 200) {
                // Add null check for response data properties
                setAttendances(response.data.attendances || []);
                setTotalRows(response.data.total || 0);
                setLastPage(response.data.last_page || 1);
                setCurrentPage(response.data.current_page || 1);
                setLastUpdate(new Date());
                setError('');
            } else {
                setError(`Unexpected response: ${response.status}`);
            }
        } catch (error) {
            console.error('Error fetching attendance data:', error);
            setError(error.response?.data?.message || 'An error occurred while retrieving attendance data.');
            setAttendances([]);
            setTotalRows(0);
        } finally {
            setIsLoaded(true);
        }
    };

    // Fetch absent users data
    const getAbsentUsersForDate = async (selectedDate, employee) => {
        if (url === '/attendance-employee') {
            // Employee view doesn't need absent users
            setAbsentUsers([]);
            setLeaves([]);
            return;
        }

        if (!selectedDate) {
            setAbsentUsers([]);
            setLeaves([]);
            return;
        }

        try {
            const response = await axios.get(route('admin.getAbsentUsersForDate'), {
                params: {
                    date: dayjs(selectedDate).format('YYYY-MM-DD'), // Ensure consistent date format
                    employee: employee || '',
                    _t: Date.now() // Add cache busting parameter
                }
            });

            if (response.status === 200) {
                setAbsentUsers(response.data.absent_users || []);
                setLeaves(response.data.leaves || []);
            } else {
                console.warn('Unexpected response getting absent users:', response.status);
                setAbsentUsers([]);
                setLeaves([]);
            }
        } catch (error) {
            console.error('Error fetching absent users:', error);
            setAbsentUsers([]);
            setLeaves([]);
        }
    };    // Add refresh functionality
    
    const handleSearch = (event) => {
        setEmployee(event.target.value.toLowerCase());
    };

    const handlePageChange = (page) => {
        setCurrentPage(page);
    };

    const handleFilterChange = useCallback((key, value) => {
        setFilterData(prevState => ({            ...prevState,
            [key]: value,
        }));
    }, []);

    const getUserLeave = (userId) => {
        return leaves.find((leave) => String(leave.user_id) === String(userId));
    };// Helper function to safely format time
    const formatTime = (timeString, date) => {
        if (!timeString) return null;
        
        try {
            let dateObj;
            
            // Handle different time formats
            if (typeof timeString === 'string') {
                // Standardize date format to prevent timezone issues
                const formattedDate = dayjs(date).format('YYYY-MM-DD');
                
                // If it's just a time string (HH:MM:SS), combine with date
                if (timeString.match(/^\d{2}:\d{2}:\d{2}$/)) {
                    const dateTimeString = `${formattedDate}T${timeString}`;
                    dateObj = new Date(dateTimeString);
                } 
                // If it's already a full datetime string
                else if (timeString.includes('T') || timeString.includes(' ')) {
                    dateObj = new Date(timeString);
                }
                // If it's just HH:MM format
                else if (timeString.match(/^\d{2}:\d{2}$/)) {
                    const dateTimeString = `${formattedDate}T${timeString}:00`;
                    dateObj = new Date(dateTimeString);
                }
                // Fallback - try to parse as is
                else {
                    dateObj = new Date(`${formattedDate}T${timeString}`);
                }
            } else {
                // If it's already a Date object or timestamp
                dateObj = new Date(timeString);
            }
            
            // Check if the date is valid
            if (isNaN(dateObj.getTime())) {
                console.warn('Invalid time data:', timeString);
                return 'Invalid time';
            }
            
            return dateObj.toLocaleTimeString('en-US', {
                hour: 'numeric',
                minute: '2-digit',
                hour12: true,
            });
        } catch (error) {
            console.warn('Error formatting time:', { timeString, date, error });
            return 'Invalid time';
        }
    };

    const getLeaveStatusColor = (status) => {
        switch (status?.toLowerCase()) {
            case 'approved': return 'green';
            case 'rejected': return 'red';
            default: return 'amber';
        }
    };    // Check permissions using new system

    const canViewAllAttendance = auth.permissions?.includes('attendance.view') || false;
    const canViewOwnAttendance = auth.permissions?.includes('attendance.own.view') || false;
    const canManageAttendance = auth.permissions?.includes('attendance.manage') || false;
    const canExportAttendance = auth.permissions?.includes('attendance.export') || canManageAttendance || false;    // Filter absent users for export functions only - backend now handles search filtering
    const filteredAbsentUsers = useMemo(() => {
        // No need to filter here since backend handles the search filtering
        return absentUsers;
    }, [absentUsers]);
    
    // Column definitions with improved descriptive labels
    const columns = [
        // Date column - only shown in employee view (when user can't view all attendance or on employee-specific page)
        ...(!canViewAllAttendance || url === '/attendance-employee' ? [
            { name: "Date", uid: "date", icon: CalendarIcon, ariaLabel: "Attendance date" }
        ] : []),
        
        // Employee column - only shown in admin/manager view (when user can view all attendance and not on employee page)
        ...(canViewAllAttendance && (url !== '/attendance-employee') ? [
            { name: "Employee", uid: "employee", icon: PersonIcon, ariaLabel: "Employee name and information" }
        ] : []),
        { name: "Clock In", uid: "clockin_time", icon: ClockIcon, ariaLabel: "All clock in times" },
        { name: "Clock Out", uid: "clockout_time", icon: ClockIcon, ariaLabel: "All clock out times" },
        { name: "Work Hours", uid: "production_time", icon: ClockIcon, ariaLabel: "Total working hours" },
        { name: "Punches", uid: "punch_details", icon: ClockIcon, ariaLabel: "Number of time punches recorded" }
    ];

    const renderCell = (attendance, columnKey) => {
        const isCurrentDate = dayjs(attendance.date).isSame(dayjs(), 'day');
        switch (columnKey) {
            case "date":
                return (
                    <Table.Cell>
                        <Flex align="center" gap="2">
                            <CalendarIcon style={{ color: 'var(--accent-9)', flexShrink: 0 }} />
                            <Text size="2">{dayjs(attendance.date).format('MMM D, YYYY')}</Text>
                        </Flex>
                    </Table.Cell>
                );
            case "employee": {
                return (
                    <Table.Cell style={{ whiteSpace: 'nowrap' }}>
                        <Flex align="center" gap="2">
                            <Avatar
                                src={attendance.user?.profile_image_url || attendance.user?.profile_image}
                                fallback={(attendance.user?.name || '?').charAt(0).toUpperCase()}
                                size="2"
                                radius="full"
                                style={{ flexShrink: 0 }}
                            />
                            <Flex direction="column">
                                <Text size="2" weight="medium">{attendance.user?.name || 'Unnamed User'}</Text>
                                {attendance.user?.phone
                                    ? <Text size="1" color="gray" as="a" href={`tel:${attendance.user.phone}`}>{attendance.user.phone}</Text>
                                    : <Flex align="center" gap="1"><MobileIcon style={{ color: 'var(--gray-9)', width: 10 }} /><Text size="1" color="gray">No phone</Text></Flex>
                                }
                            </Flex>
                        </Flex>
                    </Table.Cell>
                );
            }
            case "clockin_time":
                return (
                    <Table.Cell>
                        <Flex align="center" gap="2">
                            <ClockIcon style={{ color: 'var(--green-9)', flexShrink: 0 }} />
                            <Flex direction="column">
                                {attendance.punches && attendance.punches.length > 0 ? (
                                    attendance.punches.filter(p => p.punch_in).map((punch, idx) => (
                                        <Text key={idx} size="1">
                                            <Text size="1" color="gray">{idx + 1}. </Text>
                                            {formatTime(punch.punch_in, attendance.date) || 'Invalid time'}
                                        </Text>
                                    ))
                                ) : <Text size="2" color="gray">Not clocked in</Text>}
                            </Flex>
                        </Flex>
                    </Table.Cell>
                );
            case "clockout_time":
                return (
                    <Table.Cell>
                        <Flex align="center" gap="2">
                            <ClockIcon style={{ color: 'var(--red-9)', flexShrink: 0 }} />
                            <Flex direction="column">
                                {attendance.punches && attendance.punches.length > 0 ? (
                                    attendance.punches.map((punch, idx) => (
                                        <Text key={idx} size="1">
                                            <Text size="1" color="gray">{idx + 1}. </Text>
                                            {punch.punch_out ? formatTime(punch.punch_out, attendance.date) || 'Invalid time' : 'No punch out'}
                                        </Text>
                                    ))
                                ) : attendance.punchin_time ? (
                                    <Text size="2" color="gray">{isCurrentDate ? 'Currently working' : 'Missing punch-out'}</Text>
                                ) : (
                                    <Text size="2" color="gray">Not started</Text>
                                )}
                            </Flex>
                        </Flex>
                    </Table.Cell>
                );
            case "production_time": {
                const hasWorkTime = attendance.total_work_minutes > 0;
                const hasIncompletePunch = attendance.has_incomplete_punch;
                const isCurrentlyWorking = attendance.punchin_time && !attendance.punchout_time && isCurrentDate;
                if (hasWorkTime) {
                    const hours = Math.floor(attendance.total_work_minutes / 60);
                    const mins = Math.floor(attendance.total_work_minutes % 60);
                    return (
                        <Table.Cell>
                            <Flex align="center" gap="2">
                                <ClockIcon style={{ color: hasIncompletePunch ? 'var(--amber-9)' : 'var(--accent-9)', flexShrink: 0 }} />
                                <Flex direction="column">
                                    <Text size="2" weight="medium">{`${hours}h ${mins}m`}</Text>
                                    <Text size="1" color="gray">{hasIncompletePunch ? 'Partial — in progress' : 'Total worked time'}</Text>
                                </Flex>
                            </Flex>
                        </Table.Cell>
                    );
                } else if (isCurrentlyWorking) {
                    return (
                        <Table.Cell>
                            <Flex align="center" gap="2">
                                <ClockIcon style={{ color: 'var(--amber-9)', flexShrink: 0 }} />
                                <Flex direction="column">
                                    <Text size="2" color="amber">In Progress</Text>
                                    <Text size="1" color="gray">Currently working</Text>
                                </Flex>
                            </Flex>
                        </Table.Cell>
                    );
                } else if (attendance.punchin_time && !attendance.punchout_time && !isCurrentDate) {
                    return (
                        <Table.Cell>
                            <Flex align="center" gap="2">
                                <ExclamationTriangleIcon style={{ color: 'var(--red-9)', flexShrink: 0 }} />
                                <Flex direction="column">
                                    <Text size="2" color="red">Incomplete punch</Text>
                                    <Text size="1" color="gray">Missing punch out</Text>
                                </Flex>
                            </Flex>
                        </Table.Cell>
                    );
                }
                return (
                    <Table.Cell>
                        <Flex align="center" gap="2">
                            <ExclamationTriangleIcon style={{ color: 'var(--amber-9)', flexShrink: 0 }} />
                            <Flex direction="column">
                                <Text size="2" color="amber">No work time</Text>
                                <Text size="1" color="gray">No attendance</Text>
                            </Flex>
                        </Flex>
                    </Table.Cell>
                );
            }
            case "punch_details":
                return (
                    <Table.Cell>
                        <Flex align="center" gap="2">
                            <ClockIcon style={{ color: 'var(--gray-9)', flexShrink: 0 }} />
                            <Flex direction="column">
                                <Text size="2" weight="medium">
                                    {attendance.punch_count || 0} punch{(attendance.punch_count || 0) !== 1 ? 'es' : ''}
                                </Text>
                                {attendance.complete_punches !== attendance.punch_count && (
                                    <Text size="1" color="amber">{attendance.complete_punches} complete</Text>
                                )}
                                {attendance.complete_punches === attendance.punch_count && attendance.punch_count > 0 && (
                                    <Text size="1" color="green">All complete</Text>
                                )}
                            </Flex>
                        </Flex>
                    </Table.Cell>
                );
            default:
                return <Table.Cell><Text size="2" color="gray">N/A</Text></Table.Cell>;
        }
    };    // Excel download function
    const exportExcel = useCallback(async () => { 
        setDownloading('excel');
        try { 
            const response = await axios.get(route('attendance.exportExcel'), { params: { date: selectedDate }, responseType: 'blob', });

            // Create blob link to download
            const url = window.URL.createObjectURL(new Blob([response.data]));
            const link = document.createElement('a');
            link.href = url;
            link.setAttribute(
                'download',
                `Daily_Timesheet_${dayjs(selectedDate).format('YYYY_MM_DD')}.xlsx`
            );
            document.body.appendChild(link);
            link.click();
            link.remove();
            window.URL.revokeObjectURL(url);
            setDownloading('');
        } catch (error) {
            console.error('Error downloading Excel:', error);
            alert('Failed to download attendance excel.');
            setDownloading('');
        }

    }, [selectedDate]);

    // PDF download function
    const downloadPDF = useCallback(async () => {
        setDownloading('pdf');
        try { 
            const response = await axios.get(route('attendance.exportPdf'), { params:{date:selectedDate}, responseType:'blob' });
            const url = window.URL.createObjectURL(new Blob([response.data]));
            const link = document.createElement('a'); link.href=url;
            link.setAttribute(
                'download',
                `Daily_Timesheet_${dayjs(selectedDate).format('YYYY_MM_DD')}.pdf`
            );
            document.body.appendChild(link); 
            link.click(); 
            link.remove();
            window.URL.revokeObjectURL(url);
            setDownloading('');
        } catch (error) {
            console.error('Error downloading PDF:', error);
            alert('Failed to download attendance pdf.');
            setDownloading('');
        }
        
    },[selectedDate]);    
    
    // Fetch attendance data when filters change
    useEffect(() => {
        if (selectedDate) {
            // Only reset the page to 1 when filters OTHER THAN the page number change
            const shouldResetPage = (
                employee !== prevFilterData.current.employee || 
                filterData !== prevFilterData.current.filterData ||
                selectedDate !== prevFilterData.current.selectedDate ||
                perPage !== prevFilterData.current.perPage ||
                updateTimeSheet !== prevFilterData.current.updateTimeSheet ||
                refreshKey !== prevFilterData.current.refreshKey
            );
            
            if (shouldResetPage && currentPage !== 1) {
                // Store current values to compare against in next render
                prevFilterData.current = {
                    ...prevFilterData.current,
                    employee,
                    filterData,
                    selectedDate,
                    perPage,
                    updateTimeSheet,
                    refreshKey
                };
                setCurrentPage(1); // Reset to first page when filters change
                return; // The page change will trigger this effect again
            }
            
            // Create a synchronous update function to ensure both datasets are updated in the same render cycle
            const updateAllData = async () => {
                setIsLoaded(false);
                try {
                    // Update both datasets in parallel for efficiency
                    await Promise.all([
                        getPresentUsersForDate(selectedDate, currentPage, perPage, employee, filterData),
                        url !== '/attendance-employee' ? getAbsentUsersForDate(selectedDate, employee) : Promise.resolve()
                    ]);
                } catch (error) {
                    console.error('Error updating attendance data:', error);
                } finally {
                    setIsLoaded(true);
                }
            };
            
            updateAllData();
            
            // Store current values to compare against in next render
            prevFilterData.current = {
                ...prevFilterData.current,
                employee,
                filterData,
                selectedDate,
                perPage,
                updateTimeSheet,
                refreshKey,
                currentPage
            };
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [selectedDate, currentPage, perPage, employee, filterData, updateTimeSheet, refreshKey]);

    // Sync external filter data
    useEffect(() => {
        if (externalFilterData) {
            setFilterData(externalFilterData);
        }
    }, [externalFilterData]);

    // Sync external employee search
    useEffect(() => {
        if (externalEmployee !== undefined) {
            setEmployee(externalEmployee);
        }
    }, [externalEmployee]);

     // Track if filter data has changed
    useEffect(() => {
        // Only compare the filter data portion of the ref
        const filterChanged = JSON.stringify(filterData) !== JSON.stringify(prevFilterData.current.filterData);
        if (filterChanged) {
            prevFilterData.current = { 
                ...prevFilterData.current,
                filterData: { ...filterData }
            };
            handleRefresh();
        }
    }, [filterData, handleRefresh]);

        // Set up polling for updates
    useEffect(() => {
        if (!isPolling || !selectedDate) return;

        // Initial check
        checkForTimesheetUpdates();
        
        // Set up interval for polling (every 5 seconds)
        const intervalId = setInterval(checkForTimesheetUpdates, 5000);

        // Clean up on unmount or when dependencies change
        return () => {
            clearInterval(intervalId);
        };
    }, [isPolling, checkForTimesheetUpdates, selectedDate]);



    return (
    <>
        {canViewAllAttendance && (
            <Box p="4" role="main" aria-label="Timesheet Management">
                {/* Header */}
                <Box pb="3" mb="3" style={{ borderBottom: '1px solid var(--gray-a4)' }}>
                    <Flex justify="between" align="start" gap="3" wrap="wrap">
                        <Flex align="center" gap="3">
                            <Box style={{
                                padding: 10, borderRadius: 'var(--radius-3)',
                                background: 'var(--accent-a3)', border: '1px solid var(--accent-a6)',
                                display: 'flex', alignItems: 'center', justifyContent: 'center',
                                width: 44, height: 44, flexShrink: 0,
                            }}>
                                <ClockIcon style={{ color: 'var(--accent-9)', width: 22, height: 22 }} />
                            </Box>
                            <Box>
                                <Heading size="4">Daily Timesheet</Heading>
                                <Text size="2" color="gray">
                                    {new Date(selectedDate).toLocaleString('en-US', { month: 'long', day: 'numeric', year: 'numeric' })}
                                </Text>
                            </Box>
                        </Flex>
                        <Flex align="center" gap="2" wrap="wrap">
                            {lastCheckedText && (
                                <Text size="1" color="gray">Updated: {lastCheckedText}</Text>
                            )}
                            {canExportAttendance && (
                                <>
                                    <Button
                                        size="2" variant="soft" color="green"
                                        disabled={!isLoaded || attendances.length === 0 || downloading !== ''}
                                        onClick={exportExcel}
                                    >
                                        <DownloadIcon /> {downloading === 'excel' ? 'Exporting…' : 'Excel'}
                                    </Button>
                                    <Button
                                        size="2" variant="soft" color="red"
                                        disabled={!isLoaded || attendances.length === 0 || downloading !== ''}
                                        onClick={downloadPDF}
                                    >
                                        <DownloadIcon /> {downloading === 'pdf' ? 'Exporting…' : 'PDF'}
                                    </Button>
                                </>
                            )}
                        </Flex>
                    </Flex>

                    {/* Stats bar */}
                    <Flex align="center" gap="4" mt="3">
                        <Flex align="center" gap="1">
                            <CheckCircledIcon style={{ color: 'var(--green-9)' }} />
                            <Text size="1" color="gray">Present: {totalRows}</Text>
                        </Flex>
                        <Flex align="center" gap="1">
                            <ExclamationTriangleIcon style={{ color: 'var(--amber-9)' }} />
                            <Text size="1" color="gray">Absent: {absentUsers.length}</Text>
                        </Flex>
                        <Flex align="center" gap="1">
                            <PersonIcon style={{ color: 'var(--accent-9)' }} />
                            <Text size="1" color="gray">Total: {totalRows + absentUsers.length}</Text>
                        </Flex>
                    </Flex>
                </Box>

                {/* Filters */}
                <Box pb="3" mb="3" style={{ borderBottom: '1px solid var(--gray-a4)' }} role="search" aria-label="Timesheet filters">
                    <Grid columns={{ initial: '1', sm: '2', md: '3' }} gap="3">
                        <Flex direction="column" gap="1">
                            <Text size="1" weight="medium">Search Employee</Text>
                            <TextField.Root
                                placeholder="Enter employee name"
                                value={employee}
                                onChange={(e) => setEmployee(e.target.value)}
                                size="2"
                                aria-label="Search employees"
                            >
                                <TextField.Slot><MagnifyingGlassIcon /></TextField.Slot>
                            </TextField.Root>
                        </Flex>
                        <Flex direction="column" gap="1">
                            <Text size="1" weight="medium">Select Date</Text>
                            <TextField.Root
                                type="date"
                                onChange={handleDateChange}
                                value={new Date(selectedDate).toISOString().slice(0, 10) || ''}
                                size="2"
                                aria-label="Select date for timesheet"
                            >
                                <TextField.Slot><CalendarIcon /></TextField.Slot>
                            </TextField.Root>
                        </Flex>
                    </Grid>
                </Box>

                {/* Content: Table + Absent Users */}
                {error ? (
                    <Card style={{ borderColor: 'var(--red-a7)' }}>
                        <Flex align="center" gap="3" p="3">
                            <ExclamationTriangleIcon style={{ color: 'var(--red-9)', width: 20, height: 20 }} />
                            <Text size="2" color="red">{error}</Text>
                        </Flex>
                    </Card>
                ) : (
                    <Flex gap="4" wrap="wrap">
                        {/* Present Users Table */}
                        <Box style={{ flex: '1 1 400px', minWidth: 0 }}>
                            <Flex align="center" gap="2" mb="2">
                                <CheckCircledIcon style={{ color: 'var(--green-9)' }} />
                                <Text size="3" weight="medium">Present Employees ({totalRows})</Text>
                            </Flex>
                            <ScrollArea style={{ maxHeight: 520 }}>
                                    {!isLoaded ? (
                                        <Flex direction="column" gap="2">
                                            {[...Array(5)].map((_, i) => (
                                                <Skeleton key={i} style={{ height: 40, width: '100%', borderRadius: 4 }} />
                                            ))}
                                        </Flex>
                                    ) : (
                                        <Table.Root size="1" variant="surface" style={{ width: '100%' }}>
                                            <Table.Header>
                                                <Table.Row>
                                                    {columns.map((col) => (
                                                        <Table.ColumnHeaderCell key={col.uid}>
                                                            <Flex align="center" gap="1">
                                                                {col.icon && <col.icon style={{ width: 14, height: 14 }} />}
                                                                <Text size="1" weight="medium">{col.name}</Text>
                                                            </Flex>
                                                        </Table.ColumnHeaderCell>
                                                    ))}
                                                </Table.Row>
                                            </Table.Header>
                                            <Table.Body>
                                                {attendances.length === 0 ? (
                                                    <Table.Row>
                                                        <Table.Cell colSpan={columns.length}>
                                                            <Flex direction="column" align="center" justify="center" py="8" gap="2">
                                                                <ClockIcon style={{ color: 'var(--gray-8)', width: 40, height: 40 }} />
                                                                <Text size="2" color="gray">No attendance records found</Text>
                                                            </Flex>
                                                        </Table.Cell>
                                                    </Table.Row>
                                                ) : (
                                                    attendances.map((attendance) => (
                                                        <Table.Row key={attendance.id || attendance.user_id}>
                                                            {columns.map((col) => (
                                                                <React.Fragment key={col.uid}>
                                                                    {renderCell(attendance, col.uid)}
                                                                </React.Fragment>
                                                            ))}
                                                        </Table.Row>
                                                    ))
                                                )}
                                            </Table.Body>
                                        </Table.Root>
                                    )}
                            </ScrollArea>
                            {totalRows > perPage && (
                                <Flex justify="center" align="center" gap="1" pt="3" wrap="wrap">
                                    <Button
                                        variant="ghost" size="1" color="gray"
                                        disabled={currentPage <= 1}
                                        onClick={() => handlePageChange(currentPage - 1)}
                                        aria-label="Previous page"
                                    ><ChevronLeftIcon /></Button>
                                    {Array.from({ length: lastPage }, (_, i) => i + 1)
                                        .filter(p => p === 1 || p === lastPage || Math.abs(p - currentPage) <= 1)
                                        .reduce((acc, p, idx, arr) => {
                                            if (idx > 0 && p - arr[idx - 1] > 1) acc.push('...');
                                            acc.push(p);
                                            return acc;
                                        }, [])
                                        .map((p, idx) =>
                                            p === '...' ? (
                                                <Text key={`ellipsis-${idx}`} size="1" color="gray" style={{ padding: '0 4px' }}>…</Text>
                                            ) : (
                                                <Button
                                                    key={p}
                                                    variant={p === currentPage ? 'solid' : 'ghost'}
                                                    size="1"
                                                    color={p === currentPage ? 'accent' : 'gray'}
                                                    onClick={() => handlePageChange(p)}
                                                    aria-label={`Page ${p}`}
                                                    aria-current={p === currentPage ? 'page' : undefined}
                                                >{p}</Button>
                                            )
                                        )
                                    }
                                    <Button
                                        variant="ghost" size="1" color="gray"
                                        disabled={currentPage >= lastPage}
                                        onClick={() => handlePageChange(currentPage + 1)}
                                        aria-label="Next page"
                                    ><ChevronRightIcon /></Button>
                                </Flex>
                            )}
                        </Box>

                        {/* Absent Users */}
                        <Box style={{ width: 320, flexShrink: 0 }}>
                            <AbsentUsersInlineCard
                                absentUsers={absentUsers}
                                selectedDate={selectedDate}
                                getUserLeave={getUserLeave}
                                isLoaded={isLoaded}
                                onMarkAsPresent={onMarkAsPresent}
                            />
                        </Box>
                    </Flex>
                )}
            </Box>
        )}
    </>
);
};





export default TimeSheetTable;

