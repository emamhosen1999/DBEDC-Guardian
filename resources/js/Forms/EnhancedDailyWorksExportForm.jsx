import React, { useState, useEffect } from 'react';
import {
    Dialog,
    Button,
    Select,
    Checkbox,
    Card,
    Box,
    Flex,
    Text,
    RadioGroup,
    Badge,
    Progress,
    Separator,
} from '@radix-ui/themes';
import {
    DownloadIcon,
    FileTextIcon,
    TableIcon,
    CalendarIcon,
    InfoCircledIcon,
    CheckCircledIcon,
    FileIcon,
} from '@radix-ui/react-icons';
import { showToast } from '@/utils/toastUtils';
import axios from 'axios';
import * as XLSX from 'xlsx';

const EnhancedDailyWorksExportForm = ({
    open,
    closeModal,
    filterData = {},
    users = [],
    inCharges = [],
    auth = null
}) => {
    const userIsAdmin = auth?.roles?.includes('Administrator') || auth?.roles?.includes('Super Administrator') || auth?.roles?.includes('Daily Work Manager') || false;

    const [exportSettings, setExportSettings] = useState({
        format: 'excel',
        dateRange: {
            start: filterData.startDate || '',
            end: filterData.endDate || ''
        },
        columns: [
            'date', 'number', 'type', 'status', 'description', 
            'location', 'incharge', 'assigned', 'completion_time', 'rfi_submission_date'
        ],
        filters: {
            status: filterData.status || 'all',
            incharge: filterData.incharge || 'all',
            type: filterData.type || 'all',
            search: filterData.search || '',
        }
    });

    const [isLoading, setIsLoading] = useState(false);
    const [preview, setPreview] = useState(null);

    const exportFormats = [
        {
            key: 'excel',
            label: 'Excel (.xlsx)',
            description: 'Comprehensive spreadsheet format',
            icon: <FileIcon style={{ width: 20, height: 20, color: 'var(--green-9)' }} />
        },
        {
            key: 'csv',
            label: 'CSV (.csv)',
            description: 'Simple comma-separated values',
            icon: <TableIcon style={{ width: 20, height: 20, color: 'var(--blue-9)' }} />
        },
        {
            key: 'json',
            label: 'JSON Data',
            description: 'Structured data format',
            icon: <FileTextIcon style={{ width: 20, height: 20, color: 'var(--purple-9)' }} />
        }
    ];

    const columnOptions = [
        { key: 'date', label: 'Date', description: 'RFI submission date' },
        { key: 'number', label: 'RFI Number', description: 'Unique RFI identifier' },
        { key: 'type', label: 'Type', description: 'Work type (Embankment, Structure, Pavement)' },
        { key: 'status', label: 'Status', description: 'Current work status' },
        { key: 'description', label: 'Description', description: 'Work description' },
        { key: 'location', label: 'Location', description: 'Work location/chainage' },
        { key: 'side', label: 'Side', description: 'Road side (SR-R, SR-L)' },
        { key: 'qty_layer', label: 'Quantity/Layer', description: 'Quantity or layer information' },
        { key: 'planned_time', label: 'Planned Time', description: 'Planned completion time' },
        { key: 'incharge', label: 'In Charge', description: 'Supervision engineer' },
        { key: 'assigned', label: 'Assigned To', description: 'Assigned team member' },
        { key: 'completion_time', label: 'Completion Time', description: 'Actual completion time' },
        { key: 'inspection_details', label: 'Inspection Details', description: 'Quality inspection results' },
        { key: 'resubmission_count', label: 'Resubmission Count', description: 'Number of resubmissions' },
        { key: 'rfi_submission_date', label: 'RFI Submission Date', description: 'Date RFI was submitted' }
    ];

    const statusOptions = [
        { key: 'all', label: 'All Statuses' },
        { key: 'new', label: 'New' },
        { key: 'in-progress', label: 'In Progress' },
        { key: 'pending', label: 'Pending' },
        { key: 'completed', label: 'Completed' },
        { key: 'rejected', label: 'Rejected' },
        { key: 'resubmission', label: 'Resubmission' },
        { key: 'emergency', label: 'Emergency' }
    ];

    const typeOptions = [
        { key: 'all', label: 'All Types' },
        { key: 'Embankment', label: 'Embankment' },
        { key: 'Structure', label: 'Structure' },
        { key: 'Pavement', label: 'Pavement' }
    ];

    const handleExport = async () => {
        setIsLoading(true);
        
        const promise = new Promise(async (resolve, reject) => {
            try {
                const exportParams = {
                    columns: exportSettings.columns,
                    ...exportSettings.filters,
                    ...(exportSettings.dateRange.start && { 
                        startDate: exportSettings.dateRange.start
                    }),
                    ...(exportSettings.dateRange.end && { 
                        endDate: exportSettings.dateRange.end
                    }),
                    ...(exportSettings.filters.search && { 
                        search: exportSettings.filters.search 
                    })
                };

                const response = await axios.post(route('dailyWorks.export'), exportParams);
                
                if (response.data.data) {
                    const { data: exportData, filename } = response.data;
                    
                    switch (exportSettings.format) {
                        case 'excel':
                            exportToExcel(exportData, filename);
                            break;
                        case 'csv':
                            exportToCSV(exportData, filename);
                            break;
                        case 'json':
                            exportToJSON(exportData, filename);
                            break;
                    }

                    closeModal();
                    resolve(`Successfully exported ${exportData.length} records`);
                }
            } catch (error) {
                reject('Export failed: ' + (error.response?.data?.error || error.message));
            } finally {
                setIsLoading(false);
            }
        });

        showToast.promise(promise, {
            loading: 'Exporting daily works...',
            success: (msg) => msg,
            error: (msg) => msg,
        });
    };

    const exportToExcel = (data, filename) => {
        const worksheet = XLSX.utils.json_to_sheet(data);
        const workbook = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(workbook, worksheet, 'Daily Works');
        
        // Auto-size columns
        const cols = Object.keys(data[0] || {}).map(() => ({ wch: 20 }));
        worksheet['!cols'] = cols;
        
        XLSX.writeFile(workbook, `${filename}.xlsx`);
    };

    const exportToCSV = (data, filename) => {
        const worksheet = XLSX.utils.json_to_sheet(data);
        const csv = XLSX.utils.sheet_to_csv(worksheet);
        
        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        const url = URL.createObjectURL(blob);
        link.setAttribute('href', url);
        link.setAttribute('download', `${filename}.csv`);
        link.style.visibility = 'hidden';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    };

    const exportToJSON = (data, filename) => {
        const json = JSON.stringify(data, null, 2);
        const blob = new Blob([json], { type: 'application/json' });
        const link = document.createElement('a');
        const url = URL.createObjectURL(blob);
        link.setAttribute('href', url);
        link.setAttribute('download', `${filename}.json`);
        link.style.visibility = 'hidden';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    };

    const getEstimatedRecords = () => {
        // This would ideally come from a separate API call
        return "Calculating...";
    };

    return (
        <Dialog.Root open={open} onOpenChange={closeModal}>
            <Dialog.Content style={{ maxWidth: 800 }}>
                <Dialog.Title>Export Daily Works</Dialog.Title>
                <Dialog.Description>Export daily work records with customizable options</Dialog.Description>

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
                        </Box>
                    </Card>

                    {/* Date Range */}
                    <Flex direction="column" gap="2">
                        <Text size="2" weight="bold">Date Range</Text>
                        <Flex gap="2">
                            <Flex direction="column" gap="1" style={{ flex: 1 }}>
                                <Text size="1">Start Date</Text>
                                <input
                                    type="date"
                                    value={exportSettings.dateRange.start}
                                    onChange={(e) => setExportSettings(prev => ({ ...prev, dateRange: { ...prev.dateRange, start: e.target.value } }))}
                                    style={{ fontSize: 13, border: '1px solid var(--gray-5)', borderRadius: 'var(--radius-1)', padding: '6px 8px', background: 'var(--color-panel-solid)', minHeight: 40 }}
                                />
                            </Flex>
                            <Flex direction="column" gap="1" style={{ flex: 1 }}>
                                <Text size="1">End Date</Text>
                                <input
                                    type="date"
                                    value={exportSettings.dateRange.end}
                                    onChange={(e) => setExportSettings(prev => ({ ...prev, dateRange: { ...prev.dateRange, end: e.target.value } }))}
                                    style={{ fontSize: 13, border: '1px solid var(--gray-5)', borderRadius: 'var(--radius-1)', padding: '6px 8px', background: 'var(--color-panel-solid)', minHeight: 40 }}
                                />
                            </Flex>
                        </Flex>
                    </Flex>

                    {/* Filters */}
                    <Flex direction="column" gap="2">
                        <Text size="2" weight="bold">Filters</Text>
                        <Flex wrap="wrap" gap="3">
                            <Flex direction="column" gap="1" style={{ minWidth: 160 }}>
                                <Text size="1">Status</Text>
                                <select
                                    value={exportSettings.filters.status}
                                    onChange={(e) => setExportSettings(prev => ({ ...prev, filters: { ...prev.filters, status: e.target.value } }))}
                                    style={{ fontSize: 13, border: '1px solid var(--gray-5)', borderRadius: 'var(--radius-1)', padding: '6px 8px', background: 'var(--color-panel-solid)', minHeight: 40 }}
                                >
                                    {statusOptions.map((status) => <option key={status.key} value={status.key}>{status.label}</option>)}
                                </select>
                            </Flex>
                            <Flex direction="column" gap="1" style={{ minWidth: 160 }}>
                                <Text size="1">Type</Text>
                                <select
                                    value={exportSettings.filters.type}
                                    onChange={(e) => setExportSettings(prev => ({ ...prev, filters: { ...prev.filters, type: e.target.value } }))}
                                    style={{ fontSize: 13, border: '1px solid var(--gray-5)', borderRadius: 'var(--radius-1)', padding: '6px 8px', background: 'var(--color-panel-solid)', minHeight: 40 }}
                                >
                                    {typeOptions.map((type) => <option key={type.key} value={type.key}>{type.label}</option>)}
                                </select>
                            </Flex>
                            {userIsAdmin && (
                                <Flex direction="column" gap="1" style={{ minWidth: 160 }}>
                                    <Text size="1">In Charge</Text>
                                    <select
                                        value={exportSettings.filters.incharge}
                                        onChange={(e) => setExportSettings(prev => ({ ...prev, filters: { ...prev.filters, incharge: e.target.value } }))}
                                        style={{ fontSize: 13, border: '1px solid var(--gray-5)', borderRadius: 'var(--radius-1)', padding: '6px 8px', background: 'var(--color-panel-solid)', minHeight: 40 }}
                                    >
                                        <option value="all">All In Charges</option>
                                        {inCharges.map((inCharge) => <option key={inCharge.id} value={inCharge.id}>{inCharge.name}</option>)}
                                    </select>
                                </Flex>
                            )}
                        </Flex>
                    </Flex>

                    {/* Column Selection */}
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
                                        Columns: {exportSettings.columns.length} selected
                                    </Text>
                                    <Text size="1" style={{ color: 'var(--accent-11)' }}>
                                        Estimated records: {getEstimatedRecords()}
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
                        disabled={isLoading || exportSettings.columns.length === 0}
                    >
                        <DownloadIcon style={{ width: 16, height: 16, marginRight: 8 }} />
                        {isLoading ? 'Exporting...' : 'Export Data'}
                    </Button>
                </Flex>
            </Dialog.Content>
        </Dialog.Root>
    );
};

export default EnhancedDailyWorksExportForm;
