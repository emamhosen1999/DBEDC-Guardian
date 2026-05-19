import React, { useState, useEffect, useMemo } from 'react';
import { Avatar, Badge, Box, Flex, Select, Text, TextField } from '@radix-ui/themes';
import { MagnifyingGlassIcon, PersonIcon } from '@radix-ui/react-icons';
import ProfileAvatar from '@/Components/ProfileAvatar';

const DepartmentEmployeeSelector = ({
    selectedDepartmentId,
    selectedEmployeeId,
    onDepartmentChange,
    onEmployeeChange,
    allUsers = [],
    departments = [],
    showSearch = true,
    variant = 'outlined',
    size = 'medium',
    disabled = false,
    required = false,
    error = {},
    label = {
        department: 'Department',
        employee: 'Employee'
    },
    showAllOption = true,
    autoSelectFirstDepartment = true,
    className = '',
    theme
}) => {
    const [departmentSearchTerm, setDepartmentSearchTerm] = useState('');
    const [employeeSearchTerm, setEmployeeSearchTerm] = useState('');

    // Auto-select first department if none selected and autoSelectFirstDepartment is true
    useEffect(() => {
        if (autoSelectFirstDepartment && departments.length > 0 && !selectedDepartmentId) {
            const firstDepartment = departments[0];
            if (firstDepartment) {
                onDepartmentChange(firstDepartment.id);
            }
        }
    }, [departments, selectedDepartmentId, onDepartmentChange, autoSelectFirstDepartment]);

    // Filtered departments based on search
    const filteredDepartments = useMemo(() => {
        if (!departmentSearchTerm.trim()) return departments;
        return departments.filter(dept =>
            dept.name.toLowerCase().includes(departmentSearchTerm.toLowerCase()) ||
            dept.id.toString().includes(departmentSearchTerm)
        );
    }, [departments, departmentSearchTerm]);

    // Get employees for selected department
    const departmentEmployees = useMemo(() => {
        if (!selectedDepartmentId) return showAllOption ? allUsers : [];
        return allUsers.filter(user =>
            String(user.department_id) === String(selectedDepartmentId)
        );
    }, [allUsers, selectedDepartmentId, showAllOption]);

    // Filtered employees based on search
    const filteredEmployees = useMemo(() => {
        if (!employeeSearchTerm.trim()) return departmentEmployees;
        return departmentEmployees.filter(user =>
            user.name.toLowerCase().includes(employeeSearchTerm.toLowerCase()) ||
            user.email?.toLowerCase().includes(employeeSearchTerm.toLowerCase()) ||
            user.id.toString().includes(employeeSearchTerm) ||
            user.designation?.toLowerCase().includes(employeeSearchTerm.toLowerCase())
        );
    }, [departmentEmployees, employeeSearchTerm]);

    // Reset employee selection when department changes
    useEffect(() => {
        if (selectedDepartmentId && selectedEmployeeId) {
            const isEmployeeInDepartment = departmentEmployees.some(
                emp => String(emp.id) === String(selectedEmployeeId)
            );
            if (!isEmployeeInDepartment) {
                onEmployeeChange('');
            }
        }
    }, [selectedDepartmentId, selectedEmployeeId, departmentEmployees, onEmployeeChange]);

    const selectedEmployee = useMemo(() =>
        allUsers.find(u => String(u.id) === String(selectedEmployeeId)),
        [allUsers, selectedEmployeeId]
    );

    const isEmployeeDisabled = disabled || (!selectedDepartmentId && !showAllOption);

    return (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(2, minmax(0, 1fr))', gap: 12 }}>
            {/* Department Selector */}
            <Box>
                <Text
                    as="label"
                    size="1"
                    weight="medium"
                    style={{ display: 'block', marginBottom: 4 }}
                >
                    {label.department}
                    {required && <Text as="span" color="red"> *</Text>}
                </Text>

                <Select.Root
                    value={selectedDepartmentId != null ? String(selectedDepartmentId) : ''}
                    onValueChange={(val) => {
                        const parsedId = val === '' ? null : parseInt(val);
                        onDepartmentChange(parsedId);
                        if (selectedEmployeeId) onEmployeeChange(null);
                        setDepartmentSearchTerm('');
                        setEmployeeSearchTerm('');
                    }}
                    disabled={disabled}
                >
                    <Select.Trigger
                        placeholder="Select Department"
                        style={{ width: '100%' }}
                    />
                    <Select.Content>
                        {/* Search box */}
                        {showSearch && departments.length > 5 && (
                            <Box px="2" py="1" style={{ borderBottom: '1px solid var(--gray-a4)' }}>
                                <TextField.Root
                                    size="1"
                                    placeholder="Search departments..."
                                    value={departmentSearchTerm}
                                    onChange={(e) => setDepartmentSearchTerm(e.target.value)}
                                    onClick={(e) => e.stopPropagation()}
                                    onKeyDown={(e) => e.stopPropagation()}
                                >
                                    <TextField.Slot>
                                        <MagnifyingGlassIcon style={{ width: 12, height: 12 }} />
                                    </TextField.Slot>
                                </TextField.Root>
                            </Box>
                        )}

                        {showAllOption && (
                            <Select.Item value="">All Departments</Select.Item>
                        )}

                        {filteredDepartments.length === 0 ? (
                            <Select.Item value="__no_depts" disabled>
                                No departments found
                            </Select.Item>
                        ) : (
                            filteredDepartments.map((department) => {
                                const count = allUsers.filter(u => u.department_id === department.id).length;
                                return (
                                    <Select.Item
                                        key={String(department.id)}
                                        value={String(department.id)}
                                        textValue={department.name}
                                    >
                                        <Flex justify="between" align="center" gap="3" style={{ width: '100%' }}>
                                            <Text size="2">{department.name}</Text>
                                            <Badge color="gray" variant="soft" size="1">{count}</Badge>
                                        </Flex>
                                    </Select.Item>
                                );
                            })
                        )}
                    </Select.Content>
                </Select.Root>

                {error.department_id && (
                    <Text size="1" color="red" style={{ display: 'block', marginTop: 4 }}>
                        {error.department_id}
                    </Text>
                )}
            </Box>

            {/* Employee Selector */}
            <Box>
                <Text
                    as="label"
                    size="1"
                    weight="medium"
                    style={{ display: 'block', marginBottom: 4 }}
                >
                    {label.employee}
                    {required && <Text as="span" color="red"> *</Text>}
                </Text>

                <Select.Root
                    value={selectedEmployeeId != null ? String(selectedEmployeeId) : ''}
                    onValueChange={(val) => {
                        const parsedId = val === '' ? null : parseInt(val);
                        onEmployeeChange(parsedId);
                        setEmployeeSearchTerm('');
                    }}
                    disabled={isEmployeeDisabled}
                >
                    <Select.Trigger
                        placeholder={isEmployeeDisabled && !disabled ? 'Select department first' : 'Select Employee'}
                        style={{ width: '100%' }}
                    />
                    <Select.Content>
                        {/* Search box */}
                        {showSearch && departmentEmployees.length > 5 && (
                            <Box px="2" py="1" style={{ borderBottom: '1px solid var(--gray-a4)' }}>
                                <TextField.Root
                                    size="1"
                                    placeholder="Search employees..."
                                    value={employeeSearchTerm}
                                    onChange={(e) => setEmployeeSearchTerm(e.target.value)}
                                    onClick={(e) => e.stopPropagation()}
                                    onKeyDown={(e) => e.stopPropagation()}
                                >
                                    <TextField.Slot>
                                        <MagnifyingGlassIcon style={{ width: 12, height: 12 }} />
                                    </TextField.Slot>
                                </TextField.Root>
                            </Box>
                        )}

                        {showAllOption && (
                            <Select.Item value="">
                                {selectedDepartmentId ? 'All Employees in Dept.' : 'All Employees'}
                            </Select.Item>
                        )}

                        {filteredEmployees.length === 0 ? (
                            <Select.Item value="__no_emp" disabled>
                                {selectedDepartmentId
                                    ? 'No employees in this department'
                                    : 'No employees found'}
                            </Select.Item>
                        ) : (
                            filteredEmployees.map((user) => (
                                <Select.Item
                                    key={String(user.id)}
                                    value={String(user.id)}
                                    textValue={user.name}
                                >
                                    <Flex align="center" gap="2">
                                        <Avatar
                                            size="1"
                                            src={user.profile_image_url || user.profile_image}
                                            fallback={user.name?.[0]?.toUpperCase() ?? <PersonIcon />}
                                            radius="full"
                                            style={{ flexShrink: 0 }}
                                        />
                                        <Box>
                                            <Text size="2" weight="medium" style={{ display: 'block' }}>
                                                {user.name}
                                            </Text>
                                            {user.designation && (
                                                <Text size="1" color="gray">
                                                    {user.designation}
                                                </Text>
                                            )}
                                        </Box>
                                        {user.department && (
                                            <Badge color="gray" variant="outline" size="1" style={{ marginLeft: 'auto', flexShrink: 0 }}>
                                                {user.department}
                                            </Badge>
                                        )}
                                    </Flex>
                                </Select.Item>
                            ))
                        )}
                    </Select.Content>
                </Select.Root>

                {/* Selected employee preview */}
                {selectedEmployee && (
                    <Flex
                        align="center"
                        gap="2"
                        mt="2"
                        px="2"
                        py="1"
                        style={{
                            background: 'var(--accent-a2)',
                            borderRadius: 'var(--radius-2)',
                            border: '1px solid var(--accent-a4)',
                        }}
                    >
                        <Avatar
                            size="1"
                            src={selectedEmployee.profile_image_url || selectedEmployee.profile_image}
                            fallback={selectedEmployee.name?.[0]?.toUpperCase() ?? <PersonIcon />}
                            radius="full"
                        />
                        <Box style={{ minWidth: 0 }}>
                            <Text size="1" weight="medium" style={{ display: 'block', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                                {selectedEmployee.name}
                            </Text>
                            {selectedEmployee.designation && (
                                <Text size="1" color="gray" style={{ display: 'block', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                                    {selectedEmployee.designation}
                                </Text>
                            )}
                        </Box>
                    </Flex>
                )}

                {error.user_id && (
                    <Text size="1" color="red" style={{ display: 'block', marginTop: 4 }}>
                        {error.user_id}
                    </Text>
                )}
            </Box>
        </div>
    );
};

export default DepartmentEmployeeSelector;
