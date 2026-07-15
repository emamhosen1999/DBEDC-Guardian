import { Panel } from '@/Components/ui/Panel';
/**
 * BulkLeaveModal.jsx
 * Unified modal for bulk leave selection, validation, and submission.
 * * UX Improvements added:
 * - Single-File Architecture: Merged Validation Preview directly into this file.
 * - Responsive Layout: Used Radix Grid to stack calendar/form on mobile and place side-by-side on desktop.
 * - Sticky Header/Footer: Enforces a strict layout where actions are always reachable.
 */
import React, { useState, useCallback, useEffect, useMemo } from 'react';
import { usePage } from '@inertiajs/react';
import axios from 'axios';
import { Box, Button, Dialog, Flex, Select, Spinner, Switch, Text, TextArea, TextField, Grid, ScrollArea, Badge, Separator } from '@radix-ui/themes';
import {
    CalendarIcon, CheckCircledIcon, Cross2Icon, ExclamationTriangleIcon,
    MagnifyingGlassIcon, PersonIcon, CrossCircledIcon, InfoCircledIcon
} from '@radix-ui/react-icons';
import { showToast } from '@/utils/toastUtils';
import DepartmentEmployeeSelector from "@/Components/DepartmentEmployeeSelector.jsx";
import BulkCalendar from './BulkCalendar';

