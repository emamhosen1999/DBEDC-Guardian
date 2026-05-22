import React from 'react';
import { Button, TextField, Select } from '@radix-ui/themes';
import { AdjustmentsHorizontalIcon, MagnifyingGlassIcon } from '@heroicons/react/24/outline';
import { categoryConfig, statusConfig } from '../config/objectionUiConfig';

export default function ObjectionsFiltersBar({
    isMobile,
    showFilters,
    onToggleFilters,
    search,
    onSearchChange,
    filterData,
    onFilterChange,
    statuses,
    categories,
    onClearFilters,
}) {
    const statusOptions = statuses || statusConfig;
    const categoryOptions = categories || categoryConfig;

    return (
        <div className="mb-4 space-y-4">
            <div className="flex flex-col lg:flex-row gap-3 items-stretch lg:items-center">
                <Button
                    size="2"
                    variant={showFilters ? 'solid' : 'outline'}
                    color={showFilters ? 'indigo' : 'gray'}
                    onClick={onToggleFilters}
                    style={{ fontFamily: 'var(--fontFamily, Inter)', minHeight: 40, flexShrink: 0 }}
                >
                    <AdjustmentsHorizontalIcon style={{ width: 16, height: 16 }} />
                    {!isMobile && 'Filters'}
                </Button>

                <div style={{ flex: 1, minWidth: 0 }}>
                    <TextField.Root
                        placeholder="Search by title, description, or chainage..."
                        value={search}
                        onChange={onSearchChange}
                        size="2"
                        style={{ fontFamily: 'var(--fontFamily, Inter)' }}
                    >
                        <TextField.Slot>
                            <MagnifyingGlassIcon style={{ width: 16, height: 16, color: 'var(--gray-9)' }} />
                        </TextField.Slot>
                    </TextField.Root>
                </div>
            </div>

            <AnimatePresence>
                {showFilters && (
                    <div
                        initial={{ opacity: 0, height: 0 }}
                        animate={{ opacity: 1, height: 'auto' }}
                        exit={{ opacity: 0, height: 0 }}
                        transition={{ duration: 0.2 }}
                        style={{ overflow: 'hidden' }}
                    >
                        <div
                            style={{
                                padding: 12,
                                borderRadius: 'var(--radius-3)',
                                border: '1px solid var(--gray-a4)',
                                background: 'var(--gray-a2)',
                            }}
                        >
                            <div style={{ display: 'flex', flexWrap: 'wrap', gap: 12, alignItems: 'flex-end' }}>
                                <div style={{ minWidth: 160, flex: '1 1 160px' }}>
                                    <Select.Root
                                        size="2"
                                        value={filterData.status || 'all'}
                                        onValueChange={(value) => onFilterChange('status', value)}
                                    >
                                        <Select.Trigger placeholder="Status" style={{ width: '100%' }} />
                                        <Select.Content>
                                            <Select.Item value="all">All Statuses</Select.Item>
                                            {Object.entries(statusOptions).map(([key, value]) => (
                                                <Select.Item key={key} value={key}>
                                                    {typeof value === 'string' ? value : value.label}
                                                </Select.Item>
                                            ))}
                                        </Select.Content>
                                    </Select.Root>
                                </div>

                                <div style={{ minWidth: 160, flex: '1 1 160px' }}>
                                    <Select.Root
                                        size="2"
                                        value={filterData.category || 'all'}
                                        onValueChange={(value) => onFilterChange('category', value)}
                                    >
                                        <Select.Trigger placeholder="Category" style={{ width: '100%' }} />
                                        <Select.Content>
                                            <Select.Item value="all">All Categories</Select.Item>
                                            {Object.entries(categoryOptions).map(([key, value]) => (
                                                <Select.Item key={key} value={key}>
                                                    {typeof value === 'string' ? value : value.label}
                                                </Select.Item>
                                            ))}
                                        </Select.Content>
                                    </Select.Root>
                                </div>

                                <Button
                                    size="2"
                                    variant="soft"
                                    color="red"
                                    onClick={onClearFilters}
                                    style={{ fontFamily: 'var(--fontFamily, Inter)', minHeight: 40 }}
                                >
                                    Clear
                                </Button>
                            </div>
                        </div>
                    </div>
                )}
            </AnimatePresence>
        </div>
    );
}
