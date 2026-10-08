import React, { useEffect, useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import { Button, Callout, Flex, Spinner, TextField } from '@radix-ui/themes';
import {
    ArrowLeftIcon, CheckCircledIcon, EnvelopeClosedIcon,
} from '@radix-ui/react-icons';
import AuthLayout, { AuthField } from '@/Components/AuthLayout';

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
            <AuthLayout
                title="Forgot password?"
                subtitle="Enter your email and we'll send you a reset link."
                mark={<EnvelopeClosedIcon />}
            >
                {showSuccess && status && (
                    <Callout.Root color="green" mb="4">
                        <Callout.Icon><CheckCircledIcon /></Callout.Icon>
                        <Callout.Text>{status}</Callout.Text>
                    </Callout.Root>
                )}

                <form onSubmit={submit}>
                    <AuthField id="fp-email" label="Email address" required error={errors.email}>
                        <TextField.Root
                            id="fp-email"
                            type="email"
                            placeholder="you@company.com"
                            value={data.email}
                            onChange={e => setData('email', e.target.value)}
                            color={errors.email ? 'red' : undefined}
                            aria-invalid={errors.email ? true : undefined}
                            aria-describedby={errors.email ? 'fp-email-error' : undefined}
                            autoComplete="email"
                            autoFocus
                            required
                            size="2"
                        />
                    </AuthField>

                    <Button type="submit" size="3" variant="outline" color="gray" className="dl-auth__submit" disabled={processing}>
                        {processing ? <><Spinner size="1" /> Sending…</> : 'Send reset link'}
                    </Button>
                </form>

                <Flex justify="center" mt="4">
                    <Button asChild variant="ghost" color="gray" size="2">
                        <Link href={route('login')}>
                            <ArrowLeftIcon /> Back to sign in
                        </Link>
                    </Button>
                </Flex>
            </AuthLayout>
        </>
    );
}
