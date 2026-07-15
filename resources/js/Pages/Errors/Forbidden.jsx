import React from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { Box, Button, Callout, Flex, Heading, Text } from '@radix-ui/themes';
import { ArrowLeftIcon, HomeIcon, LockClosedIcon } from '@radix-ui/react-icons';
import App from '@/Layouts/App.jsx';

export default function Forbidden({ message, accessType, accessPath }) {
    return (
        <App>
            <Head title="Access Denied" />
            <Box style={{ minHeight: '70vh', display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 24 }}>
                <Box style={{ maxWidth: 480, width: '100%', textAlign: 'center' }}>
                    <Box style={{
                        width: 88, height: 88, borderRadius: '50%',
                        background: 'var(--red-a3)', border: '1px solid var(--red-a6)',
                        display: 'flex', alignItems: 'center', justifyContent: 'center',
                        margin: '0 auto 24px',
                    }}>
                        <LockClosedIcon style={{ width: 36, height: 36, color: 'var(--red-9)' }} />
                    </Box>

                    <Heading size="9" style={{ color: 'var(--red-9)', marginBottom: 8 }}>403</Heading>
                    <Heading size="6" mb="3">Access Denied</Heading>

                    <Text as="p" color="gray" mb="4">
                        {message || "You don't have permission to access this resource."}
                    </Text>

                    {(accessType || accessPath) && (
                        <Callout.Root color="red" mb="5">
                            <Callout.Icon><LockClosedIcon /></Callout.Icon>
                            <Callout.Text>
                                {accessType && <span style={{ textTransform: 'capitalize' }}>{accessType}</span>}
                                {accessPath && <span style={{ opacity: 0.7 }}> ({accessPath})</span>}
                            </Callout.Text>
                        </Callout.Root>
                    )}

                    <Text as="p" size="2" color="gray" mb="5">
                        If you believe you should have access, please contact your administrator.
                    </Text>

                    <Flex gap="3" justify="center">
                        <Button variant="soft" color="gray" onClick={() => router.back()} style={{ cursor: 'pointer' }}>
                            <ArrowLeftIcon /> Go Back
                        </Button>
                        <Button asChild>
                            <Link href={route('dashboard')}>
                                <HomeIcon /> Dashboard
                            </Link>
                        </Button>
                    </Flex>
                </Box>
            </Box>
        </App>
    );
}
