import React, { useState } from 'react';
import { Head, useForm } from '@inertiajs/react';
import { Box, Button, Flex, IconButton, Spinner, Text, TextField } from '@radix-ui/themes';
import {
    EyeNoneIcon, EyeOpenIcon, LockClosedIcon,
} from '@radix-ui/react-icons';
import AuthLayout, { AuthField } from '@/Components/AuthLayout';

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
        <AuthField id={id} label={label} required error={error}>
            <TextField.Root
                id={id}
                type={visible ? 'text' : 'password'}
                placeholder={placeholder}
                value={value}
                onChange={onChange}
                color={error ? 'red' : undefined}
                aria-invalid={error ? true : undefined}
                aria-describedby={error ? `${id}-error` : undefined}
                autoComplete="new-password"
                required
                size="2"
            >
                <TextField.Slot side="right">
                    <IconButton
                        type="button"
                        variant="ghost"
                        color="gray"
                        size="1"
                        onClick={() => setVisible(v => !v)}
                        aria-label={visible ? 'Hide password' : 'Show password'}
                    >
                        {visible ? <EyeNoneIcon /> : <EyeOpenIcon />}
                    </IconButton>
                </TextField.Slot>
            </TextField.Root>
        </AuthField>
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
        post(route('password.update'), { onFinish: () => reset('password', 'password_confirmation') });
    };

    return (
        <>
            <Head title="Reset Password" />
            <AuthLayout
                title="Set new password"
                subtitle="Choose a strong password for your account."
                mark={<LockClosedIcon />}
            >
                <form onSubmit={submit}>
                    {/* Email (read-only when it came with the link) */}
                    <AuthField id="rp-email" label="Email" required error={errors.email}>
                        <TextField.Root
                            id="rp-email"
                            type="email"
                            value={data.email}
                            onChange={e => setData('email', e.target.value)}
                            aria-invalid={errors.email ? true : undefined}
                            aria-describedby={errors.email ? 'rp-email-error' : undefined}
                            autoComplete="email"
                            size="2"
                            readOnly={!!email}
                            style={email ? { opacity: 0.7 } : undefined}
                        />
                    </AuthField>

                    {/* Verification code (if required by backend) */}
                    <AuthField id="rp-code" label="Verification code" error={errors.verification_code}>
                        <TextField.Root
                            id="rp-code"
                            type="text"
                            placeholder="Enter the code from your email"
                            value={data.verification_code}
                            onChange={e => setData('verification_code', e.target.value)}
                            color={errors.verification_code ? 'red' : undefined}
                            aria-invalid={errors.verification_code ? true : undefined}
                            aria-describedby={errors.verification_code ? 'rp-code-error' : undefined}
                            autoComplete="one-time-code"
                            size="2"
                        />
                    </AuthField>

                    {/* New password + strength */}
                    <PasswordField
                        id="rp-password"
                        label="New password"
                        placeholder="Minimum 8 characters"
                        value={data.password}
                        onChange={handlePasswordChange}
                        error={errors.password}
                    />
                    {data.password.length > 0 && (
                        <Flex direction="column" gap="1" mb="4" mt="-2">
                            <Box
                                role="meter"
                                aria-label="Password strength"
                                aria-valuemin={0}
                                aria-valuemax={5}
                                aria-valuenow={strength}
                                aria-valuetext={STRENGTH_LABELS[strength] || 'Empty'}
                                style={{ height: 4, background: 'var(--gray-a4)', overflow: 'hidden' }}
                            >
                                <Box style={{
                                    height: '100%',
                                    width: `${(strength / 5) * 100}%`,
                                    background: STRENGTH_COLORS[strength],
                                    transition: 'width 0.3s ease, background 0.3s ease',
                                }} />
                            </Box>
                            <Text size="1" color="gray" style={{ textTransform: 'uppercase' }}>{STRENGTH_LABELS[strength]}</Text>
                        </Flex>
                    )}

                    <PasswordField
                        id="rp-confirm"
                        label="Confirm new password"
                        placeholder="Repeat your password"
                        value={data.password_confirmation}
                        onChange={e => setData('password_confirmation', e.target.value)}
                        error={errors.password_confirmation}
                    />

                    <Button type="submit" size="3" variant="outline" color="gray" className="dl-auth__submit" disabled={processing}>
                        {processing ? <><Spinner size="1" /> Resetting…</> : 'Reset password'}
                    </Button>
                </form>
            </AuthLayout>
        </>
    );
}
