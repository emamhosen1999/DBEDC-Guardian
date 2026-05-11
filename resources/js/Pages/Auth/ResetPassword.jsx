import React, { useState } from 'react';
import { Head, useForm } from '@inertiajs/react';
import {
    Box, Button, Callout, Card, Flex, Heading,
    IconButton, Spinner, Text, TextField,
} from '@radix-ui/themes';
import {
    CheckCircledIcon, ExclamationTriangleIcon,
    EyeNoneIcon, EyeOpenIcon, LockClosedIcon,
} from '@radix-ui/react-icons';

function RadixBg() {
    return (
        <Box style={{ position: 'fixed', inset: 0, overflow: 'hidden', pointerEvents: 'none', zIndex: 0 }}>
            <svg width="100%" height="100%" viewBox="0 0 2560 1920" preserveAspectRatio="xMidYMid slice" fill="none" xmlns="http://www.w3.org/2000/svg" style={{ opacity: 0.55 }}>
                <g>
                    <path d="M-119.809 -1055.99L859.027 -684.98C915.435 -663.6 955.626 -624.994 968.519 -579.807L1129.49 -15.6245L1860.47 -241.727C1919.02 -259.836 1985.68 -257.939 2042.09 -236.559L3020.93 134.453C3124.79 173.822 3164.97 266.777 3110.66 342.073L2850.06 703.385C2827.36 734.857 2790.34 759.666 2745.28 773.604L1467.45 1168.86L1748.58 2154.16C1758.67 2189.52 1751.28 2226.32 1727.72 2258.12L1361.75 2752.01L203.258 2312.91C146.85 2291.53 106.659 2252.92 93.7664 2207.73L-67.2076 1643.55L-798.184 1869.65C-856.73 1887.76 -923.398 1885.87 -979.806 1864.48L-2138.3 1425.38L-1787.63 925.687C-1765.05 893.507 -1727.57 868.111 -1681.77 853.942L-405.167 459.07L-686.568 -527.183C-696.491 -561.961 -689.511 -598.157 -666.811 -629.629L-406.21 -990.941C-351.902 -1066.24 -223.676 -1095.36 -119.809 -1055.99Z" fill="url(#rp0)"/>
                    <path d="M1597.13 169.784L2575.97 540.796C2632.38 562.177 2672.57 600.783 2685.46 645.97L2846.44 1210.15L3577.41 984.049C3635.96 965.94 3702.63 967.837 3759.03 989.218L4737.87 1360.23C4841.74 1399.6 4881.91 1492.55 4827.61 1567.85L4567 1929.16C4544.3 1960.63 4507.28 1985.44 4462.22 1999.38L3184.4 2394.63L3465.53 3379.94C3475.61 3415.29 3468.23 3452.09 3444.66 3483.9L3078.69 3977.79L1920.2 3538.68C1863.79 3517.3 1823.6 3478.7 1810.71 3433.51L1649.74 2869.33L918.759 3095.43C860.213 3113.54 793.545 3111.64 737.138 3090.26L-421.356 2651.15L-70.6875 2151.46C-48.1049 2119.28 -10.63 2093.89 35.1782 2079.72L1311.78 1684.85L1030.38 698.593C1020.45 663.815 1027.43 627.619 1050.13 596.147L1310.73 234.835C1365.04 159.539 1493.27 130.415 1597.13 169.784Z" fill="url(#rp1)"/>
                </g>
                <defs>
                    <radialGradient id="rp0" cx="0" cy="0" r="1" gradientUnits="userSpaceOnUse" gradientTransform="translate(-804.109 -2036.8) rotate(64.9401) scale(6436.87 6304.81)">
                        <stop stopColor="var(--color-background)"/><stop offset="0.0833333" stopColor="var(--accent-7)"/><stop offset="0.364583" stopColor="var(--accent-5)"/><stop offset="0.658041" stopColor="var(--color-background)"/><stop offset="1" stopColor="var(--color-background)"/>
                    </radialGradient>
                    <radialGradient id="rp1" cx="0" cy="0" r="1" gradientUnits="userSpaceOnUse" gradientTransform="translate(912.834 -811.021) rotate(64.9401) scale(6436.87 6304.81)">
                        <stop stopColor="var(--color-background)"/><stop offset="0.140625" stopColor="var(--accent-3)"/><stop offset="0.333803" stopColor="var(--accent-7)"/><stop offset="0.658041" stopColor="var(--color-background)"/><stop offset="1" stopColor="var(--color-background)"/>
                    </radialGradient>
                </defs>
            </svg>
        </Box>
    );
}

