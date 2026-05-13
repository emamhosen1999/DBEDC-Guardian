import React, { useState, useCallback } from "react";
import { Dialog, Button, Badge, Separator, Box, Flex, Text, ScrollArea } from '@radix-ui/themes';
import { UploadIcon, FileTextIcon, CheckCircledIcon, ExclamationTriangleIcon, Cross2Icon, InfoCircledIcon, DownloadIcon } from '@radix-ui/react-icons';
import { showToast } from "@/utils/toastUtils";
import { useDropzone } from 'react-dropzone';
import axios from 'axios';
import ImportPreviewModalRadix from './ImportPreviewModalRadix';

const DailyWorksUploadForm = ({ open, closeModal, setTotalRows, setData, refreshData, onSuccess }) => {
    // Expected Excel format data - based on actual project format
    const expectedFormat = [
        { column: 'A', field: 'Date', example: '4/27/2025', required: true, processed: true },
        { column: 'B', field: 'RFI Number', example: 'S2025-0425-9663, E2025-0426-14687, P2025-0427-3180', required: true, processed: true },
        { column: 'C', field: 'Work Type', example: 'Structure, Embankment, Pavement', required: true, processed: true },
        { column: 'D', field: 'Description', example: 'Isolation Barrier (Type-2, Steel Post) Installation Work', required: true, processed: true },
        { column: 'E', field: 'Location/Chainage', example: 'K05+560-K05+660', required: true, processed: true },
        { column: 'F', field: 'Quantity/Layer', example: '150 MT, 2 Layers, 500 SQM', required: false, processed: false },
        { column: 'G', field: 'Side (Optional)', example: 'TR-R, TR-L, SR-L, SR-R', required: false, processed: false },
        { column: 'H', field: 'Time (Optional)', example: '3:00 PM, 4:00 PM, 9:00 AM', required: false, processed: false },
    ];

    const [file, setFile] = useState(null);
    const [processing, setProcessing] = useState(false);
    const [uploadProgress, setUploadProgress] = useState(0);
    const [dragActive, setDragActive] = useState(false);
    const [validationErrors, setValidationErrors] = useState([]);
    const [serverErrors, setServerErrors] = useState({});
    const [previewData, setPreviewData] = useState(null);
    const [showPreviewModal, setShowPreviewModal] = useState(false);
    const [previewLoading, setPreviewLoading] = useState(false);

    // Validate file before processing
    const validateFile = (file) => {
        const errors = [];
        const maxSize = 10 * 1024 * 1024; // 10MB
        const allowedTypes = [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', // .xlsx
            'application/vnd.ms-excel', // .xls
            'text/csv' // .csv
        ];

        if (!allowedTypes.includes(file.type)) {
            errors.push('Please upload an Excel file (.xlsx, .xls) or CSV file (.csv)');
        }

        if (file.size > maxSize) {
            errors.push('File size must be less than 10MB');
        }

        return errors;
    };

    // Handle file selection
    const onFileChange = useCallback((acceptedFiles) => {
        if (acceptedFiles.length > 0) {
            const selectedFile = acceptedFiles[0];
            const errors = validateFile(selectedFile);
            
            if (errors.length > 0) {
                setValidationErrors(errors);
                showToast.error('File validation failed');
                return;
            }

            setFile(selectedFile);
            setValidationErrors([]);
            setServerErrors({});
            setPreviewData(null);
        }
    }, []);

    // Clear selected file
    const clearFile = () => {
        setFile(null);
        setValidationErrors([]);
        setServerErrors({});
        setPreviewData(null);
        setShowPreviewModal(false);
        setUploadProgress(0);
    };

    // Handle preview
    const handlePreview = async () => {
        if (!file) {
            showToast.error('Please select a file to preview');
            return;
        }

        setPreviewLoading(true);
        setServerErrors({});

        const formData = new FormData();
        formData.append('file', file);

        try {
            const csrfToken = document.head.querySelector('meta[name="csrf-token"]')?.content
                || document.querySelector('input[name="_token"]')?.value
                || window.Laravel?.csrfToken;

            const response = await axios.post(route('dailyWorks.previewImport'), formData, {
                headers: {
                    'Content-Type': 'multipart/form-data',
                    ...(csrfToken && { 'X-CSRF-TOKEN': csrfToken }),
                }
            });

            if (response.status === 200) {
                // New shape: { token, incharges, sheets }
                setPreviewData(response.data.summary || response.data);
                setShowPreviewModal(true);
                showToast.success('Preview generated successfully');
            }
        } catch (error) {
            console.error('Preview error:', error);

            if (error.response) {
                if (error.response.status === 422) {
                    setServerErrors(error.response.data.errors || {});
                    showToast.error('Validation failed');
                } else {
                    const errorMessage = error.response.data.error || error.response.data.message || 'Preview failed';
                    setServerErrors({ general: [errorMessage] });
                    showToast.error(errorMessage);
                }
            } else {
                showToast.error('Failed to generate preview');
            }
        } finally {
            setPreviewLoading(false);
        }
    };

    // Setup dropzone
    const { getRootProps, getInputProps, isDragActive } = useDropzone({
        onDrop: onFileChange,
        accept: {
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': ['.xlsx'],
            'application/vnd.ms-excel': ['.xls'],
            'text/csv': ['.csv']
        },
        multiple: false,
        onDragEnter: () => setDragActive(true),
        onDragLeave: () => setDragActive(false)
    });

    // Get file icon based on type
    const getFileIcon = (file) => {
        if (!file) return <UploadIcon style={{ width: 32, height: 32, color: 'var(--gray-9)' }} />;
        if (file.type.includes('sheet') || file.type.includes('excel')) {
            return <FileTextIcon style={{ width: 32, height: 32, color: 'var(--green-9)' }} />;
        } else if (file.type.includes('csv')) {
            return <FileTextIcon style={{ width: 32, height: 32, color: 'var(--blue-9)' }} />;
        }
        return <FileTextIcon style={{ width: 32, height: 32 }} />;
    };

    // Format file size
    const formatFileSize = (bytes) => {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    };

    // Handle form submission (actual import after preview confirmation)
    // overrides: { rfiNumber: inchargeId } - only for RFIs whose incharge was changed
    const handleConfirmImport = async (overrides = {}) => {
        if (!file && !previewData?.token) {
            showToast.error('Please select a file to upload');
            return;
        }

        setProcessing(true);
        setUploadProgress(0);

        const csrfToken = document.head.querySelector('meta[name="csrf-token"]')?.content
            || document.querySelector('input[name="_token"]')?.value
            || window.Laravel?.csrfToken;

        // Prefer token-based confirm (no re-upload). Fallback to file upload.
        const formData = new FormData();
        if (previewData?.token) {
            formData.append('token', previewData.token);
        } else if (file) {
            formData.append('file', file);
        }
        formData.append('incharge_overrides', JSON.stringify(overrides || {}));

        const promise = new Promise(async (resolve, reject) => {
            try {
                const response = await axios.post(route('dailyWorks.import'), formData, {
                    headers: {
                        'Content-Type': 'multipart/form-data',
                        ...(csrfToken && { 'X-CSRF-TOKEN': csrfToken }),
                    },
                    onUploadProgress: (progressEvent) => {
                        if (!progressEvent.total) return;
                        const percentCompleted = Math.round((progressEvent.loaded * 100) / progressEvent.total);
                        setUploadProgress(percentCompleted);
                    }
                });

                if (response.status === 200) {
                    // Clear form data and close modal first
                    clearFile();
                    setServerErrors({});
                    closeModal();

                    // Call onSuccess callback with import results (includes date info)
                    // This allows parent to update date range/selectedDate
                    if (typeof onSuccess === 'function') {
                        onSuccess(response.data.results);
                    } else {
                        // Fallback: Legacy behavior - set data directly and refresh
                        if (response.data.data) {
                            setData(response.data.data);
                        }
                        if (response.data.total) {
                            setTotalRows(response.data.total);
                        }
                        if (typeof refreshData === 'function') {
                            refreshData();
                        }
                    }

                    resolve(response.data.message || 'Daily works imported successfully.');
                }
            } catch (error) {
                console.error('Upload error:', error);

                if (error.response) {
                    console.error('Error response status:', error.response.status);
                    console.error('Error response data:', error.response.data);
                    console.error('Error response headers:', error.response.headers);

                    if (error.response.status === 422) {
                        // Handle validation errors
                        setServerErrors(error.response.data.errors || {});
                        reject(error.response.data.error || 'Failed to import daily works');
                    } else if (error.response.status === 500) {
                        // Handle server errors
                        const errorMessage = error.response.data.error || error.response.data.message || 'Internal server error occurred';
                        setServerErrors({ general: [errorMessage] });
                        reject(`Server Error: ${errorMessage}`);
                    } else {
                        // Handle other HTTP errors
                        const errorMessage = error.response.data.message || error.response.data.error || 'An unexpected error occurred';
                        setServerErrors({ general: [errorMessage] });
                        reject(`HTTP Error ${error.response.status}: ${errorMessage}`);
                    }
                } else if (error.request) {
                    // The request was made but no response was received
                    console.error('No response received:', error.request);
                    setServerErrors({ general: ['No response received from the server. Please check your internet connection.'] });
                    reject('No response received from the server. Please check your internet connection.');
                } else {
                    // Something happened in setting up the request that triggered an Error
                    console.error('Request setup error:', error.message);
                    setServerErrors({ general: ['An error occurred while setting up the request.'] });
                    reject('An error occurred while setting up the request.');
                }
            } finally {
                setProcessing(false);
            }
        });

        showToast.promise(promise, {
            loading: 'Uploading file...',
            success: (data) => data,
            error: (data) => data,
        });
    };

    // Handle modal close
    const handleClose = () => {
        if (!processing) {
            clearFile();
            setServerErrors({});
            closeModal();
        }
    };


    return (
        <>
            <Dialog.Root open={open && !showPreviewModal} onOpenChange={(v) => { if (!v && !processing && !showPreviewModal) handleClose(); }}>
                <Dialog.Content maxWidth="700px" style={{ fontFamily: `var(--fontFamily,"Inter")` }} aria-describedby={undefined}>
                    <Dialog.Title>
                        <Flex align="center" gap="3">
                            <Box p="2" style={{ borderRadius: 8, background: 'var(--accent-a3)' }}>
                                <UploadIcon style={{ width: 20, height: 20, color: 'var(--accent-9)' }} />
                            </Box>
                            <Flex direction="column">
                                <Text weight="bold" size="3" as="p">Import Daily Works</Text>
                                <Text size="1" color="gray" as="p">Upload Excel or CSV file to import multiple daily work entries</Text>
                            </Flex>
                        </Flex>
                    </Dialog.Title>

                    <Box style={{ overflowY: 'auto', maxHeight: 'min(70vh, 560px)' }} py="3">
                        <Flex direction="column" gap="5">
                            {/* Dropzone */}
                            <Flex
                                {...getRootProps()}
                                style={{ cursor: 'pointer',
                                    border: `2px dashed ${isDragActive ? 'var(--accent-8)' : file ? 'var(--green-8)' : 'var(--gray-a6)'}`,
                                    background: isDragActive ? 'var(--accent-a2)' : file ? 'var(--green-a2)' : 'transparent',
                                    borderRadius: 8, padding: 32, textAlign: 'center',
                                }}
                            >
                                <input {...getInputProps()} />
                                <Flex direction="column" align="center" gap="3">
                                    <Box p="4" style={{ borderRadius: '50%', background: file ? 'var(--green-a3)' : 'var(--gray-a3)' }}>
                                        {getFileIcon(file)}
                                    </Box>
                                    {file ? (
                                        <Flex direction="column">
                                            <Text size="3" weight="medium" as="p">{file.name}</Text>
                                            <Text size="2" color="gray" as="p">{formatFileSize(file.size)}</Text>
                                            <Flex align="center" gap="2" justify="center" mt="2">
                                                <Badge color="green" variant="soft" size="1"><CheckCircledIcon style={{ width: 10, height: 10 }} /> File Ready</Badge>
                                                <Button type="button" variant="ghost" color="red" size="1" onClick={(e) => { e.stopPropagation(); clearFile(); }}>
                                                    <Cross2Icon style={{ width: 12, height: 12 }} />
                                                </Button>
                                            </Flex>
                                        </Flex>
                                    ) : (
                                        <Flex direction="column">
                                            <Text size="3" weight="medium" as="p">{isDragActive ? 'Drop your file here' : 'Choose file to upload'}</Text>
                                            <Text size="2" color="gray" as="p">Drag and drop your Excel or CSV file here, or click to browse</Text>
                                            <Text size="1" color="gray" as="p">Supported formats: .xlsx, .xls, .csv (Max: 10MB)</Text>
                                        </Flex>
                                    )}
                                </Flex>
                            </Flex>

                            {/* Upload progress */}
                            {processing && (
                                <Box p="4" style={{ border: '1px solid var(--gray-a4)', borderRadius: 8 }}>
                                    <Flex justify="between" mb="2">
                                        <Text size="2" weight="medium">Uploading...</Text>
                                        <Text size="2" color="gray">{uploadProgress}%</Text>
                                    </Flex>
                                    <Box style={{ width: '100%', height: 6, background: 'var(--gray-a4)', borderRadius: 3, overflow: 'hidden' }}>
                                        <Box style={{ width: `${uploadProgress}%`, height: '100%', background: 'var(--accent-9)', transition: 'width 0.3s ease' }} />
                                    </Box>
                                </Box>
                            )}

                            {/* Validation errors */}
                            {validationErrors.length > 0 && (
                                <Box p="4" style={{ border: '1px solid var(--red-a6)', borderRadius: 8, background: 'var(--red-a3)' }}>
                                    <Flex align="start" gap="3">
                                        <ExclamationTriangleIcon style={{ width: 18, height: 18, color: 'var(--red-9)', flexShrink: 0, marginTop: 2 }} />
                                        <Flex direction="column">
                                            <Text size="2" weight="medium" style={{ color: 'var(--red-11)' }} as="p" mb="1">File Validation Errors</Text>
                                            <ul>{validationErrors.map((err, i) => <li key={i}><Text size="1" style={{ color: 'var(--red-11)' }}>• {err}</Text></li>)}</ul>
                                        </Flex>
                                    </Flex>
                                </Box>
                            )}

                            {/* Server errors */}
                            {Object.keys(serverErrors).length > 0 && (
                                <Box p="4" style={{ border: '1px solid var(--red-a6)', borderRadius: 8, background: 'var(--red-a3)' }}>
                                    <Flex align="start" gap="3">
                                        <ExclamationTriangleIcon style={{ width: 18, height: 18, color: 'var(--red-9)', flexShrink: 0, marginTop: 2 }} />
                                        <Flex direction="column">
                                            <Text size="2" weight="medium" style={{ color: 'var(--red-11)' }} as="p" mb="1">Upload Errors</Text>
                                            {Object.entries(serverErrors).map(([field, errors]) => (
                                                <Flex key={field} direction="column">
                                                    {field !== 'general' && <Text size="1" weight="medium" style={{ color: 'var(--red-11)', textTransform: 'capitalize' }} as="p">{field.replace('_', ' ')}:</Text>}
                                                    <ul>{Array.isArray(errors) ? errors.map((e, i) => <li key={i}><Text size="1" style={{ color: 'var(--red-11)' }}>• {e}</Text></li>) : <li><Text size="1" style={{ color: 'var(--red-11)' }}>• {errors}</Text></li>}</ul>
                                                </Flex>
                                            ))}
                                        </Flex>
                                    </Flex>
                                </Box>
                            )}

                            <Separator size="4" />

                            {/* Format guide */}
                            <details>
                                <summary style={{ cursor: 'pointer', listStyle: 'none', userSelect: 'none' }}>
                                    <Flex align="center" gap="2" p="2" style={{ borderRadius: 6, border: '1px solid var(--gray-a4)' }}>
                                        <InfoCircledIcon style={{ width: 18, height: 18, color: 'var(--accent-9)', flexShrink: 0 }} />
                                        <Flex direction="column" style={{ flex: 1 }}>
                                            <Text size="2" weight="medium" as="p">Expected File Format</Text>
                                            <Text size="1" color="gray" as="p">Click to view the required Excel/CSV structure</Text>
                                        </Flex>
                                    </Flex>
                                </summary>
                                <Flex direction="column" gap="3" mt="3">
                                    <Box p="3" style={{ background: 'var(--gray-a2)', borderRadius: 6 }}>
                                        <Text size="2" weight="medium" as="p" mb="1">Excel/CSV Column Structure:</Text>
                                        <Text size="1" color="gray" as="p">Your file should have exactly 8 columns in this order (with or without headers):</Text>
                                    </Box>
                                    <ScrollArea style={{ maxHeight: 240 }}>
                                        <table style={{ width: '100%', borderCollapse: 'collapse' }}>
                                            <thead>
                                                <tr style={{ borderBottom: '1px solid var(--gray-a4)' }}>
                                                    {['COL', 'FIELD', 'EXAMPLE', 'REQ', 'PROC'].map(h => <th key={h} style={{ padding: '5px 8px', textAlign: 'left' }}><Text size="1" weight="bold" color="gray">{h}</Text></th>)}
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {expectedFormat.map((item, i) => (
                                                    <tr key={i} style={{ borderBottom: '1px solid var(--gray-a3)' }}>
                                                        <td style={{ padding: '5px 8px' }}><Badge size="1" color="indigo" variant="soft">{item.column}</Badge></td>
                                                        <td style={{ padding: '5px 8px' }}><Text size="1" weight="medium">{item.field}</Text></td>
                                                        <td style={{ padding: '5px 8px' }}><Text size="1" color="gray">{item.example}</Text></td>
                                                        <td style={{ padding: '5px 8px' }}>{item.required ? <Badge size="1" color="red" variant="soft">Required</Badge> : <Badge size="1" color="gray" variant="soft">Optional</Badge>}</td>
                                                        <td style={{ padding: '5px 8px' }}>{item.processed ? <Badge size="1" color="green" variant="soft">Yes</Badge> : <Badge size="1" color="amber" variant="soft">No</Badge>}</td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </ScrollArea>
                                    <Box p="3" mt="3" style={{ background: 'var(--accent-a2)', border: '1px solid var(--accent-a4)', borderRadius: 6 }}>
                                        <Flex align="start" gap="2">
                                            <InfoCircledIcon style={{ width: 12, height: 12, color: 'var(--accent-9)', flexShrink: 0, marginTop: 2 }} />
                                            <Text size="1" style={{ color: 'var(--accent-11)' }}><strong>Format Tips:</strong> Only columns A-E are processed. Work Type: Structure, Embankment, Pavement. Quantity: 150 MT, 2 Layers, 500 SQM. Side: TR-R, TR-L, SR-R, SR-L. Location: K05+560-K05+660.</Text>
                                        </Flex>
                                    </Box>
                                    <Box p="3" mt="2" style={{ background: 'var(--amber-a2)', border: '1px solid var(--amber-a4)', borderRadius: 6 }}>
                                        <Flex align="start" gap="2">
                                            <ExclamationTriangleIcon style={{ width: 12, height: 12, color: 'var(--amber-9)', flexShrink: 0, marginTop: 2 }} />
                                            <Text size="1" style={{ color: 'var(--amber-11)' }}><strong>Important:</strong> First row headers will be skipped. Date format: M/D/YYYY. RFI Number can contain multiple comma-separated values.</Text>
                                        </Flex>
                                    </Box>
                                </Flex>
                            </details>

                            {/* Template download */}
                            <details>
                                <summary style={{ cursor: 'pointer', listStyle: 'none', userSelect: 'none' }}>
                                    <Flex align="center" gap="2" p="2" style={{ borderRadius: 6, border: '1px solid var(--gray-a4)' }}>
                                        <DownloadIcon style={{ width: 18, height: 18, color: 'var(--green-9)', flexShrink: 0 }} />
                                        <Flex direction="column" style={{ flex: 1 }}>
                                            <Text size="2" weight="medium" as="p">Download Template</Text>
                                            <Text size="1" color="gray" as="p">Get a pre-formatted Excel template</Text>
                                        </Flex>
                                    </Flex>
                                </summary>
                                <Flex direction="column" gap="3" mt="3">
                                    <Text size="2" color="gray" as="p">Download our Excel template with the correct 8-column format and sample daily work data:</Text>
                                    <Button color="green" variant="soft" onClick={() => window.open(route('dailyWorks.downloadTemplate'), '_blank')}>
                                        <DownloadIcon style={{ width: 14, height: 14 }} /> Download Excel Template
                                    </Button>
                                </Flex>
                            </details>
                        </Flex>
                    </Box>

                    <Flex justify="center" gap="2" pt="3" style={{ borderTop: '1px solid var(--gray-a4)' }}>
                        <Button variant="outline" color="gray" onClick={handleClose} disabled={processing} size="2">Cancel</Button>
                        <Button color="indigo" onClick={handlePreview} loading={previewLoading} disabled={!file || validationErrors.length > 0} size="2">
                            {!previewLoading && <UploadIcon style={{ width: 14, height: 14 }} />}
                            {previewLoading ? 'Generating Preview...' : 'Preview Import'}
                        </Button>
                    </Flex>
                </Dialog.Content>
            </Dialog.Root>

            <ImportPreviewModalRadix
                isOpen={showPreviewModal}
                onClose={() => !processing && setShowPreviewModal(false)}
                previewData={previewData}
                onConfirm={handleConfirmImport}
                isImporting={processing}
                importProgress={uploadProgress}
            />
        </>
    );
};

export default DailyWorksUploadForm;