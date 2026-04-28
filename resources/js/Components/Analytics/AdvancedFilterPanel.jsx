import React from 'react';
import { motion } from 'framer-motion';
import {
    Card,
    CardBody,
    Input,
    Select,
    SelectItem,
    DatePicker,
    Button,
    Chip
} from "@heroui/react";
import { XMarkIcon, FunnelIcon } from "@heroicons/react/24/outline";

// Helper function to get theme radius
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

// Get theme-aware card style
const getCardStyle = () => ({
    background: `linear-gradient(135deg, 
        var(--theme-content1, #FAFAFA) 20%, 
        var(--theme-content2, #F4F4F5) 10%, 
        var(--theme-content3, #F1F3F4) 20%)`,
    borderColor: `transparent`,
    borderWidth: `var(--borderWidth, 2px)`,
    borderRadius: `var(--borderRadius, 12px)`,
    fontFamily: `var(--fontFamily, "Inter")`,
    transform: `scale(var(--scale, 1))`,
    transition: 'all 0.2s ease-in-out',
    boxShadow: '0 2px 8px rgba(0, 0, 0, 0.1)',
});

const AdvancedFilterPanel = ({ filters, onFilterChange }) => {
    const handleClearField = (field) => {
        onFilterChange({ [field]: null });
    };

    const statusOptions = [
        { key: 'pending', label: 'Pending' },
        { key: 'in_progress', label: 'In Progress' },
        { key: 'completed', label: 'Completed' },
        { key: 'overdue', label: 'Overdue' }
    ];

    const typeOptions = [
        { key: 'inspection', label: 'Inspection' },
        { key: 'survey', label: 'Survey' },
        { key: 'testing', label: 'Testing' },
        { key: 'documentation', label: 'Documentation' }
    ];

    const inputStyle = { fontFamily: `var(--fontFamily, "Inter")` };
    const labelStyle = { 
        color: 'var(--theme-foreground, #11181C)', 
        fontFamily: `var(--fontFamily, "Inter")` 
    };

    return (
        <Card 
            radius={getThemeRadius()}
            style={getCardStyle()}
        >
            <CardBody>
                <div className="flex items-center gap-2 mb-4">
                    <FunnelIcon className="w-5 h-5" style={{ color: 'var(--theme-primary, #006FEE)' }} />
                    <h4 
                        className="text-base font-semibold"
                        style={labelStyle}
                    >
                        Advanced Filters
                    </h4>
                </div>
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    {/* Search */}
                    <div className="space-y-2">
                        <label className="text-sm font-medium" style={labelStyle}>Search</label>
                        <Input
                            placeholder="Search by work ID, location, type..."
                            value={filters.search || ''}
                            onValueChange={(value) => onFilterChange({ search: value })}
                            variant="bordered"
                            size="sm"
                            radius={getThemeRadius()}
                            style={inputStyle}
                            classNames={{ input: "text-sm" }}
                            endContent={
                                filters.search && (
                                    <Button
                                        isIconOnly
                                        size="sm"
                                        variant="light"
                                        onPress={() => handleClearField('search')}
                                    >
                                        <XMarkIcon className="w-4 h-4" />
                                    </Button>
                                )
                            }
                        />
                    </div>

                    {/* Date Range - Start */}
                    <div className="space-y-2">
                        <label className="text-sm font-medium" style={labelStyle}>Start Date</label>
                        <DatePicker
                            variant="bordered"
                            size="sm"
                            radius={getThemeRadius()}
                            value={filters.start_date ? new Date(filters.start_date) : null}
                            onChange={(date) => onFilterChange({ start_date: date ? date.toISOString() : null })}
                            className="w-full"
                            style={inputStyle}
                        />
                    </div>

                    {/* Date Range - End */}
                    <div className="space-y-2">
                        <label className="text-sm font-medium" style={labelStyle}>End Date</label>
                        <DatePicker
                            variant="bordered"
                            size="sm"
                            radius={getThemeRadius()}
                            value={filters.end_date ? new Date(filters.end_date) : null}
                            onChange={(date) => onFilterChange({ end_date: date ? date.toISOString() : null })}
                            className="w-full"
                            style={inputStyle}
                        />
                    </div>

                    {/* Status */}
                    <div className="space-y-2">
                        <label className="text-sm font-medium" style={labelStyle}>Status</label>
                        <Select
                            placeholder="Select status"
                            selectedKeys={filters.status ? [filters.status] : []}
                            onSelectionChange={(keys) => onFilterChange({ status: Array.from(keys)[0] || null })}
                            variant="bordered"
                            size="sm"
                            radius={getThemeRadius()}
                            style={inputStyle}
                        >
                            {statusOptions.map((option) => (
                                <SelectItem key={option.key} value={option.key}>
                                    {option.label}
                                </SelectItem>
                            ))}
                        </Select>
                    </div>

                    {/* Type */}
                    <div className="space-y-2">
                        <label className="text-sm font-medium" style={labelStyle}>Type</label>
                        <Select
                            placeholder="Select type"
                            selectedKeys={filters.type ? [filters.type] : []}
                            onSelectionChange={(keys) => onFilterChange({ type: Array.from(keys)[0] || null })}
                            variant="bordered"
                            size="sm"
                            radius={getThemeRadius()}
                            style={inputStyle}
                        >
                            {typeOptions.map((option) => (
                                <SelectItem key={option.key} value={option.key}>
                                    {option.label}
                                </SelectItem>
                            ))}
                        </Select>
                    </div>

                    {/* Incharge */}
                    <div className="space-y-2">
                        <label className="text-sm font-medium" style={labelStyle}>Incharge</label>
                        <Input
                            placeholder="Enter incharge ID"
                            value={filters.incharge_id || ''}
                            onValueChange={(value) => onFilterChange({ incharge_id: value })}
                            variant="bordered"
                            size="sm"
                            radius={getThemeRadius()}
                            type="number"
                            style={inputStyle}
                            endContent={
                                filters.incharge_id && (
                                    <Button
                                        isIconOnly
                                        size="sm"
                                        variant="light"
                                        onPress={() => handleClearField('incharge_id')}
                                    >
                                        <XMarkIcon className="w-4 h-4" />
                                    </Button>
                                )
                            }
                        />
                    </div>

                    {/* Assigned */}
                    <div className="space-y-2">
                        <label className="text-sm font-medium" style={labelStyle}>Assigned</label>
                        <Input
                            placeholder="Enter assigned ID"
                            value={filters.assigned_id || ''}
                            onValueChange={(value) => onFilterChange({ assigned_id: value })}
                            variant="bordered"
                            size="sm"
                            radius={getThemeRadius()}
                            type="number"
                            style={inputStyle}
                            endContent={
                                filters.assigned_id && (
                                    <Button
                                        isIconOnly
                                        size="sm"
                                        variant="light"
                                        onPress={() => handleClearField('assigned_id')}
                                    >
                                        <XMarkIcon className="w-4 h-4" />
                                    </Button>
                                )
                            }
                        />
                    </div>
                </div>

                {/* Quick Filter Presets */}
                <div 
                    className="mt-4 pt-4 flex flex-wrap items-center gap-2" 
                    style={{ borderTop: `1px solid var(--theme-divider, #E4E4E7)` }}
                >
                    <span 
                        className="text-sm font-medium"
                        style={labelStyle}
                    >
                        Quick Filters:
                    </span>
                    <Chip
                        size="sm"
                        variant="flat"
                        color="primary"
                        className="cursor-pointer"
                        onClick={() => onFilterChange({ status: 'pending' })}
                        style={inputStyle}
                    >
                        Pending
                    </Chip>
                    <Chip
                        size="sm"
                        variant="flat"
                        color="success"
                        className="cursor-pointer"
                        onClick={() => onFilterChange({ status: 'completed' })}
                        style={inputStyle}
                    >
                        Completed
                    </Chip>
                    <Chip
                        size="sm"
                        variant="flat"
                        color="warning"
                        className="cursor-pointer"
                        onClick={() => onFilterChange({ status: 'in_progress' })}
                        style={inputStyle}
                    >
                        In Progress
                    </Chip>
                    <Chip
                        size="sm"
                        variant="flat"
                        color="danger"
                        className="cursor-pointer"
                        onClick={() => {
                            const today = new Date();
                            onFilterChange({ 
                                start_date: new Date(today.setDate(today.getDate() - 7)).toISOString(),
                                end_date: new Date().toISOString()
                            });
                        }}
                        style={inputStyle}
                    >
                        Last 7 Days
                    </Chip>
                    <Chip
                        size="sm"
                        variant="flat"
                        color="secondary"
                        className="cursor-pointer"
                        onClick={() => {
                            const today = new Date();
                            onFilterChange({ 
                                start_date: new Date(today.setDate(today.getDate() - 30)).toISOString(),
                                end_date: new Date().toISOString()
                            });
                        }}
                        style={inputStyle}
                    >
                        Last 30 Days
                    </Chip>
                </div>
            </CardBody>
        </Card>
    );
};

export default AdvancedFilterPanel;