const STRENGTH_LABELS = ['', 'Very weak', 'Weak', 'Fair', 'Good', 'Strong'];
const STRENGTH_COLORS = ['var(--gray-6)', 'var(--red-9)', 'var(--orange-9)', 'var(--amber-9)', 'var(--blue-9)', 'var(--green-9)'];

function calcStrength(pw) {
    let s = 0;
    if (pw.length >= 8)           s++;
    if (/[a-z]/.test(pw))         s++;
    if (/[A-Z]/.test(pw))         s++;
    if (/[0-9]/.test(pw))         s++;
    if (/[^A-Za-z0-9]/.test(pw))  s++;
    return s;
}

function PasswordField({ id, label, value, onChange, error, placeholder }) {
    const [visible, setVisible] = useState(false);
    return (
        <Flex direction="column" gap="1">
            <Text as="label" size="2" weight="medium" htmlFor={id}>{label}</Text>
            <TextField.Root
                id={id}
                type={visible ? 'text' : 'password'}
                placeholder={placeholder}
                value={value}
                onChange={onChange}
                color={error ? 'red' : undefined}
                autoComplete="new-password"
                size="3"
            >
                <TextField.Slot>
                    <LockClosedIcon style={{ width: 14, height: 14 }} />
                </TextField.Slot>
                <TextField.Slot side="right">
                    <IconButton
                        type="button"
                        variant="ghost"
                        color="gray"
                        size="1"
                        onClick={() => setVisible(v => !v)}
                        aria-label={visible ? 'Hide password' : 'Show password'}
                        style={{ cursor: 'pointer' }}
                    >
                        {visible ? <EyeNoneIcon /> : <EyeOpenIcon />}
                    </IconButton>
                </TextField.Slot>
            </TextField.Root>
            {error && (
                <Callout.Root color="red" size="1">
                    <Callout.Icon><ExclamationTriangleIcon /></Callout.Icon>
                    <Callout.Text>{error}</Callout.Text>
                </Callout.Root>
            )}
        </Flex>
    );
}

