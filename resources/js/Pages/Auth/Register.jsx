import React from 'react';
import { Head, Link } from '@inertiajs/react';
import { Box, Button, Card, Flex, Heading, Text } from '@radix-ui/themes';
import { LockClosedIcon, ArrowLeftIcon } from '@radix-ui/react-icons';

export default function Register() {
    return (
        <>
            <Head title="Registration" />
            <Box style={{
                minHeight: '100vh', display: 'flex',
                alignItems: 'center', justifyContent: 'center',
                padding: 16, background: 'var(--color-background)',
            }}>
                <Box style={{ width: '100%', maxWidth: 400 }}>
                    <Card size="4" style={{
                        background: 'var(--color-panel-translucent)',
                        backdropFilter: 'blur(24px)',
                        border: '1px solid var(--gray-a4)',
                        boxShadow: '0 24px 64px var(--black-a6)',
                    }}>
                        <Flex direction="column" align="center" gap="3">
                            <Box style={{
                                width: 56, height: 56, borderRadius: '50%',
                                background: 'var(--gray-a3)',
                                display: 'flex', alignItems: 'center', justifyContent: 'center',
                            }}>
                                <LockClosedIcon style={{ width: 24, height: 24, color: 'var(--gray-9)' }} />
                            </Box>
                            <Heading size="5" align="center">Registration Restricted</Heading>
                            <Text size="2" color="gray" align="center">
                                New accounts are created by system administrators.
                                Please contact your administrator to request access.
                            </Text>
                            <Button asChild variant="soft" color="gray" mt="2" style={{ width: '100%' }}>
                                <Link href={route('login')}>
                                    <ArrowLeftIcon /> Back to Sign In
                                </Link>
                            </Button>
                        </Flex>
                    </Card>
                </Box>
            </Box>
        </>
    );
}
