import React from 'react';
import { Flex, Text, Select, Button, IconButton, Spinner } from '@radix-ui/themes';
import { ChevronLeftIcon, ChevronRightIcon } from '@radix-ui/react-icons';

const TablePagination = ({ 
    pagination, 
    onPageChange, 
    onRowsPerPageChange,
    loading = false 
}) => {
    if (!pagination || pagination.total <= 0) {
        return null;
    }

    const { currentPage, perPage, total } = pagination;
    const totalPages = Math.max(1, Math.ceil(total / perPage));
    const startRow = Math.min(((currentPage - 1) * perPage) + 1, total);
    const endRow = Math.min(currentPage * perPage, total);

    return (
        <Flex
            as="nav"
            aria-label="Pagination"
            align="center"
            justify="between"
            pt="3"
            mt="2"
            style={{ 
                borderTop: '1px solid var(--dl-border-color, rgba(0,0,0,0.06))',
                opacity: loading ? 0.75 : 1,
                pointerEvents: loading ? 'none' : 'auto',
                transition: 'opacity 0.2s ease',
            }}
            wrap="wrap"
            gap="3"
        >
            {/* Rows per page */}
            <Flex align="center" gap="2">
                <Text size="1" style={{ color: 'var(--aero-color-subtle, var(--gray-9))', whiteSpace: 'nowrap' }}>Rows per page</Text>
                <Select.Root
                    size="1"
                    disabled={loading}
                    value={String(perPage)}
                    onValueChange={(v) => onRowsPerPageChange?.(parseInt(v))}
                >
                    <Select.Trigger style={{ borderRadius: 8 }} />
                    <Select.Content>
                        {[5, 10, 15, 20, 25, 30, 50, 100].map(n => (
                            <Select.Item key={n} value={String(n)}>{n}</Select.Item>
                        ))}
                    </Select.Content>
                </Select.Root>
                {loading && <Spinner size="1" style={{ marginLeft: 4 }} />}
            </Flex>

            {/* Info + nav */}
            <Flex align="center" gap="3">
                <Text size="1" style={{ fontFamily: `'Space Grotesk', system-ui, sans-serif`, fontVariantNumeric: 'tabular-nums', color: 'var(--aero-color-subtle, var(--gray-9))', whiteSpace: 'nowrap' }}>
                    {startRow}–{endRow} of {total}
                </Text>
                <Flex gap="1">
                    <IconButton
                        size="1"
                        variant="soft"
                        color="gray"
                        disabled={loading || currentPage <= 1}
                        onClick={() => onPageChange?.(currentPage - 1)}
                        aria-label="Previous page"
                        style={{ borderRadius: 8 }}
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
                                disabled={loading}
                                variant={page === currentPage ? 'solid' : 'soft'}
                                color={page === currentPage ? 'blue' : 'gray'}
                                aria-label={`Page ${page}`}
                                aria-current={page === currentPage ? 'page' : undefined}
                                onClick={() => onPageChange?.(page)}
                                style={{ borderRadius: 8, fontFamily: `'Space Grotesk', system-ui, sans-serif`, fontVariantNumeric: 'tabular-nums', fontWeight: 700 }}
                            >
                                {page}
                            </Button>
                        );
                    })}
                    <IconButton
                        size="1"
                        variant="soft"
                        color="gray"
                        disabled={loading || currentPage >= totalPages}
                        onClick={() => onPageChange?.(currentPage + 1)}
                        aria-label="Next page"
                        style={{ borderRadius: 8 }}
                    >
                        <ChevronRightIcon />
                    </IconButton>
                </Flex>
            </Flex>
        </Flex>
    );
};

export default TablePagination;
