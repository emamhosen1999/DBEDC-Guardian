import React, { useState, useEffect, useMemo, useCallback } from 'react';
import {
    Select,
    SelectItem,
    Input,
    Chip
} from '@heroui/react';
import { 
    MagnifyingGlassIcon
} from '@heroicons/react/24/outline';
import axios from 'axios';
import ProfileAvatar from '@/Components/ProfileAvatar';

const DepartmentMemberSelector = ({
    selectedDepartmentId,
    selectedMemberId,
    onDepartmentChange,
    onMemberChange,
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
        employee: 'Member'
    },
    showAllOption = true,
    autoSelectFirstDepartment = true,
    className = '',
    theme
}) => {
    // Helper function to convert theme borderRadius to HeroUI radius values
    const getThemeRadius = () => {
        if (typeof window === 'undefined') return 'lg';
        
        const rootStyles = getComputedStyle(document.documentElement);
        const borderRadius = rootStyles.getPropertyValue('--borderRadius')?.trim() || '12px';
        
        const radiusValue = parseInt(borderRadius);
        if (radiusValue === 0) return 'none';
        if (radiusValue <= 4) return 'sm';
        if (radiusValue <= 8) return 'md';
        if (radiusValue <= 16) return 'lg';
        return 'full';
    };

    const [departmentSearchTerm, setDepartmentSearchTerm] = useState('');
    const [employeeSearchTerm, setMemberSearchTerm] = useState('');
    const [loadingWorkForce, setLoadingWorkForce] = useState(false);

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
    const departmentWorkForce = useMemo(() => {
        if (!selectedDepartmentId) return showAllOption ? allUsers : [];
        return allUsers.filter(user => 
            String(user.department_id) === String(selectedDepartmentId)
        );
    }, [allUsers, selectedDepartmentId, showAllOption]);

    // Filtered employees based on search
    const filteredWorkForce = useMemo(() => {
        if (!employeeSearchTerm.trim()) return departmentWorkForce;
        return departmentWorkForce.filter(user =>
            user.name.toLowerCase().includes(employeeSearchTerm.toLowerCase()) ||
            user.email?.toLowerCase().includes(employeeSearchTerm.toLowerCase()) ||
            user.id.toString().includes(employeeSearchTerm) ||
            user.designation?.toLowerCase().includes(employeeSearchTerm.toLowerCase())
        );
    }, [departmentWorkForce, employeeSearchTerm]);

    // Reset employee selection when department changes
    useEffect(() => {
        if (selectedDepartmentId && selectedMemberId) {
            const isMemberInDepartment = departmentWorkForce.some(
                emp => String(emp.id) === String(selectedMemberId)
            );
            if (!isMemberInDepartment) {
                onMemberChange('');
            }
        }
    }, [selectedDepartmentId, selectedMemberId, departmentWorkForce, onMemberChange]);

    return (
        <div className={`grid grid-cols-1 md:grid-cols-2 gap-4 ${className}`}>
            {/* Department Selector */}
            <div>
                <Select
                    label={label.department}
                    placeholder="Select Department"
                    selectionMode="single"
                    selectedKeys={selectedDepartmentId != null ? new Set([String(selectedDepartmentId)]) : new Set()}
                    onSelectionChange={(keys) => {
                        const deptId = Array.from(keys)[0];
                        const parsedId = deptId === '' || deptId === undefined || deptId === null ? null : parseInt(deptId);
                        onDepartmentChange(parsedId);
                        if (selectedMemberId) {
                            onMemberChange(null);
                        }
                        setDepartmentSearchTerm('');
                        setMemberSearchTerm('');
                    }}
                    isDisabled={disabled}
                    isRequired={required}
                    isInvalid={Boolean(error.department_id)}
                    errorMessage={error.department_id}
                    variant="bordered"
                    size={size === 'medium' ? 'sm' : size}
                    radius={getThemeRadius()}
                    className="w-full"
                    classNames={{
                        trigger: "text-sm",
                    }}
                    style={{
                        fontFamily: `var(--fontFamily, "Inter")`,
                    }}
                >
                    {showAllOption && (
                        <SelectItem key="" value="" textValue="All Departments">
                            <span>All Departments</span>
                        </SelectItem>
                    )}
                    
                    {filteredDepartments.map((department) => (
                        <SelectItem 
                            key={String(department.id)} 
                            value={String(department.id)}
                            textValue={department.name}
                        >
                            <div className="flex items-center justify-between w-full">
                                <span>{department.name}</span>
                                <Chip 
                                    size="sm" 
                                    variant="bordered"
                                    className="ml-auto text-xs"
                                >
                                    {allUsers.filter(u => u.department_id === department.id).length}
                                </Chip>
                            </div>
                        </SelectItem>
                    ))}
                </Select>
            </div>

            {/* Member Selector */}
            <div>
                <Select
                    label={label.employee}
                    placeholder={(!selectedDepartmentId && !showAllOption) ? "Select department first" : "Select Member"}
                    selectionMode="single"
                    selectedKeys={selectedMemberId != null ? new Set([String(selectedMemberId)]) : new Set()}
                    onSelectionChange={(keys) => {
                        const empId = Array.from(keys)[0];
                        const parsedId = empId === '' || empId === undefined || empId === null ? null : parseInt(empId);
                        onMemberChange(parsedId);
                        setMemberSearchTerm('');
                    }}
                    isDisabled={disabled || loadingWorkForce || (!selectedDepartmentId && !showAllOption)}
                    isRequired={required}
                    isInvalid={Boolean(error.user_id)}
                    errorMessage={error.user_id}
                    variant="bordered"
                    size={size === 'medium' ? 'sm' : size}
                    radius={getThemeRadius()}
                    className="w-full"
                    classNames={{
                        trigger: "text-sm",
                    }}
                    style={{
                        fontFamily: `var(--fontFamily, "Inter")`,
                    }}
                >
                    {showAllOption && (
                        <SelectItem key="" value="" textValue={selectedDepartmentId ? 'All WorkForce in Department' : 'Select Department First'}>
                            <span>
                                {selectedDepartmentId ? 'All WorkForce in Department' : 'Select Department First'}
                            </span>
                        </SelectItem>
                    )}
                    
                    {filteredWorkForce.map((user) => (
                        <SelectItem 
                            key={String(user.id)} 
                            value={String(user.id)}
                            textValue={user.name}
                        >
                            <div className="flex items-center gap-2 w-full">
                                <ProfileAvatar
                                    src={user.profile_image_url || user.profile_image}
                                    size="sm"
                                    name={user.name}
                                />
                                <div className="flex flex-col flex-1">
                                    <span className="text-sm font-medium">{user.name}</span>
                                    {user.designation && (
                                        <span className="text-xs text-gray-500">{user.designation}</span>
                                    )}
                                </div>
                                {user.department && (
                                    <Chip 
                                        size="sm"
                                        variant="bordered"
                                        className="text-xs h-5"
                                    >
                                        {user.department}
                                    </Chip>
                                )}
                            </div>
                        </SelectItem>
                    ))}
                    
                    {filteredWorkForce.length === 0 && (
                        <SelectItem key="no-employees" isDisabled>
                            <span className="text-sm text-gray-500">
                                {selectedDepartmentId 
                                    ? 'No employees found in selected department'
                                    : 'No employees found'
                                }
                            </span>
                        </SelectItem>
                    )}
                </Select>
            </div>
        </div>
    );
};

export default DepartmentMemberSelector;
