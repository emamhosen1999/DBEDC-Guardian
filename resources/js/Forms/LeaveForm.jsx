import React, {useState, useEffect} from 'react';
import { Box, Button, Dialog, Flex, Grid, Select, Spinner, Text, TextArea, TextField } from '@radix-ui/themes';
import { CalendarIcon, ClockIcon, PersonIcon } from '@radix-ui/react-icons';
import axios from 'axios';

import { showToast } from "@/utils/toastUtils";

import DepartmentEmployeeSelector from "@/Components/DepartmentEmployeeSelector.jsx";
import ApprovalChain from "@/Components/Leave/ApprovalChain.jsx";
import {router, usePage} from "@inertiajs/react";

const LeaveForm = ({
                       open,
                       closeModal,
                       leavesData,
                       setLeavesData,
                       currentLeave,
                       allUsers,
                       departments = [],
                       setTotalRows,
                       setLastPage,
                       setLeaves,
                       handleMonthChange,
                       employee,
                       selectedMonth,
                       addLeaveOptimized,
                       updateLeaveOptimized,
                       fetchLeavesStats
}) => {
    const {auth} = usePage().props;

    const [user_id, setUserId] = useState(currentLeave?.user_id || auth.user.id);
    
    // Initialize selectedDepartmentId based on currentLeave or user's department
    const initializeDepartmentId = () => {
        if (currentLeave?.user_id) {
            // Find the user from allUsers to get their department
            const leaveUser = allUsers?.find(user => user.id === currentLeave.user_id);
            return leaveUser?.department_id || null;
        }
        // For new leaves, use the current user's department
        return auth?.user?.department_id || null;
    };
    
    const [selectedDepartmentId, setSelectedDepartmentId] = useState(initializeDepartmentId());
    // Initialize state variables
    const [leaveTypes, setLeaveTypes] = useState(leavesData?.leaveTypes || []);
    const [leaveCounts, setLeaveCounts] = useState([]);
    const [leaveType, setLeaveType] = useState(currentLeave?.leave_type || "");
    const formatDate = (dateString) => {
 
        
        if (!dateString) return new Date().toISOString().split('T')[0];
        
        // For date strings like 'YYYY-MM-DD', return as-is (handled by backend now)
        if (typeof dateString === 'string' && dateString.match(/^\d{4}-\d{2}-\d{2}$/)) {
       
            return dateString;
        }
        
        // Handle legacy ISO datetime strings with timezone (e.g., "2025-07-17T18:00:00.000000Z")
        if (typeof dateString === 'string' && dateString.includes('T')) {
            // For dates stored at 6 PM UTC, they represent the following day in most timezones
            if (dateString.includes('T18:00:00')) {
                const dateParts = dateString.split('T')[0].split('-');
                const year = parseInt(dateParts[0]);
                const month = parseInt(dateParts[1]);
                const day = parseInt(dateParts[2]) + 1; // Add one day
                
                // Create a proper date object and format it
                const adjustedDate = new Date(year, month - 1, day);
                const formattedDate = adjustedDate.toISOString().split('T')[0];
              
                return formattedDate;
            }
            
            // For other ISO strings, just take the date part
            const formattedDate = dateString.split('T')[0];
        
            return formattedDate;
        }
        
        // For other formats, use local date components to avoid timezone issues
        try {
            const date = new Date(dateString);
            if (isNaN(date.getTime())) {
          
                return new Date().toISOString().split('T')[0];
            }
            
            // Use local date components to avoid timezone shifting
            const year = date.getFullYear();
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');
            const formattedDate = `${year}-${month}-${day}`;
          
            return formattedDate;
        } catch (error) {
            console.error("Error formatting date:", error);
            return new Date().toISOString().split('T')[0];
        }
    };
    
    const [fromDate, setFromDate] = useState(currentLeave?.from_date ? formatDate(currentLeave.from_date) : '');
    const [toDate, setToDate] = useState(currentLeave?.to_date ? formatDate(currentLeave.to_date) : '');
    const [daysCount, setDaysCount] = useState(currentLeave?.no_of_days || '');
    const [remainingLeaves, setRemainingLeaves] = useState(''); // Default remaining leaves
    const [leaveReason, setLeaveReason] = useState(currentLeave?.reason ||'');
    const [errors, setErrors] = useState({});
    const [processing, setProcessing] = useState(false);
    const [daysUsed, setDaysUsed] = useState('');



    // Populate state when leavesData or auth changes
    useEffect(() => {
        if (leavesData) {
            const newLeaveTypes = leavesData.leaveTypes || [];
            setLeaveTypes(newLeaveTypes);
            const userLeaveCounts = leavesData.leaveCountsByUser?.[user_id] || [];
            setLeaveCounts(userLeaveCounts);
            
            // Set initial leave type if not set and we have leave types (only for new leaves)
            if (newLeaveTypes.length > 0 && !leaveType && !currentLeave) {
                setLeaveType(newLeaveTypes[0].type);
            }
            
            // For edit mode, ensure leave type is set from current leave
            if (currentLeave && !leaveType) {
                const leaveTypeFromSettings = newLeaveTypes?.find(lt => lt.id === currentLeave.leave_type);
                if (leaveTypeFromSettings) {
                    setLeaveType(leaveTypeFromSettings.type);
                }
            }
        }
    }, [leavesData, user_id, currentLeave]);

    // Update department and user ID when currentLeave changes (for edit mode)
    useEffect(() => {
        if (currentLeave) {
            setUserId(currentLeave.user_id);
            
            // Find the user from allUsers to get their department
            const leaveUser = allUsers?.find(user => user.id === currentLeave.user_id);
            if (leaveUser?.department_id) {
                setSelectedDepartmentId(leaveUser.department_id);
            }
        }
    }, [currentLeave, allUsers]);

    // Update remaining leaves when user or leave type changes
    useEffect(() => {
        if (!leaveType || !leaveCounts || !leaveTypes) return;
        
        // Find the leave count for the selected leave type
        const leaveCount = leaveCounts.find(lc => lc.leave_type === leaveType);
        const daysUsed = leaveCount?.days_used || 0;
        setDaysUsed(daysUsed);

        // Find the leave type definition
        const selectedLeaveType = leaveTypes.find(lt => lt.type === leaveType);
        if (selectedLeaveType) {
            const remaining = selectedLeaveType.days - daysUsed;
            setRemainingLeaves(remaining);
            
            // If editing and days exceed remaining, adjust the days count
            if (currentLeave && daysCount > remaining) {
                setDaysCount(remaining);
            }
        }
    }, [leaveType, leaveCounts, leaveTypes, currentLeave, daysCount]);

    useEffect(() => {
        // Function to calculate the number of days between two dates, inclusive of both start and end date
        const calculateDaysBetweenDates = (start, end) => {
            if (!start || !end) return '';

            const startDate = new Date(start);
            const endDate = new Date(end);

            if (startDate > endDate) return '';

            const timeDifference = endDate.getTime() - startDate.getTime();
            const daysDifference = Math.ceil(timeDifference / (1000 * 3600 * 24)) + 1;

            return daysDifference;
        };

        // Update daysCount whenever fromDate or toDate changes
        setDaysCount(calculateDaysBetweenDates(fromDate, toDate));
    }, [fromDate, toDate]);

    const handleSubmit = async (event) => {
        event.preventDefault();
        setProcessing(true);

        const promise = new Promise(async (resolve, reject) => {

            try {
                const data = {
                    route: route().current(),
                    user_id,
                    leaveType,
                    fromDate,
                    toDate,
                    daysCount,
                    leaveReason,
                    employee,
                    month: selectedMonth,
                };

                const apiRoute = currentLeave ? route('leave-update') : route('leave-add');

                if (currentLeave) {
                    data.id = currentLeave.id;
                }

              

                const response = await axios.post(apiRoute, data);
               
    

                if (response.status === 200 || response.status === 201) {
                    // Update leave data
                    setLeavesData(response.data.leavesData);
                    
                    // Use optimized data manipulation without triggering full reloads
                    if (currentLeave && updateLeaveOptimized && response.data.leave) {
                        // Update existing leave in-place without re-fetching
                        updateLeaveOptimized(response.data.leave);
                        fetchLeavesStats();
                    } else if (addLeaveOptimized && response.data.leave) {
                        // Add new leave in-place without re-fetching
                        addLeaveOptimized(response.data.leave);
                        fetchLeavesStats();
                        
                        // Update pagination data if provided by backend
                        if (response.data.pagination) {
                            setTotalRows(response.data.pagination.total);
                            setLastPage(response.data.pagination.lastPage);
                        }
                    } else {
                        // Fallback: use the server response data if optimized functions aren't available
                     
                        if (response.data.leaves) {
                            // Handle consistent LeaveResourceCollection structure
                            setLeaves(response.data.leaves.data);
                            setTotalRows(response.data.leaves.total);
                            setLastPage(response.data.leaves.last_page);
                        }
                        
                        // Update leavesData if provided
                        if (response.data.leavesData && typeof updateLeavesData === 'function') {
                            updateLeavesData(response.data.leavesData);
                        }
                    }
                    
                    closeModal();
                    resolve([response.data.message || 'Leave application submitted successfully']);
                } else {
                    // Handle unexpected status codes
                    console.error('Unexpected response status:', response.status);
                    reject(`Unexpected response status: ${response.status}`);
                }
            } catch (error) {
                console.error('Full error object:', error);

                if (error.response) {
                    // The request was made and the server responded with a status code
                    // that falls out of the range of 2xx
                    console.error('Error response status:', error.response.status);
                    console.error('Error response data:', error.response.data);
                    
                    if (error.response.status === 422) {
                        // Handle validation errors
                        setErrors(error.response.data.errors || {});
                        reject(error.response.data.error || 'Failed to submit leave application');
                    } else {
                        // Handle other HTTP errors
                        reject(`HTTP Error ${error.response.status}: ${error.response.data.message || 'An unexpected error occurred. Please try again later.'}`);
                    }
                } else if (error.request) {
                    // The request was made but no response was received
                    console.error('No response received:', error.request);
                    reject('No response received from the server. Please check your internet connection.');
                } else {
                    // Something happened in setting up the request that triggered an Error
                    console.error('Request setup error:', error.message);
                    reject('An error occurred while setting up the request.');
                }
            } finally {
                setProcessing(false);
            }
        });

        showToast.promise(
            promise,
            {
                loading: 'Submitting leave application...',
                success: (data) => Array.isArray(data) ? data.join(', ') : data,
                error: (err) => typeof err === 'string' ? err : 'Failed to submit leave application'
            }
        );
    };

    return (
        <Dialog.Root open={open} onOpenChange={v => { if (!v && !processing) closeModal(); }}>
            <Dialog.Content style={{ maxWidth: 640 }}>
                <Dialog.Title>
                    <Flex align="center" gap="2">
                        <CalendarIcon style={{ color: 'var(--accent-9)' }} />
                        {currentLeave ? 'Edit Leave' : 'Add Leave'}
                    </Flex>
                </Dialog.Title>

                <form onSubmit={handleSubmit}>
                    <Grid columns={{ initial: '1', sm: '2' }} gap="3" mb="4">
                        {/* Leave Type */}
                        <Box>
                            <Text as="label" size="1" weight="medium" style={{ display: 'block', marginBottom: 4 }}>Leave Type</Text>
                            <Select.Root value={leaveType} onValueChange={setLeaveType}>
                                <Select.Trigger style={{ width: '100%' }} />
                                <Select.Content>
                                    {leaveTypes.map((type) => {
                                        const leaveCount = leaveCounts?.find(lc => lc.leave_type === type.type);
                                        const remaining = leaveCount ? (type.days - leaveCount.days_used) : type.days;
                                        return (
                                            <Select.Item key={type.type} value={type.type} disabled={remaining <= 0}>
                                                {type.type} ({leaveCount ? `${leaveCount.days_used}/${type.days}` : type.days} days)
                                            </Select.Item>
                                        );
                                    })}
                                </Select.Content>
                            </Select.Root>
                            {errors.leaveType && <Text size="1" color="red">{errors.leaveType}</Text>}
                        </Box>

                        {/* From Date */}
                        <Box>
                            <Text as="label" size="1" weight="medium" style={{ display: 'block', marginBottom: 4 }}>From Date</Text>
                            <TextField.Root type="date" value={fromDate} onChange={e => setFromDate(e.target.value)} style={{ width: '100%' }}>
                                <TextField.Slot><CalendarIcon /></TextField.Slot>
                            </TextField.Root>
                            {errors.fromDate && <Text size="1" color="red">{errors.fromDate}</Text>}
                        </Box>

                        {/* To Date */}
                        <Box>
                            <Text as="label" size="1" weight="medium" style={{ display: 'block', marginBottom: 4 }}>To Date</Text>
                            <TextField.Root type="date" value={toDate} onChange={e => setToDate(e.target.value)} style={{ width: '100%' }}>
                                <TextField.Slot><CalendarIcon /></TextField.Slot>
                            </TextField.Root>
                            {errors.toDate && <Text size="1" color="red">{errors.toDate}</Text>}
                        </Box>

                        {/* Number of Days */}
                        <Box>
                            <Text as="label" size="1" weight="medium" style={{ display: 'block', marginBottom: 4 }}>Number of Days</Text>
                            <TextField.Root value={daysCount.toString()} readOnly style={{ width: '100%' }}>
                                <TextField.Slot><ClockIcon /></TextField.Slot>
                            </TextField.Root>
                        </Box>

                        {/* Remaining Leaves */}
                        <Box>
                            <Text as="label" size="1" weight="medium" style={{ display: 'block', marginBottom: 4 }}>Remaining Leaves</Text>
                            <TextField.Root
                                value={`${remainingLeaves} day${remainingLeaves !== 1 ? 's' : ''} of ${leaveTypes.find(lt => lt.type === leaveType)?.days || 0} total`}
                                readOnly style={{ width: '100%' }}
                            >
                                <TextField.Slot><ClockIcon /></TextField.Slot>
                            </TextField.Root>
                        </Box>

                        {/* Department & Employee for admin */}
                        {route().current() === 'leaves' && (
                            <Box style={{ gridColumn: '1 / -1' }}>
                                <DepartmentEmployeeSelector
                                    selectedDepartmentId={selectedDepartmentId}
                                    selectedEmployeeId={user_id}
                                    onDepartmentChange={setSelectedDepartmentId}
                                    onEmployeeChange={setUserId}
                                    allUsers={allUsers}
                                    departments={departments}
                                    showSearch={true}
                                    error={errors}
                                    variant="bordered"
                                    size="sm"
                                    showAllOption={false}
                                    autoSelectFirstDepartment={true}
                                    required={true}
                                />
                            </Box>
                        )}

                        {/* Leave Reason */}
                        <Box style={{ gridColumn: '1 / -1' }}>
                            <Text as="label" size="1" weight="medium" style={{ display: 'block', marginBottom: 4 }}>Leave Reason</Text>
                            <TextArea
                                placeholder="Please provide a detailed reason for your leave request..."
                                value={leaveReason}
                                onChange={e => setLeaveReason(e.target.value)}
                                rows={3}
                                style={{ borderColor: errors.leaveReason ? 'var(--red-7)' : undefined, width: '100%' }}
                            />
                            {errors.leaveReason && <Text size="1" color="red">{errors.leaveReason}</Text>}
                        </Box>

                        {/* Approval Chain */}
                        {currentLeave?.approval_chain?.length > 0 && (
                            <Box style={{ gridColumn: '1 / -1' }}>
                                <ApprovalChain
                                    approvalChain={currentLeave.approval_chain}
                                    currentLevel={currentLeave.current_approval_level}
                                    status={currentLeave.status}
                                />
                            </Box>
                        )}
                    </Grid>

                    <Flex justify="end" gap="2">
                        <Button type="button" variant="soft" color="gray" onClick={closeModal} disabled={processing} style={{ cursor: 'pointer' }}>Cancel</Button>
                        <Button type="submit" disabled={processing} style={{ cursor: 'pointer' }}>
                            {processing ? <Spinner size="1" /> : null} {processing ? 'Submitting...' : 'Submit'}
                        </Button>
                    </Flex>
                </form>
            </Dialog.Content>
        </Dialog.Root>
    );
};

export default LeaveForm;
