import React, { useId } from 'react';
import { Box, Flex, Text, TextField, Button, Badge, Spinner, IconButton } from '@radix-ui/themes';
import { MagnifyingGlassIcon, MixerHorizontalIcon, Cross2Icon } from '@radix-ui/react-icons';

export default function SearchFilterBar({
    // Search Props
    showSearch = true,
    searchValue = '',
    onSearchChange,
    searchPlaceholder = 'Search records...',
    searchLoading = false,
    searchMaxWidth = 320,
    
    // Filter Toggle Props
    showFilterToggle = true,
    showFilters = false,
    onToggleFilters,
    activeFiltersCount = 0,
    filterButtonLabel = 'Filters',
    
    // Expandable Filter Panel Props
    children,
    onClearFilters,
    clearButtonLabel = 'Clear Filters',
    
    // Active Chips
    activeFilterChips = [],
    
    // Extra actions slot (e.g. DatePicker, View Mode, Export, etc.)
    extraActions = null,
    
    // Layout styling
    mb = '4',
    style,
    className,
}) {
    // Lets the filter toggle name the panel it opens, so assistive tech can
    // report "Filters, expanded" and jump to the panel.
    const panelId = useId();

    return (
        <Box mb={mb} className={className} style={style}>
            {/* Top Toolbar Row: Search + Filter Toggle + Extra Actions */}
            <Flex
                direction={{ initial: 'column', sm: 'row' }}
                gap="3"
                align={{ initial: 'stretch', sm: 'center' }}
                justify="between"
                wrap="wrap"
                mb={showFilters || activeFilterChips.length > 0 ? '3' : '0'}
            >
                {/* Left group: Search + Filter Toggle */}
                <Flex gap="2" align="center" style={{ flex: 1, minWidth: 240 }} wrap="wrap">
                    {showSearch && (
                        <Box style={{ flex: 1, maxWidth: searchMaxWidth }}>
                            <TextField.Root
                                type="search"
                                placeholder={searchPlaceholder}
                                aria-label={searchPlaceholder}
                                aria-busy={searchLoading && Boolean(searchValue)}
                                value={searchValue}
                                onChange={(e) => onSearchChange?.(e.target.value)}
                                size="2"
                                style={{ borderRadius: 10 }}
                            >
                                <TextField.Slot>
                                    <MagnifyingGlassIcon width="16" height="16" />
                                </TextField.Slot>
                                <TextField.Slot side="right">
                                    {searchLoading && searchValue ? (
                                        <Spinner size="2" />
                                    ) : searchValue ? (
                                        <IconButton
                                            size="1"
                                            variant="ghost"
                                            color="gray"
                                            onClick={() => onSearchChange?.('')}
                                            aria-label="Clear search"
                                            style={{ cursor: 'pointer' }}
                                        >
                                            <Cross2Icon width="14" height="14" />
                                        </IconButton>
                                    ) : null}
                                </TextField.Slot>
                            </TextField.Root>
                        </Box>
                    )}

                    {showFilterToggle && onToggleFilters && (
                        <Button
                            size="2"
                            variant={showFilters ? 'solid' : 'surface'}
                            color={showFilters || activeFiltersCount > 0 ? 'indigo' : 'gray'}
                            onClick={onToggleFilters}
                            aria-expanded={showFilters}
                            aria-controls={panelId}
                            style={{ borderRadius: 10, cursor: 'pointer' }}
                        >
                            <MixerHorizontalIcon width="16" height="16" />
                            {filterButtonLabel}
                            {activeFiltersCount > 0 && (
                                <Badge
                                    size="1"
                                    variant="solid"
                                    color={showFilters ? 'gray' : 'indigo'}
                                    style={{ marginLeft: 4, borderRadius: 999 }}
                                >
                                    {activeFiltersCount}
                                </Badge>
                            )}
                        </Button>
                    )}
                </Flex>

                {/* Right group: Extra actions (e.g. Date Range, View Switcher, Action buttons) */}
                {extraActions && (
                    <Flex gap="2" align="center" wrap="wrap">
                        {extraActions}
                    </Flex>
                )}
            </Flex>

            {/* Expandable Filter Drawer Panel */}
            {showFilters && children && (
                <Box
                    id={panelId}
                    role="region"
                    aria-label={filterButtonLabel}
                    mb="3"
                    p={{ initial: '3', sm: '4' }}
                    style={{
                        borderRadius: 14,
                        border: '1px solid var(--aero-surface-border, rgba(0,0,0,0.06))',
                        background: 'var(--aero-surface, var(--color-background))',
                    }}
                >
                    {children}

                    {onClearFilters && (
                        <Flex justify="end" mt="3" pt="2" style={{ borderTop: '1px solid var(--dl-border-color, rgba(0,0,0,0.06))' }}>
                            <Button
                                size="2"
                                variant="soft"
                                color="red"
                                onClick={onClearFilters}
                                style={{ borderRadius: 8, cursor: 'pointer' }}
                            >
                                <Cross2Icon width="14" height="14" />
                                {clearButtonLabel}
                            </Button>
                        </Flex>
                    )}
                </Box>
            )}

            {/* Screen-reader status: how many filters narrow the list right now. */}
            <span
                aria-live="polite"
                style={{ position: 'absolute', width: 1, height: 1, overflow: 'hidden', clip: 'rect(0 0 0 0)', whiteSpace: 'nowrap' }}
            >
                {activeFilterChips?.length
                    ? `${activeFilterChips.length} filter${activeFilterChips.length === 1 ? '' : 's'} active`
                    : ''}
            </span>

            {/* Active Filter Chips / Badges Row */}
            {activeFilterChips && activeFilterChips.length > 0 && (
                <Flex wrap="wrap" gap="2" align="center" mt="2">
                    <Text size="1" color="gray" weight="medium" mr="1">
                        Active Filters:
                    </Text>
                    {activeFilterChips.map((chip, index) => (
                        <Badge
                            key={chip.key || `${chip.label}-${index}`}
                            size="2"
                            variant="soft"
                            color={chip.color || 'indigo'}
                            style={{ borderRadius: 8, display: 'inline-flex', alignItems: 'center', gap: 4 }}
                        >
                            <Text size="1" weight="medium">
                                {chip.label}: {chip.value}
                            </Text>
                            {chip.onRemove && (
                                <IconButton
                                    size="1"
                                    variant="ghost"
                                    color="gray"
                                    onClick={chip.onRemove}
                                    aria-label={`Remove ${chip.label} filter`}
                                    style={{ width: 14, height: 14, cursor: 'pointer', padding: 0 }}
                                >
                                    <Cross2Icon width="12" height="12" />
                                </IconButton>
                            )}
                        </Badge>
                    ))}
                    {onClearFilters && (
                        <Button
                            size="1"
                            variant="ghost"
                            color="red"
                            onClick={onClearFilters}
                            style={{ cursor: 'pointer', fontSize: 11 }}
                        >
                            Reset All
                        </Button>
                    )}
                </Flex>
            )}
        </Box>
    );
}
