import React, { useState, useRef } from 'react';
import {
    Dialog,
    Button,
    Checkbox,
    Card,
    Box,
    Flex,
    Text,
    RadioGroup,
    Separator,
} from '@radix-ui/themes';
import {
    DownloadIcon,
    FileTextIcon,
    ActivityLogIcon,
    InfoCircledIcon,
    CheckCircledIcon,
    ImageIcon,
    FileIcon,
} from '@radix-ui/react-icons';
import { showToast } from '@/utils/toastUtils';
import { route } from 'ziggy-js';
import axios from 'axios';

const EnhancedDailyWorkSummaryExportForm = ({
    open,
    closeModal,
    filteredData = [],
    inCharges = [],
    currentFilters = {},
    auth = null
}) => {
    console.log('ExportForm props:', { open, filteredDataLength: filteredData.length });
    
    // Role-based access control
    const userIsAdmin = auth?.roles?.includes('Administrator') || auth?.roles?.includes('Super Administrator') || auth?.roles?.includes('Daily Work Manager') || false;

    const [exportSettings, setExportSettings] = useState({
        format: 'excel',
        columns: [
            'date', 'totalDailyWorks', 'completed', 'pending', 'inProgress',
            'completionPercentage', 'rfiSubmissions', 'embankment',
            'structure', 'pavement', 'resubmissions', 'emergency'
        ],
        includeCharts: false,
        groupBy: 'date' // date, incharge, type
    });

    const [isLoading, setIsLoading] = useState(false);
    const [isFetchingData, setIsFetchingData] = useState(false);

    const exportFormats = [
        {
            key: 'pdf',
            label: 'PDF Report',
            description: 'Professional PDF with charts and branding',
            icon: <FileIcon style={{ width: 20, height: 20, color: 'var(--red-9)' }} />
        },
        {
            key: 'excel',
            label: 'Excel (.xlsx)',
            description: 'Comprehensive spreadsheet with multi-sheet data',
            icon: <FileIcon style={{ width: 20, height: 20, color: 'var(--green-9)' }} />
        },
        {
            key: 'csv',
            label: 'CSV (.csv)',
            description: 'Simple comma-separated values',
            icon: <FileTextIcon style={{ width: 20, height: 20, color: 'var(--blue-9)' }} />
        }
    ];

    const columnOptions = [
        { key: 'date', label: 'Date', description: 'Summary date' },
        { key: 'totalDailyWorks', label: 'Total Works', description: 'Total number of daily works' },
        { key: 'completed', label: 'Completed', description: 'Number of completed works' },
        { key: 'pending', label: 'Pending', description: 'Number of pending works' },
        { key: 'inProgress', label: 'In Progress', description: 'Number of in-progress works' },
        { key: 'completionPercentage', label: 'Completion %', description: 'Completion percentage' },
        { key: 'rfiSubmissions', label: 'RFI Submissions', description: 'Number of RFI submissions' },
        { key: 'rfiSubmissionPercentage', label: 'RFI Submission %', description: 'RFI submission percentage' },
        { key: 'embankment', label: 'Embankment Works', description: 'Number of embankment works' },
        { key: 'structure', label: 'Structure Works', description: 'Number of structure works' },
        { key: 'pavement', label: 'Pavement Works', description: 'Number of pavement works' },
        { key: 'resubmissions', label: 'Resubmissions', description: 'Number of resubmissions' },
        { key: 'rejected', label: 'Rejected', description: 'Number of rejected works' },
        { key: 'emergency', label: 'Emergency', description: 'Number of emergency works' }
    ];

    const groupByOptions = [
        { key: 'date', label: 'By Date', description: 'Group summary by date' },
        { key: 'type', label: 'By Type', description: 'Group summary by work type' },
        { key: 'overall', label: 'Overall Summary', description: 'Single overall summary' }
    ];

    /**
     * Fetch fresh data from backend with current filters
     * This ensures export always uses up-to-date data from database
     */
    const fetchFreshExportData = async () => {
        try {
            const response = await axios.post(route('daily-works-summary.export'), {
                startDate: currentFilters.startDate || null,
                endDate: currentFilters.endDate || null,
                status: currentFilters.status || null,
                type: currentFilters.type || null,
                search: currentFilters.search || null,
                incharge: currentFilters.incharge || null,
                jurisdiction: currentFilters.jurisdiction || null,
            });
            return response.data.data || [];
        } catch (error) {
            console.error('Failed to fetch fresh export data:', error);
            // Fallback to filteredData if API fails
            return filteredData;
        }
    };

    const handleExport = async () => {
        setIsLoading(true);
        
        const promise = new Promise(async (resolve, reject) => {
            try {
                const filename = `daily_work_summary_${new Date().toISOString().split('T')[0]}`;
                
                switch (exportSettings.format) {
                    case 'pdf':
                        await exportToPDF(filename);
                        break;
                    case 'excel':
                        await exportToExcelServer(filename);
                        break;
                    case 'csv':
                        await exportToCSVServer(filename);
                        break;
                }

                closeModal();
                resolve(`Successfully exported ${exportSettings.format.toUpperCase()} report`);
            } catch (error) {
                reject('Export failed: ' + error.message);
            } finally {
                setIsLoading(false);
            }
        });

        showToast.promise(promise, {
            loading: 'Exporting summary...',
            success: (msg) => msg,
            error: (msg) => msg,
        });
    };

    const prepareExportData = (sourceData) => {
        let data = [...sourceData];
        
        if (exportSettings.groupBy === 'overall') {
            // Create overall summary
            const totalWorks = data.reduce((sum, item) => sum + (item.totalDailyWorks || 0), 0);
            const totalCompleted = data.reduce((sum, item) => sum + (item.completed || 0), 0);
            const totalPending = data.reduce((sum, item) => sum + (item.pending || 0), 0);
            const totalInProgress = data.reduce((sum, item) => sum + (item.inProgress || 0), 0);
            const totalRFI = data.reduce((sum, item) => sum + (item.rfiSubmissions || 0), 0);
            const totalEmbankment = data.reduce((sum, item) => sum + (item.embankment || 0), 0);
            const totalStructure = data.reduce((sum, item) => sum + (item.structure || 0), 0);
            const totalPavement = data.reduce((sum, item) => sum + (item.pavement || 0), 0);
            const totalResubmissions = data.reduce((sum, item) => sum + (item.resubmissions || 0), 0);
            const totalRejected = data.reduce((sum, item) => sum + (item.rejected || 0), 0);
            const totalEmergency = data.reduce((sum, item) => sum + (item.emergency || 0), 0);
            
            data = [{
                date: 'Overall Summary',
                totalDailyWorks: totalWorks,
                completed: totalCompleted,
                pending: totalPending,
                inProgress: totalInProgress,
                completionPercentage: totalWorks > 0 ? ((totalCompleted / totalWorks) * 100).toFixed(1) + '%' : '0%',
                rfiSubmissions: totalRFI,
                rfiSubmissionPercentage: totalCompleted > 0 ? ((totalRFI / totalCompleted) * 100).toFixed(1) + '%' : '0%',
                embankment: totalEmbankment,
                structure: totalStructure,
                pavement: totalPavement,
                resubmissions: totalResubmissions,
                rejected: totalRejected,
                emergency: totalEmergency
            }];
        } else if (exportSettings.groupBy === 'type') {
            // Group by work type
            const typeGroups = data.reduce((groups, item) => {
                ['embankment', 'structure', 'pavement'].forEach(type => {
                    if (item[type] > 0) {
                        if (!groups[type]) {
                            groups[type] = { 
                                type: type.charAt(0).toUpperCase() + type.slice(1),
                                totalWorks: 0, 
                                dates: [] 
                            };
                        }
                        groups[type].totalWorks += item[type];
                        groups[type].dates.push(item.date);
                    }
                });
                return groups;
            }, {});
            
            data = Object.values(typeGroups).map(group => ({
                type: group.type,
                totalWorks: group.totalWorks,
                dateRange: `${Math.min(...group.dates)} to ${Math.max(...group.dates)}`,
                dateCount: group.dates.length
            }));
        }
        
        // Filter columns based on selection
        return data.map(row => {
            const filteredRow = {};
            exportSettings.columns.forEach(column => {
                if (row.hasOwnProperty(column)) {
                    const label = columnOptions.find(col => col.key === column)?.label || column;
                    filteredRow[label] = row[column];
                }
            });
            return filteredRow;
        });
    };

    const exportToPDF = async (filename) => {
        const response = await axios.post(route('daily-works-summary.export-pdf'), {
            startDate: currentFilters.startDate || null,
            endDate: currentFilters.endDate || null,
            status: currentFilters.status || null,
            type: currentFilters.type || null,
            search: currentFilters.search || null,
            incharge: currentFilters.incharge || null,
            jurisdiction: currentFilters.jurisdiction || null,
            includeCharts: exportSettings.includeCharts
        }, {
            responseType: 'blob'
        });
        
        const url = window.URL.createObjectURL(new Blob([response.data]));
        const link = document.createElement('a');
        link.href = url;
        link.setAttribute('download', `${filename}.pdf`);
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.URL.revokeObjectURL(url);
    };

    const exportToExcelServer = async (filename) => {
        const response = await axios.post(route('daily-works-summary.export-excel'), {
            startDate: currentFilters.startDate || null,
            endDate: currentFilters.endDate || null,
            status: currentFilters.status || null,
            type: currentFilters.type || null,
            search: currentFilters.search || null,
            incharge: currentFilters.incharge || null,
            jurisdiction: currentFilters.jurisdiction || null,
            columns: exportSettings.columns,
            groupBy: exportSettings.groupBy,
            includeCharts: exportSettings.includeCharts
        }, {
            responseType: 'blob'
        });
        
        const url = window.URL.createObjectURL(new Blob([response.data]));
        const link = document.createElement('a');
        link.href = url;
        link.setAttribute('download', `${filename}.xlsx`);
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.URL.revokeObjectURL(url);
    };

    const exportToCSVServer = async (filename) => {
        const response = await axios.post(route('daily-works-summary.export-excel'), {
            startDate: currentFilters.startDate || null,
            endDate: currentFilters.endDate || null,
            status: currentFilters.status || null,
            type: currentFilters.type || null,
            search: currentFilters.search || null,
            incharge: currentFilters.incharge || null,
            jurisdiction: currentFilters.jurisdiction || null,
            columns: exportSettings.columns,
            groupBy: exportSettings.groupBy,
            format: 'csv'
        }, {
            responseType: 'blob'
        });
        
        const url = window.URL.createObjectURL(new Blob([response.data]));
        const link = document.createElement('a');
        link.href = url;
        link.setAttribute('download', `${filename}.csv`);
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.URL.revokeObjectURL(url);
    };

    return (
        <Dialog.Root open={open} onOpenChange={closeModal}>
            <Dialog.Content style={{ maxWidth: 700 }}>
                <Dialog.Title>Export Daily Work Summary</Dialog.Title>
                <Dialog.Description>Export summary data with customizable grouping and format options</Dialog.Description>

                <Flex direction="column" gap="4" mt="4">
                    {/* Export Format */}
                    <Card>
                        <Box p="3">
                            <Text size="2" weight="bold" mb="3">Export Format</Text>
                            <RadioGroup.Root
                                value={exportSettings.format}
                                onValueChange={(value) => setExportSettings(prev => ({ ...prev, format: value }))}
                            >
                                <Flex direction="column" gap="2">
                                    {exportFormats.map((format) => (
                                        <Flex key={format.key} asChild align="center" gap="2">
                                            <RadioGroup.Item value={format.key}>
                                                {format.icon}
                                                <Flex direction="column">
                                                    <Text size="2" weight="medium">{format.label}</Text>
                                                    <Text size="1" color="gray">{format.description}</Text>
                                                </Flex>
                                            </RadioGroup.Item>
                                        </Flex>
                                    ))}
                                </Flex>
                            </RadioGroup.Root>
                            <Flex align="center" gap="2" mt="3">
                                <Checkbox
                                    checked={exportSettings.includeCharts}
                                    onCheckedChange={(checked) => setExportSettings(prev => ({ ...prev, includeCharts: checked }))}
                                />
                                <ImageIcon style={{ width: 16, height: 16 }} />
                                <Text size="2">Include Charts</Text>
                            </Flex>
                            {exportSettings.includeCharts && (
                                <Text size="1" color="gray" ml="6">Charts will be generated server-side and included in the export</Text>
                            )}
                        </Box>
                    </Card>

                    {/* Grouping Options - Only for Excel/CSV */}
                    {exportSettings.format !== 'pdf' && (
                        <Flex direction="column" gap="2">
                            <Text size="2" weight="bold">Grouping & Summary</Text>
                            <RadioGroup.Root
                                value={exportSettings.groupBy}
                                onValueChange={(value) => setExportSettings(prev => ({ ...prev, groupBy: value }))}
                            >
                                <Flex direction="column" gap="2">
                                    {groupByOptions.map((option) => (
                                        <Flex key={option.key} asChild align="center" gap="2">
                                            <RadioGroup.Item value={option.key}>
                                                <Flex direction="column">
                                                    <Text size="2" weight="medium">{option.label}</Text>
                                                    <Text size="1" color="gray">{option.description}</Text>
                                                </Flex>
                                            </RadioGroup.Item>
                                        </Flex>
                                    ))}
                                </Flex>
                            </RadioGroup.Root>
                        </Flex>
                    )}

                    {/* Column Selection - Only for Excel/CSV */}
                    {exportSettings.format !== 'pdf' && (
                        <Flex direction="column" gap="2">
                            <Text size="2" weight="bold">Columns to Export</Text>
                            <Flex wrap="wrap" gap="2">
                                {columnOptions.map((column) => (
                                    <Flex key={column.key} align="center" gap="2" p="2" style={{ background: 'var(--gray-a2)', borderRadius: 'var(--radius-1)', minWidth: 200 }}>
                                        <Checkbox
                                            checked={exportSettings.columns.includes(column.key)}
                                            onCheckedChange={(checked) => {
                                                if (checked) {
                                                    setExportSettings(prev => ({ ...prev, columns: [...prev.columns, column.key] }));
                                                } else {
                                                    setExportSettings(prev => ({ ...prev, columns: prev.columns.filter(c => c !== column.key) }));
                                                }
                                            }}
                                        />
                                        <Flex direction="column">
                                            <Text size="2" weight="medium">{column.label}</Text>
                                            <Text size="1" color="gray">{column.description}</Text>
                                        </Flex>
                                    </Flex>
                                ))}
                            </Flex>
                        </Flex>
                    )}

                    {/* Export Summary */}
                    <Card style={{ background: 'var(--accent-a2)' }}>
                        <Box p="3">
                            <Flex align="start" gap="2">
                                <InfoCircledIcon style={{ width: 20, height: 20, color: 'var(--accent-9)' }} />
                                <Flex direction="column" gap="1">
                                    <Text size="2" weight="medium" style={{ color: 'var(--accent-9)' }}>Export Summary</Text>
                                    <Text size="1" style={{ color: 'var(--accent-11)' }}>
                                        Format: {exportFormats.find(f => f.key === exportSettings.format)?.label}
                                    </Text>
                                    <Text size="1" style={{ color: 'var(--accent-11)' }}>
                                        Grouping: {groupByOptions.find(g => g.key === exportSettings.groupBy)?.label}
                                    </Text>
                                    <Text size="1" style={{ color: 'var(--accent-11)' }}>
                                        Columns: {exportSettings.columns.length} selected
                                    </Text>
                                    <Text size="1" style={{ color: 'var(--accent-11)' }}>
                                        Records: {filteredData.length} summary entries
                                    </Text>
                                </Flex>
                            </Flex>
                        </Box>
                    </Card>
                </Flex>

                <Flex gap="2" justify="end" mt="4">
                    <Dialog.Close>
                        <Button variant="soft" color="gray">Cancel</Button>
                    </Dialog.Close>
                    <Button
                        onClick={handleExport}
                        disabled={isLoading || (exportSettings.format !== 'pdf' && exportSettings.columns.length === 0)}
                    >
                        <DownloadIcon style={{ width: 16, height: 16, marginRight: 8 }} />
                        {isLoading ? 'Exporting...' : 'Export Summary'}
                    </Button>
                </Flex>
            </Dialog.Content>
        </Dialog.Root>
    );
};

export default EnhancedDailyWorkSummaryExportForm;
