import React from 'react';
import { Box, Flex, Text, Button, Spinner, Callout } from '@radix-ui/themes';
import { ReloadIcon } from '@radix-ui/react-icons';

/**
 * Consistent loading / error / empty wrapper for React Query driven views.
 */
export default function QueryState({
    isLoading = false,
    isError = false,
    error = null,
    isEmpty = false,
    emptyMessage = 'No data found.',
    onRetry,
    children,
    minHeight = 120,
}) {
    if (isLoading) {
        return (
            <Flex align="center" justify="center" style={{ minHeight }} gap="2">
                <Spinner size="3" />
                <Text size="2" color="gray">Loading…</Text>
            </Flex>
        );
    }

    if (isError) {
        const message = error?.message
            || error?.response?.data?.message
            || 'Something went wrong while loading data.';

        return (
            <Box style={{ minHeight }}>
                <Callout.Root color="red" size="2">
                    <Callout.Text>{message}</Callout.Text>
                </Callout.Root>
                {onRetry && (
                    <Flex mt="3" justify="center">
                        <Button size="2" variant="soft" onClick={onRetry}>
                            <ReloadIcon /> Retry
                        </Button>
                    </Flex>
                )}
            </Box>
        );
    }

    if (isEmpty) {
        return (
            <Flex align="center" justify="center" direction="column" gap="2" style={{ minHeight }}>
                <Text size="2" color="gray">{emptyMessage}</Text>
            </Flex>
        );
    }

    return children;
}
