import React, { useEffect, useMemo, useState } from 'react';
import { Box, Button, Flex, Popover, ScrollArea, Text, TextField } from '@radix-ui/themes';
import { MagnifyingGlassIcon } from '@radix-ui/react-icons';
import axios from 'axios';
import { groupCandidates } from '@/utils/reportingCandidates';

/**
 * "Reports To" picker: every employee the actor may choose (server-scoped), searchable and grouped by
 * department, each showing name, designation and department. The server re-validates the choice.
 *
 * @param {object} props
 * @param {string|number|null} props.value        selected employee id ('' / null = none)
 * @param {(id: string) => void} props.onChange   receives '' for "no manager"
 * @param {string|number|null} [props.employeeId] the employee being edited (excluded with everyone below them)
 * @param {boolean} [props.disabled]
 */
const ReportingManagerPicker = ({ value, onChange, employeeId = null, disabled = false }) => {
    const [candidates, setCandidates] = useState([]);
    const [loading, setLoading] = useState(false);
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');

    useEffect(() => {
        let cancelled = false;
        setLoading(true);
        axios
            .get(route('employees.reporting-manager-candidates'), { params: employeeId ? { employee_id: employeeId } : {} })
            .then(({ data }) => { if (!cancelled) setCandidates(data.candidates ?? []); })
            .catch(() => { if (!cancelled) setCandidates([]); })
            .finally(() => { if (!cancelled) setLoading(false); });
        return () => { cancelled = true; };
    }, [employeeId]);

    const groups = useMemo(() => groupCandidates(candidates, query), [candidates, query]);
    const selected = candidates.find((c) => String(c.id) === String(value));
    const label = selected ? selected.name : (value ? `Employee ${value}` : 'No manager');

    const choose = (id) => {
        onChange(id);
        setOpen(false);
        setQuery('');
    };

    return (
        <Popover.Root open={open} onOpenChange={setOpen}>
            <Popover.Trigger>
                <Button type="button" variant="surface" color="gray" disabled={disabled || loading} style={{ width: '100%', justifyContent: 'space-between' }} aria-haspopup="listbox">
                    <Text truncate>{loading ? 'Loading...' : label}</Text>
                    {selected?.department && <Text size="1" color="gray">{selected.department}</Text>}
                </Button>
            </Popover.Trigger>
            <Popover.Content style={{ width: 360, maxWidth: '90vw' }}>
                <TextField.Root placeholder="Search name, designation or department" value={query} onChange={(e) => setQuery(e.target.value)} aria-label="Search managers" autoFocus>
                    <TextField.Slot><MagnifyingGlassIcon /></TextField.Slot>
                </TextField.Root>
                <ScrollArea type="auto" style={{ maxHeight: 280 }} mt="2">
                    <Box role="listbox" aria-label="Reporting managers">
                        <Button type="button" variant="ghost" color="gray" style={{ width: '100%', justifyContent: 'flex-start' }} onClick={() => choose('')}>No manager</Button>
                        {groups.length === 0 && <Text size="2" color="gray" as="p" mt="2">No matching employees.</Text>}
                        {groups.map((group) => (
                            <Box key={group.department} mt="2">
                                <Text size="1" weight="bold" color="gray" as="p">{group.department}</Text>
                                {group.people.map((p) => (
                                    <Button key={p.id} type="button" role="option" aria-selected={String(p.id) === String(value)} variant={String(p.id) === String(value) ? 'soft' : 'ghost'} color="gray" style={{ width: '100%', height: 'auto', justifyContent: 'flex-start', padding: '6px 8px' }} onClick={() => choose(String(p.id))}>
                                        <Flex direction="column" align="start">
                                            <Text size="2">{p.name}</Text>
                                            <Text size="1" color="gray">{[p.designation, p.department].filter(Boolean).join(' · ')}</Text>
                                        </Flex>
                                    </Button>
                                ))}
                            </Box>
                        ))}
                    </Box>
                </ScrollArea>
            </Popover.Content>
        </Popover.Root>
    );
};

export default ReportingManagerPicker;