/* ── Integrated Validation Preview Component ── */
const ValidationPreview = ({ validationResults = [], balanceImpact = null, isValidating = false }) => {
    if (validationResults.length === 0 && !isValidating) return null;

    const validCount = validationResults.filter(r => r.status === 'valid').length;
    const warningCount = validationResults.filter(r => r.status === 'warning').length;
    const conflictCount = validationResults.filter(r => r.status === 'conflict').length;

    const getStatusIcon = (status) => {
        switch (status) {
            case 'valid':    return <CheckCircledIcon style={{ color: 'var(--green-9)', width: 16, height: 16 }} />;
            case 'warning':  return <ExclamationTriangleIcon style={{ color: 'var(--amber-9)', width: 16, height: 16 }} />;
            case 'conflict': return <CrossCircledIcon style={{ color: 'var(--red-9)', width: 16, height: 16 }} />;
            default:         return <InfoCircledIcon style={{ color: 'var(--gray-9)', width: 16, height: 16 }} />;
        }
    };

    return (
        <Flex direction="column" gap="3" mt="2">
            <Panel tinted>
                <Flex justify="between" align="center" mb="3">
                    <Text size="3" weight="bold">Validation Results</Text>
                    {isValidating && (
                        <Flex align="center" gap="2">
                            <Spinner size="1" />
                            <Text size="1" color="gray">Checking rules...</Text>
                        </Flex>
                    )}
                </Flex>

                {isValidating && validationResults.length === 0 ? (
                    <Flex align="center" justify="center" direction="column" gap="2" py="4" style={{ background: 'var(--gray-a1)', borderRadius: 'var(--radius-2)', border: '1px solid var(--gray-a3)' }}>
                        <Spinner size="3" />
                        <Text size="2" color="gray">Simulating leave request...</Text>
                    </Flex>
                ) : (
                    <>
                        <Grid columns="3" gap="2">
                            <Box p="3" style={{ textAlign: 'center', background: 'var(--green-a3)', borderRadius: 'var(--radius-2)' }}>
                                <Text size="6" weight="bold" color="green" style={{ display: 'block' }}>{validCount}</Text>
                                <Text size="1" color="green">Valid</Text>
                            </Box>
                            <Box p="3" style={{ textAlign: 'center', background: 'var(--amber-a3)', borderRadius: 'var(--radius-2)' }}>
                                <Text size="6" weight="bold" color="amber" style={{ display: 'block' }}>{warningCount}</Text>
                                <Text size="1" color="amber">Warnings</Text>
                            </Box>
                            <Box p="3" style={{ textAlign: 'center', background: 'var(--red-a3)', borderRadius: 'var(--radius-2)' }}>
                                <Text size="6" weight="bold" color="red" style={{ display: 'block' }}>{conflictCount}</Text>
                                <Text size="1" color="red">Conflicts</Text>
                            </Box>
                        </Grid>

                        {balanceImpact && (
                            <Box mt="3" p="3" style={{ background: 'var(--gray-a3)', borderRadius: 'var(--radius-2)' }}>
                                <Flex align="center" gap="2" mb="2">
                                    <InfoCircledIcon style={{ color: 'var(--accent-9)' }} />
                                    <Text size="2" weight="bold">Balance Impact</Text>
                                </Flex>
                                <Grid columns="2" gap="2">
                                    <Box><Text size="1" color="gray" as="div">Type</Text><Text size="2" weight="medium">{balanceImpact.leave_type}</Text></Box>
                                    <Box><Text size="1" color="gray" as="div">Current</Text><Text size="2" weight="medium">{balanceImpact.current_balance} days</Text></Box>
                                    <Box><Text size="1" color="gray" as="div">Requested</Text><Text size="2" weight="medium">{balanceImpact.requested_days} days</Text></Box>
                                    <Box>
                                        <Text size="1" color="gray" as="div">Remaining</Text>
                                        <Text size="2" weight="bold" color={balanceImpact.remaining_balance < 0 ? 'red' : 'green'}>
                                            {balanceImpact.remaining_balance} days
                                        </Text>
                                    </Box>
                                </Grid>
                                {balanceImpact.remaining_balance < 0 && (
                                    <Flex align="center" gap="2" mt="3" p="2" style={{ background: 'var(--red-a3)', borderRadius: 'var(--radius-2)' }}>
                                        <ExclamationTriangleIcon color="var(--red-9)" />
                                        <Text size="1" color="red">Exceeds balance by {Math.abs(balanceImpact.remaining_balance)} days.</Text>
                                    </Flex>
                                )}
                            </Box>
                        )}
                    </>
                )}
            </Panel>

            {validationResults.length > 0 && !isValidating && (
                <Panel p="0" style={{ overflow: 'hidden' }}>
                    <Box px="3" pt="2">
                        <Panel.Header
                            actions={<Badge color="gray" variant="soft">{validationResults.length} dates</Badge>}
                        >
                            <Text size="2" weight="bold">Date Details</Text>
                        </Panel.Header>
                    </Box>
                    <ScrollArea type="auto" style={{ maxHeight: '200px' }}>
                        <Flex direction="column" p="2" gap="2">
                            {validationResults.map((result, index) => (
                                <Flex key={result.date ?? index} justify="between" align="start" p="2" style={{ background: 'var(--gray-a2)', borderRadius: 'var(--radius-2)' }}>
                                    <Flex align="center" gap="2">
                                        {getStatusIcon(result.status)}
                                        <Box>
                                            <Text size="2" weight="bold" style={{ display: 'block' }}>
                                                {new Date(result.date).toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' })}
                                            </Text>
                                        </Box>
                                    </Flex>
                                    <Flex direction="column" align="end" gap="1">
                                        <Badge color={result.status === 'valid' ? 'green' : result.status === 'warning' ? 'amber' : 'red'} variant="soft" style={{ textTransform: 'capitalize' }}>
                                            {result.status}
                                        </Badge>
                                        {result.errors?.map((e, i) => <Text key={i} size="1" color="red">{e}</Text>)}
                                        {result.warnings?.map((w, i) => <Text key={i} size="1" color="amber">{w}</Text>)}
                                    </Flex>
                                </Flex>
                            ))}
                        </Flex>
                    </ScrollArea>
                </Panel>
            )}
        </Flex>
    );
};

/* ── Main Modal Component ── */
export default function BulkLeaveModal({ 
    open, onClose, onSuccess, allUsers = [], departments = [],
    leavesData = { leaveTypes: [], leaveCountsByUser: {} }, isAdmin = false, existingLeaves = [], publicHolidays = []
}) {
    const { auth } = usePage().props;

    const [selectedDates, setSelectedDates] = useState([]);
    const [selectedDepartmentId, setSelectedDepartmentId] = useState(null);
    const [selectedUserId, setSelectedUserId] = useState(null);
    const [selectedLeaveType, setSelectedLeaveType] = useState('');
    const [reason, setReason] = useState('');
    const [allowPartialSuccess, setAllowPartialSuccess] = useState(false);
    
    const [userLeaveTypes, setUserLeaveTypes] = useState([]);
    const [loadingLeaveTypes, setLoadingLeaveTypes] = useState(false);
    
    const [validationResults, setValidationResults] = useState([]);
    const [balanceImpact, setBalanceImpact] = useState(null);
    const [isValidating, setIsValidating] = useState(false);
    const [hasValidated, setHasValidated] = useState(false);
    
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [errors, setErrors] = useState({});

    const leaveTypes = useMemo(() => userLeaveTypes.length > 0 ? userLeaveTypes : (leavesData?.leaveTypes || []), [userLeaveTypes, leavesData]);
    const leaveCounts = useMemo(() => {
        if (userLeaveTypes.length > 0) return userLeaveTypes.map(type => ({ leave_type: type.type, days_used: type.used, total_days: type.days, remaining_days: type.remaining }));
        return leavesData?.leaveCountsByUser?.[selectedUserId] || [];
    }, [userLeaveTypes, leavesData, selectedUserId]);

    const fetchUserLeaveTypes = useCallback(async (userId) => {
        if (!userId) return;
        setLoadingLeaveTypes(true);
        try {
            const response = await axios.get(route('leaves.bulk.leave-types'), { params: { user_id: userId, year: new Date().getFullYear() } });
            if (response.data.success) setUserLeaveTypes(response.data.leave_types);
        } catch { setUserLeaveTypes([]); } 
        finally { setLoadingLeaveTypes(false); }
    }, []);

    useEffect(() => {
        if (open && selectedUserId) fetchUserLeaveTypes(selectedUserId);
    }, [selectedUserId, open, fetchUserLeaveTypes]);

    useEffect(() => {
        if (open && auth?.user) {
            const currentUser = allUsers?.find(u => u.id === auth.user.id);
            if (currentUser?.department_id) {
                setSelectedDepartmentId(currentUser.department_id);
                setSelectedUserId(auth.user.id);
            }
        }
    }, [open]);  // intentionally runs only on open — sets current user as default

    useEffect(() => {
        if (!open) {
            setSelectedDates([]); setSelectedDepartmentId(null); setSelectedUserId(null);
            setSelectedLeaveType(''); setReason(''); setAllowPartialSuccess(false); setValidationResults([]);
            setBalanceImpact(null); setHasValidated(false); setErrors({}); setUserLeaveTypes([]);
        }
    }, [open, auth?.user?.id]);

    useEffect(() => {
        if (leaveTypes.length > 0 && !selectedLeaveType && open && selectedUserId) {
            const availableLeaveType = leaveTypes.find(lt => {
                if (userLeaveTypes.length > 0) return lt.remaining > 0;
                const leaveCount = leaveCounts?.find(lc => lc.leave_type === lt.type);
                return (leaveCount ? (lt.days - leaveCount.days_used) : lt.days) > 0;
            });
            if (availableLeaveType) setSelectedLeaveType(availableLeaveType.type);
        }
    }, [leaveTypes, leaveCounts, userLeaveTypes, selectedLeaveType, open, selectedUserId]);

    const handleValidate = useCallback(async () => {
        if (selectedDates.length === 0) return showToast.error('Please select at least one date');
        if (!selectedLeaveType) return showToast.error('Please select a leave type');
        if (!reason.trim() || reason.trim().length < 5) return showToast.error('Please provide a reason (at least 5 characters)');

        setIsValidating(true); setErrors({});
        try {
            const ltData = leaveTypes.find(lt => lt.type === selectedLeaveType);
            const response = await axios.post(route('leaves.bulk.validate'), {
                user_id: parseInt(selectedUserId), dates: selectedDates, leave_type_id: ltData?.id, reason: reason.trim()
            });
            if (response.data.success) {
                setValidationResults(response.data.validation_results);
                setBalanceImpact(response.data.estimated_balance_impact);
                setHasValidated(true);
                const conflicts = response.data.validation_results.filter(r => r.status === 'conflict').length;
                if (conflicts > 0) showToast.success(`${conflicts} date(s) have conflicts. Please review.`);
                else showToast.success('All dates validated successfully!');
            }
        } catch (error) {
            if (error.response?.status === 422) setErrors(error.response.data.errors || {});
            showToast.error(error.response?.data?.message || 'Failed to validate dates');
        } finally { setIsValidating(false); }
    }, [selectedDates, selectedLeaveType, reason, selectedUserId, leaveTypes]);

    const handleSubmit = useCallback(async () => {
        if (!hasValidated) return showToast.error('Please validate dates before submitting');
        if (validationResults.filter(r => r.status === 'conflict').length > 0 && !allowPartialSuccess) {
            return showToast.error('Please resolve conflicts or allow partial success');
        }

        setIsSubmitting(true);
        try {
            const ltData = leaveTypes.find(lt => lt.type === selectedLeaveType);
            const response = await axios.post(route('leaves.bulk.store'), {
                user_id: parseInt(selectedUserId), dates: selectedDates, leave_type_id: ltData?.id, reason: reason.trim(), allow_partial_success: allowPartialSuccess
            });
            onSuccess?.(response.data);
            onClose();
            showToast.success(response.data.message || 'Bulk leave requested successfully');
        } catch (error) {
            if (error.response?.status === 422) setErrors(error.response.data.errors || {});
            showToast.error(error.response?.data?.error || 'Failed to submit bulk requests');
        } finally { setIsSubmitting(false); }
    }, [hasValidated, validationResults, allowPartialSuccess, selectedUserId, selectedDates, selectedLeaveType, reason, onSuccess, onClose, leaveTypes]);

    const canValidate = selectedDates.length > 0 && selectedLeaveType && reason.trim().length >= 5;
    const canSubmit = hasValidated && (validationResults.filter(r => r.status === 'conflict').length === 0 || allowPartialSuccess);

    return (
        <Dialog.Root open={open} onOpenChange={v => { if (!v && !isSubmitting && !isValidating) onClose(); }}>
            <Dialog.Content style={{ maxWidth: 1000, width: '95vw', padding: 0, display: 'flex', flexDirection: 'column', maxHeight: '90vh', overflow: 'hidden' }}>
                
                {/* ── Header (Sticky) ── */}
                <Box px="4" py="3" style={{ borderBottom: '1px solid var(--gray-a4)', backgroundColor: 'var(--color-panel-solid)', zIndex: 10 }}>
                    <Flex justify="between" align="center">
                        <Flex align="center" gap="3">
                            <Box p="2" style={{ backgroundColor: 'var(--accent-a3)', borderRadius: 'var(--radius-2)' }}>
                                <CalendarIcon style={{ color: 'var(--accent-9)', width: 20, height: 20 }} />
                            </Box>
                            <Box>
                                <Text size="4" weight="bold" as="div">Bulk Leave Request</Text>
                                <Text size="1" color="gray" as="div">Select multiple dates to create requests in batch</Text>
                            </Box>
                        </Flex>
                        <Dialog.Close asChild>
                            <Button size="1" variant="ghost" color="gray" disabled={isSubmitting} style={{ cursor: 'pointer' }}><Cross2Icon /></Button>
                        </Dialog.Close>
                    </Flex>
                </Box>

                {/* ── Body (Scrollable) ── */}
                <ScrollArea type="auto" style={{ flex: 1, backgroundColor: 'var(--gray-1)' }}>
                    <Box p="4">
                        <Grid columns="2" gap="5">
                            
                            {/* Left Column: Calendar */}
                            <Box>
                                <Text size="3" weight="bold" as="div" mb="3">1. Select Dates</Text>
                                <BulkCalendar
                                    selectedDates={selectedDates}
                                    onDatesChange={(dates) => { setSelectedDates(dates); setHasValidated(false); }}
                                    userId={selectedUserId}
                                    fetchFromAPI={true}
                                />
                            </Box>

                            {/* Right Column: Form & Validation */}
                            <Flex direction="column" gap="4">
                                <Box>
                                    <Text size="3" weight="bold" as="div" mb="3">2. Leave Details</Text>
                                    <Panel>
                                        <Flex direction="column" gap="3">
                                            {isAdmin && (
                                                <DepartmentEmployeeSelector
                                                    selectedDepartmentId={selectedDepartmentId}
                                                    selectedEmployeeId={selectedUserId}
                                                    onDepartmentChange={setSelectedDepartmentId}
                                                    onEmployeeChange={(empId) => {
                                                        setSelectedUserId(empId); setSelectedLeaveType(''); setHasValidated(false); setUserLeaveTypes([]);
                                                    }}
                                                    allUsers={allUsers}
                                                    departments={departments}
                                                    showSearch={true}
                                                    error={errors}
                                                    variant="outlined"
                                                    showAllOption={false}
                                                    required={true}
                                                    autoSelectFirstDepartment={false}
                                                    disabled={isSubmitting || isValidating}
                                                />
                                            )}

                                            <Box>
                                                <Text as="label" size="2" weight="medium" mb="1" display="block">Leave Type <Text color="red">*</Text></Text>
                                                <Select.Root value={selectedLeaveType} onValueChange={(v) => { setSelectedLeaveType(v); setHasValidated(false); }} disabled={isSubmitting || isValidating || loadingLeaveTypes}>
                                                    <Select.Trigger style={{ width: '100%' }} placeholder={loadingLeaveTypes ? "Loading..." : "Select Leave Type"} />
                                                    <Select.Content>
                                                        {!loadingLeaveTypes && leaveTypes.map((type) => {
                                                            const remaining = userLeaveTypes.length > 0 ? type.remaining : (() => {
                                                                const lc = leaveCounts?.find(lc => lc.leave_type === type.type);
                                                                return lc ? (type.days - lc.days_used) : type.days;
                                                            })();
                                                            return (
                                                                <Select.Item key={type.type} value={type.type} disabled={remaining <= 0}>
                                                                    {type.type} ({remaining} remaining)
                                                                </Select.Item>
                                                            );
                                                        })}
                                                    </Select.Content>
                                                </Select.Root>
                                            </Box>

                                            <Box>
                                                <Text as="label" size="2" weight="medium" mb="1" display="block">Reason <Text color="red">*</Text></Text>
                                                <TextArea
                                                    placeholder="Provide a reason (min 5 characters)..."
                                                    value={reason}
                                                    onChange={(e) => { setReason(e.target.value); setHasValidated(false); }}
                                                    disabled={isSubmitting || isValidating}
                                                    style={{ height: '80px', resize: 'none' }}
                                                />
                                            </Box>

                                            <Panel tinted p="3">
                                                <Flex align="center" justify="between">
                                                    <Box>
                                                        <Text size="2" weight="bold" display="block">Allow Partial Success</Text>
                                                        <Text size="1" color="gray">Process valid dates even if some conflict</Text>
                                                    </Box>
                                                    <Switch size="2" checked={allowPartialSuccess} onCheckedChange={setAllowPartialSuccess} disabled={isSubmitting || isValidating} />
                                                </Flex>
                                            </Panel>
                                        </Flex>
                                    </Panel>
                                </Box>

                                <Box>
                                    <ValidationPreview validationResults={validationResults} balanceImpact={balanceImpact} isValidating={isValidating} />
                                </Box>
                            </Flex>
                        </Grid>
                    </Box>
                </ScrollArea>

                {/* ── Footer (Sticky) ── */}
                <Box px="4" py="3" style={{ borderTop: '1px solid var(--gray-a4)', backgroundColor: 'var(--color-panel-solid)', zIndex: 10 }}>
                    <Flex justify="between" align="center" wrap="wrap" gap="3">
                        <Box>
                            {validationResults.length > 0 && !hasValidated && !isValidating && (
                                <Flex align="center" gap="2">
                                    <ExclamationTriangleIcon color="var(--amber-9)" />
                                    <Text size="2" color="amber" weight="medium">Details changed. Please re-validate.</Text>
                                </Flex>
                            )}
                        </Box>

                        <Flex gap="3" style={{ width: '100%', justifyContent: 'flex-end' }}>
                            <Button variant="soft" color="gray" onClick={onClose} disabled={isSubmitting} style={{ cursor: 'pointer' }}>Cancel</Button>
                            
                            <Button variant="surface" color="indigo" onClick={handleValidate} disabled={!canValidate || isSubmitting} style={{ cursor: canValidate ? 'pointer' : 'not-allowed' }}>
                                {isValidating ? <Spinner size="1" /> : <MagnifyingGlassIcon />} Validate Dates
                            </Button>

                            <Button color="accent" onClick={handleSubmit} disabled={!canSubmit || isValidating} style={{ cursor: canSubmit ? 'pointer' : 'not-allowed' }}>
                                {isSubmitting ? <Spinner size="1" /> : <CheckCircledIcon />} 
                                {isSubmitting ? 'Submitting...' : `Submit ${selectedDates.length} Request(s)`}
                            </Button>
                        </Flex>
                    </Flex>
                </Box>
            </Dialog.Content>
        </Dialog.Root>
    );
}