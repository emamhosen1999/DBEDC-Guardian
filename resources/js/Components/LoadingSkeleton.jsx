import React from 'react';
import { Box, Flex, Text } from '@radix-ui/themes';

/**
 * Loading Skeleton Component
 * Provides AXIOM-compliant loading placeholders
 * @param {Object} props
 * @param {string} [props.variant] - Skeleton variant (text, avatar, card, table)
 * @param {number} [props.lines] - Number of lines for text variant
 * @param {number} [props.width] - Custom width
 * @param {number} [props.height] - Custom height
 * @param {string} [props.className] - Additional CSS classes
 */
export function LoadingSkeleton({
    variant = 'text',
    lines = 3,
    width,
    height,
    className = '',
}) {
    const baseStyle = {
        backgroundColor: 'var(--color-gray-4)',
        animation: 'pulse 1.5s ease-in-out infinite',
    };

    const skeletonStyle = {
        ...baseStyle,
        width: width || '100%',
        height: height || (variant === 'text' ? '16px' : '100%'),
    };

    switch (variant) {
        case 'avatar':
            return (
                <Box
                    style={{
                        ...baseStyle,
                        width: width || '40px',
                        height: height || '40px',
                        borderRadius: '50%',
                    }}
                    className={className}
                />
            );

        case 'card':
            return (
                <Box
                    style={{
                        ...baseStyle,
                        width: '100%',
                        height: height || '120px',
                    }}
                    className={className}
                />
            );

        case 'table':
            return (
                <Flex direction="column" gap="2">
                    {Array.from({ length: lines }).map((_, index) => (
                        <Flex key={index} gap="3" align="center">
                            <Box
                                style={{
                                    ...baseStyle,
                                    width: '40px',
                                    height: '40px',
                                    borderRadius: '50%',
                                }}
                            />
                            <Box
                                style={{
                                    ...baseStyle,
                                    width: '120px',
                                    height: '16px',
                                }}
                            />
                            <Box
                                style={{
                                    ...baseStyle,
                                    width: '150px',
                                    height: '16px',
                                }}
                            />
                            <Box
                                style={{
                                    ...baseStyle,
                                    width: '100px',
                                    height: '16px',
                                }}
                            />
                        </Flex>
                    ))}
                </Flex>
            );

        case 'text':
        default:
            return (
                <Flex direction="column" gap="2">
                    {Array.from({ length: lines }).map((_, index) => (
                        <Box
                            key={index}
                            style={{
                                ...skeletonStyle,
                                width: index === lines - 1 ? '60%' : '100%',
                            }}
                            className={className}
                        />
                    ))}
                </Flex>
            );
    }
}

/**
 * Page Loading Skeleton
 * Full-page loading placeholder
 */
export function PageLoadingSkeleton() {
    return (
        <Flex direction="column" gap="6" p="6">
            <Flex justify="between" align="center">
                <Box
                    style={{
                        backgroundColor: 'var(--color-gray-4)',
                        width: '200px',
                        height: '32px',
                    }}
                />
                <Box
                    style={{
                        backgroundColor: 'var(--color-gray-4)',
                        width: '120px',
                        height: '36px',
                    }}
                />
            </Flex>
            <LoadingSkeleton variant="card" height="80px" />
            <LoadingSkeleton variant="table" lines={5} />
        </Flex>
    );
}

/**
 * Table Loading Skeleton
 * Table-specific loading placeholder
 * @param {Object} props
 * @param {number} [props.rows] - Number of rows
 * @param {number} [props.columns] - Number of columns
 */
export function TableLoadingSkeleton({ rows = 5, columns = 4 }) {
    return (
        <Flex direction="column" gap="2">
            {Array.from({ length: rows }).map((_, rowIndex) => (
                <Flex key={rowIndex} gap="3" align="center" p="2">
                    {Array.from({ length: columns }).map((_, colIndex) => (
                        <Box
                            key={colIndex}
                            style={{
                                backgroundColor: 'var(--color-gray-4)',
                                width: colIndex === 0 ? '40px' : '100%',
                                height: '16px',
                                borderRadius: colIndex === 0 ? '50%' : '0',
                            }}
                        />
                    ))}
                </Flex>
            ))}
        </Flex>
    );
}

/**
 * Card Grid Loading Skeleton
 * Grid of card placeholders
 * @param {Object} props
 * @param {number} [props.count] - Number of cards
 */
export function CardGridLoadingSkeleton({ count = 4 }) {
    return (
        <Flex gap="4" wrap="wrap">
            {Array.from({ length: count }).map((_, index) => (
                <Box
                    key={index}
                    style={{
                        backgroundColor: 'var(--color-gray-4)',
                        width: '250px',
                        height: '150px',
                    }}
                />
            ))}
        </Flex>
    );
}

export default LoadingSkeleton;
