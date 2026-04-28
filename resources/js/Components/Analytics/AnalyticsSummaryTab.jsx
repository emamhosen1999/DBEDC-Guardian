import React, { useEffect, useState, useCallback } from 'react';
import { motion } from 'framer-motion';
import axios from 'axios';
import { route } from 'ziggy-js';
import { showToast } from '@/utils/toastUtils';
import DailyWorkSummaryTable from '@/Tables/DailyWorkSummaryTable.jsx';
import AdvancedFilterPanel from '@/Components/Analytics/AdvancedFilterPanel.jsx';
import ExportModal from '@/Components/Analytics/ExportModal.jsx';
import {
    Button,
    Card,
    CardHeader,
    CardBody,
    Spinner
} from "@heroui/react";
import {
    MagnifyingGlassIcon,
    AdjustmentsHorizontalIcon,
    ArrowPathIcon,
    DocumentArrowDownIcon
} from "@heroicons/react/24/outline";

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

const AnalyticsSummaryTab = ({ auth }) => {
    const [summaryData, setSummaryData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [showFilters, setShowFilters] = useState(false);
    const [showExportModal, setShowExportModal] = useState(false);
    const [filters, setFilters] = useState({
        start_date: null,
        end_date: null,
        status: null,
        type: null,
        incharge_id: null,
        assigned_id: null,
        search: ''
    });

    const fetchSummary = useCallback(async () => {
        setLoading(true);
        try {
            const response = await axios.get(route('daily-works-analytics.summary'), {
                params: { ...filters }
            });
            // The summary endpoint returns paginated data: { success, data: { data: [...], current_page, ... }, total }
            const responseData = response.data?.data;
            // Flatten paginate result so the table receives the array of rows
            setSummaryData(Array.isArray(responseData?.data) ? responseData.data : (Array.isArray(responseData) ? responseData : []));
        } catch (error) {
            console.error('Error fetching summary data:', error);
            showToast.error('Failed to load summary data');
            setSummaryData([]);
        } finally {
            setLoading(false);
        }
    }, [filters]);

    useEffect(() => {
        fetchSummary();
    }, [fetchSummary]);

    const handleFilterChange = (newFilters) => {
        setFilters(prev => ({ ...prev, ...newFilters }));
    };

    const handleRefresh = () => {
        fetchSummary();
    };

    const handleClearFilters = () => {
        setFilters({
            start_date: null,
            end_date: null,
            status: null,
            type: null,
            incharge_id: null,
            assigned_id: null,
            search: ''
        });
    };

    const handleExport = () => {
        setShowExportModal(true);
    };

    const activeFilterCount = Object.values(filters).filter(value => 
        value !== null && value !== ''
    ).length;

    return (
        <div className="space-y-4">
            {/* Header with filters */}
            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div className="flex items-center gap-3">
                    <Button
                        size="sm"
                        variant={showFilters ? 'solid' : 'bordered'}
                        color="primary"
                        radius={getThemeRadius()}
                        startContent={<AdjustmentsHorizontalIcon className="w-4 h-4" />}
                        onPress={() => setShowFilters(!showFilters)}
                        className="font-semibold"
                        style={{ fontFamily: `var(--fontFamily, "Inter")` }}
                    >
                        Filters
                        {activeFilterCount > 0 && (
                            <span 
                                className="ml-1 px-2 py-0.5 rounded-full text-xs"
                                style={{ 
                                    background: showFilters ? 'rgba(255,255,255,0.25)' : `color-mix(in srgb, var(--theme-primary) 20%, transparent)`,
                                    color: showFilters ? '#FFFFFF' : 'var(--theme-primary)',
                                }}
                            >
                                {activeFilterCount}
                            </span>
                        )}
                    </Button>
                    
                    {activeFilterCount > 0 && (
                        <Button
                            size="sm"
                            variant="light"
                            radius={getThemeRadius()}
                            onPress={handleClearFilters}
                            style={{ fontFamily: `var(--fontFamily, "Inter")` }}
                        >
                            Clear All
                        </Button>
                    )}
                </div>
                
                <div className="flex items-center gap-2">
                    <Button
                        size="sm"
                        variant="bordered"
                        color="default"
                        radius={getThemeRadius()}
                        startContent={<DocumentArrowDownIcon className="w-4 h-4" />}
                        onPress={handleExport}
                        className="font-semibold"
                        style={{ fontFamily: `var(--fontFamily, "Inter")` }}
                    >
                        Export
                    </Button>
                    <Button
                        size="sm"
                        variant="bordered"
                        radius={getThemeRadius()}
                        isIconOnly
                        onPress={handleRefresh}
                        style={{ fontFamily: `var(--fontFamily, "Inter")` }}
                    >
                        <ArrowPathIcon className="w-4 h-4" />
                    </Button>
                </div>
            </div>

            {/* Advanced Filter Panel */}
            {showFilters && (
                <motion.div
                    initial={{ opacity: 0, height: 0 }}
                    animate={{ opacity: 1, height: 'auto' }}
                    exit={{ opacity: 0, height: 0 }}
                    transition={{ duration: 0.2 }}
                >
                    <AdvancedFilterPanel
                        filters={filters}
                        onFilterChange={handleFilterChange}
                    />
                </motion.div>
            )}

            {/* Summary Table */}
            <DailyWorkSummaryTable
                filteredData={summaryData || []}
                onRefresh={handleRefresh}
                loading={loading}
            />

            {/* Export Modal */}
            <ExportModal
                isOpen={showExportModal}
                onClose={() => setShowExportModal(false)}
                currentFilters={filters}
            />
        </div>
    );
};

export default AnalyticsSummaryTab;
