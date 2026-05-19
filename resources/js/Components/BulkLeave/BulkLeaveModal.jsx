import React, { useState, useCallback, useEffect, useMemo } from 'react';
import { Box, Button, Dialog, Flex, Select, Spinner, Switch, Text, TextArea, TextField } from '@radix-ui/themes';
import { CalendarIcon, CheckCircledIcon, Cross2Icon, ExclamationTriangleIcon, MagnifyingGlassIcon, PersonIcon } from '@radix-ui/react-icons';

import { usePage } from '@inertiajs/react';
import { showToast } from '@/utils/toastUtils';
import axios from 'axios';
import DepartmentEmployeeSelector from "@/Components/DepartmentEmployeeSelector.jsx";
import BulkCalendar from './BulkCalendar';
import BulkValidationPreview from './BulkValidationPreview';

const BulkLeaveModal = ({ 
    open, 
    onClose, 
    onSuccess,
    allUsers = [],
    departments = [],
    leavesData = { leaveTypes: [], leaveCountsByUser: {} },
    isAdmin = false,
    existingLeaves = [],
    publicHolidays = []
}) => {
    const { auth } = usePage().props;

    // Form state
    const [selectedDates, setSelectedDates] = useState([]);
    const [selectedDepartmentId, setSelectedDepartmentId] = useState(null);
    const [selectedUserId, setSelectedUserId] = useState(null);
    const [selectedLeaveType, setSelectedLeaveType] = useState('');
    const [reason, setReason] = useState('');
    const [allowPartialSuccess, setAllowPartialSuccess] = useState(false);
    
    // Dynamic leave types state (updated per user)
    const [userLeaveTypes, setUserLeaveTypes] = useState([]);
    const [loadingLeaveTypes, setLoadingLeaveTypes] = useState(false);
    
    // Validation state
    const [validationResults, setValidationResults] = useState([]);
    const [balanceImpact, setBalanceImpact] = useState(null);
    const [isValidating, setIsValidating] = useState(false);
    const [hasValidated, setHasValidated] = useState(false);
    
    // Submission state
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [errors, setErrors] = useState({});

    // Filter existing leaves for the selected user (only used as fallback)
    const userExistingLeaves = useMemo(() => {
        if (!existingLeaves || existingLeaves.length === 0) return [];
        return existingLeaves.filter(leave => leave.user_id === parseInt(selectedUserId));
    }, [existingLeaves, selectedUserId]);

    // Available leave types and counts
    const leaveTypes = useMemo(() => {
        return userLeaveTypes.length > 0 ? userLeaveTypes : (leavesData?.leaveTypes || []);
    }, [userLeaveTypes, leavesData]);

    const leaveCounts = useMemo(() => {
        // If we have user-specific leave types with balance info, use that
        if (userLeaveTypes.length > 0) {
            return userLeaveTypes.map(type => ({
                leave_type: type.type,
                days_used: type.used,
                total_days: type.days,
                remaining_days: type.remaining
            }));
        }
        // Fallback to leavesData
        return leavesData?.leaveCountsByUser?.[selectedUserId] || [];
    }, [userLeaveTypes, leavesData, selectedUserId]);

    // Fetch leave types with balances for specific user
    const fetchUserLeaveTypes = useCallback(async (userId) => {
        if (!userId) return;
        
        setLoadingLeaveTypes(true);
        try {
            const response = await axios.get(route('leaves.bulk.leave-types'), {
                params: {
                    user_id: userId,
                    year: new Date().getFullYear()
                }
            });

            if (response.data.success) {
                setUserLeaveTypes(response.data.leave_types);
            }
        } catch (error) {
            console.error('Failed to fetch user leave types:', error);
            // Fallback to original leaveTypes
            setUserLeaveTypes([]);
        } finally {
            setLoadingLeaveTypes(false);
        }
    }, []);

    // Fetch leave types when user changes
    useEffect(() => {
        if (open && selectedUserId && isAdmin) {
            fetchUserLeaveTypes(selectedUserId);
        } else if (open && selectedUserId && !isAdmin) {
            // For non-admin users, also fetch their leave types
            fetchUserLeaveTypes(selectedUserId);
        }
    }, [selectedUserId, open, isAdmin, fetchUserLeaveTypes]);

    // Initialize form when modal opens for current user
    useEffect(() => {
        if (open && auth?.user) {
            // Auto-select current user's department and set user
            const currentUser = allUsers?.find(user => user.id === auth.user.id);
            if (currentUser && currentUser.department_id && !isAdmin) {
                setSelectedDepartmentId(currentUser.department_id);
                setSelectedUserId(auth.user.id);
            } else if (isAdmin && !selectedDepartmentId && !selectedUserId) {
                // For admin, set to current user as default
                if (currentUser && currentUser.department_id) {
                    setSelectedDepartmentId(currentUser.department_id);
                    setSelectedUserId(auth.user.id);
                }
            }
        }
    }, [open, auth?.user, allUsers, isAdmin, selectedDepartmentId, selectedUserId]);

    // Reset form when modal closes
    useEffect(() => {
        if (!open) {
            setSelectedDates([]);
            setSelectedDepartmentId(null);
            setSelectedUserId(auth?.user?.id || null);
            setSelectedLeaveType('');
            setReason('');
            setAllowPartialSuccess(false);
            setValidationResults([]);
            setBalanceImpact(null);
            setHasValidated(false);
            setErrors({});
            setUserLeaveTypes([]);
        }
    }, [open, auth?.user?.id]);

    // Set initial leave type when leave types are available (only for new requests)
    useEffect(() => {
        if (leaveTypes.length > 0 && !selectedLeaveType && open && selectedUserId) {
            // Find a leave type with remaining days for the selected user
            const availableLeaveType = leaveTypes.find(lt => {
                // For user-specific leave types (with balance info)
                if (userLeaveTypes.length > 0) {
                    return lt.remaining > 0;
                }
                // For fallback to leavesData
                const leaveCount = leaveCounts?.find(lc => lc.leave_type === lt.type);
                const remaining = leaveCount ? (lt.days - leaveCount.days_used) : lt.days;
                return remaining > 0;
            });
            
            if (availableLeaveType) {
                setSelectedLeaveType(availableLeaveType.type);
            }
        }
    }, [leaveTypes, leaveCounts, userLeaveTypes, selectedLeaveType, open, selectedUserId]);

    // Validate dates
    const handleValidate = useCallback(async () => {
        if (selectedDates.length === 0) {
            const toastPromise = Promise.reject(new Error('No dates selected'));
            showToast.promise(toastPromise, {
                error: 'Please select at least one date'
            });
            return;
        }
        
        if (!selectedLeaveType) {
            const toastPromise = Promise.reject(new Error('No leave type selected'));
            showToast.promise(toastPromise, {
                error: 'Please select a leave type'
            });
            return;
        }
        
        if (!reason.trim() || reason.trim().length < 5) {
            const toastPromise = Promise.reject('Please provide a reason for leave (at least 5 characters)');
            showToast.promise(toastPromise, {
                error: 'Please provide a reason for leave (at least 5 characters)'
            });
            return;
        }

        setIsValidating(true);
        setErrors({});
        
        try {
            const selectedLeaveTypeData = leaveTypes.find(lt => lt.type === selectedLeaveType);
            
            const response = await axios.post(route('leaves.bulk.validate'), {
                user_id: parseInt(selectedUserId),
                dates: selectedDates,
                leave_type_id: selectedLeaveTypeData?.id,
                reason: reason.trim()
            });

            if (response.data.success) {
                setValidationResults(response.data.validation_results);
                setBalanceImpact(response.data.estimated_balance_impact);
                setHasValidated(true);
                
                const conflictCount = response.data.validation_results.filter(r => r.status === 'conflict').length;
                const warningCount = response.data.validation_results.filter(r => r.status === 'warning').length;
                
                const toastPromise = Promise.resolve();
                if (conflictCount > 0) {
                    showToast.promise(toastPromise, {
                        success: `${conflictCount} date(s) have conflicts. Please review before submitting.`
                    });
                } else if (warningCount > 0) {
                    showToast.promise(toastPromise, {
                        success: `${warningCount} date(s) have warnings. You may proceed if acceptable.`
                    });
                } else {
                    showToast.promise(toastPromise, {
                        success: 'All dates validated successfully!'
                    });
                }
            }
        } catch (error) {
            console.error('Validation error:', error);
            if (error.response?.status === 422) {
                setErrors(error.response.data.errors || {});
            }
            const toastPromise = Promise.reject(error);
            showToast.promise(toastPromise, {
                error: error.response?.data?.message || 'Failed to validate dates'
            });
        } finally {
            setIsValidating(false);
        }
    }, [selectedDates, selectedLeaveType, reason, selectedUserId, leaveTypes]);

    // Submit bulk leave request
    const handleSubmit = useCallback(async () => {
        if (!hasValidated) {
            const toastPromise = Promise.reject(new Error('Not validated'));
            showToast.promise(toastPromise, {
                error: 'Please validate dates before submitting'
            });
            return;
        }

        const conflictCount = validationResults.filter(r => r.status === 'conflict').length;
        if (conflictCount > 0 && !allowPartialSuccess) {
            const toastPromise = Promise.reject('Please resolve conflicts or enable partial success mode');
            showToast.promise(toastPromise, {
                error: 'Please resolve conflicts or enable partial success mode'
            });
            return;
        }

        setIsSubmitting(true);

        // Follow exact same promise pattern as LeaveForm
        const promise = new Promise(async (resolve, reject) => {
            try {
                const selectedLeaveTypeData = leaveTypes.find(lt => lt.type === selectedLeaveType);
                
                const response = await axios.post(route('leaves.bulk.store'), {
                    user_id: parseInt(selectedUserId),
                    dates: selectedDates,
                    leave_type_id: selectedLeaveTypeData?.id,
                    reason: reason.trim(),
                    allow_partial_success: allowPartialSuccess
                });

                if (response.status === 200 || response.status === 201) {
                    // Pass the response data to parent component for optimized updates
                    // Follow the same pattern as single leave form
                    onSuccess?.(response.data);
                    onClose();
                    resolve([response.data.message || 'Bulk leave requests created successfully']);
                } else {
                    console.error('Unexpected response status:', response.status);
                    reject(`Unexpected response status: ${response.status}`);
                }
            } catch (error) {
                console.error('Full error object:', error);

                if (error.response) {
                    console.error('Error response status:', error.response.status);
                    console.error('Error response data:', error.response.data);
                    
                    if (error.response.status === 422) {
                        // Handle validation errors
                        setErrors(error.response.data.errors || {});
                        reject(error.response.data.error || 'Failed to submit bulk leave requests');
                    } else {
                        // Handle other HTTP errors
                        reject(`HTTP Error ${error.response.status}: ${error.response.data.message || 'An unexpected error occurred. Please try again later.'}`);
                    }
                } else if (error.request) {
                    console.error('No response received:', error.request);
                    reject('No response received from the server. Please check your internet connection.');
                } else {
                    console.error('Request setup error:', error.message);
                    reject('An error occurred while setting up the request.');
                }
            } finally {
                setIsSubmitting(false);
            }
        });

        // Use exact same toast promise structure as LeaveForm
        showToast.promise(
            promise,
            {
                pending: 'Creating bulk leave requests...',
                success: {
                    render({ data }) {
                        return data.join(', ');
                    }
                },
                error: {
                    render({ data }) {
                        return data;
                    }
                }
            }
        );
    }, [hasValidated, validationResults, allowPartialSuccess, selectedUserId, selectedDates, selectedLeaveType, reason, onSuccess, onClose, leaveTypes]);

    // Check if form is valid for validation
    const canValidate = selectedDates.length > 0 && selectedLeaveType && reason.trim().length >= 5;
    
    // Check if can submit
    const canSubmit = hasValidated && 
                     (validationResults.filter(r => r.status === 'conflict').length === 0 || allowPartialSuccess);

    return (
        <Dialog.Root open={open} onOpenChange={v => { if (!v && !isSubmitting && !isValidating) onClose(); }}>
            <Dialog.Content
                style={{
                    maxWidth: 900,
                    maxHeight: '95vh',
                    padding: 0,
                    display: 'flex',
                    flexDirection: 'column',
                    overflow: 'hidden',
                }}
            >
                {/* Modal Header */}
                <Box
                    px="5"
                    py="4"
                    style={{
                        borderBottom: '1px solid var(--gray-a4)',
                        flexShrink: 0,
                    }}
                >
                    <Flex justify="between" align="start">
                        <Dialog.Title
                            style={{
                                margin: 0,
                                fontSize: 'inherit',
                                fontWeight: 'inherit',
                                lineHeight: 'inherit',
                            }}
                        >
                            <Flex align="center" gap="2">
                                <CalendarIcon
                                    style={{ color: 'var(--accent-9)', width: 20, height: 20, flexShrink: 0 }}
                                />
                                <Box>
                                    <Text size="4" weight="semibold" style={{ display: 'block' }}>
                                        Add Bulk Leave
                                    </Text>
                                    <Text size="1" color="gray" style={{ display: 'block' }}>
                                        Select multiple dates and create leave requests in batch
                                    </Text>
                                </Box>
                            </Flex>
                        </Dialog.Title>

                        <Dialog.Close asChild>
                            <Button
                                size="1"
                                variant="ghost"
                                color="gray"
                                style={{ cursor: 'pointer', marginTop: 2 }}
                                disabled={isSubmitting}
                                aria-label="Close"
                            >
                                <Cross2Icon />
                            </Button>
                        </Dialog.Close>
                    </Flex>
                </Box>

                {/* Modal Body — scrollable */}
                <Box
                    px="5"
                    py="4"
                    style={{
                        overflowY: 'auto',
                        flex: 1,
                        minHeight: 0,
                    }}
                >
                    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(2, minmax(0, 1fr))', gap: 16 }}>
                        {/* Left Column: Calendar */}
                        <Box>
                            <Flex align="center" gap="2" mb="3">
                                <CalendarIcon style={{ color: 'var(--accent-9)', width: 18, height: 18 }} />
                                <Text size="3" weight="semibold">Select Dates</Text>
                            </Flex>
                            <BulkCalendar
                                selectedDates={selectedDates}
                                onDatesChange={(dates) => {
                                    setSelectedDates(dates);
                                    setHasValidated(false); // Reset validation when dates change
                                }}
                                userId={selectedUserId}
                                fetchFromAPI={true} // Enable API-driven data fetching
                            />
                        </Box>

                        {/* Right Column: Form and Validation */}
                        <Flex direction="column" gap="4">
                            {/* Form Controls */}
                            <Box>
                                <Flex align="center" gap="2" mb="3">
                                    <PersonIcon style={{ color: 'var(--accent-9)', width: 18, height: 18 }} />
                                    <Text size="3" weight="semibold">Leave Details</Text>
                                </Flex>

                                <Flex direction="column" gap="3">
                                    {/* Department & User Selection (Admin only) */}
                                    {isAdmin && (
                                        <DepartmentEmployeeSelector
                                            selectedDepartmentId={selectedDepartmentId}
                                            selectedEmployeeId={selectedUserId}
                                            onDepartmentChange={setSelectedDepartmentId}
                                            onEmployeeChange={(empId) => {
                                                setSelectedUserId(empId);
                                                setSelectedLeaveType(''); // Reset leave type when user changes
                                                setHasValidated(false);
                                                setUserLeaveTypes([]); // Clear current user leave types
                                            }}
                                            allUsers={allUsers}
                                            departments={departments}
                                            showSearch={true}
                                            error={errors}
                                            variant="outlined"
                                            showAllOption={false}
                                            autoSelectFirstDepartment={false} // Let our initialization effect handle this
                                            required={true}
                                            disabled={isSubmitting || isValidating}
                                        />
                                    )}

                                    {/* Leave Type Selection */}
                                    <Box>
                                        <Text
                                            as="label"
                                            size="1"
                                            weight="medium"
                                            style={{ display: 'block', marginBottom: 4 }}
                                        >
                                            Leave Type
                                        </Text>
                                        <Select.Root
                                            value={selectedLeaveType}
                                            onValueChange={(v) => {
                                                setSelectedLeaveType(v);
                                                setHasValidated(false);
                                            }}
                                            disabled={isSubmitting || isValidating || loadingLeaveTypes}
                                        >
                                            <Select.Trigger
                                                style={{ width: '100%' }}
                                                placeholder="Select Leave Type"
                                            />
                                            <Select.Content>
                                                {loadingLeaveTypes ? (
                                                    <Select.Item value="__loading" disabled>
                                                        Loading leave types...
                                                    </Select.Item>
                                                ) : (
                                                    leaveTypes.map((type) => {
                                                        const remaining = userLeaveTypes.length > 0
                                                            ? type.remaining
                                                            : (() => {
                                                                const lc = leaveCounts?.find(lc => lc.leave_type === type.type);
                                                                return lc ? (type.days - lc.days_used) : type.days;
                                                            })();
                                                        return (
                                                            <Select.Item
                                                                key={type.type}
                                                                value={type.type}
                                                                disabled={remaining <= 0}
                                                            >
                                                                {type.type} ({remaining} remaining)
                                                            </Select.Item>
                                                        );
                                                    })
                                                )}
                                            </Select.Content>
                                        </Select.Root>
                                        {errors.leave_type_id && (
                                            <Text size="1" color="red" style={{ display: 'block', marginTop: 4 }}>
                                                {errors.leave_type_id}
                                            </Text>
                                        )}
                                    </Box>

                                    {/* Remaining Leaves Display */}
                                    {selectedLeaveType && (() => {
                                        const st = leaveTypes.find(lt => lt.type === selectedLeaveType);
                                        const remaining = userLeaveTypes.length > 0
                                            ? st?.remaining
                                            : (() => {
                                                const lc = leaveCounts?.find(lc => lc.leave_type === selectedLeaveType);
                                                return lc ? (st?.days - lc.days_used) : st?.days;
                                            })();
                                        return (
                                            <Box>
                                                <Text
                                                    as="label"
                                                    size="1"
                                                    weight="medium"
                                                    style={{ display: 'block', marginBottom: 4 }}
                                                >
                                                    Remaining Leaves
                                                </Text>
                                                <TextField.Root
                                                    value={`${remaining || 0} remaining of ${st?.days || 0} total`}
                                                    readOnly
                                                    style={{ width: '100%' }}
                                                />
                                            </Box>
                                        );
                                    })()}

                                    {/* Reason */}
                                    <Box>
                                        <Text
                                            as="label"
                                            size="1"
                                            weight="medium"
                                            style={{ display: 'block', marginBottom: 4 }}
                                        >
                                            Reason for Leave{' '}
                                            <Text as="span" color="red">*</Text>
                                        </Text>
                                        <TextArea
                                            placeholder="Please provide a detailed reason for your leave request..."
                                            value={reason}
                                            onChange={(e) => {
                                                setReason(e.target.value);
                                                setHasValidated(false);
                                            }}
                                            maxLength={500}
                                            rows={3}
                                            disabled={isSubmitting || isValidating}
                                            style={{
                                                width: '100%',
                                                resize: 'vertical',
                                                borderColor: (errors.reason || (reason.length > 0 && reason.length < 5))
                                                    ? 'var(--red-7)'
                                                    : undefined,
                                            }}
                                        />
                                        {errors.reason && (
                                            <Text size="1" color="red" style={{ display: 'block', marginTop: 4 }}>
                                                {errors.reason}
                                            </Text>
                                        )}
                                        {reason.length > 0 && reason.length < 5 && (
                                            <Text size="1" color="red" style={{ display: 'block', marginTop: 4 }}>
                                                Reason must be at least 5 characters
                                            </Text>
                                        )}
                                        {reason.length >= 5 && (
                                            <Text size="1" color="gray" style={{ display: 'block', marginTop: 4 }}>
                                                {reason.length}/500 characters
                                            </Text>
                                        )}
                                    </Box>

                                    {/* Options — partial success toggle */}
                                    <Flex align="start" gap="3">
                                        <Switch
                                            size="1"
                                            checked={allowPartialSuccess}
                                            onCheckedChange={setAllowPartialSuccess}
                                            disabled={isSubmitting || isValidating}
                                        />
                                        <Box>
                                            <Text size="2" weight="medium" style={{ display: 'block' }}>
                                                Allow partial success
                                            </Text>
                                            <Text size="1" color="gray" style={{ display: 'block' }}>
                                                Valid dates will be processed even if some dates fail validation
                                            </Text>
                                        </Box>
                                    </Flex>
                                </Flex>
                            </Box>

                            {/* Summary Information */}
                            {selectedDates.length > 0 && (
                                <Box
                                    p="3"
                                    style={{
                                        background: 'var(--gray-a2)',
                                        borderRadius: 'var(--radius-2)',
                                        border: '1px solid var(--gray-a4)',
                                    }}
                                >
                                    <Text size="1" color="gray" style={{ display: 'block', marginBottom: 4 }}>
                                        Selected Dates Summary
                                    </Text>
                                    <Text size="3" weight="medium" style={{ display: 'block' }}>
                                        <Text as="span" weight="bold">{selectedDates.length}</Text>
                                        {' '}date{selectedDates.length !== 1 ? 's' : ''} selected
                                    </Text>
                                    {selectedLeaveType && (
                                        <Text size="1" color="gray" style={{ display: 'block', marginTop: 2 }}>
                                            Leave type: {selectedLeaveType}
                                        </Text>
                                    )}
                                </Box>
                            )}

                            {/* Validation and Preview */}
                            <BulkValidationPreview
                                validationResults={validationResults}
                                balanceImpact={balanceImpact}
                                isValidating={isValidating}
                            />
                        </Flex>
                    </div>
                </Box>

                {/* Modal Footer */}
                <Box
                    px="5"
                    py="3"
                    style={{
                        borderTop: '1px solid var(--gray-a4)',
                        flexShrink: 0,
                    }}
                >
                    <Flex direction="column" gap="2">
                        {/* Re-validate hint */}
                        {validationResults.length > 0 && !hasValidated && !isValidating && (
                            <Flex
                                align="center"
                                gap="1"
                                px="1"
                                style={{ opacity: 0.85 }}
                            >
                                <ExclamationTriangleIcon
                                    style={{ color: 'var(--amber-9)', width: 13, height: 13, flexShrink: 0 }}
                                />
                                <Text size="1" color="amber">
                                    Dates or details changed — please re-validate before submitting.
                                </Text>
                            </Flex>
                        )}

                        <Flex justify="between" align="center" gap="2">
                            <Button
                                variant="soft"
                                color="gray"
                                onClick={onClose}
                                disabled={isSubmitting}
                                style={{ cursor: 'pointer' }}
                            >
                                Cancel
                            </Button>

                            <Flex gap="2">
                                <Button
                                    variant="outline"
                                    onClick={handleValidate}
                                    disabled={!canValidate || isSubmitting}
                                    style={{ cursor: canValidate && !isSubmitting ? 'pointer' : 'not-allowed' }}
                                >
                                    {isValidating ? <Spinner size="1" /> : <MagnifyingGlassIcon />}
                                    {isValidating ? 'Validating...' : 'Validate Dates'}
                                </Button>

                                <Button
                                    onClick={handleSubmit}
                                    disabled={!canSubmit || isValidating}
                                    style={{ cursor: canSubmit && !isValidating ? 'pointer' : 'not-allowed' }}
                                >
                                    {isSubmitting ? <Spinner size="1" /> : <CheckCircledIcon />}
                                    {isSubmitting
                                        ? 'Creating...'
                                        : `Create ${selectedDates.length} Leave${selectedDates.length !== 1 ? 's' : ''}`
                                    }
                                </Button>
                            </Flex>
                        </Flex>
                    </Flex>
                </Box>
            </Dialog.Content>
        </Dialog.Root>
    );
};

export default BulkLeaveModal;
