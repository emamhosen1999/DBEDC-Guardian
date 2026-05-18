import React from 'react';
import { Badge, Box, Flex, Separator, Spinner, Text } from '@radix-ui/themes';
import { CheckCircledIcon, CrossCircledIcon, ExclamationTriangleIcon, InfoCircledIcon } from '@radix-ui/react-icons';

const BulkValidationPreview = ({ 
    validationResults = [], 
    balanceImpact = null,
    isValidating = false 
}) => {

    
    if (validationResults.length === 0 && !isValidating) {
        return null;
    }

    // Count validation statuses
    const validCount = validationResults.filter(r => r.status === 'valid').length;
    const warningCount = validationResults.filter(r => r.status === 'warning').length;
    const conflictCount = validationResults.filter(r => r.status === 'conflict').length;
    const totalCount = validationResults.length;

    const getStatusIcon = (status) => {
        switch (status) {
            case 'valid':    return <CheckCircledIcon style={{ color: 'var(--green-9)' }} />;
            case 'warning':  return <ExclamationTriangleIcon style={{ color: 'var(--amber-9)' }} />;
            case 'conflict': return <CrossCircledIcon style={{ color: 'var(--red-9)' }} />;
            default:         return <InfoCircledIcon style={{ color: 'var(--gray-9)' }} />;
        }
    };

    const getStatusColor = (status) => {
        switch (status) {
            case 'valid':    return 'green';
            case 'warning':  return 'amber';
            case 'conflict': return 'red';
            default:         return 'gray';
        }
    };

    const formatDate = (dateString) => {
        return new Date(dateString).toLocaleDateString('en-US', {
            weekday: 'short',
            month: 'short',
            day: 'numeric'
        });
    };

    return (
        <Flex direction="column" gap="3">
            {/* Summary */}
            <Box p="4" style={{ background: 'var(--gray-a2)', borderRadius: 'var(--radius-2)', border: '1px solid var(--gray-a4)' }}>
                <Flex justify="between" align="center" mb="3">
                    <Text size="3" weight="medium">Validation Results</Text>
                    {isValidating && <Flex align="center" gap="2"><Spinner size="1" /><Text size="1" color="gray">Validating...</Text></Flex>}
                </Flex>
                <Flex gap="4" mb={balanceImpact ? '4' : '0'}>
                    <Box style={{ textAlign: 'center' }}>
                        <Text size="6" weight="bold" color="green" style={{ display: 'block' }}>{validCount}</Text>
                        <Text size="1" color="green">Valid</Text>
                    </Box>
                    <Box style={{ textAlign: 'center' }}>
                        <Text size="6" weight="bold" color="amber" style={{ display: 'block' }}>{warningCount}</Text>
                        <Text size="1" color="amber">Warnings</Text>
                    </Box>
                    <Box style={{ textAlign: 'center' }}>
                        <Text size="6" weight="bold" color="red" style={{ display: 'block' }}>{conflictCount}</Text>
                        <Text size="1" color="red">Conflicts</Text>
                    </Box>
                </Flex>
                {balanceImpact && (
                    <Box p="3" style={{ background: 'var(--gray-a3)', borderRadius: 'var(--radius-2)' }}>
                        <Flex align="center" gap="1" mb="2"><InfoCircledIcon style={{ color: 'var(--accent-9)' }} /><Text size="2" weight="medium">Leave Balance Impact</Text></Flex>
                        <Flex gap="4" wrap="wrap">
                            {[['Leave Type', balanceImpact.leave_type], ['Current Balance', `${balanceImpact.current_balance} days`], ['Requested Days', `${balanceImpact.requested_days} days`]].map(([label, val]) => (
                                <Box key={label}><Text size="1" color="gray" style={{ display: 'block' }}>{label}</Text><Text size="2" weight="medium">{val}</Text></Box>
                            ))}
                            <Box>
                                <Text size="1" color="gray" style={{ display: 'block' }}>Remaining Balance</Text>
                                <Text size="2" weight="medium" color={balanceImpact.remaining_balance < 0 ? 'red' : 'green'}>{balanceImpact.remaining_balance} days</Text>
                            </Box>
                        </Flex>
                        {balanceImpact.remaining_balance < 0 && (
                            <Box mt="2" p="2" style={{ background: 'var(--red-a2)', borderRadius: 'var(--radius-1)', border: '1px solid var(--red-a4)' }}>
                                <Text size="1" color="red">This request exceeds your available leave balance by {Math.abs(balanceImpact.remaining_balance)} days.</Text>
                            </Box>
                        )}
                    </Box>
                )}
            </Box>

            {/* Date-by-date */}
            {validationResults.length > 0 && (
                <Box p="4" style={{ background: 'var(--gray-a2)', borderRadius: 'var(--radius-2)', border: '1px solid var(--gray-a4)' }}>
                    <Text size="3" weight="medium" mb="3" style={{ display: 'block' }}>Date-by-Date Results</Text>
                    <Flex direction="column" gap="2" style={{ maxHeight: 160, overflowY: 'auto' }}>
                        {validationResults.map((result, index) => (
                            <Flex key={result.date ?? index} justify="between" align="start" p="2" style={{ background: 'var(--gray-a1)', borderRadius: 'var(--radius-1)', border: '1px solid var(--gray-a3)' }}>
                                <Flex align="center" gap="2">
                                    {getStatusIcon(result.status)}
                                    <Box>
                                        <Text size="2" weight="medium" style={{ display: 'block' }}>{formatDate(result.date)}</Text>
                                        <Text size="1" color="gray">{result.date}</Text>
                                    </Box>
                                </Flex>
                                <Flex direction="column" align="end" gap="1">
                                    <Badge color={getStatusColor(result.status)} variant="soft" style={{ textTransform: 'capitalize' }}>{result.status}</Badge>
                                    {result.errors?.map((e, i) => <Text key={i} size="1" color="red">{e}</Text>)}
                                    {result.warnings?.map((w, i) => <Text key={i} size="1" color="amber">{w}</Text>)}
                                </Flex>
                            </Flex>
                        ))}
                    </Flex>
                </Box>
            )}
        </Flex>
    );
};

export default BulkValidationPreview;
