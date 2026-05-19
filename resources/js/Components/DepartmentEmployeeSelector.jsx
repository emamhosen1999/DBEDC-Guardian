import React, { useState, useEffect, useMemo } from 'react';
import { Avatar, Badge, Box, Flex, Grid, Select, Text, TextField } from '@radix-ui/themes';
import { MagnifyingGlassIcon, PersonIcon } from '@radix-ui/react-icons';

const DepartmentEmployeeSelector = ({
    selectedDepartmentId,
    selectedEmployeeId,
    onDepartmentChange,
    onEmployeeChange,
    allUsers = [],
    departments = [],
    showSearch = true,
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
}) => {
    const [departmentSearchTerm, setDepartmentSearchTerm] = useState('');
    const [employeeSearchTerm, setEmployeeSearchTerm] = useState('');

    // Auto-select first department
    useEffect(() => {
        if (autoSelectFirstDepartment && departments.length > 0 && !selectedDepartmentId) {
            const firstDepartment = departments[0];
            if (firstDepartment) onDepartmentChange(firstDepartment.id);
        }
    }, [departments, selectedDepartmentId, onDepartmentChange, autoSelectFirstDepartment]);

    // Search filters
    const filteredDepartments = useMemo(() => {
        if (!departmentSearchTerm.trim()) return departments;
        const term = departmentSearchTerm.toLowerCase();
        return departments.filter(dept =>
            dept.name?.toLowerCase().includes(term) || String(dept.id).includes(term)
        );
    }, [departments, departmentSearchTerm]);

    // Only surface active users (active === false means inactive; undefined = not provided = treat as active)
    const activeUsers = useMemo(() => allUsers.filter(u => u.active !== false), [allUsers]);

    // Strictly filter employees by selected department
    const departmentEmployees = useMemo(() => {
        if (!selectedDepartmentId) return showAllOption ? activeUsers : [];
        return activeUsers.filter(user =>
            String(user.department_id || user.department?.id) === String(selectedDepartmentId)
        );
    }, [activeUsers, selectedDepartmentId, showAllOption]);

    // Safety helper to extract names from objects
    const getSafeName = (val) => typeof val === 'object' && val !== null ? (val.name || val.title || '') : (val || '');

    const filteredEmployees = useMemo(() => {
        if (!employeeSearchTerm.trim()) return departmentEmployees;
        const term = employeeSearchTerm.toLowerCase();
        return departmentEmployees.filter(user => {
            const designationName = getSafeName(user.designation);
            return (
                user.name?.toLowerCase().includes(term) ||
                user.email?.toLowerCase().includes(term) ||
                String(user.id).includes(term) ||
                designationName.toLowerCase().includes(term)
            );
        });
    }, [departmentEmployees, employeeSearchTerm]);

    // Reset employee on department change
    useEffect(() => {
        if (selectedDepartmentId && selectedEmployeeId) {
            const isEmployeeInDepartment = departmentEmployees.some(emp => String(emp.id) === String(selectedEmployeeId));
            if (!isEmployeeInDepartment) onEmployeeChange('');
        }
    }, [selectedDepartmentId, selectedEmployeeId, departmentEmployees, onEmployeeChange]);

    const selectedEmployee = useMemo(() => activeUsers.find(u => String(u.id) === String(selectedEmployeeId)), [activeUsers, selectedEmployeeId]);
    const isEmployeeDisabled = disabled || (!selectedDepartmentId && !showAllOption);

    // CRITICAL FIX: Radix UI requires `undefined` to trigger the placeholder if no valid option is selected
    const deptValue = selectedDepartmentId ? String(selectedDepartmentId) : (showAllOption ? "all" : undefined);
    const empValue = selectedEmployeeId ? String(selectedEmployeeId) : (showAllOption && !selectedDepartmentId ? "all" : undefined);

    return (
        <Grid columns={{ initial: '1', sm: '2' }} gap="4" className={className}>
            {/* ── Department Selector ── */}
            <Box>
                <Text as="label" size="2" weight="medium" mb="1" display="block">
                    {label.department} {required && <Text as="span" color="red">*</Text>}
                </Text>
                <Select.Root
                    size="2"
                    value={deptValue}
                    onValueChange={(val) => {
                        const parsedId = (val === 'all' || val === '') ? null : parseInt(val);
                        onDepartmentChange(parsedId);
                        if (selectedEmployeeId) onEmployeeChange(null);
                        setDepartmentSearchTerm('');
                    }}
                    disabled={disabled}
                >
                    <Select.Trigger placeholder="Select Department" style={{ width: '100%' }} />
                    <Select.Content>
                        {showSearch && departments.length > 5 && (
                            <Box px="2" py="1" mb="1" style={{ borderBottom: '1px solid var(--gray-a4)' }}>
                                <TextField.Root size="1" placeholder="Search departments..." value={departmentSearchTerm} onChange={(e) => setDepartmentSearchTerm(e.target.value)} onClick={e => e.stopPropagation()} onKeyDown={e => e.stopPropagation()}>
                                    <TextField.Slot><MagnifyingGlassIcon style={{ width: 12, height: 12 }} /></TextField.Slot>
                                </TextField.Root>
                            </Box>
                        )}
                        {showAllOption && <Select.Item value="all">All Departments</Select.Item>}
                        {filteredDepartments.length === 0 ? (
                            <Select.Item value="__no_depts" disabled>No departments found</Select.Item>
                        ) : (
                            filteredDepartments.map((department) => {
                                const count = activeUsers.filter(u => String(u.department_id || u.department?.id) === String(department.id)).length;
                                return (
                                    <Select.Item key={String(department.id)} value={String(department.id)} textValue={department.name}>
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
                {error.department_id && <Text size="1" color="red" mt="1" display="block">{error.department_id}</Text>}
            </Box>

            {/* ── Employee Selector ── */}
            <Box>
                <Text as="label" size="2" weight="medium" mb="1" display="block">
                    {label.employee} {required && <Text as="span" color="red">*</Text>}
                </Text>
                <Select.Root
                    size="2"
                    value={empValue}
                    onValueChange={(val) => {
                        const parsedId = (val === 'all' || val === '') ? null : parseInt(val);
                        onEmployeeChange(parsedId);
                        setEmployeeSearchTerm('');
                    }}
                    disabled={isEmployeeDisabled}
                >
                    <Select.Trigger placeholder={isEmployeeDisabled && !disabled ? 'Select department first' : 'Select Employee'} style={{ width: '100%' }} />
                    <Select.Content>
                        {showSearch && departmentEmployees.length > 5 && (
                            <Box px="2" py="1" mb="1" style={{ borderBottom: '1px solid var(--gray-a4)' }}>
                                <TextField.Root size="1" placeholder="Search employees..." value={employeeSearchTerm} onChange={(e) => setEmployeeSearchTerm(e.target.value)} onClick={e => e.stopPropagation()} onKeyDown={e => e.stopPropagation()}>
                                    <TextField.Slot><MagnifyingGlassIcon style={{ width: 12, height: 12 }} /></TextField.Slot>
                                </TextField.Root>
                            </Box>
                        )}
                        {showAllOption && <Select.Item value="all">{selectedDepartmentId ? 'All Employees in Dept.' : 'All Employees'}</Select.Item>}
                        {filteredEmployees.length === 0 ? (
                            <Select.Item value="__no_emp" disabled>{selectedDepartmentId ? 'No employees in this department' : 'No employees found'}</Select.Item>
                        ) : (
                            filteredEmployees.map((user) => (
                                <Select.Item key={String(user.id)} value={String(user.id)} textValue={user.name}>
                                    <Flex align="center" gap="2">
                                        <Avatar size="1" src={user.profile_image_url || user.profile_image} fallback={user.name?.[0]?.toUpperCase() ?? <PersonIcon />} radius="full" style={{ flexShrink: 0 }} />
                                        <Box>
                                            <Text size="2" weight="medium" display="block">{user.name}</Text>
                                            {getSafeName(user.designation) && <Text size="1" color="gray" display="block">{getSafeName(user.designation)}</Text>}
                                        </Box>
                                        {/* REMOVED: Department Chip */}
                                    </Flex>
                                </Select.Item>
                            ))
                        )}
                    </Select.Content>
                </Select.Root>

                
                {error.user_id && <Text size="1" color="red" mt="1" display="block">{error.user_id}</Text>}
            </Box>
        </Grid>
    );
};

export default DepartmentEmployeeSelector;