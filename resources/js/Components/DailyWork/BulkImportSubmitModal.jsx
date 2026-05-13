import React, { useState, useEffect, useRef } from 'react';
import { Dialog, Button, Badge, Separator, Card, Box, Flex, TextArea, Text, ScrollArea } from '@radix-ui/themes';
import {
    ExclamationTriangleIcon,
    FileTextIcon,
    CheckCircledIcon,
    CrossCircledIcon,
    UploadIcon,
    DownloadIcon,
    QuestionMarkCircledIcon,
    ReloadIcon,
} from "@radix-ui/react-icons";
import axios from 'axios';
import { showToast } from '@/utils/toastUtils';

/**
 * BulkImportSubmitModal - Modal for importing RFI submission dates from Excel.
 * Excel should have two columns: RFI Number and Submission Date.
 */
const BulkImportSubmitModal = ({
    isOpen,
    onClose,
    onSuccess,
}) => {
    const [loading, setLoading] = useState(false);
    const [step, setStep] = useState('upload'); // 'upload', 'validation-errors', 'objection-decision', 'result'
    const [file, setFile] = useState(null);
    const [dragActive, setDragActive] = useState(false);
    const [objectedWorks, setObjectedWorks] = useState([]);
    const [overrideReason, setOverrideReason] = useState('');
    const [result, setResult] = useState(null);
    const [parseSummary, setParseSummary] = useState(null);
    const [validationErrors, setValidationErrors] = useState(null);
    const fileInputRef = useRef(null);

    // Reset state when modal opens
    useEffect(() => {
        if (isOpen) {
            setStep('upload');
            setFile(null);
            setOverrideReason('');
            setResult(null);
            setParseSummary(null);
            setValidationErrors(null);
            setObjectedWorks([]);
        }
    }, [isOpen]);

    const handleDrag = (e) => {
        e.preventDefault();
        e.stopPropagation();
        if (e.type === "dragenter" || e.type === "dragover") {
            setDragActive(true);
        } else if (e.type === "dragleave") {
            setDragActive(false);
        }
    };

    const handleDrop = (e) => {
        e.preventDefault();
        e.stopPropagation();
        setDragActive(false);

        if (e.dataTransfer.files && e.dataTransfer.files[0]) {
            validateAndSetFile(e.dataTransfer.files[0]);
        }
    };

    const handleFileChange = (e) => {
        if (e.target.files && e.target.files[0]) {
            validateAndSetFile(e.target.files[0]);
        }
    };

    const validateAndSetFile = (selectedFile) => {
        const validTypes = [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-excel',
            'text/csv',
        ];
        const validExtensions = ['.xlsx', '.xls', '.csv'];

        const fileName = selectedFile.name.toLowerCase();
        const hasValidExtension = validExtensions.some(ext => fileName.endsWith(ext));

        if (!validTypes.includes(selectedFile.type) && !hasValidExtension) {
            showToast.error('Please upload an Excel file (.xlsx, .xls) or CSV file');
            return;
        }

        if (selectedFile.size > 10 * 1024 * 1024) {
            showToast.error('File size should be less than 10MB');
            return;
        }

        setFile(selectedFile);
    };

    const handleUpload = async (skipObjected = false, overrideObjected = false) => {
        if (!file) {
            showToast.error('Please select a file');
            return;
        }

        setLoading(true);
        try {
            const formData = new FormData();
            formData.append('file', file);
            formData.append('skip_objected', skipObjected ? '1' : '0');
            formData.append('override_objected', overrideObjected ? '1' : '0');
            if (overrideObjected && overrideReason) {
                formData.append('override_reason', overrideReason);
            }

            const response = await axios.post(route('dailyWorks.bulkImportSubmit'), formData, {
                headers: {
                    'Content-Type': 'multipart/form-data',
                },
            });

            if (response.data.requires_decision) {
                // Need user decision about objected works
                setObjectedWorks(response.data.objected_works || []);
                setParseSummary({
                    total: response.data.total_count,
                    objected: response.data.objected_count,
                    clean: response.data.clean_count,
                    notFound: response.data.not_found_count || 0,
                    invalid: response.data.invalid_count || 0,
                });
                setStep('objection-decision');
            } else {
                // Success
                setResult(response.data);
                setStep('result');
                showToast.success(response.data.message);
            }
        } catch (error) {
            if (error.response?.data?.requires_decision) {
                setObjectedWorks(error.response.data.objected_works || []);
                setParseSummary({
                    total: error.response.data.total_count,
                    objected: error.response.data.objected_count,
                    clean: error.response.data.clean_count,
                    notFound: error.response.data.not_found_count || 0,
                    invalid: error.response.data.invalid_count || 0,
                    notFoundList: error.response.data.not_found || [],
                    invalidList: error.response.data.invalid || [],
                });
                setStep('objection-decision');
            } else if (error.response?.data?.invalid || error.response?.data?.not_found) {
                // Show validation errors step
                setValidationErrors({
                    error: error.response?.data?.error || 'Validation errors found',
                    invalid: error.response?.data?.invalid || [],
                    notFound: error.response?.data?.not_found || [],
                });
                setStep('validation-errors');
            } else {
                showToast.error(error.response?.data?.error || 'Failed to import file');
            }
        } finally {
            setLoading(false);
        }
    };

    const handleSkipObjected = () => {
        handleUpload(true, false);
    };

    const handleOverrideObjected = () => {
        if (!overrideReason.trim()) {
            showToast.error('Please provide a reason for overriding objected RFIs');
            return;
        }
        handleUpload(false, true);
    };

    const handleDownloadTemplate = () => {
        window.location.href = route('dailyWorks.bulkImportTemplate');
    };

    const handleClose = () => {
        if (step === 'result' && result) {
            onSuccess?.(result);
        }
        onClose();
    };

    return (
        <Dialog.Root open={isOpen} onOpenChange={(v) => { if (!v && !loading) handleClose(); }}>
            <Dialog.Content maxWidth="640px" style={{ fontFamily: `var(--fontFamily,"Inter")` }}>
                <>
                        <Dialog.Title>
                            <Flex align="center" gap="2" wrap="wrap">
                                <DownloadIcon style={{ width: 20, height: 20, flexShrink: 0, color: 'var(--accent-9)' }} />
                                <Text weight="bold" size={{ initial: '2', sm: '3' }}>Import RFI Submissions</Text>
                            </Flex>
                        </Dialog.Title>
                        <Dialog.Description size="2" color="gray" mb="3">Upload an Excel file with RFI numbers and submission dates</Dialog.Description>

                        <Box style={{ overflowY: 'auto', maxHeight: 'min(65vh, 500px)' }} py="2">
                            {/* Step 1: Upload file */}
                            {step === 'upload' && (
                                <Flex direction="column" gap="3">
                                    {/* Template download */}
                                    <Box p="3" style={{ background: 'var(--accent-a2)', border: '1px solid var(--accent-a6)', borderRadius: 'var(--radius-2)' }}>
                                        <Flex align="start" gap="2">
                                            <QuestionMarkCircledIcon style={{ width: 16, height: 16, flexShrink: 0, marginTop: '0.125rem', color: 'var(--accent-9)' }} />
                                            <Flex direction="column" style={{ flex: 1 }}>
                                                <Text weight="medium" style={{ color: 'var(--accent-11)', fontSize: 12 }}>
                                                    Need a template?
                                                </Text>
                                                <Text size="1" style={{ color: 'var(--accent-10)', marginTop: 4 }}>
                                                    Download the Excel template with the correct format (RFI Number, Submission Date).
                                                </Text>
                                                <Button size="1" color="indigo" variant="soft" style={{ marginTop: 8, width: '100%' }} onClick={handleDownloadTemplate}>
                                                    <DownloadIcon style={{ width: 14, height: 14 }} /> Download Template
                                                </Button>
                                            </Flex>
                                        </Flex>
                                    </Box>

                                    {/* File drop zone */}
                                    <Box
                                        style={{
                                            position: 'relative',
                                            border: '2px dashed',
                                            borderRadius: 'var(--radius-2)',
                                            padding: '24px 32px',
                                            textAlign: 'center',
                                            transition: 'background-color 0.2s',
                                            borderColor: dragActive
                                                ? 'var(--accent-8)'
                                                : file
                                                ? 'var(--green-8)'
                                                : 'var(--gray-a6)',
                                            background: dragActive
                                                ? 'var(--accent-a2)'
                                                : file
                                                ? 'var(--green-a2)'
                                                : 'transparent'
                                        }}
                                        onDragEnter={handleDrag}
                                        onDragLeave={handleDrag}
                                        onDragOver={handleDrag}
                                        onDrop={handleDrop}
                                    >
                                        <input
                                            ref={fileInputRef}
                                            type="file"
                                            accept=".xlsx,.xls,.csv"
                                            onChange={handleFileChange}
                                            style={{ display: 'none' }}
                                        />

                                        {file ? (
                                            <Flex direction="column" gap="2">
                                                <CheckCircledIcon style={{ width: 40, height: 40, color: 'var(--green-9)', margin: '0 auto' }} />
                                                <Text weight="medium" style={{ color: 'var(--green-11)', fontSize: 14, truncate: true, padding: '0 16px' }}>
                                                    {file.name}
                                                </Text>
                                                <Text size="1" style={{ color: 'var(--gray-11)' }}>
                                                    {(file.size / 1024).toFixed(1)} KB
                                                </Text>
                                                <Button size="1" variant="ghost" color="red" onClick={() => setFile(null)} style={{ width: '100%' }}>Remove</Button>
                                            </Flex>
                                        ) : (
                                            <Flex direction="column" gap="2">
                                                <UploadIcon style={{ width: 40, height: 40, color: 'var(--gray-9)', margin: '0 auto' }} />
                                                <Text weight="medium" style={{ color: 'var(--gray-12)', fontSize: 14 }}>
                                                    Drag and drop your file here
                                                </Text>
                                                <Text size="2" style={{ color: 'var(--gray-11)' }}>or</Text>
                                                <Button size="1" color="indigo" variant="soft" onClick={() => fileInputRef.current?.click()} style={{ width: '100%' }}>Browse Files</Button>
                                                <Text size="1" style={{ color: 'var(--gray-10)', marginTop: 8 }}>
                                                    Supported: .xlsx, .xls, .csv (max 10MB)
                                                </Text>
                                            </Flex>
                                        )}
                                    </Box>

                                    {/* Format info */}
                                    <Box p="3" style={{ background: 'var(--gray-a2)', borderRadius: 'var(--radius-2)' }}>
                                        <Text weight="medium" size="1" mb="2">Expected Format:</Text>
                                        <ScrollArea style={{ overflowX: 'auto', marginLeft: -12, marginRight: -12, paddingLeft: 12, paddingRight: 12 }}>
                                            <table style={{ fontSize: 12, width: '100%' }}>
                                                <thead>
                                                    <tr style={{ borderBottom: '1px solid var(--gray-a4)' }}>
                                                        <th style={{ textAlign: 'left', padding: '8px', fontWeight: 600 }}>RFI Number</th>
                                                        <th style={{ textAlign: 'left', padding: '8px', fontWeight: 600 }}>Submission Date</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <tr style={{ borderBottom: '1px solid var(--gray-a3)' }}>
                                                        <td style={{ padding: '8px', color: 'var(--gray-11)' }}>RFI-001</td>
                                                        <td style={{ padding: '8px', color: 'var(--gray-11)' }}>2024-12-20</td>
                                                    </tr>
                                                    <tr>
                                                        <td style={{ padding: '8px', color: 'var(--gray-11)' }}>RFI-002</td>
                                                        <td style={{ padding: '8px', color: 'var(--gray-11)' }}>2024-12-21</td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </ScrollArea>
                                    </Box>
                                </Flex>
                            )}

                            {/* Validation Errors Step */}
                            {step === 'validation-errors' && validationErrors && (
                                <Flex direction="column" gap="4">
                                    {/* Error Header */}
                                    <Box p="4" style={{ background: 'var(--red-a2)', border: '1px solid var(--red-a6)', borderRadius: 'var(--radius-2)' }}>
                                        <Flex align="center" gap="2" style={{ color: 'var(--red-11)' }}>
                                            <ExclamationTriangleIcon style={{ width: 20, height: 20 }} />
                                            <Text weight="semibold">{validationErrors.error}</Text>
                                        </Flex>
                                        <Text size="2" style={{ color: 'var(--red-11)', marginTop: 8 }} as="p">
                                            Please check your file format and try again. The expected format is:
                                            <Text weight="bold"> Column A: RFI Number, Column B: Submission Date (YYYY-MM-DD)</Text>
                                        </Text>
                                    </Box>

                                    {/* Invalid Rows */}
                                    {validationErrors.invalid?.length > 0 && (
                                        <Flex direction="column" gap="2">
                                            <Text weight="medium" size="2" style={{ color: 'var(--red-11)' }} as="p">
                                                <Flex align="center" gap="2">
                                                    <CrossCircledIcon style={{ width: 16, height: 16 }} />
                                                    Invalid Rows ({validationErrors.invalid.length})
                                                </Flex>
                                            </Text>
                                            <ScrollArea style={{ maxHeight: 192, borderRadius: 'var(--radius-2)', border: '1px solid var(--red-a6)' }}>
                                                <table style={{ width: '100%', fontSize: 12 }}>
                                                    <thead style={{ background: 'var(--red-a2)', position: 'sticky', top: 0 }}>
                                                        <tr>
                                                            <th style={{ textAlign: 'left', padding: '8px', fontWeight: 600 }}>Row</th>
                                                            <th style={{ textAlign: 'left', padding: '8px', fontWeight: 600 }}>RFI Number</th>
                                                            <th style={{ textAlign: 'left', padding: '8px', fontWeight: 600 }}>Error</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        {validationErrors.invalid.map((item, idx) => (
                                                            <tr key={idx} style={{ borderTop: '1px solid var(--red-a3)' }}>
                                                                <td style={{ padding: '8px', color: 'var(--gray-11)' }}>{item.row}</td>
                                                                <td style={{ padding: '8px', color: 'var(--gray-11)', fontFamily: 'monospace' }}>{item.rfi_number}</td>
                                                                <td style={{ padding: '8px', color: 'var(--red-11)' }}>{item.error}</td>
                                                            </tr>
                                                        ))}
                                                    </tbody>
                                                </table>
                                            </ScrollArea>
                                        </Flex>
                                    )}

                                    {/* Not Found Rows */}
                                    {validationErrors.notFound?.length > 0 && (
                                        <Flex direction="column" gap="2">
                                            <Text weight="medium" size="2" style={{ color: 'var(--amber-11)' }} as="p">
                                                <Flex align="center" gap="2">
                                                    <ExclamationTriangleIcon style={{ width: 16, height: 16 }} />
                                                    RFIs Not Found ({validationErrors.notFound.length})
                                                </Flex>
                                            </Text>
                                            <ScrollArea style={{ maxHeight: 128, borderRadius: 'var(--radius-2)', border: '1px solid var(--amber-a6)' }}>
                                                <table style={{ width: '100%', fontSize: 12 }}>
                                                    <thead style={{ background: 'var(--amber-a2)', position: 'sticky', top: 0 }}>
                                                        <tr>
                                                            <th style={{ textAlign: 'left', padding: '8px', fontWeight: 600 }}>Row</th>
                                                            <th style={{ textAlign: 'left', padding: '8px', fontWeight: 600 }}>RFI Number</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        {validationErrors.notFound.map((item, idx) => (
                                                            <tr key={idx} style={{ borderTop: '1px solid var(--amber-a3)' }}>
                                                                <td style={{ padding: '8px', color: 'var(--gray-11)' }}>{item.row}</td>
                                                                <td style={{ padding: '8px', color: 'var(--gray-11)', fontFamily: 'monospace' }}>{item.rfi_number}</td>
                                                            </tr>
                                                        ))}
                                                    </tbody>
                                                </table>
                                            </ScrollArea>
                                        </Flex>
                                    )}

                                    <Flex justify="center" pt="2">
                                        <Button color="indigo" variant="soft" onClick={() => { setStep('upload'); setFile(null); setValidationErrors(null); }}>
                                            <ReloadIcon style={{ width: 14, height: 14 }} /> Try Different File
                                        </Button>
                                    </Flex>
                                </Flex>
                            )}

                            {/* Step 2: Objection decision */}
                            {step === 'objection-decision' && (
                                <Flex direction="column" gap="4">
                                    {/* Parse Summary */}
                                    {parseSummary && (
                                        <Flex wrap="wrap" gap="2">
                                            <Card style={{ border: '1px solid var(--gray-a4)' }}><Box p="2" style={{ textAlign: 'center' }}><Text size="4" weight="bold" color="gray">{parseSummary.total}</Text><Text size="1" color="gray" as="p">Total Parsed</Text></Box></Card>
                                            <Card style={{ border: '1px solid var(--green-a6)', background: 'var(--green-a2)' }}><Box p="2" style={{ textAlign: 'center' }}><Text size="4" weight="bold" style={{ color: 'var(--green-11)' }}>{parseSummary.clean}</Text><Text size="1" style={{ color: 'var(--green-11)' }} as="p">Ready</Text></Box></Card>
                                            <Card style={{ border: '1px solid var(--amber-a6)', background: 'var(--amber-a2)' }}><Box p="2" style={{ textAlign: 'center' }}><Text size="4" weight="bold" style={{ color: 'var(--amber-11)' }}>{parseSummary.objected}</Text><Text size="1" style={{ color: 'var(--amber-11)' }} as="p">Objected</Text></Box></Card>
                                            <Card style={{ border: '1px solid var(--red-a6)', background: 'var(--red-a2)' }}><Box p="2" style={{ textAlign: 'center' }}><Text size="4" weight="bold" style={{ color: 'var(--red-11)' }}>{parseSummary.notFound + parseSummary.invalid}</Text><Text size="1" style={{ color: 'var(--red-11)' }} as="p">Issues</Text></Box></Card>
                                        </Flex>
                                    )}

                                    {/* Warning message */}
                                    <Box p="4" style={{ background: 'var(--amber-a2)', border: '1px solid var(--amber-a6)', borderRadius: 'var(--radius-2)' }}>
                                        <Flex align="start" gap="3">
                                            <ExclamationTriangleIcon style={{ width: 20, height: 20, flexShrink: 0, marginTop: '0.125rem', color: 'var(--amber-9)' }} />
                                            <Flex direction="column" style={{ flex: 1 }}>
                                                <Text weight="semibold" size="2" style={{ color: 'var(--amber-11)' }} as="p">
                                                    {objectedWorks.length} RFI{objectedWorks.length !== 1 ? 's have' : ' has'} active objections
                                                </Text>
                                                <Text size="1" style={{ color: 'var(--amber-10)', marginTop: 4 }} as="p">
                                                    Choose how to handle these RFIs below.
                                                </Text>
                                            </Flex>
                                        </Flex>
                                    </Box>

                                    <Box>
                                        <Text size="2" weight="medium" as="p" mb="2">RFIs with Objections:</Text>
                                        <ScrollArea style={{ maxHeight: 128 }}>
                                            <Flex direction="column" gap="1" pr="2">
                                                {objectedWorks.map((work, idx) => (
                                                    <Flex key={work.id || work.row} justify="between" align="center" p="2" style={{ borderRadius: 'var(--radius-1)', background: 'var(--amber-a2)', border: '1px solid var(--amber-a6)' }}>
                                                        <Flex align="center" gap="2">
                                                            <ExclamationTriangleIcon style={{ width: 16, height: 16, flexShrink: 0, color: 'var(--amber-9)' }} />
                                                            <Text size="2" weight="medium">{work.rfi_number}</Text>
                                                            {work.status && getStatusBadge(work.status)}
                                                        </Flex>
                                                        <Badge size="1" color="amber" variant="soft">{work.active_objections_count} objection{work.active_objections_count !== 1 ? 's' : ''}</Badge>
                                                    </Flex>
                                                ))}
                                            </Flex>
                                        </ScrollArea>
                                    </Box>

                                    <Separator size="4" />

                                    <Flex wrap="wrap" gap="4">
                                        <Card asChild><button onClick={handleSkipObjected} style={{ padding: 16, textAlign: 'left', width: '100%', cursor: 'pointer', transition: 'opacity', border: '2px solid var(--gray-a4)', borderRadius: 8 }}>
                                            <Flex direction="column" gap="2">
                                                <Flex align="center" gap="2">
                                                    <CheckCircledIcon style={{ width: 24, height: 24, color: 'var(--green-9)' }} />
                                                    <Text weight="bold">Skip Objected RFIs</Text>
                                                </Flex>
                                                <Text size="1" color="gray" as="p">Submit only {parseSummary?.clean || 0} RFI{(parseSummary?.clean || 0) !== 1 ? 's' : ''} without objections</Text>
                                            </Flex>
                                        </button></Card>
                                        <Card style={{ padding: 16, border: '2px solid var(--gray-a4)', borderRadius: 8 }}>
                                            <Flex direction="column" gap="2">
                                                <Text size="1" color="gray" as="p" mb="1">Override Reason (required to submit objected RFIs)</Text>
                                                <TextArea placeholder="Explain why you're submitting RFIs that have active objections..." value={overrideReason} onChange={(e) => setOverrideReason(e.target.value)} rows={2} />
                                                <Text size="1" color="gray" as="p" mt="1">This reason will be logged for audit purposes.</Text>
                                                <Button color="amber" style={{ width: '100%', marginTop: 8 }} onClick={handleOverrideObjected} loading={loading} disabled={!overrideReason.trim()}>Override & Submit All</Button>
                                            </Flex>
                                        </Card>
                                    </Flex>
                                </Flex>
                            )}

                            {/* Step 3: Results */}
                            {step === 'result' && result && (
                                <Flex direction="column" gap="4">
                                    <Flex wrap="wrap" gap="2">
                                        <Card style={{ border: '1px solid var(--green-a6)', background: 'var(--green-a2)' }}><Box p="2" style={{ textAlign: 'center' }}><Text size="4" weight="bold" style={{ color: 'var(--green-11)' }}>{result.submitted_count}</Text><Text size="1" style={{ color: 'var(--green-11)' }} as="p">Submitted</Text></Box></Card>
                                        <Card style={{ border: '1px solid var(--amber-a6)', background: 'var(--amber-a2)' }}><Box p="2" style={{ textAlign: 'center' }}><Text size="4" weight="bold" style={{ color: 'var(--amber-11)' }}>{result.skipped_count}</Text><Text size="1" style={{ color: 'var(--amber-11)' }} as="p">Skipped</Text></Box></Card>
                                        <Card style={{ border: '1px solid var(--red-a6)', background: 'var(--red-a2)' }}><Box p="2" style={{ textAlign: 'center' }}><Text size="4" weight="bold" style={{ color: 'var(--red-11)' }}>{result.failed_count}</Text><Text size="1" style={{ color: 'var(--red-11)' }} as="p">Failed</Text></Box></Card>
                                        <Card style={{ border: '1px solid var(--gray-a4)' }}><Box p="2" style={{ textAlign: 'center' }}><Text size="4" weight="bold" color="gray">{result.not_found_count || 0}</Text><Text size="1" color="gray" as="p">Not Found</Text></Box></Card>
                                        <Card style={{ border: '1px solid var(--gray-a4)' }}><Box p="2" style={{ textAlign: 'center' }}><Text size="4" weight="bold" color="gray">{result.invalid_count || 0}</Text><Text size="1" color="gray" as="p">Invalid</Text></Box></Card>
                                    </Flex>

                                    {/* Submitted List */}
                                    {result.submitted?.length > 0 && (
                                        <Box>
                                            <Text size="2" weight="medium" mb="2" style={{ color: 'var(--green-11)' }} as="p">
                                                <Flex align="center" gap="1">
                                                    <CheckCircledIcon style={{ width: 14, height: 14 }} />
                                                    Submitted Successfully ({result.submitted.length})
                                                </Flex>
                                            </Text>
                                            <ScrollArea style={{ maxHeight: 96 }}><Flex wrap="wrap" gap="1" pr="2">{result.submitted.map((work) => <Badge key={work.id} size="1" color="green" variant="soft">{work.number}</Badge>)}</Flex></ScrollArea>
                                        </Box>
                                    )}

                                    {/* Skipped List */}
                                    {result.skipped?.length > 0 && (
                                        <Box>
                                            <Flex align="center" justify="between" mb="2">
                                                <Text size="2" weight="medium" style={{ color: 'var(--amber-11)' }} as="p">
                                                    <Flex align="center" gap="1">
                                                        <ExclamationTriangleIcon style={{ width: 16, height: 16 }} />
                                                        Skipped (Have Objections) ({result.skipped.length})
                                                    </Flex>
                                                </Text>
                                                <Button size="1" variant="soft" color="amber" onClick={() => { const csv="RFI Number,Objections Count\n"+result.skipped.map(w=>`${w.number},${w.active_objections_count||'N/A'}`).join('\n');const b=new Blob([csv],{type:'text/csv'});const u=window.URL.createObjectURL(b);const a=document.createElement('a');a.href=u;a.download='skipped_objected_rfis.csv';a.click();window.URL.revokeObjectURL(u); }}><DownloadIcon style={{ width: 12, height: 12 }} /> Download List</Button>
                                            </Flex>
                                            <ScrollArea style={{ maxHeight: 96 }}><Flex wrap="wrap" gap="1" pr="2">{result.skipped.map((work) => <Badge key={work.id} size="1" color="amber" variant="soft">{work.number}</Badge>)}</Flex></ScrollArea>
                                        </Box>
                                    )}

                                    {result.failed?.length > 0 && (
                                        <Box>
                                            <Flex align="center" gap="1" mb="2"><CrossCircledIcon style={{ width: 14, height: 14, color: 'var(--red-11)' }} /><Text size="2" weight="medium" style={{ color: 'var(--red-11)' }}>Failed ({result.failed.length})</Text></Flex>
                                            <ScrollArea style={{ maxHeight: 96 }}><Flex wrap="wrap" gap="1" pr="2">{result.failed.map((work, idx) => <Badge key={work.id||idx} size="1" color="red" variant="soft">{work.number}: {work.error}</Badge>)}</Flex></ScrollArea>
                                        </Box>
                                    )}

                                    {result.not_found?.length > 0 && (
                                        <Box>
                                            <Flex align="center" gap="1" mb="2"><QuestionMarkCircledIcon style={{ width: 14, height: 14 }} /><Text size="2" weight="medium" color="gray">Not Found ({result.not_found.length})</Text></Flex>
                                            <ScrollArea style={{ maxHeight: 96 }}><Flex wrap="wrap" gap="1" pr="2">{result.not_found.map((item, idx) => <Badge key={idx} size="1" variant="soft" color="gray">Row {item.row}: {item.rfi_number}</Badge>)}</Flex></ScrollArea>
                                        </Box>
                                    )}

                                    {result.invalid?.length > 0 && (
                                        <Box>
                                            <Flex align="center" gap="1" mb="2"><ExclamationTriangleIcon style={{ width: 14, height: 14 }} /><Text size="2" weight="medium" color="gray">Invalid Rows ({result.invalid.length})</Text></Flex>
                                            <ScrollArea style={{ maxHeight: 96 }}><Flex wrap="wrap" gap="1" pr="2">{result.invalid.map((item, idx) => <Badge key={idx} size="1" color="red" variant="soft">Row {item.row}: {item.error}</Badge>)}</Flex></ScrollArea>
                                        </Box>
                                    )}
                                </Flex>
                            )}
                        </Box>

                        <Flex gap="2" pt="3" style={{ borderTop: '1px solid var(--gray-a4)', flexDirection: 'column-reverse' }}>
                            {step === 'upload' && (<>
                                <Button variant="ghost" color="gray" onClick={handleClose} style={{ width: '100%' }}>Cancel</Button>
                                <Button color="indigo" onClick={() => handleUpload(false, false)} loading={loading} disabled={!file} style={{ width: '100%' }}>
                                    {!loading && <UploadIcon style={{ width: 16, height: 16 }} />} Import & Submit
                                </Button>
                            </>)}
                            {step === 'objection-decision' && <Button variant="ghost" color="gray" onClick={() => setStep('upload')} style={{ width: '100%' }}>Back</Button>}
                            {step === 'result' && <Button color="indigo" onClick={handleClose} style={{ width: '100%' }}>Done</Button>}
                        </Flex>
                    </>
            </Dialog.Content>
        </Dialog.Root>
    );
};

export default BulkImportSubmitModal;
