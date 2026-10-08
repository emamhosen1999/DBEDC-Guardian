import React from 'react';
import { Head, Link } from '@inertiajs/react';
import { Button } from '@radix-ui/themes';
import { LockClosedIcon, ArrowLeftIcon } from '@radix-ui/react-icons';
import AuthLayout from '@/Components/AuthLayout';

export default function Register() {
    return (
        <>
            <Head title="Registration" />
            <AuthLayout
                title="Registration restricted"
                subtitle="New accounts are created by system administrators. Please contact your administrator to request access."
                mark={<LockClosedIcon />}
            >
                <Button asChild size="3" variant="outline" color="gray" className="dl-auth__submit">
                    <Link href={route('login')}>
                        <ArrowLeftIcon /> Back to sign in
                    </Link>
                </Button>
            </AuthLayout>
        </>
    );
}
