import React, { useEffect, useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import {
    Box, Button, Callout, Card, Flex, Heading,
    Spinner, Text, TextField,
} from '@radix-ui/themes';
import {
    ArrowLeftIcon, CheckCircledIcon, EnvelopeClosedIcon, ExclamationTriangleIcon,
} from '@radix-ui/react-icons';

function RadixBg() {
    return (
        <Box style={{ position: 'fixed', inset: 0, overflow: 'hidden', pointerEvents: 'none', zIndex: 0 }}>
            <svg width="100%" height="100%" viewBox="0 0 2560 1920" preserveAspectRatio="xMidYMid slice" fill="none" xmlns="http://www.w3.org/2000/svg" style={{ opacity: 0.55 }}>
                <g>
                    <path d="M-119.809 -1055.99L859.027 -684.98C915.435 -663.6 955.626 -624.994 968.519 -579.807L1129.49 -15.6245L1860.47 -241.727C1919.02 -259.836 1985.68 -257.939 2042.09 -236.559L3020.93 134.453C3124.79 173.822 3164.97 266.777 3110.66 342.073L2850.06 703.385C2827.36 734.857 2790.34 759.666 2745.28 773.604L1467.45 1168.86L1748.58 2154.16C1758.67 2189.52 1751.28 2226.32 1727.72 2258.12L1361.75 2752.01L203.258 2312.91C146.85 2291.53 106.659 2252.92 93.7664 2207.73L-67.2076 1643.55L-798.184 1869.65C-856.73 1887.76 -923.398 1885.87 -979.806 1864.48L-2138.3 1425.38L-1787.63 925.687C-1765.05 893.507 -1727.57 868.111 -1681.77 853.942L-405.167 459.07L-686.568 -527.183C-696.491 -561.961 -689.511 -598.157 -666.811 -629.629L-406.21 -990.941C-351.902 -1066.24 -223.676 -1095.36 -119.809 -1055.99Z" fill="url(#fp0)"/>
                    <path d="M885.9 -99.2158L1864.74 271.796C1921.14 293.177 1961.34 331.783 1974.23 376.97L2135.2 941.152L2866.18 715.049C2924.72 696.94 2991.39 698.837 3047.8 720.218L4026.64 1091.23C4130.5 1130.6 4170.68 1223.55 4116.37 1298.85L3855.77 1660.16C3833.07 1691.63 3796.05 1716.44 3750.99 1730.38L2473.16 2125.63L2754.29 3110.94C2764.38 3146.29 2756.99 3183.09 2733.43 3214.9L2367.46 3708.79L1208.97 3269.68C1152.56 3248.3 1112.37 3209.7 1099.48 3164.51C816.824 2173.87 747.087 1929.46 319.141 429.593C309.218 394.815 316.198 358.619 338.898 327.147L599.499 -34.1647C653.807 -109.461 782.033 -138.585 885.9 -99.2158Z" fill="url(#fp1)"/>
                </g>
                <defs>
                    <radialGradient id="fp0" cx="0" cy="0" r="1" gradientUnits="userSpaceOnUse" gradientTransform="translate(-804.109 -2036.8) rotate(64.9401) scale(6436.87 6304.81)">
                        <stop stopColor="var(--color-background)"/><stop offset="0.0833333" stopColor="var(--accent-7)"/><stop offset="0.364583" stopColor="var(--accent-5)"/><stop offset="0.658041" stopColor="var(--color-background)"/><stop offset="1" stopColor="var(--color-background)"/>
                    </radialGradient>
                    <radialGradient id="fp1" cx="0" cy="0" r="1" gradientUnits="userSpaceOnUse" gradientTransform="translate(201.6 -1080.02) rotate(64.9401) scale(6436.87 6304.81)">
                        <stop stopColor="var(--color-background)"/><stop offset="0.0833333" stopColor="var(--accent-2)"/><stop offset="0.333803" stopColor="var(--accent-1)"/><stop offset="0.658041" stopColor="var(--color-background)"/><stop offset="1" stopColor="var(--color-background)"/>
                    </radialGradient>
                </defs>
            </svg>
        </Box>
    );
}

export default function ForgotPassword({ status }) {
    const { data, setData, post, processing, errors } = useForm({ email: '' });
    const [showSuccess, setShowSuccess] = useState(false);

    useEffect(() => {
        if (status) {
            setShowSuccess(true);
            const t = setTimeout(() => setShowSuccess(false), 12000);
            return () => clearTimeout(t);
        }
    }, [status]);

    const submit = (e) => {
        e.preventDefault();
        post(route('password.email'));
    };

    return (
        <>
            <Head title="Forgot Password" />
            <RadixBg />
            <Box style={{
                minHeight: '100vh', display: 'flex', alignItems: 'center',
                justifyContent: 'center', padding: 16,
                position: 'relative', zIndex: 1, overflowX: 'hidden',
            }}>
                <Box style={{ width: '100%', maxWidth: 420 }}>
                    <Card size="4" style={{
                        }}>
                        {/* Icon + title */}
                        <Flex direction="column" align="center" gap="2" mb="5">
                            <Box style={{
                                width: 52, height: 52, borderRadius: '50%',
                                background: 'var(--accent-a3)', border: '1px solid var(--accent-a6)',
                                display: 'flex', alignItems: 'center', justifyContent: 'center',
                            }}>
                                <EnvelopeClosedIcon style={{ width: 22, height: 22, color: 'var(--accent-9)' }} />
                            </Box>
                            <Heading size="6" align="center">Forgot password?</Heading>
                            <Text size="2" color="gray" align="center">
                                Enter your email and we'll send you a reset link.
                            </Text>
                        </Flex>

                        {showSuccess && status && (
                            <Callout.Root color="green" mb="4">
                                <Callout.Icon><CheckCircledIcon /></Callout.Icon>
                                <Callout.Text>{status}</Callout.Text>
                            </Callout.Root>
                        )}

                        <form onSubmit={submit}>
                            <Flex direction="column" gap="4">
                                <Flex direction="column" gap="1">
                                    <Text as="label" size="2" weight="medium" htmlFor="fp-email">
                                        Email address
                                    </Text>
                                    <TextField.Root
                                        id="fp-email"
                                        type="email"
                                        placeholder="you@company.com"
                                        value={data.email}
                                        onChange={e => setData('email', e.target.value)}
                                        color={errors.email ? 'red' : undefined}
                                        autoComplete="email"
                                        autoFocus
                                        size="3"
                                    >
                                        <TextField.Slot>
                                            <EnvelopeClosedIcon style={{ width: 14, height: 14 }} />
                                        </TextField.Slot>
                                    </TextField.Root>
                                    {errors.email && (
                                        <Callout.Root color="red" size="1">
                                            <Callout.Icon><ExclamationTriangleIcon /></Callout.Icon>
                                            <Callout.Text>{errors.email}</Callout.Text>
                                        </Callout.Root>
                                    )}
                                </Flex>

                                <Button type="submit" size="3" disabled={processing} style={{ cursor: processing ? 'not-allowed' : 'pointer' }}>
                                    {processing ? <><Spinner size="1" /> Sending…</> : 'Send reset link'}
                                </Button>
                            </Flex>
                        </form>

                        <Flex justify="center" mt="4">
                            <Button asChild variant="ghost" color="gray" size="2">
                                <Link href={route('login')}>
                                    <ArrowLeftIcon /> Back to sign in
                                </Link>
                            </Button>
                        </Flex>
                    </Card>
                </Box>
            </Box>
        </>
    );
}
