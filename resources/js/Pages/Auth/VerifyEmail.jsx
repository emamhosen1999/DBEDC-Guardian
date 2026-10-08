import React, { useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import { Button, Callout, Flex, Spinner } from '@radix-ui/themes';
import {
    CheckCircledIcon, EnvelopeClosedIcon, ExitIcon,
} from '@radix-ui/react-icons';
import AuthLayout from '@/Components/AuthLayout';

export default function VerifyEmail({ status }) {
    const { post, processing } = useForm({});
    const [sent, setSent] = useState(false);

    const submit = (e) => {
        e.preventDefault();
        post(route('verification.send'), { onSuccess: () => setSent(true) });
    };

    return (
        <>
            <Head title="Verify Email" />
            <AuthLayout
                title="Verify your email"
                subtitle="Please verify your email address by clicking the link we sent you."
                mark={<EnvelopeClosedIcon />}
            >
                {(sent || status === 'verification-link-sent') && (
                    <Callout.Root color="green" mb="4" role="status">
                        <Callout.Icon><CheckCircledIcon /></Callout.Icon>
                        <Callout.Text>A new verification link has been sent to your email address.</Callout.Text>
                    </Callout.Root>
                )}
                <form onSubmit={submit}>
                    <Button type="submit" size="3" variant="outline" color="gray" className="dl-auth__submit" disabled={processing}>
                        {processing ? <><Spinner size="1" /> Sending…</> : 'Resend verification email'}
                    </Button>
                </form>
                <Flex justify="center" mt="4">
                    <Button asChild variant="ghost" color="gray" size="2">
                        <Link href={route('logout')} method="post" as="button">
                            <ExitIcon /> Sign out
                        </Link>
                    </Button>
                </Flex>
            </AuthLayout>
        </>
    );
}
