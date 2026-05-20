import React from 'react';
import { Flex, Text, Select, Button, IconButton } from '@radix-ui/themes';
import { ChevronLeftIcon, ChevronRightIcon } from '@radix-ui/react-icons';

const TablePagination = ({ 
    pagination, 
    onPageChange, 
    onRowsPerPageChange,
    loading = false 
}) => {
    if (!pagination || loading || pagination.total <= 0) {
        return null;
    }

    const { currentPage, perPage, total } = pagination;
    const totalPages = Math.ceil(total / perPage);
    const startRow = ((currentPage - 1) * perPage) + 1;
    const endRow = Math.min(currentPage * perPage, total);

    return (
        <Flex
            align="center"
            justify="between"
            pt="3"
            mt="2"
            style={{ borderTop: '1px solid var(--gray-a4)' }}
            wrap="wrap"
            gap="3"
        >
            {/* Rows per page */}
            <Flex align="center" gap="2">
                <Text size="1" color="gray">Rows per page</Text>
                <Select.Root
                    size="1"
                    value={String(perPage)}
                    onValueChange={(v) => onRowsPerPageChange?.(parseInt(v))}
                >
                    <Select.Trigger />
                    <Select.Content>
                        {[10, 20, 30, 50, 100].map(n => (
                            <Select.Item key={n} value={String(n)}>{n}</Select.Item>
                        ))}
                    </Select.Content>
                </Select.Root>
            </Flex>

            {/* Info + nav */}
            <Flex align="center" gap="3">
                <Text size="1" color="gray">
                    {startRow}–{endRow} of {total}
                </Text>
                <Flex gap="1">
                    <IconButton
                        size="1"
                        variant="soft"
                        color="gray"
                        disabled={currentPage <= 1}
                        onClick={() => onPageChange?.(currentPage - 1)}
                        aria-label="Previous page"
                    >
                        <ChevronLeftIcon />
                    </IconButton>
                    {/* Page number pills */}
                    {Array.from({ length: Math.min(totalPages, 5) }, (_, i) => {
                        let page;
                        if (totalPages <= 5) {
                            page = i + 1;
                        } else if (currentPage <= 3) {
                            page = i + 1;
                        } else if (currentPage >= totalPages - 2) {
                            page = totalPages - 4 + i;
                        } else {
                            page = currentPage - 2 + i;
                        }
                        return (
                            <Button
                                key={page}
                                size="1"
                                variant={page === currentPage ? 'solid' : 'soft'}
                                color={page === currentPage ? 'indigo' : 'gray'}
                                onClick={() => onPageChange?.(page)}
                            >
                                {page}
                            </Button>
                        );
                    })}
                    <IconButton
                        size="1"
                        variant="soft"
                        color="gray"
                        disabled={currentPage >= totalPages}
                        onClick={() => onPageChange?.(currentPage + 1)}
                        aria-label="Next page"
                    >
                        <ChevronRightIcon />
                    </IconButton>
                </Flex>
            </Flex>
        </Flex>
    );
};

export default TablePagination;
