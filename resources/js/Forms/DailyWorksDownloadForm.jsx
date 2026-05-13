import React, { useEffect, useState } from "react";
import { Dialog, Button, Flex, Box, Text, ScrollArea } from '@radix-ui/themes';
import { DownloadIcon } from '@radix-ui/react-icons';

import { showToast } from "@/utils/toastUtils";
import * as XLSX from 'xlsx';
import axios from "axios";


const DailyWorksDownloadForm = ({ open, closeModal, search, filterData, users }) => {
    const [data, setData] = useState([]);

    useEffect(() => {
        const fetchData = async () => {
            try {
                const response = await axios.get(route('dailyWorks.all'), {
                    params: {
                        search,
                        status: filterData.status !== 'all' ? filterData.status : '',
                        inCharge: filterData.incharge !== 'all' ? filterData.incharge : '',
                        startDate: filterData.startDate,
                        endDate: filterData.endDate,
                    }
                });
                setData(response.data);
            } catch (error) {
                console.error(error);
                showToast.error('Failed to fetch data.');
            }
        };

        fetchData();
    }, [filterData, search]);

    const [processing, setProcessing] = useState(false);

    const columns = [

        { label: 'Date', key: 'date'  },
        { label: 'RFI No.', key: 'number' },
        { label: 'Status', key: 'status' },
        { label: 'Assigned', key: 'assigned'},  // Visible for admin users
        { label: 'In charge', key: 'incharge' },   // Visible for SE users
        { label: 'Type', key: 'type' },
        { label: 'Description', key: 'description' },
        { label: 'Location', key: 'location' },
        { label: 'Side', key: 'side' },
        { label: 'Quantity/Layer', key: 'qty_layer' },
        { label: 'Planned Time', key: 'planned_time' },
        { label: 'Completion Time', key: 'completion_time' },
        { label: 'Results', key: 'inspection_details' },
        { label: 'Resubmission Count', key: 'resubmission_count' },
        { label: 'RFI Submission Date', key: 'rfi_submission_date' },


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
                const exportData = data.map(row => {
                    const selectedRow = {};
                    selectedColumns.forEach(column => {
                        if (column.checked) {
                            if (column.key === 'incharge' || column.key === 'assigned') {
                                const user = users.find(user => user.id === row[column.key]);
                                selectedRow[column.label] = user ? user.name : 'N/A';
                            } else if (column.key === 'status') {
                                const statusValue = row[column.key];
                                selectedRow[column.label] = statusValue.charAt(0).toUpperCase() + statusValue.slice(1);
                            } else {
                                selectedRow[column.label] = row[column.key];
                            }
                        }
                    });
                    return selectedRow;
                });

                const worksheet = XLSX.utils.json_to_sheet(exportData);
                const workbook = XLSX.utils.book_new();
                XLSX.utils.book_append_sheet(workbook, worksheet, 'Daily Works');
                XLSX.writeFile(workbook, 'DailyWorks.xlsx');
                resolve('Export successful!');
                closeModal();
            } catch (error) {
                reject('Failed to export data. Please try again.');
                console.error("Error exporting data to Excel:", error);
            }
        });

        showToast.promise(promise, {
            loading: 'Exporting data to Excel...',
            success: (msg) => msg,
            error: (msg) => msg,
        });
    };





    return (
        <Dialog.Root open={open} onOpenChange={(v) => { if (!v) closeModal(); }}>
            <Dialog.Content maxWidth="480px" style={{ fontFamily: `var(--fontFamily,"Inter")` }}>
                <Dialog.Title>
                    <Flex align="center" gap="2">
                        <DownloadIcon style={{ width: 18, height: 18, color: 'var(--accent-9)' }} />
                        <Text weight="bold">Export Daily Works</Text>
                    </Flex>
                </Dialog.Title>
                <Dialog.Description size="2" color="gray" mb="3">Select the columns to include in the export</Dialog.Description>

                <ScrollArea style={{ maxHeight: 360 }}>
                    <table style={{ width: '100%', borderCollapse: 'collapse' }}>
                        <thead>
                            <tr style={{ borderBottom: '1px solid var(--gray-a4)' }}>
                                <th style={{ textAlign: 'left', padding: '6px 8px' }}><Text size="1" weight="bold" color="gray">COLUMN</Text></th>
                                <th style={{ textAlign: 'center', padding: '6px 8px', width: 80 }}><Text size="1" weight="bold" color="gray">INCLUDE</Text></th>
                            </tr>
                        </thead>
                        <tbody>
                            {selectedColumns.map((column, index) => (
                                <tr key={column.key} style={{ borderBottom: '1px solid var(--gray-a3)' }}>
                                    <td style={{ padding: '6px 8px' }}><Text size="2">{column.label}</Text></td>
                                    <td style={{ padding: '6px 8px', textAlign: 'center' }}>
                                        <input
                                            type="checkbox"
                                            checked={column.checked}
                                            onChange={() => handleCheckboxChange(index)}
                                            style={{ width: 16, height: 16, cursor: 'pointer', accentColor: 'var(--accent-9)' }}
                                        />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </ScrollArea>

                <Flex justify="between" align="center" pt="3" mt="2" style={{ borderTop: '1px solid var(--gray-a4)' }}>
                    <Text size="1" color="gray">{selectedColumns.filter(c => c.checked).length} of {selectedColumns.length} columns selected</Text>
                    <Flex gap="2">
                        <Button variant="ghost" color="gray" onClick={closeModal}>Cancel</Button>
                        <Button color="indigo" onClick={() => exportToExcel(selectedColumns)} disabled={!selectedColumns.some(c => c.checked)}>
                            <DownloadIcon style={{ width: 14, height: 14 }} /> Download
                        </Button>
                    </Flex>
                </Flex>
            </Dialog.Content>
        </Dialog.Root>
    );
};

export default DailyWorksDownloadForm;
