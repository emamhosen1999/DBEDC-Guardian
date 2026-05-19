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

    const validCount = validationResults.filter(r => r.status === 'valid').length;
    const warningCount = validationResults.filter(r => r.status === 'warning').length;
    const conflictCount = validationResults.filter(r => r.status === 'conflict').length;

    const getStatusIcon = (status) => {
        switch (status) {
            case 'valid':    return <CheckCircledIcon style={{ color: 'var(--green-9)', width: 16, height: 16 }} />;
            case 'warning':  return <ExclamationTriangleIcon style={{ color: 'var(--amber-9)', width: 16, height: 16 }} />;
            case 'conflict': return <CrossCircledIcon style={{ color: 'var(--red-9)', width: 16, height: 16 }} />;
            default:         return <InfoCircledIcon style={{ color: 'var(--gray-9)', width: 16, height: 16 }} />;
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
            {/* Summary Card */}
            <Box
                p="4"
                style={{
                    background: 'var(--gray-a2)',
                    borderRadius: 'var(--radius-3)',
                    border: '1px solid var(--gray-a4)',
                }}
            >
                {/* Card Header */}
                <Flex justify="between" align="center" mb="3">
                    <Text size="3" weight="semibold">Validation Results</Text>
                    {isValidating && (
                        <Flex align="center" gap="2">
                            <Spinner size="1" />
                            <Text size="1" color="gray">Validating...</Text>
                        </Flex>
                    )}
                </Flex>

                {/* Loading skeleton when no prior results */}
                {isValidating && validationResults.length === 0 ? (
                    <Flex
                        align="center"
                        justify="center"
                        direction="column"
                        gap="2"
                        py="4"
                        style={{
                            background: 'var(--gray-a1)',
                            borderRadius: 'var(--radius-2)',
                            border: '1px solid var(--gray-a3)',
                        }}
                    >
                        <Spinner size="2" />
                        <Text size="2" color="gray">Checking dates against leave rules...</Text>
                    </Flex>
                ) : (
                    <>
                        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 8 }}>
                            <Box
                                p="3"
                                style={{
                                    textAlign: 'center',
                                    background: 'var(--green-a2)',
                                    borderRadius: 'var(--radius-2)',
                                    border: '1px solid var(--green-a4)',
                                }}
                            >
                                <Text size="6" weight="bold" color="green" style={{ display: 'block' }}>{validCount}</Text>
                                <Text size="1" color="green">Valid</Text>
                            </Box>
                            <Box
                                p="3"
                                style={{
                                    textAlign: 'center',
                                    background: 'var(--amber-a2)',
                                    borderRadius: 'var(--radius-2)',
                                    border: '1px solid var(--amber-a4)',
                                }}
                            >
                                <Text size="6" weight="bold" color="amber" style={{ display: 'block' }}>{warningCount}</Text>
                                <Text size="1" color="amber">Warnings</Text>
                            </Box>
                            <Box
                                p="3"
                                style={{
                                    textAlign: 'center',
                                    background: 'var(--red-a2)',
                                    borderRadius: 'var(--radius-2)',
                                    border: '1px solid var(--red-a4)',
                                }}
                            >
                                <Text size="6" weight="bold" color="red" style={{ display: 'block' }}>{conflictCount}</Text>
                                <Text size="1" color="red">Conflicts</Text>
                            </Box>
                        </div>

                        {balanceImpact && <Separator size="4" my="3" />}
                    </>
                )}

                {/* Balance Impact */}
                {balanceImpact && !isValidating && (
                    <Box
                        p="3"
                        style={{
                            background: 'var(--gray-a3)',
                            borderRadius: 'var(--radius-2)',
                            border: '1px solid var(--gray-a5)',
                        }}
                    >
                        <Flex align="center" gap="1" mb="2">
                            <InfoCircledIcon style={{ color: 'var(--accent-9)', width: 16, height: 16 }} />
                            <Text size="2" weight="medium">Leave Balance Impact</Text>
                        </Flex>

                        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(2, 1fr)', gap: '8px 16px' }}>
                            <Box>
                                <Text size="1" color="gray" style={{ display: 'block' }}>Leave Type:</Text>
                                <Text size="2" weight="medium">{balanceImpact.leave_type}</Text>
                            </Box>
                            <Box>
                                <Text size="1" color="gray" style={{ display: 'block' }}>Current Balance:</Text>
                                <Text size="2" weight="medium">{balanceImpact.current_balance} days</Text>
                            </Box>
                            <Box>
                                <Text size="1" color="gray" style={{ display: 'block' }}>Requested Days:</Text>
                                <Text size="2" weight="medium">{balanceImpact.requested_days} days</Text>
                            </Box>
                            <Box>
                                <Text size="1" color="gray" style={{ display: 'block' }}>Remaining Balance:</Text>
                                <Text
                                    size="2"
                                    weight="medium"
                                    color={balanceImpact.remaining_balance < 0 ? 'red' : 'green'}
                                >
                                    {balanceImpact.remaining_balance} days
                                </Text>
                            </Box>
                        </div>

                        {balanceImpact.remaining_balance < 0 && (
                            <Box
                                mt="2"
                                p="2"
                                style={{
                                    background: 'var(--red-a2)',
                                    borderRadius: 'var(--radius-1)',
                                    border: '1px solid var(--red-a4)',
                                }}
                            >
                                <Flex align="center" gap="1">
                                    <ExclamationTriangleIcon style={{ color: 'var(--red-9)', width: 14, height: 14, flexShrink: 0 }} />
                                    <Text size="1" color="red">
                                        Exceeds balance by {Math.abs(balanceImpact.remaining_balance)} day{Math.abs(balanceImpact.remaining_balance) !== 1 ? 's' : ''}.
                                    </Text>
                                </Flex>
                            </Box>
                        )}
                    </Box>
                )}
            </Box>

            {/* Date-by-Date Results Card */}
            {validationResults.length > 0 && !isValidating && (
                <Box
                    style={{
                        background: 'var(--gray-a2)',
                        borderRadius: 'var(--radius-3)',
                        border: '1px solid var(--gray-a4)',
                        overflow: 'hidden',
                    }}
                >
                    <Box
                        px="4"
                        pt="3"
                        pb="2"
                        style={{ borderBottom: '1px solid var(--gray-a3)' }}
                    >
                        <Flex justify="between" align="center">
                            <Text size="2" weight="semibold">Date-by-Date Results</Text>
                            <Badge color="gray" variant="soft">{validationResults.length} dates</Badge>
                        </Flex>
                    </Box>

                    <Flex direction="column" gap="2" px="4" py="3" style={{ maxHeight: 180, overflowY: 'auto' }}>
                        {validationResults.map((result, index) => (
                            <Flex
                                key={result.date ?? index}
                                justify="between"
                                align="start"
                                p="2"
                                style={{
                                    background: 'var(--gray-a1)',
                                    borderRadius: 'var(--radius-2)',
                                    border: '1px solid var(--gray-a3)',
                                }}
                            >
                                <Flex align="center" gap="2">
                                    {getStatusIcon(result.status)}
                                    <Box>
                                        <Text size="2" weight="medium" style={{ display: 'block' }}>{formatDate(result.date)}</Text>
                                        <Text size="1" color="gray">{result.date}</Text>
                                    </Box>
                                </Flex>

                                <Flex direction="column" align="end" gap="1">
                                    <Badge
                                        color={getStatusColor(result.status)}
                                        variant="soft"
                                        style={{ textTransform: 'capitalize' }}
                                    >
                                        {result.status}
                                    </Badge>
                                    {result.errors?.map((e, i) => (
                                        <Text key={i} size="1" color="red">{e}</Text>
                                    ))}
                                    {result.warnings?.map((w, i) => (
                                        <Text key={i} size="1" color="amber">{w}</Text>
                                    ))}
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
