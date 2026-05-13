import React, { useState, useEffect, useMemo } from 'react';
import { Dialog, Button, Badge, Separator, Card, Box, Flex, TextArea, Text, TextField, ScrollArea } from '@radix-ui/themes';
import {
    ExclamationTriangleIcon,
    CalendarIcon,
    FileTextIcon,
    CheckCircledIcon,
    CrossCircledIcon,
    UploadIcon,
    TargetIcon,
} from "@radix-ui/react-icons";
import axios from 'axios';
import { showToast } from '@/utils/toastUtils';

/**
 * BulkSubmitModal - Modal for bulk RFI submission with objection warnings.
 */
const BulkSubmitModal = ({
    isOpen,
    onClose,
    selectedWorks = [],
    onSuccess,
}) => {
    const [submissionDate, setSubmissionDate] = useState(new Date().toISOString().split('T')[0]);
    const [loading, setLoading] = useState(false);
    const [step, setStep] = useState('confirm'); // 'confirm', 'objection-decision', 'result'
    const [objectedWorks, setObjectedWorks] = useState([]);
    const [cleanWorks, setCleanWorks] = useState([]);
    const [overrideReason, setOverrideReason] = useState('');
    const [result, setResult] = useState(null);

    // Calculate works with and without objections from selected works
    const { worksWithObjections, worksWithoutObjections } = useMemo(() => {
        const withObjections = selectedWorks.filter(w => (w.active_objections_count || 0) > 0);
        const withoutObjections = selectedWorks.filter(w => (w.active_objections_count || 0) === 0);
        return { worksWithObjections: withObjections, worksWithoutObjections: withoutObjections };
    }, [selectedWorks]);

    // Reset state when modal opens
    useEffect(() => {
        if (isOpen) {
            setStep('confirm');
            setOverrideReason('');
            setResult(null);
            setSubmissionDate(new Date().toISOString().split('T')[0]);
        }
    }, [isOpen]);

    const handleSubmit = async (skipObjected = false, overrideObjected = false) => {
        if (selectedWorks.length === 0) {
            showToast.error('No RFIs selected');
            return;
        }

        setLoading(true);
        try {
            const response = await axios.post(route('dailyWorks.bulkSubmit'), {
                ids: selectedWorks.map(w => w.id),
                rfi_submission_date: submissionDate,
                skip_objected: skipObjected,
                override_objected: overrideObjected,
                override_reason: overrideObjected ? overrideReason : null,
            });

            if (response.data.requires_decision) {
                // Need user decision about objected works
                setObjectedWorks(response.data.objected_works || []);
                setCleanWorks(worksWithoutObjections);
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
                setCleanWorks(worksWithoutObjections);
                setStep('objection-decision');
            } else {
                showToast.error(error.response?.data?.error || 'Failed to submit RFIs');
            }
        } finally {
            setLoading(false);
        }
    };

    const handleSkipObjected = () => {
        handleSubmit(true, false);
    };

    const handleOverrideObjected = () => {
        if (!overrideReason.trim()) {
            showToast.error('Please provide a reason for overriding objected RFIs');
            return;
        }
        handleSubmit(false, true);
    };

    const handleClose = () => {
        if (step === 'result' && result) {
            onSuccess?.(result);
        }
        onClose();
    };

    const formatDate = (dateStr) => {
        if (!dateStr) return 'Not set';
        return new Date(dateStr).toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
        });
    };

    return (
        <Dialog.Root open={isOpen} onOpenChange={(v) => { if (!v) handleClose(); }}>
            <Dialog.Content maxWidth="640px" style={{ fontFamily: `var(--fontFamily,"Inter")` }}>
                <>
                        <Dialog.Title>
                            <Flex align="center" gap="2" wrap="wrap">
                                <UploadIcon style={{ width: 20, height: 20, flexShrink: 0, color: 'var(--accent-9)' }} />
                                <Text weight="bold" size={{ initial: '2', sm: '3' }}>Bulk RFI Submission</Text>
                                <Badge color="indigo" variant="soft" size="1">{selectedWorks.length} RFI{selectedWorks.length !== 1 ? 's' : ''}</Badge>
                            </Flex>
                        </Dialog.Title>

                        <Box py="3">
                            {/* Step 1: Confirm submission */}
                            {step === 'confirm' && (
                                <Flex direction="column" gap="4">
                                    {/* Submission Date */}
                                    <Flex direction="column" gap="1">
                                        <Text as="label" size="1" weight="medium">RFI Submission Date</Text>
                                        <TextField.Root type="date" value={submissionDate} onChange={(e) => setSubmissionDate(e.target.value)}>
                                            <TextField.Slot><CalendarIcon style={{ width: 16, height: 16 }} /></TextField.Slot>
                                        </TextField.Root>
                                    </Flex>

                                    {/* Summary */}
                                    <Flex wrap="wrap" gap="3">
                                        <Card style={{ background: 'var(--green-3)', border: '1px solid var(--green-6)' }}>
                                            <Box p="3">
                                                <Flex align="center" gap="2" mb="1">
                                                    <CheckCircledIcon style={{ width: 16, height: 16, flexShrink: 0, color: 'var(--green-9)' }} />
                                                    <Text weight="semibold" style={{ fontSize: 12, color: 'var(--green-11)' }}>
                                                        Ready to Submit
                                                    </Text>
                                                </Flex>
                                                <Text size="6" weight="bold" style={{ color: 'var(--green-11)' }}>
                                                    {worksWithoutObjections.length}
                                                </Text>
                                                <Text size="1" style={{ color: 'var(--green-10)' }}>
                                                    RFIs without active objections
                                                </Text>
                                            </Box>
                                        </Card>

                                        <Card style={{ background: worksWithObjections.length > 0 ? 'var(--amber-3)' : 'var(--gray-a2)', border: `1px solid ${worksWithObjections.length > 0 ? 'var(--amber-6)' : 'var(--gray-a4)'}` }}>
                                            <Box p="3">
                                                <Flex align="center" gap="2" mb="1">
                                                    <ExclamationTriangleIcon style={{ width: 16, height: 16, flexShrink: 0, color: worksWithObjections.length > 0 ? 'var(--amber-9)' : 'var(--gray-9)' }} />
                                                    <Text weight="semibold" style={{ fontSize: 12, color: worksWithObjections.length > 0 ? 'var(--amber-11)' : 'var(--gray-11)' }}>
                                                        With Objections
                                                    </Text>
                                                </Flex>
                                                <Text size="6" weight="bold" style={{ color: worksWithObjections.length > 0 ? 'var(--amber-11)' : 'var(--gray-10)' }}>
                                                    {worksWithObjections.length}
                                                </Text>
                                                <Text size="1" style={{ color: worksWithObjections.length > 0 ? 'var(--amber-10)' : 'var(--gray-10)' }}>
                                                    RFIs with active objections
                                                </Text>
                                            </Box>
                                        </Card>
                                    </Flex>

                                    {/* Objection Warning */}
                                    {worksWithObjections.length > 0 && (
                                        <Box p="3" style={{ background: 'var(--amber-a2)', border: '1px solid var(--amber-a6)', borderRadius: 'var(--radius-2)' }}>
                                            <Flex align="start" gap="2">
                                                <ExclamationTriangleIcon style={{ width: 16, height: 16, flexShrink: 0, marginTop: '0.125rem', color: 'var(--amber-9)' }} />
                                                <Flex direction="column">
                                                    <Text weight="semibold" style={{ fontSize: 12, color: 'var(--amber-11)' }} as="p">
                                                        {worksWithObjections.length} RFI{worksWithObjections.length !== 1 ? 's have' : ' has'} active objections
                                                    </Text>
                                                    <Text size="1" style={{ color: 'var(--amber-10)', marginTop: 4 }} as="p">
                                                        You'll be asked to skip or override these RFIs in the next step.
                                                    </Text>
                                                </Flex>
                                            </Flex>
                                        </Box>
                                    )}

                                    {/* Selected RFIs List */}
                                    <Box>
                                        <Text weight="medium" size="1" mb="2">Selected RFIs:</Text>
                                        <ScrollArea style={{ maxHeight: 192 }}>
                                            <Flex direction="column" gap="2" pr="2">
                                                {selectedWorks.map((work) => (
                                                    <Flex key={work.id} align="center" justify="between" p="2" style={{ borderRadius: 'var(--radius-1)', background: (work.active_objections_count||0)>0?'var(--amber-3)':'var(--gray-a2)', border:`1px solid ${(work.active_objections_count||0)>0?'var(--amber-6)':'var(--gray-a4)'}` }}>
                                                        <Flex align="center" gap="2" style={{ flex: 1, minWidth: 0 }}>
                                                            <FileTextIcon style={{ width: 12, height: 12, flexShrink: 0, color: 'var(--gray-9)' }} />
                                                            <Text weight="medium" style={{ truncate: true, fontSize: 12 }}>{work.number}</Text>
                                                            {work.location && <Text size="1" style={{color:'var(--gray-11)', display: window.innerWidth >= 640 ? 'flex' : 'none', alignItems: 'center', gap: 4 }}><TargetIcon style={{width:12,height:12}}/>{work.location}</Text>}
                                                        </Flex>
                                                        {(work.active_objections_count||0)>0 && <Badge color="amber" variant="soft" size="1">{work.active_objections_count} objection{work.active_objections_count!==1?'s':''}</Badge>}
                                                    </Flex>
                                                ))}
                                            </Flex>
                                        </ScrollArea>
                                    </Box>
                                </Flex>
                            )}

                            {/* Step 2: Objection Decision */}
                            {step === 'objection-decision' && (
                                <Flex direction="column" gap="4">
                                    <Box p="4" style={{ background: 'var(--amber-a2)', border: '1px solid var(--amber-a6)', borderRadius: 'var(--radius-2)' }}>
                                        <Flex align="start" gap="3">
                                            <ExclamationTriangleIcon style={{ width: 24, height: 24, flexShrink: 0, color: 'var(--amber-9)' }} />
                                            <Flex direction="column">
                                                <Text weight="bold" style={{ color: 'var(--amber-11)' }} as="p">
                                                    Action Required: RFIs with Active Objections
                                                </Text>
                                                <Text size="2" style={{ color: 'var(--amber-10)', marginTop: 4 }} as="p">
                                                    The following {objectedWorks.length} RFI{objectedWorks.length !== 1 ? 's have' : ' has'} active objections. 
                                                    Choose how to proceed:
                                                </Text>
                                            </Flex>
                                        </Flex>
                                    </Box>

                                    {/* Objected RFIs List */}
                                    <Box>
                                        <Text weight="medium" size="2" mb="2">RFIs with Objections:</Text>
                                        <ScrollArea style={{ maxHeight: 128 }}>
                                            <Flex direction="column" gap="2" pr="2">
                                                {objectedWorks.map((work) => (
                                                    <Flex key={work.id} align="center" justify="between" p="2" style={{ borderRadius: 'var(--radius-1)', background: 'var(--amber-3)', border: '1px solid var(--amber-6)' }}>
                                                        <Flex align="center" gap="2">
                                                            <ExclamationTriangleIcon style={{ width: 16, height: 16, color: 'var(--amber-9)' }} />
                                                            <Text weight="medium" size="2">{work.number}</Text>
                                                            <Text size="1" style={{ color: 'var(--gray-11)' }}>{work.location}</Text>
                                                        </Flex>
                                                        <Badge color="amber" variant="soft" size="1">{work.active_objections_count} objection{work.active_objections_count!==1?'s':''}</Badge>
                                                    </Flex>
                                                ))}
                                            </Flex>
                                        </ScrollArea>
                                    </Box>

                                    <Separator size="4" />
                                    <Flex direction="column" gap="1">
                                        <Text as="label" size="1" weight="medium">Override Reason (required to submit objected RFIs)</Text>
                                        <TextArea placeholder="Explain why you're submitting RFIs that have active objections..." value={overrideReason} onChange={(e) => setOverrideReason(e.target.value)} rows={2} />
                                        <Text size="1" color="gray">This reason will be logged for audit purposes.</Text>
                                    </Flex>

                                    {/* Action Buttons */}
                                    <Flex wrap="wrap" gap="3">
                                        <Card style={{ cursor: 'pointer', border: '2px solid var(--gray-a4)' }} onClick={handleSkipObjected}>
                                            <Box p="3" style={{ textAlign: 'center' }}>
                                                <CheckCircledIcon style={{ width: 28, height: 28, color: 'var(--green-9)', margin: '0 auto 8px' }} />
                                                <Text weight="bold" size={{ initial: '2', sm: '3' }} as="p">Skip Objected RFIs</Text>
                                                <Text size="1" color="gray" as="p" mt="1">Submit only {cleanWorks.length} RFI{cleanWorks.length!==1?'s':''} without objections</Text>
                                            </Box>
                                        </Card>
                                        <Card style={{ cursor: overrideReason.trim() ? 'pointer' : 'not-allowed', opacity: overrideReason.trim() ? 1 : 0.6, border: `2px solid ${overrideReason.trim() ? 'var(--amber-6)' : 'var(--gray-a4)'}` }} onClick={overrideReason.trim() ? handleOverrideObjected : undefined}>
                                            <Box p="3" style={{ textAlign: 'center' }}>
                                                <ExclamationTriangleIcon style={{ width: 28, height: 28, color: 'var(--amber-9)', margin: '0 auto 8px' }} />
                                                <Text weight="bold" size={{ initial: '2', sm: '3' }} as="p">Override &amp; Submit All</Text>
                                                <Text size="1" color="gray" as="p" mt="1">Submit all {selectedWorks.length} RFI{selectedWorks.length!==1?'s':''} including objected</Text>
                                            </Box>
                                        </Card>
                                    </Flex>
                                </Flex>
                            )}

                            {/* Step 3: Result */}
                            {step === 'result' && result && (
                                <Flex direction="column" gap="4">
                                    {/* Success Summary */}
                                    <Flex direction="column" align="center" py="4">
                                        <CheckCircledIcon style={{ width: 48, height: 48, color: 'var(--green-9)', margin: '0 auto 12px' }} />
                                        <Text size="5" weight="bold" style={{ color: 'var(--green-11)' }}>
                                            Bulk Submission Complete
                                        </Text>
                                        <Text size="2" style={{ color: 'var(--gray-11)', marginTop: 4 }}>{result.message}</Text>
                                    </Flex>

                                    {/* Results Grid */}
                                    <Flex wrap="wrap" gap="2">
                                        <Card style={{ background: 'var(--green-3)', border: '1px solid var(--green-6)' }}><Box p="3" style={{ textAlign: 'center' }}><Text size="5" weight="bold" style={{ color: 'var(--green-11)' }}>{result.submitted_count}</Text><Text size="1" style={{ color: 'var(--green-11)' }} as="p">Submitted</Text></Box></Card>
                                        <Card style={{ background: 'var(--amber-3)', border: '1px solid var(--amber-6)' }}><Box p="3" style={{ textAlign: 'center' }}><Text size="5" weight="bold" style={{ color: 'var(--amber-11)' }}>{result.skipped_count}</Text><Text size="1" style={{ color: 'var(--amber-11)' }} as="p">Skipped</Text></Box></Card>
                                        <Card style={{ background: 'var(--red-3)', border: '1px solid var(--red-6)' }}><Box p="3" style={{ textAlign: 'center' }}><Text size="5" weight="bold" style={{ color: 'var(--red-11)' }}>{result.failed_count}</Text><Text size="1" style={{ color: 'var(--red-11)' }} as="p">Failed</Text></Box></Card>
                                    </Flex>

                                    {/* Submitted List */}
                                    {result.submitted?.length > 0 && (
                                        <Box>
                                            <Text weight="medium" size="2" mb="2" style={{ color: 'var(--green-11)' }} as="p">
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
                                            <Text weight="medium" size="2" mb="2" style={{ color: 'var(--amber-11)' }} as="p">
                                                <Flex align="center" gap="1">
                                                    <ExclamationTriangleIcon style={{ width: 14, height: 14 }} />
                                                    Skipped (Have Objections) ({result.skipped.length})
                                                </Flex>
                                            </Text>
                                            <ScrollArea style={{ maxHeight: 96 }}><Flex wrap="wrap" gap="1" pr="2">{result.skipped.map((work) => <Badge key={work.id} size="1" color="amber" variant="soft">{work.number}</Badge>)}</Flex></ScrollArea>
                                        </Box>
                                    )}

                                    {/* Failed List */}
                                    {result.failed?.length > 0 && (
                                        <Box>
                                            <Text weight="medium" size="2" mb="2" style={{ color: 'var(--red-11)' }} as="p">
                                                <Flex align="center" gap="1">
                                                    <CrossCircledIcon style={{ width: 14, height: 14 }} />
                                                    Failed ({result.failed.length})
                                                </Flex>
                                            </Text>
                                            <ScrollArea style={{ maxHeight: 96 }}><Flex wrap="wrap" gap="1" pr="2">{result.failed.map((work, idx) => <Badge key={work.id||idx} size="1" color="red" variant="soft">{work.number}: {work.error}</Badge>)}</Flex></ScrollArea>
                                        </Box>
                                    )}
                                </Flex>
                            )}
                        </Box>

                        <Flex gap="2" pt="3" style={{ borderTop: '1px solid var(--gray-a4)', flexDirection: 'column-reverse' }}>
                            {step === 'confirm' && (<>
                                <Button variant="ghost" color="gray" onClick={handleClose} style={{ width: '100%' }}>Cancel</Button>
                                <Button color="indigo" onClick={() => handleSubmit(false, false)} loading={loading} style={{ width: '100%' }}>
                                    {!loading && <UploadIcon style={{ width: 16, height: 16 }} />}
                                    Submit {selectedWorks.length} RFI{selectedWorks.length !== 1 ? 's' : ''}
                                </Button>
                            </>)}
                            {step === 'objection-decision' && <Button variant="ghost" color="gray" onClick={() => setStep('confirm')} style={{ width: '100%' }}>Back</Button>}
                            {step === 'result' && <Button color="indigo" onClick={handleClose} style={{ width: '100%' }}>Done</Button>}
                        </Flex>
                    </>
            </Dialog.Content>
        </Dialog.Root>
    );
};

export default BulkSubmitModal;