export default function ResetPassword({ token, email }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        token,
        email: email ?? '',
        verification_code: '',
        password: '',
        password_confirmation: '',
    });
    const [strength, setStrength] = useState(0);

    const handlePasswordChange = (e) => {
        setData('password', e.target.value);
        setStrength(calcStrength(e.target.value));
    };

    const submit = (e) => {
        e.preventDefault();
        post(route('password.store'), { onFinish: () => reset('password', 'password_confirmation') });
    };

    return (
        <>
            <Head title="Reset Password" />
            <RadixBg />
            <Box style={{
                minHeight: '100vh', display: 'flex', alignItems: 'center',
                justifyContent: 'center', padding: 16,
                position: 'relative', zIndex: 1, overflowX: 'hidden',
            }}>
                <Box style={{ width: '100%', maxWidth: 440 }}>
                    <Card size="4" style={{
                        backdropFilter: 'blur(24px)',
                        WebkitBackdropFilter: 'blur(24px)',
                        background: 'var(--color-panel-translucent)',
                        border: '1px solid var(--gray-a4)',
                        boxShadow: '0 24px 64px var(--black-a6), 0 4px 16px var(--black-a3)',
                    }}>
                        <Flex direction="column" align="center" gap="2" mb="5">
                            <Box style={{
                                width: 52, height: 52, borderRadius: '50%',
                                background: 'var(--accent-a3)', border: '1px solid var(--accent-a6)',
                                display: 'flex', alignItems: 'center', justifyContent: 'center',
                            }}>
                                <LockClosedIcon style={{ width: 22, height: 22, color: 'var(--accent-9)' }} />
                            </Box>
                            <Heading size="6" align="center">Set new password</Heading>
                            <Text size="2" color="gray" align="center">Choose a strong password for your account.</Text>
                        </Flex>

                        <form onSubmit={submit}>
                            <Flex direction="column" gap="4">
                                {/* Email (read-only) */}
                                <Flex direction="column" gap="1">
                                    <Text as="label" size="2" weight="medium">Email</Text>
                                    <TextField.Root
                                        type="email"
                                        value={data.email}
                                        onChange={e => setData('email', e.target.value)}
                                        size="3"
                                        readOnly={!!email}
                                        style={email ? { opacity: 0.7 } : undefined}
                                    />
                                    {errors.email && (
                                        <Callout.Root color="red" size="1">
                                            <Callout.Icon><ExclamationTriangleIcon /></Callout.Icon>
                                            <Callout.Text>{errors.email}</Callout.Text>
                                        </Callout.Root>
                                    )}
                                </Flex>

                                {/* Verification code (if required by backend) */}
                                <Flex direction="column" gap="1">
                                    <Text as="label" size="2" weight="medium">Verification code</Text>
                                    <TextField.Root
                                        type="text"
                                        placeholder="Enter the code from your email"
                                        value={data.verification_code}
                                        onChange={e => setData('verification_code', e.target.value)}
                                        color={errors.verification_code ? 'red' : undefined}
                                        size="3"
                                    />
                                    {errors.verification_code && (
                                        <Callout.Root color="red" size="1">
                                            <Callout.Icon><ExclamationTriangleIcon /></Callout.Icon>
                                            <Callout.Text>{errors.verification_code}</Callout.Text>
                                        </Callout.Root>
                                    )}
                                </Flex>

                                {/* New password + strength */}
                                <Flex direction="column" gap="2">
                                    <PasswordField
                                        id="rp-password"
                                        label="New password"
                                        placeholder="Minimum 8 characters"
                                        value={data.password}
                                        onChange={handlePasswordChange}
                                        error={errors.password}
                                    />
                                    {data.password.length > 0 && (
                                        <Flex direction="column" gap="1">
                                            <Box style={{
                                                height: 4, borderRadius: 'var(--radius-full)',
                                                background: 'var(--gray-a4)', overflow: 'hidden',
                                            }}>
                                                <Box style={{
                                                    height: '100%', borderRadius: 'var(--radius-full)',
                                                    width: `${(strength / 5) * 100}%`,
                                                    background: STRENGTH_COLORS[strength],
                                                    transition: 'width 0.3s ease, background 0.3s ease',
                                                }} />
                                            </Box>
                                            <Text size="1" color="gray">{STRENGTH_LABELS[strength]}</Text>
                                        </Flex>
                                    )}
                                </Flex>

                                <PasswordField
                                    id="rp-confirm"
                                    label="Confirm new password"
                                    placeholder="Repeat your password"
                                    value={data.password_confirmation}
                                    onChange={e => setData('password_confirmation', e.target.value)}
                                    error={errors.password_confirmation}
                                />

                                <Button type="submit" size="3" disabled={processing} style={{ cursor: processing ? 'not-allowed' : 'pointer' }}>
                                    {processing ? <><Spinner size="1" /> Resetting…</> : 'Reset password'}
                                </Button>
                            </Flex>
                        </form>
                    </Card>
                </Box>
            </Box>
        </>
    );
}
