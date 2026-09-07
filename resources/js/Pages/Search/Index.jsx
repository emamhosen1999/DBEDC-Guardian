import React, { useEffect, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { MagnifyingGlassIcon } from '@radix-ui/react-icons';
import { Badge, Box, Button, Flex, Heading, Text, TextField } from '@radix-ui/themes';
import App from '@/Layouts/App.jsx';
import { Panel } from '@/Components/ui/Panel';

export default function GlobalSearch({ query = '', groups = [], minimumLength = 2 }) {
    const [value, setValue] = useState(query);
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState('');
    useEffect(() => setValue(query), [query]);
    const resultCount = groups.reduce((total, group) => total + group.items.length, 0);

    const submit = (event) => {
        event.preventDefault();
        const q = value.trim();
        if (processing || q.length < minimumLength) return;
        router.get(route('search'), { q }, {
            preserveState: true,
            replace: true,
            onStart: () => { setProcessing(true); setError(''); },
            onError: (errors) => setError(errors.q || 'Search could not be completed. Please retry.'),
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <App>
            <Head title="Search" />
            <Flex justify="center" p={{ initial: '3', md: '4' }}>
                <Box style={{ width: '100%', maxWidth: 1100 }}>
                    <Panel>
                        <Flex direction="column" gap="4">
                            <Box>
                                <Heading size="6">Search Guardian</Heading>
                                <Text size="2" color="gray">
                                    Results only include records you are allowed to view.
                                </Text>
                            </Box>

                            <form onSubmit={submit}>
                                <Flex gap="2">
                                    <Box style={{ flex: 1 }}>
                                        <TextField.Root
                                            value={value}
                                            onChange={(event) => setValue(event.target.value)}
                                            placeholder="Employee, RFI, objection, work order, incident…"
                                            size="3"
                                            aria-label="Search Guardian"
                                            maxLength={100}
                                            autoFocus
                                        >
                                            <TextField.Slot><MagnifyingGlassIcon /></TextField.Slot>
                                        </TextField.Root>
                                    </Box>
                                    <Button type="submit" size="3" loading={processing} disabled={processing || value.trim().length < minimumLength}>
                                        Search
                                    </Button>
                                </Flex>
                            </form>

                            {error && <Text color="red" role="alert">{error}</Text>}

                            {query.length > 0 && query.length < minimumLength && (
                                <Text color="amber" size="2">Enter at least {minimumLength} characters.</Text>
                            )}

                            {query.length >= minimumLength && resultCount === 0 && (
                                <Box p="6" style={{ textAlign: 'center', border: '1px dashed var(--gray-a6)', borderRadius: 12 }}>
                                    <Text weight="medium" as="div">No accessible results for “{query}”.</Text>
                                    <Text size="2" color="gray">Try a record number, employee ID, name, location, or description.</Text>
                                </Box>
                            )}

                            {groups.map((group) => (
                                <Box key={group.key}>
                                    <Flex align="center" gap="2" mb="2">
                                        <Heading size="3">{group.label}</Heading>
                                        <Badge variant="soft">{group.items.length}</Badge>
                                    </Flex>
                                    <Flex direction="column" gap="2">
                                        {group.items.map((item) => (
                                            <Link key={`${group.key}-${item.id}`} href={item.url} style={{ textDecoration: 'none' }}>
                                                <Box p="3" style={{ border: '1px solid var(--gray-a5)', borderRadius: 10, background: 'var(--gray-a2)' }}>
                                                    <Text weight="medium" as="div">{item.title}</Text>
                                                    {item.subtitle && <Text size="2" color="gray" as="div">{item.subtitle}</Text>}
                                                    {item.meta && <Text size="1" color="gray" as="div" mt="1">{item.meta}</Text>}
                                                </Box>
                                            </Link>
                                        ))}
                                    </Flex>
                                </Box>
                            ))}
                        </Flex>
                    </Panel>
                </Box>
            </Flex>
        </App>
    );
}
