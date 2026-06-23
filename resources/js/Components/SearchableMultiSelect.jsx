/**
 * SearchableMultiSelect.jsx
 * A searchable, multi-select dropdown built with Radix Popover + Checkbox.
 * Supports badge-based selected display, search filtering, and select-all.
 */
import React, { useState, useMemo, useRef, useEffect } from 'react';
import {
    Badge, Box, Button, Checkbox, Flex, Popover,
    ScrollArea, Separator, Text, TextField,
} from '@radix-ui/themes';
import { ChevronDownIcon, Cross2Icon, MagnifyingGlassIcon } from '@radix-ui/react-icons';

export default function SearchableMultiSelect({
    options = [],          // [{ value: string|number, label: string }]
    selected = [],         // array of values
    onChange,               // (newSelected: value[]) => void
    placeholder = 'Select…',
    label,
    maxDisplay = 3,        // max badges to show before "+N more"
    disabled = false,
    size = '2',
}) {
    const [open, setOpen] = useState(false);
    const [search, setSearch] = useState('');
    const inputRef = useRef(null);

    useEffect(() => {
        if (open) {
            setSearch('');
            setTimeout(() => inputRef.current?.focus(), 50);
        }
    }, [open]);

    const filtered = useMemo(() => {
        if (!search.trim()) return options;
        const q = search.toLowerCase();
        return options.filter(o => o.label.toLowerCase().includes(q));
    }, [options, search]);

    const toggle = (value) => {
        const set = new Set(selected);
        set.has(value) ? set.delete(value) : set.add(value);
        onChange([...set]);
    };

    const selectAll = () => onChange(filtered.map(o => o.value));
    const clearAll = () => onChange([]);
    const allSelected = filtered.length > 0 && filtered.every(o => selected.includes(o.value));

    const selectedLabels = useMemo(() =>
        selected.map(v => options.find(o => o.value === v)?.label || v),
    [selected, options]);

    return (
        <Box>
            {label && <Text size="2" weight="medium" as="div" mb="1">{label}</Text>}
            <Popover.Root open={open} onOpenChange={setOpen}>
                <Popover.Trigger disabled={disabled}>
                    <Button
                        variant="surface"
                        color="gray"
                        size={size}
                        style={{
                            width: '100%',
                            justifyContent: 'space-between',
                            fontWeight: 'normal',
                            minHeight: 36,
                            cursor: disabled ? 'default' : 'pointer',
                        }}
                    >
                        <Flex gap="1" wrap="wrap" align="center" style={{ flex: 1, overflow: 'hidden' }}>
                            {selected.length === 0 ? (
                                <Text size="2" color="gray">{placeholder}</Text>
                            ) : (
                                <>
                                    {selectedLabels.slice(0, maxDisplay).map((l, i) => (
                                        <Badge key={i} size="1" variant="soft" color="blue">{l}</Badge>
                                    ))}
                                    {selected.length > maxDisplay && (
                                        <Badge size="1" variant="outline" color="gray">
                                            +{selected.length - maxDisplay} more
                                        </Badge>
                                    )}
                                </>
                            )}
                        </Flex>
                        <ChevronDownIcon style={{ flexShrink: 0 }} />
                    </Button>
                </Popover.Trigger>

                <Popover.Content
                    style={{ width: 'var(--radix-popover-trigger-width)', minWidth: 240, padding: 0 }}
                    align="start"
                >
                    {/* Search */}
                    <Box p="2">
                        <TextField.Root
                            ref={inputRef}
                            size="2"
                            placeholder="Search…"
                            value={search}
                            onChange={e => setSearch(e.target.value)}
                        >
                            <TextField.Slot>
                                <MagnifyingGlassIcon style={{ width: 14, height: 14 }} />
                            </TextField.Slot>
                            {search && (
                                <TextField.Slot side="right" onClick={() => setSearch('')} style={{ cursor: 'pointer' }}>
                                    <Cross2Icon style={{ width: 12, height: 12 }} />
                                </TextField.Slot>
                            )}
                        </TextField.Root>
                    </Box>

                    {/* Select all / Clear */}
                    <Flex px="3" pb="1" gap="2">
                        <Button size="1" variant="ghost" color="blue" onClick={selectAll} disabled={allSelected}>
                            Select all{search ? ' filtered' : ''}
                        </Button>
                        <Button size="1" variant="ghost" color="red" onClick={clearAll} disabled={selected.length === 0}>
                            Clear
                        </Button>
                        {selected.length > 0 && (
                            <Text size="1" color="gray" style={{ marginLeft: 'auto', alignSelf: 'center' }}>
                                {selected.length} selected
                            </Text>
                        )}
                    </Flex>

                    <Separator size="4" />

                    {/* Options list */}
                    <ScrollArea style={{ maxHeight: 240 }}>
                        <Box px="1" py="1">
                            {filtered.length === 0 ? (
                                <Text size="2" color="gray" style={{ display: 'block', padding: '8px 12px' }}>
                                    No matches found
                                </Text>
                            ) : (
                                filtered.map(o => {
                                    const checked = selected.includes(o.value);
                                    return (
                                        <Flex
                                            key={o.value}
                                            align="center"
                                            gap="2"
                                            px="2"
                                            py="1"
                                            onClick={() => toggle(o.value)}
                                            style={{
                                                cursor: 'pointer',
                                                borderRadius: 'var(--radius-1)',
                                                background: checked ? 'var(--accent-a3)' : 'transparent',
                                            }}
                                            onMouseEnter={e => { if (!checked) e.currentTarget.style.background = 'var(--gray-a3)'; }}
                                            onMouseLeave={e => { if (!checked) e.currentTarget.style.background = 'transparent'; }}
                                        >
                                            <Checkbox checked={checked} tabIndex={-1} />
                                            <Text size="2">{o.label}</Text>
                                        </Flex>
                                    );
                                })
                            )}
                        </Box>
                    </ScrollArea>
                </Popover.Content>
            </Popover.Root>
        </Box>
    );
}
