import React, { useState } from 'react';
import {
    Modal,
    ModalContent,
    ModalHeader,
    ModalBody,
    ModalFooter,
    Button,
    Select,
    SelectItem,
    Checkbox,
    DatePicker,
    Spinner
} from "@heroui/react";
import { DocumentArrowDownIcon, XMarkIcon } from "@heroicons/react/24/outline";
import axios from 'axios';
import { route } from 'ziggy-js';
import { showToast } from '@/utils/toastUtils';

const ExportModal = ({ isOpen, onClose, currentFilters = {} }) => {
    const [format, setFormat] = useState('csv');
    const [selectedColumns, setSelectedColumns] = useState([]);
    const [exportFilters, setExportFilters] = useState({
        start_date: currentFilters.start_date || null,
        end_date: currentFilters.end_date || null,
        status: currentFilters.status || null,
        type: currentFilters.type || null,
        incharge_id: currentFilters.incharge_id || null,
        assigned_id: currentFilters.assigned_id || null,
    });
    const [loading, setLoading] = useState(false);

    const availableColumns = [
        { key: 'date', label: 'Date' },
        { key: 'totalDailyWorks', label: 'Total Daily Works' },
        { key: 'completed', label: 'Completed' },
        { key: 'embankment', label: 'Embankment' },
        { key: 'structure', label: 'Structure' },
        { key: 'pavement', label: 'Pavement' },
        { key: 'resubmissions', label: 'Resubmissions' },
        { key: 'rfiSubmissions', label: 'RFI Submissions' },
    ];

    const formatOptions = [
        { key: 'csv', label: 'CSV' },
        { key: 'xlsx', label: 'Excel (XLSX)' },
        { key: 'pdf', label: 'PDF' },
    ];

    const statusOptions = [
        { key: '', label: 'All Statuses' },
        { key: 'New', label: 'New' },
        { key: 'In Progress', label: 'In Progress' },
        { key: 'Completed', label: 'Completed' },
        { key: 'Emergency', label: 'Emergency' },
    ];

    const typeOptions = [
        { key: '', label: 'All Types' },
        { key: 'Embankment', label: 'Embankment' },
        { key: 'Structure', label: 'Structure' },
        { key: 'Pavement', label: 'Pavement' },
    ];

    const handleColumnToggle = (columnKey) => {
        setSelectedColumns(prev => {
            if (prev.includes(columnKey)) {
                return prev.filter(key => key !== columnKey);
            } else {
                return [...prev, columnKey];
            }
        });
    };

    const handleSelectAllColumns = () => {
        if (selectedColumns.length === availableColumns.length) {
            setSelectedColumns([]);
        } else {
            setSelectedColumns(availableColumns.map(col => col.key));
        }
    };

    const handleExport = async () => {
        setLoading(true);
        try {
            const params = {
                format,
                columns: selectedColumns.length > 0 ? selectedColumns : availableColumns.map(col => col.key),
                export_type: 'summary',
                ...exportFilters,
            };

            const response = await axios.get(route('daily-works-analytics.export'), {
                params,
                responseType: 'blob',
            });

            // Create download link
            const url = window.URL.createObjectURL(new Blob([response.data]));
            const link = document.createElement('a');
            link.href = url;
            link.setAttribute('download', `daily_works_export_${Date.now()}.${format}`);
            document.body.appendChild(link);
            link.click();
            link.remove();
            window.URL.revokeObjectURL(url);

            showToast.success('Export completed successfully');
            onClose();
        } catch (error) {
            console.error('Export error:', error);
            showToast.error('Failed to export data');
        } finally {
            setLoading(false);
        }
    };

    const handleCancel = () => {
        onClose();
    };

    return (
        <Modal
            isOpen={isOpen}
            onClose={onClose}
            size="2xl"
            scrollBehavior="inside"
        >
            <ModalContent>
                <ModalHeader>
                    <div className="flex items-center gap-2">
                        <DocumentArrowDownIcon className="w-5 h-5" />
                        <span>Export Data</span>
                    </div>
                </ModalHeader>
                <ModalBody>
                    <div className="space-y-6">
                        {/* Format Selection */}
                        <div>
                            <label className="block text-sm font-semibold mb-2">
                                Export Format
                            </label>
                            <Select
                                placeholder="Select format"
                                selectedKeys={[format]}
                                onSelectionChange={(keys) => setFormat(Array.from(keys)[0])}
                                className="max-w-xs"
                            >
                                {formatOptions.map((option) => (
                                    <SelectItem key={option.key} value={option.key}>
                                        {option.label}
                                    </SelectItem>
                                ))}
                            </Select>
                        </div>

                        {/* Column Selection */}
                        <div>
                            <div className="flex items-center justify-between mb-2">
                                <label className="block text-sm font-semibold">
                                    Select Columns
                                </label>
                                <Button
                                    size="sm"
                                    variant="light"
                                    onPress={handleSelectAllColumns}
                                >
                                    {selectedColumns.length === availableColumns.length ? 'Deselect All' : 'Select All'}
                                </Button>
                            </div>
                            <div className="grid grid-cols-3 gap-2 max-h-48 overflow-y-auto p-2 border rounded-lg">
                                {availableColumns.map((column) => (
                                    <Checkbox
                                        key={column.key}
                                        isSelected={selectedColumns.includes(column.key)}
                                        onValueChange={() => handleColumnToggle(column.key)}
                                        size="sm"
                                    >
                                        {column.label}
                                    </Checkbox>
                                ))}
                            </div>
                        </div>

                        {/* Filters */}
                        <div>
                            <label className="block text-sm font-semibold mb-2">
                                Export Filters (optional)
                            </label>
                            <div className="space-y-3">
                                <div className="grid grid-cols-2 gap-3">
                                    <DatePicker
                                        label="Start Date"
                                        value={exportFilters.start_date}
                                        onChange={(date) => setExportFilters(prev => ({ ...prev, start_date: date }))}
                                        className="w-full"
                                    />
                                    <DatePicker
                                        label="End Date"
                                        value={exportFilters.end_date}
                                        onChange={(date) => setExportFilters(prev => ({ ...prev, end_date: date }))}
                                        className="w-full"
                                    />
                                </div>
                                <div className="grid grid-cols-2 gap-3">
                                    <Select
                                        label="Status"
                                        placeholder="All Statuses"
                                        selectedKeys={exportFilters.status ? [exportFilters.status] : []}
                                        onSelectionChange={(keys) => setExportFilters(prev => ({ ...prev, status: Array.from(keys)[0] }))}
                                    >
                                        {statusOptions.map((option) => (
                                            <SelectItem key={option.key} value={option.key}>
                                                {option.label}
                                            </SelectItem>
                                        ))}
                                    </Select>
                                    <Select
                                        label="Type"
                                        placeholder="All Types"
                                        selectedKeys={exportFilters.type ? [exportFilters.type] : []}
                                        onSelectionChange={(keys) => setExportFilters(prev => ({ ...prev, type: Array.from(keys)[0] }))}
                                    >
                                        {typeOptions.map((option) => (
                                            <SelectItem key={option.key} value={option.key}>
                                                {option.label}
                                            </SelectItem>
                                        ))}
                                    </Select>
                                </div>
                            </div>
                        </div>
                    </div>
                </ModalBody>
                <ModalFooter>
                    <Button
                        color="default"
                        variant="light"
                        onPress={handleCancel}
                        isDisabled={loading}
                    >
                        Cancel
                    </Button>
                    <Button
                        color="primary"
                        onPress={handleExport}
                        isDisabled={loading}
                        startContent={loading ? <Spinner size="sm" /> : <DocumentArrowDownIcon className="w-4 h-4" />}
                    >
                        {loading ? 'Exporting...' : 'Export'}
                    </Button>
                </ModalFooter>
            </ModalContent>
        </Modal>
    );
};

export default ExportModal;
