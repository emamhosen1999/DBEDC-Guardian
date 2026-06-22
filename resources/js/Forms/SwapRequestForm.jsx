import React, { useState, useEffect } from 'react';
import { Dialog, Flex, Box, Select, Button, Text, TextArea, SegmentedControl } from '@radix-ui/themes';
import dayjs from 'dayjs';
import { requestJson } from '@/api/client';
import { showToast } from '@/utils/toastUtils';

export default function SwapRequestForm({ open, onOpenChange, onSaved }) {
    const [mode, setMode] = useState('swap');            // 'swap' | 'cover'
    const [myShifts, setMyShifts] = useState([]);        // [{date, label}]
    const [requesterDate, setRequesterDate] = useState('');
    const [coworkers, setCoworkers] = useState([]);      // [{id, name}]
    const [counterpartyId, setCounterpartyId] = useState('');
    const [counterShifts, setCounterShifts] = useState([]); // [{date, label}]
    const [counterpartyDate, setCounterpartyDate] = useState('');
    const [reason, setReason] = useState('');
    const [saving, setSaving] = useState(false);

    const from = dayjs().format('YYYY-MM-DD');
    const to = dayjs().add(60, 'day').format('YYYY-MM-DD');

    // Reset + load the signed-in user's own upcoming working shifts when opened.
    useEffect(() => {
        if (!open) return;
        setMode('swap');
        setRequesterDate(''); setCounterpartyId(''); setCounterpartyDate('');
        setCoworkers([]); setCounterShifts([]); setReason('');

        (async () => {
            try {
                const res = await requestJson('get', '/attendance/my-roster', { params: { from, to } });
                const mine = Object.values(res?.roster ?? {})[0]?.days ?? {};
                const shifts = Object.entries(mine)
                    .filter(([, d]) => !d.off)
                    .map(([date, d]) => ({ date, label: `${dayjs(date).format('ddd DD MMM')} — ${d.code ?? 'Shift'}` }));
                setMyShifts(shifts);
            } catch {
                setMyShifts([]);
            }
        })();
    }, [open]);

    // When the requester's date changes, load eligible same-dept coworkers free that day.
    useEffect(() => {
        setCounterpartyId(''); setCounterShifts([]); setCounterpartyDate('');
        if (!requesterDate) { setCoworkers([]); return; }
        (async () => {
            try {
                const res = await requestJson('get', '/attendance/swaps/eligible', { params: { date: requesterDate } });
                setCoworkers(res?.employees ?? []);
            } catch {
                setCoworkers([]);
            }
        })();
    }, [requesterDate]);

    // For a swap, load the chosen coworker's upcoming working shifts.
    useEffect(() => {
        setCounterpartyDate(''); setCounterShifts([]);
        if (mode !== 'swap' || !counterpartyId) return;
        (async () => {
            try {
                const res = await requestJson('get', '/attendance/swaps/counterparty-roster', {
                    params: { counterparty_id: counterpartyId, from, to },
                });
                setCounterShifts((res?.days ?? []).map(d => ({
                    date: d.date,
                    label: `${dayjs(d.date).format('ddd DD MMM')} — ${d.code ?? 'Shift'}${d.start ? ` ${d.start}–${d.end}` : ''}`,
                })));
            } catch {
                setCounterShifts([]);
            }
        })();
    }, [counterpartyId, mode]);

    const canSubmit = requesterDate && counterpartyId && (mode === 'cover' || counterpartyDate) && !saving;

    const save = async () => {
        setSaving(true);
        try {
            await requestJson('post', '/attendance/swaps', {
                data: {
                    type: mode,
                    requester_date: requesterDate,
                    counterparty_id: Number(counterpartyId),
                    counterparty_date: mode === 'swap' ? counterpartyDate : null,
                    reason: reason || null,
                },
            });
            showToast.success('Swap request sent to the counterparty for confirmation.');
            onSaved?.();
            onOpenChange(false);
        } catch (err) {
            showToast.error(err?.message || 'Failed to submit swap request.');
        } finally {
            setSaving(false);
        }
    };

    return (
        <Dialog.Root open={open} onOpenChange={onOpenChange}>
            <Dialog.Content maxWidth="460px">
                <Dialog.Title>Request Shift Swap / Cover</Dialog.Title>
                <Flex direction="column" gap="3">
                    <SegmentedControl.Root value={mode} onValueChange={setMode}>
                        <SegmentedControl.Item value="swap">Swap (trade shifts)</SegmentedControl.Item>
                        <SegmentedControl.Item value="cover">Cover (they take mine)</SegmentedControl.Item>
                    </SegmentedControl.Root>

                    <Box>
                        <Text size="1" color="gray" as="div" mb="1">Your shift to give up</Text>
                        <Select.Root value={requesterDate || undefined} onValueChange={setRequesterDate}>
                            <Select.Trigger placeholder="Pick one of your shifts" style={{ width: '100%' }} />
                            <Select.Content>
                                {myShifts.map(s => <Select.Item key={s.date} value={s.date}>{s.label}</Select.Item>)}
                            </Select.Content>
                        </Select.Root>
                    </Box>

                    <Box>
                        <Text size="1" color="gray" as="div" mb="1">Coworker (same department, free that day)</Text>
                        <Select.Root value={counterpartyId || undefined} onValueChange={setCounterpartyId} disabled={!requesterDate}>
                            <Select.Trigger placeholder={requesterDate ? 'Select a coworker' : 'Pick your shift first'} style={{ width: '100%' }} />
                            <Select.Content>
                                {coworkers.map(c => <Select.Item key={c.id} value={String(c.id)}>{c.name}</Select.Item>)}
                            </Select.Content>
                        </Select.Root>
                    </Box>

                    {mode === 'swap' && (
                        <Box>
                            <Text size="1" color="gray" as="div" mb="1">Their shift you'll take</Text>
                            <Select.Root value={counterpartyDate || undefined} onValueChange={setCounterpartyDate} disabled={!counterpartyId}>
                                <Select.Trigger placeholder={counterpartyId ? 'Select their shift' : 'Select a coworker first'} style={{ width: '100%' }} />
                                <Select.Content>
                                    {counterShifts.map(s => <Select.Item key={s.date} value={s.date}>{s.label}</Select.Item>)}
                                </Select.Content>
                            </Select.Root>
                        </Box>
                    )}

                    <TextArea placeholder="Reason (optional)" value={reason} onChange={e => setReason(e.target.value)} rows={3} />

                    <Flex justify="end" gap="2" mt="2">
                        <Button variant="soft" color="gray" onClick={() => onOpenChange(false)} disabled={saving}>Cancel</Button>
                        <Button onClick={save} disabled={!canSubmit}>{saving ? 'Submitting…' : 'Submit'}</Button>
                    </Flex>
                </Flex>
            </Dialog.Content>
        </Dialog.Root>
    );
}
