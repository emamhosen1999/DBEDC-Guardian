import React, { useState } from 'react';
import { Dialog, Button, Badge, Separator, Card, Box, Flex, TextArea, Text } from '@radix-ui/themes';
import {
    ExclamationTriangleIcon,
    CalendarIcon,
    FileTextIcon,
} from "@radix-ui/react-icons";

/**
 * ObjectionWarningModal - Blocking modal that requires confirmation 
 * when updating RFI submission date while active objections exist.
 */
const ObjectionWarningModal = ({
    isOpen,
    onClose,
    onConfirm,
    dailyWork,
    newSubmissionDate,
    activeObjectionsCount,
    activeObjections = [],
    isLoading = false,
}) => {
    const [reason, setReason] = useState('');
    const [acknowledged, setAcknowledged] = useState(false);

    const handleConfirm = () => {
        if (!reason.trim()) {
            return;
        }
        onConfirm(reason);
    };

    const handleClose = () => {
        setReason('');
        setAcknowledged(false);
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

    // Don't render if dailyWork is missing
    if (!dailyWork) {
        return null;
    }

    return (
        <Dialog.Root open={isOpen} onOpenChange={(v) => { if (!v) handleClose(); }}>
            <Dialog.Content maxWidth="580px" style={{ fontFamily: `var(--fontFamily, "Inter")` }}>
                <Dialog.Title>
                    <Flex align="center" gap="2" style={{ color: 'var(--amber-11)' }}>
                        <ExclamationTriangleIcon style={{ width: 24, height: 24 }} />
                        <Text weight="bold">⚠️ Active Objections Warning</Text>
                    </Flex>
                </Dialog.Title>

                <Box py="3">
                    {/* Warning Message */}
                    <Box p="4" mb="4" style={{ background: 'var(--amber-3)', border: '1px solid var(--amber-6)', borderRadius: 'var(--radius-2)' }}>
                        <Flex align="start" gap="3">
                            <ExclamationTriangleIcon style={{ width: 24, height: 24, flexShrink: 0, marginTop: '0.125rem', color: 'var(--amber-11)' }} />
                            <Flex direction="column">
                                <Text weight="semibold" mb="2" style={{ color: 'var(--amber-11)' }} as="p">
                                    This RFI has {activeObjectionsCount} active objection{activeObjectionsCount !== 1 ? 's' : ''}
                                </Text>
                                <Text size="2" style={{ color: 'var(--amber-11)' }} as="p">
                                    Changing the RFI submission date while objections are pending may:
                                </Text>
                                <Box as="ul" style={{ listStyle: 'disc', listStylePosition: 'inside', marginTop: 8, color: 'var(--amber-11)' }}>
                                    <Box as="li" style={{ marginBottom: 4 }}>Affect approval timelines and workflows</Box>
                                    <Box as="li" style={{ marginBottom: 4 }}>Impact official records and documentation</Box>
                                    <Box as="li" style={{ marginBottom: 4 }}>Create discrepancies in claims or reports</Box>
                                    <Box as="li">Cause issues with regulatory compliance</Box>
                                </Box>
                            </Flex>
                        </Flex>
                    </Box>

                    {/* RFI Details */}
                    <Card mb="4">
                        <Box p="3">
                            <Flex align="center" justify="between" wrap="wrap" gap="2">
                                <Flex align="center" gap="2">
                                    <FileTextIcon style={{ width: 16, height: 16, color: 'var(--gray-9)' }} />
                                    <Text weight="medium">RFI Number:</Text>
                                    <Text>{dailyWork?.number}</Text>
                                </Flex>
                                <Badge color="amber" variant="soft" size="1">
                                    {activeObjectionsCount} Active Objection{activeObjectionsCount !== 1 ? 's' : ''}
                                </Badge>
                            </Flex>
                            <Separator size="4" my="2" />
                            <Flex align="center" gap="4" wrap="wrap">
                                <Flex align="center" gap="2">
                                    <CalendarIcon style={{ width: 16, height: 16, color: 'var(--gray-9)' }} />
                                    <Text>Current Date:</Text>
                                    <Text weight="medium">{formatDate(dailyWork?.rfi_submission_date)}</Text>
                                </Flex>
                                <Text>→</Text>
                                <Flex align="center" gap="2">
                                    <Text>New Date:</Text>
                                    <Text weight="medium" style={{ color: 'var(--indigo-11)' }}>{formatDate(newSubmissionDate)}</Text>
                                </Flex>
                            </Flex>
                        </Box>
                    </Card>

                    {/* Active Objections List */}
                    {activeObjections.length > 0 && (
                        <Box mb="4">
                            <Text size="2" weight="medium" mb="2">Active Objections:</Text>
                            <Flex direction="column" gap="2" style={{ maxHeight: 128, overflowY: 'auto' }}>
                                {activeObjections.map((obj) => (
                                    <Flex
                                        key={obj.id}
                                        align="center"
                                        justify="between"
                                        p="2"
                                        style={{ background: 'var(--gray-a3)', borderRadius: 'var(--radius-1)' }}
                                    >
                                        <Flex align="center" gap="2">
                                            <ExclamationTriangleIcon style={{ width: 16, height: 16, color: 'var(--amber-9)' }} />
                                            <Text weight="medium" size="2">{obj.title}</Text>
                                        </Flex>
                                        <Flex align="center" gap="2" style={{ color: 'var(--gray-11)' }}>
                                            <Text size="1">{obj.created_by?.name}</Text>
                                            <Badge color="amber" variant="soft" size="1">
                                                {obj.status?.replace('_', ' ')}
                                            </Badge>
                                        </Flex>
                                    </Flex>
                                ))}
                            </Flex>
                        </Box>
                    )}

                    {/* Reason Input */}
                    <Flex direction="column" gap="1">
                        <Text size="2" weight="medium" color="red">
                            * Provide a reason for overriding this warning:
                        </Text>
                        <TextArea
                            placeholder="Explain why you need to change the submission date despite active objections..."
                            value={reason}
                            onChange={(e) => setReason(e.target.value)}
                            rows={3}
                            style={{ borderColor: 'var(--amber-7)' }}
                        />
                        <Text size="1" color="gray">This action will be logged for audit purposes.</Text>
                    </Flex>
                </Box>

                <Flex justify="end" gap="2" pt="2" style={{ borderTop: '1px solid var(--gray-a4)' }}>
                    <Button variant="ghost" color="gray" onClick={handleClose} disabled={isLoading}>
                        Cancel
                    </Button>
                    <Button color="amber" variant="solid" onClick={handleConfirm} loading={isLoading} disabled={!reason.trim()}>
                        {!isLoading && <ExclamationTriangleIcon style={{ width: 16, height: 16 }} />}
                        I Understand, Proceed Anyway
                    </Button>
                </Flex>
            </Dialog.Content>
        </Dialog.Root>
    );
};

export default ObjectionWarningModal;
