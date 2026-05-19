/**
 * LeaveForm.jsx
 * Full Radix UI refactor.
 */
import React, { useState, useEffect } from 'react';
import { 
    Box, Button, Dialog, Flex, Grid, Select, 
    Spinner, Text, TextArea, TextField, Card 
} from '@radix-ui/themes';
import axios from 'axios';
import { showToast } from "@/utils/toastUtils";
import DepartmentEmployeeSelector from "@/Components/DepartmentEmployeeSelector.jsx";
import { usePage } from "@inertiajs/react";

const LeaveForm = ({
    open,
    closeModal,
    leavesData,
    setLeavesData,
    currentLeave,
    allUsers,
    departments = [],
    selectedMonth,
    addLeaveOptimized,
    updateLeaveOptimized,
    fetchLeavesStats
}) => {
    const { auth } = usePage().props;

    const [user_id, setUserId] = useState(currentLeave?.user_id || auth.user.id);
    const [selectedDepartmentId, setSelectedDepartmentId] = useState(() => {
        if (currentLeave?.user_id) {
            const leaveUser = allUsers?.find(user => user.id === currentLeave.user_id);
            return leaveUser?.department_id || null;
        }
        return auth?.user?.department_id || null;
    });

    const [leaveTypes, setLeaveTypes] = useState(leavesData?.leaveTypes || []);
    const [leaveCounts, setLeaveCounts] = useState([]);
    const [leaveType, setLeaveType] = useState(currentLeave?.leave_type || "");
    const [fromDate, setFromDate] = useState(currentLeave?.from_date ? currentLeave.from_date.split('T')[0] : '');
    const [toDate, setToDate] = useState(currentLeave?.to_date ? currentLeave.to_date.split('T')[0] : '');
    const [daysCount, setDaysCount] = useState(currentLeave?.no_of_days || '');
    const [remainingLeaves, setRemainingLeaves] = useState('');
    const [leaveReason, setLeaveReason] = useState(currentLeave?.reason || '');
    const [errors, setErrors] = useState({});
    const [processing, setProcessing] = useState(false);

    // Sync calculations
    useEffect(() => {
        const start = new Date(fromDate);
        const end = new Date(toDate);
        if (start && end && end >= start) {
            const diff = Math.ceil((end - start) / (1000 * 60 * 60 * 24)) + 1;
            setDaysCount(diff);
        } else {
            setDaysCount('');
        }
    }, [fromDate, toDate]);

    // Populate data
    useEffect(() => {
        if (leavesData) {
            setLeaveTypes(leavesData.leaveTypes || []);
            setLeaveCounts(leavesData.leaveCountsByUser?.[user_id] || []);
        }
    }, [leavesData, user_id]);

    // Compute remaining days whenever leaveType or leaveCounts change
    useEffect(() => {
        if (!leaveType) { setRemainingLeaves(''); return; }
        const type = leaveTypes.find(t => t.type === leaveType);
        if (!type) { setRemainingLeaves(''); return; }
        const lc = leaveCounts.find(l => l.leave_type === leaveType);
        const remaining = lc ? (type.days - lc.days_used) : type.days;
        setRemainingLeaves(remaining);
    }, [leaveType, leaveTypes, leaveCounts]);

    const handleSubmit = async (e) => {
        e.preventDefault();
        setProcessing(true);
        const apiRoute = currentLeave ? route('leave-update') : route('leave-add');
        const data = { user_id, leaveType, fromDate, toDate, daysCount, leaveReason, month: selectedMonth };
        if (currentLeave) data.id = currentLeave.id;

        try {
            const response = await axios.post(apiRoute, data);
            setLeavesData(response.data.leavesData);
            
            if (currentLeave && updateLeaveOptimized) updateLeaveOptimized(response.data.leave);
            else if (addLeaveOptimized) addLeaveOptimized(response.data.leave);
            
            fetchLeavesStats();
            showToast.success(response.data.message || 'Leave submitted successfully');
            closeModal();
        } catch (err) {
            if (err.response?.status === 422) setErrors(err.response.data.errors);
            else showToast.error('Failed to submit application');
        } finally {
            setProcessing(false);
        }
    };

    return (
        <Dialog.Root open={open} onOpenChange={v => !v && !processing && closeModal()}>
            <Dialog.Content style={{ maxWidth: 640, padding: 0 }}>
                <Box px="5" py="4" style={{ borderBottom: '1px solid var(--gray-a4)' }}>
                    <Dialog.Title size="4">{currentLeave ? 'Edit Leave' : 'Add Leave'}</Dialog.Title>
                </Box>

                <Box px="5" py="4">
                    <form onSubmit={handleSubmit}>
                        <Card variant="surface" mb="4">
                            <Grid columns={{ initial: '1', sm: '2' }} gap="4">
                                <Box>
                                    <Text size="2" weight="medium" mb="1" display="block">Leave Type</Text>
                                    <Select.Root value={leaveType} onValueChange={setLeaveType}>
                                        <Select.Trigger style={{ width: '100%' }} />
                                        <Select.Content>
                                            {leaveTypes.map((type) => {
                                                const lc = leaveCounts.find(l => l.leave_type === type.type);
                                                const remaining = lc ? (type.days - lc.days_used) : type.days;
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
                                    <Text size="2" weight="medium" mb="1" display="block">Remaining Days</Text>
                                    <TextField.Root value={remainingLeaves} readOnly />
                                </Box>

                                <Box>
                                    <Text size="2" weight="medium" mb="1" display="block">From</Text>
                                    <TextField.Root type="date" value={fromDate} onChange={e => setFromDate(e.target.value)} />
                                </Box>

                                <Box>
                                    <Text size="2" weight="medium" mb="1" display="block">To</Text>
                                    <TextField.Root type="date" value={toDate} onChange={e => setToDate(e.target.value)} />
                                </Box>

                                {route().current('leaves*') && (
                                    <Box style={{ gridColumn: '1 / -1' }}>
                                        <DepartmentEmployeeSelector
                                            selectedDepartmentId={selectedDepartmentId}
                                            selectedEmployeeId={user_id}
                                            onDepartmentChange={setSelectedDepartmentId}
                                            onEmployeeChange={setUserId}
                                            allUsers={allUsers}
                                            departments={departments}
                                            error={errors}
                                        />
                                    </Box>
                                )}

                                <Box style={{ gridColumn: '1 / -1' }}>
                                    <Text size="2" weight="medium" mb="1" display="block">Reason</Text>
                                    <TextArea value={leaveReason} onChange={e => setLeaveReason(e.target.value)} rows={3} />
                                </Box>
                            </Grid>
                        </Card>

                        <Flex justify="end" gap="3">
                            <Button variant="soft" color="gray" onClick={closeModal} disabled={processing}>Cancel</Button>
                            <Button type="submit" disabled={processing}>
                                {processing ? <Spinner size="1" /> : 'Submit'}
                            </Button>
                        </Flex>
                    </form>
                </Box>
            </Dialog.Content>
        </Dialog.Root>
    );
};

export default LeaveForm;