import React, { useState, useEffect } from 'react';
import { Box, Button, Callout, Dialog, Flex, Select, Text } from '@radix-ui/themes';
import { CopyIcon, InfoCircledIcon } from '@radix-ui/react-icons';
import { showToast } from '@/utils/toastUtils';
import axios from 'axios';

/**
 * Copy a source year's holidays into a target year. Gregorian/national dates carry
 * over (Feb-29 clamped); overlapping clones are skipped server-side. HR then edits
 * the lunar/Eid dates for the new year per that year's moon-sighting.
 */
const CopyYearForm = ({ open, closeModal, setHolidaysData }) => {
    const thisYear = new Date().getFullYear();
    const years = Array.from({ length: thisYear - 2019 + 3 }, (_, i) => 2020 + i);

    const [fromYear, setFromYear] = useState(String(thisYear - 1));
    const [toYear, setToYear] = useState(String(thisYear));
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState('');

    useEffect(() => {
        if (open) {
            setFromYear(String(thisYear - 1));
            setToYear(String(thisYear));
            setError('');
        }
    }, [open, thisYear]);

    const handleSubmit = async (event) => {
        event.preventDefault();
        if (fromYear === toYear) {
            setError('Source and target year must differ.');
            return;
        }
        setProcessing(true);
        setError('');
        try {
            const response = await axios.post(route('holidays-copy-year'), {
                fromYear: Number(fromYear),
                toYear: Number(toYear),
            });
            setHolidaysData(response.data.holidays);
            showToast.success(response.data.message || 'Holidays copied successfully!');
            closeModal();
        } catch (err) {
            setError(err.response?.data?.message || 'Failed to copy holidays.');
            showToast.error('Failed to copy holidays');
        } finally {
            setProcessing(false);
        }
    };

    return (
        <Dialog.Root open={open} onOpenChange={(v) => { if (!v && !processing) closeModal(); }}>
            <Dialog.Content style={{ maxWidth: 480 }}>
                <Dialog.Title>
                    <Flex align="center" gap="2">
                        <CopyIcon style={{ width: 20, height: 20 }} />
                        Copy holidays from another year
                    </Flex>
                </Dialog.Title>

                <form onSubmit={handleSubmit}>
                    <Flex direction="column" gap="4" mt="4">
                        <Callout.Root color="gray" size="1">
                            <Callout.Icon><InfoCircledIcon /></Callout.Icon>
                            <Callout.Text>
                                Fixed/national dates carry over. Overlapping holidays are skipped.
                                Edit lunar/Eid dates afterward for the new year.
                            </Callout.Text>
                        </Callout.Root>

                        <Flex gap="4" wrap="wrap">
                            <Box style={{ flex: 1, minWidth: 160 }}>
                                <Text size="2" weight="medium" mb="1" as="div">From year</Text>
                                <Select.Root value={fromYear} onValueChange={setFromYear}>
                                    <Select.Trigger style={{ width: '100%' }} />
                                    <Select.Content>
                                        {years.map((y) => (
                                            <Select.Item key={y} value={String(y)}>{y}</Select.Item>
                                        ))}
                                    </Select.Content>
                                </Select.Root>
                            </Box>
                            <Box style={{ flex: 1, minWidth: 160 }}>
                                <Text size="2" weight="medium" mb="1" as="div">To year</Text>
                                <Select.Root value={toYear} onValueChange={setToYear}>
                                    <Select.Trigger style={{ width: '100%' }} />
                                    <Select.Content>
                                        {years.map((y) => (
                                            <Select.Item key={y} value={String(y)}>{y}</Select.Item>
                                        ))}
                                    </Select.Content>
                                </Select.Root>
                            </Box>
                        </Flex>

                        {error && <Text size="1" color="red">{error}</Text>}
                    </Flex>

                    <Flex justify="end" gap="3" mt="5">
                        <Button type="button" variant="soft" color="gray" onClick={closeModal} disabled={processing}>
                            Cancel
                        </Button>
                        <Button type="submit" loading={processing}>
                            <CopyIcon style={{ width: 16, height: 16 }} />
                            Copy holidays
                        </Button>
                    </Flex>
                </form>
            </Dialog.Content>
        </Dialog.Root>
    );
};

export default CopyYearForm;
