import React, { useState, useRef } from 'react';
import {
    Modal,
    ModalContent,
    ModalHeader,
    ModalBody,
    ModalFooter,
    Button,
    Select,
    SelectItem,
    DateRangePicker,
    Checkbox,
    CheckboxGroup,
    Card,
    CardBody,
    RadioGroup,
    Radio,
    Divider
} from "@heroui/react";
import {
    DocumentArrowDownIcon,
    ChartBarIcon,
    InformationCircleIcon,
    CheckCircleIcon,
    PhotoIcon
} from "@heroicons/react/24/outline";
import { Download, FileSpreadsheet, FileText } from 'lucide-react';
import { showToast } from '@/utils/toastUtils';
import { route } from 'ziggy-js';
import axios from 'axios';
import { parseDate } from "@internationalized/date";

const EnhancedDailyWorkSummaryExportForm = ({ 
    open, 
    closeModal, 
    filteredData = [],
    inCharges = [],
    currentFilters = {},
    analyticsRef = null
}) => {
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
            icon: <DocumentArrowDownIcon className="w-5 h-5 text-red-600" />
        },
        {
            key: 'excel',
            label: 'Excel (.xlsx)',
            description: 'Comprehensive spreadsheet with multi-sheet data',
            icon: <FileSpreadsheet size={20} className="text-green-600" />
        },
        {
            key: 'csv',
            label: 'CSV (.csv)',
            description: 'Simple comma-separated values',
            icon: <FileText size={20} className="text-blue-600" />
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
        let chartImages = [];
        
        if (exportSettings.includeCharts && analyticsRef?.current) {
            chartImages = await analyticsRef.current.captureCharts();
        }
        
        const response = await axios.post(route('daily-works-summary.export-pdf'), {
            startDate: currentFilters.startDate || null,
            endDate: currentFilters.endDate || null,
            status: currentFilters.status || null,
            type: currentFilters.type || null,
            search: currentFilters.search || null,
            incharge: currentFilters.incharge || null,
            jurisdiction: currentFilters.jurisdiction || null,
            chartImages: chartImages,
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
            groupBy: exportSettings.groupBy
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
        <Modal 
            isOpen={open} 
            onClose={closeModal}
            size="3xl"
            scrollBehavior="inside"
            classNames={{
                base: "backdrop-blur-md",
                backdrop: "bg-black/50 backdrop-blur-sm"
            }}
        >
            <ModalContent>
                <ModalHeader className="flex flex-col gap-1">
                    <div className="flex items-center gap-2">
                        <ChartBarIcon className="w-6 h-6 text-primary" />
                        <span>Export Daily Work Summary</span>
                    </div>
                    <p className="text-sm text-default-500 font-normal">
                        Export summary data with customizable grouping and format options
                    </p>
                </ModalHeader>
                
                <ModalBody>
                    <div className="space-y-6">
                        {/* Export Format */}
                        <Card className="bg-default-50">
                            <CardBody>
                                <h4 className="font-semibold mb-3">Export Format</h4>
                                <RadioGroup
                                    value={exportSettings.format}
                                    onValueChange={(value) => setExportSettings(prev => ({ ...prev, format: value }))}
                                    orientation="horizontal"
                                >
                                    {exportFormats.map((format) => (
                                        <Radio key={format.key} value={format.key}>
                                            <div className="flex items-center gap-2">
                                                {format.icon}
                                                <div>
                                                    <div className="font-medium">{format.label}</div>
                                                    <div className="text-xs text-default-500">{format.description}</div>
                                                </div>
                                            </div>
                                        </Radio>
                                    ))}
                                </RadioGroup>
                            </CardBody>
                        </Card>

                        {/* Include Charts Option - Only for PDF */}
                        {exportSettings.format === 'pdf' && (
                            <Card className="bg-default-50">
                                <CardBody>
                                    <h4 className="font-semibold mb-3">Chart Options</h4>
                                    <Checkbox
                                        isSelected={exportSettings.includeCharts}
                                        onValueChange={(value) => setExportSettings(prev => ({ ...prev, includeCharts: value }))}
                                    >
                                        <div>
                                            <div className="font-medium">Include Charts</div>
                                            <div className="text-xs text-default-500">
                                                Embed analytics charts in the PDF report
                                            </div>
                                        </div>
                                    </Checkbox>
                                    {exportSettings.includeCharts && !analyticsRef && (
                                        <div className="mt-2 p-2 bg-warning-50 text-warning-700 text-xs rounded-lg flex items-start gap-2">
                                            <InformationCircleIcon className="w-4 h-4 mt-0.5 flex-shrink-0" />
                                            <span>
                                                Charts will only be included if you switch to the Analytics tab before exporting.
                                            </span>
                                        </div>
                                    )}
                                </CardBody>
                            </Card>
                        )}

                        {/* Grouping Options - Only for Excel/CSV */}
                        {exportSettings.format !== 'pdf' && (
                            <div>
                                <h4 className="font-semibold mb-3">Grouping & Summary</h4>
                                <RadioGroup
                                    value={exportSettings.groupBy}
                                    onValueChange={(value) => setExportSettings(prev => ({ ...prev, groupBy: value }))}
                                >
                                    {groupByOptions.map((option) => (
                                        <Radio key={option.key} value={option.key}>
                                            <div>
                                                <div className="font-medium">{option.label}</div>
                                                <div className="text-xs text-default-500">{option.description}</div>
                                            </div>
                                        </Radio>
                                    ))}
                                </RadioGroup>
                            </div>
                        )}

                        {/* Column Selection - Only for Excel/CSV */}
                        {exportSettings.format !== 'pdf' && (
                            <div>
                                <h4 className="font-semibold mb-3">Columns to Export</h4>
                                <CheckboxGroup
                                    value={exportSettings.columns}
                                    onValueChange={(columns) => setExportSettings(prev => ({ 
                                        ...prev, 
                                        columns 
                                    }))}
                                >
                                    <div className="grid grid-cols-1 md:grid-cols-2 gap-2">
                                        {columnOptions.map((column) => (
                                            <Checkbox key={column.key} value={column.key}>
                                                <div>
                                                    <div className="font-medium">{column.label}</div>
                                                    <div className="text-xs text-default-500">{column.description}</div>
                                                </div>
                                            </Checkbox>
                                        ))}
                                    </div>
                                </CheckboxGroup>
                            </div>
                        )}

                        {/* Export Summary */}
                        <Card className="bg-primary-50 border-primary-200">
                            <CardBody>
                                <div className="flex items-start space-x-3">
                                    <InformationCircleIcon className="w-5 h-5 text-primary-600 mt-0.5" />
                                    <div>
                                        <h5 className="font-medium text-primary-900">Export Summary</h5>
                                        <div className="text-sm text-primary-700 space-y-1">
                                            <p>• Format: {exportFormats.find(f => f.key === exportSettings.format)?.label}</p>
                                            <p>• Grouping: {groupByOptions.find(g => g.key === exportSettings.groupBy)?.label}</p>
                                            <p>• Columns: {exportSettings.columns.length} selected</p>
                                            <p>• Records: {filteredData.length} summary entries</p>
                                        </div>
                                    </div>
                                </div>
                            </CardBody>
                        </Card>
                    </div>
                </ModalBody>
                
                <ModalFooter>
                    <Button 
                        variant="light" 
                        onPress={closeModal}
                        isDisabled={isLoading}
                    >
                        Cancel
                    </Button>
                    <Button 
                        color="primary" 
                        onPress={handleExport}
                        isLoading={isLoading}
                        startContent={!isLoading && <Download size={16} />}
                        isDisabled={exportSettings.columns.length === 0}
                    >
                        {isLoading ? 'Exporting...' : 'Export Summary'}
                    </Button>
                </ModalFooter>
            </ModalContent>
        </Modal>
    );
};

export default EnhancedDailyWorkSummaryExportForm;
