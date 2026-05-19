import React from 'react';
import { Badge, Box, Button, Dialog, Flex, Text } from '@radix-ui/themes';
import { ExclamationTriangleIcon, TrashIcon } from '@radix-ui/react-icons';
import ProfileAvatar from './Profile/ProfileAvatar';

const DeleteEmployeeModal = ({ 
    open, 
    onClose, 
    employee, 
    onConfirm,
    loading = false 
}) => {

    
    if (!employee) return null;

    const hasActiveData = employee.active_projects_count > 0 || 
                         employee.pending_leaves_count > 0 || 
                         employee.active_trainings_count > 0;

    return (
        <Dialog.Root open={open} onOpenChange={v => { if (!v) onClose?.(); }}>
            <Dialog.Content style={{ maxWidth: 520 }}>
                <Dialog.Title>
                    <Flex align="center" gap="2">
                        <Box style={{ padding: 8, background: 'var(--red-a3)', borderRadius: 'var(--radius-2)', display: 'flex' }}>
                            <ExclamationTriangleIcon style={{ color: 'var(--red-9)' }} />
                        </Box>
                        Delete Employee
                    </Flex>
                </Dialog.Title>

                {/* Employee info */}
                <Box p="3" mb="3" style={{ background: 'var(--gray-a2)', borderRadius: 'var(--radius-2)', border: '1px solid var(--gray-a4)' }}>
                    <Flex align="center" gap="3" mb="3">
                        <ProfileAvatar src={employee.profile_image_url || employee.profile_image} name={employee.name} size="md" />
                        <Box>
                            <Text weight="medium" style={{ display: 'block' }}>{employee.name}</Text>
                            <Text size="2" color="gray">{employee.email}</Text>
                            {employee.employee_id && <Text size="1" color="gray">ID: {employee.employee_id}</Text>}
                        </Box>
                    </Flex>
                    <Flex gap="3">
                        <Box style={{ flex: 1 }}>
                            <Text size="1" color="gray" style={{ display: 'block' }}>Department</Text>
                            <Text size="2" weight="medium">{employee.department_name || 'Not assigned'}</Text>
                        </Box>
                        <Box style={{ flex: 1 }}>
                            <Text size="1" color="gray" style={{ display: 'block' }}>Designation</Text>
                            <Text size="2" weight="medium">{employee.designation_name || 'Not assigned'}</Text>
                        </Box>
                    </Flex>
                </Box>

                {/* Danger warning */}
                <Box p="3" mb="3" style={{ background: 'var(--red-a2)', borderRadius: 'var(--radius-2)', border: '1px solid var(--red-a5)' }}>
                    <Flex align="center" gap="2">
                        <ExclamationTriangleIcon style={{ color: 'var(--red-9)', flexShrink: 0 }} />
                        <Text size="2" color="red">This action will permanently delete the employee record and cannot be undone.</Text>
                    </Flex>
                </Box>

                {/* Active data warning */}
                {hasActiveData && (
                    <Box p="3" mb="3" style={{ background: 'var(--amber-a2)', borderRadius: 'var(--radius-2)', border: '1px solid var(--amber-a5)' }}>
                        <Text size="2" weight="medium" color="amber" style={{ display: 'block', marginBottom: 8 }}>Employee Has Active Data</Text>
                        <Text size="2" color="gray" style={{ display: 'block', marginBottom: 8 }}>The following active records will also be affected:</Text>
                        <Flex gap="2" wrap="wrap">
                            {employee.active_projects_count > 0 && <Badge color="amber" variant="soft">{employee.active_projects_count} Active Projects</Badge>}
                            {employee.pending_leaves_count > 0 && <Badge color="amber" variant="soft">{employee.pending_leaves_count} Pending Leaves</Badge>}
                            {employee.active_trainings_count > 0 && <Badge color="amber" variant="soft">{employee.active_trainings_count} Active Trainings</Badge>}
                        </Flex>
                    </Box>
                )}

                <Text size="2" mb="4" style={{ display: 'block' }}>
                    Are you sure you want to delete <Text weight="bold" color="red">{employee.name}</Text>?
                </Text>

                <Flex justify="end" gap="2">
                    <Button variant="soft" color="gray" onClick={onClose} disabled={loading} style={{ cursor: 'pointer' }}>Cancel</Button>
                    <Button color="red" onClick={onConfirm} disabled={loading} style={{ cursor: 'pointer' }}>
                        <TrashIcon /> {loading ? 'Deleting...' : 'Delete Employee'}
                    </Button>
                </Flex>
            </Dialog.Content>
        </Dialog.Root>
    );
};

export default DeleteEmployeeModal;
