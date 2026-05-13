import React, { useState } from "react";
import { Dialog, Button, Checkbox, Table, Flex, Text } from "@radix-ui/themes";
import { Cross2Icon, DownloadIcon } from "@radix-ui/react-icons";
import { showToast } from "@/utils/toastUtils";
import * as XLSX from 'xlsx';


const DailyWorkSummaryDownloadForm = ({ open, closeModal,  filteredData, users }) => {

    const [processing, setProcessing] = useState(false);

    const columns = [
        { label: 'Date', key: 'date' },
        { label: 'Total Daily Works', key: 'totalDailyWorks' },
        { label: 'Resubmissions', key: 'resubmissions' },
        { label: 'Embankment', key: 'embankment' },
        { label: 'Structure', key: 'structure' },
        { label: 'Pavement', key: 'pavement' },
        { label: 'Completed', key: 'completed' },
        { label: 'Pending', key: 'pending' },
        { label: 'Completion Percentage', key: 'completionPercentage' },
        { label: 'RFI Submissions', key: 'rfiSubmissions' },
        { label: 'RFI Submission Percentage', key: 'rfiSubmissionPercentage' },

    ];

    const [selectedColumns, setSelectedColumns] = useState(
        columns.map(column => ({ ...column, checked: true })) // All columns checked by default
    );

    const handleCheckboxChange = (index) => {
        const newColumns = [...selectedColumns];
        newColumns[index].checked = !newColumns[index].checked;
        setSelectedColumns(newColumns);
    };

    // Function to handle export with selected columns
    const exportToExcel = async (selectedColumns) => {
        const promise = new Promise((resolve, reject) => {
            try {
                // Check if there are selected columns
                if (!selectedColumns || selectedColumns.length === 0) {
                    reject('No columns selected for export.');
                    return;
                }

                // Filter the data based on the selected columns
                const exportData = filteredData.map(row => {
                    const selectedRow = {};

                    // Calculate completion percentage and RFI submission percentage
                    const totalDailyWorks = row.totalDailyWorks || 0;
                    const completed = row.completed || 0;
                    const rfiSubmissions = row.rfiSubmissions || 0;

                    const pending = totalDailyWorks > 0
                        ? `${((totalDailyWorks - completed))}`
                        : '0';

                    const completionPercentage = totalDailyWorks > 0
                        ? `${((completed / totalDailyWorks) * 100).toFixed(1)}%`
                        : '0%';

                    const rfiSubmissionPercentage = totalDailyWorks > 0
                        ? `${((rfiSubmissions / totalDailyWorks) * 100).toFixed(1)}%`
                        : '0%';

                    // Loop through selected columns and populate selectedRow
                    selectedColumns.forEach(column => {
                        if (column.checked) {
                            if (column.key === 'pending') {
                                selectedRow[column.label] = pending;
                            } else if (column.key === 'completionPercentage') {
                                selectedRow[column.label] = completionPercentage;
                            } else if (column.key === 'rfiSubmissionPercentage') {
                                selectedRow[column.label] = rfiSubmissionPercentage;
                            } else {
                                selectedRow[column.label] = row[column.key] || ''; // Fallback to empty string if value is null/undefined
                            }
                        }
                    });

                    return selectedRow;
                });

                // Check if there's data to export
                if (exportData.length === 0) {
                    reject('No data available for export.');
                    return;
                }

                // Create and download Excel file
                const worksheet = XLSX.utils.json_to_sheet(exportData);
                const workbook = XLSX.utils.book_new();
                XLSX.utils.book_append_sheet(workbook, worksheet, 'Daily Work Summary');
                XLSX.writeFile(workbook, 'DailyWorkSummary.xlsx');

                resolve('Export successful!');
                closeModal(); // Close modal after successful export

            } catch (error) {
                reject('Failed to export data. Please try again.');
                console.error("Error exporting data to Excel:", error); // Log the actual error for debugging
            }
        });

        showToast.promise(promise, {
            pending: 'Exporting data to Excel...',
            success: 'Export successful!',
            error: 'Failed to export data. Please try again.',
        });
    };





    return (
        <Dialog.Root open={open} onOpenChange={(v) => { if (!v) closeModal(); }}>
            <Dialog.Content maxWidth="520px">
                <Flex justify="between" align="center" mb="3">
                    <Dialog.Title mb="0">Export Daily Works Summary</Dialog.Title>
                    <Button variant="ghost" size="1" onClick={closeModal}><Cross2Icon /></Button>
                </Flex>

                <Table.Root variant="surface" mb="4">
                    <Table.Header>
                        <Table.Row>
                            <Table.ColumnHeaderCell>Column Label</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Include in Export</Table.ColumnHeaderCell>
                        </Table.Row>
                    </Table.Header>
                    <Table.Body>
                        {selectedColumns.map((column, index) => (
                            <Table.Row key={column.key}>
                                <Table.Cell><Text size="2">{column.label}</Text></Table.Cell>
                                <Table.Cell>
                                    <Checkbox
                                        checked={column.checked}
                                        onCheckedChange={() => handleCheckboxChange(index)}
                                    />
                                </Table.Cell>
                            </Table.Row>
                        ))}
                    </Table.Body>
                </Table.Root>

                <Flex justify="center">
                    <Button color="indigo" onClick={() => exportToExcel(selectedColumns)}>
                        <DownloadIcon /> Download
                    </Button>
                </Flex>
            </Dialog.Content>
        </Dialog.Root>
    );
};

export default DailyWorkSummaryDownloadForm;
