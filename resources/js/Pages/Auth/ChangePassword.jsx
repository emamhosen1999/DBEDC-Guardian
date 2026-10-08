import React from 'react';
import { Head, useForm, router } from '@inertiajs/react';
import { Button, Callout, Flex, TextField } from '@radix-ui/themes';
import { InfoCircledIcon, LockClosedIcon } from '@radix-ui/react-icons';
import AuthLayout, { AuthField } from '@/Components/AuthLayout';

/*
 * Change one's own password. Where an admin-set password lands the user (users.must_change_password):
 * every other page redirects here until it is replaced, so sign-out is the only other way out.
 */
const ChangePassword = ({ forced = false }) => {
    const form = useForm({ current_password: '', password: '', password_confirmation: '' });

    const submit = (e) => {
        e.preventDefault();
        form.put(route('account.password.update'), { onSuccess: () => form.reset() });
    };

    return (
        <>
            <Head title="Change your password" />
            <AuthLayout title="Change your password" mark={<LockClosedIcon />}>
                <form onSubmit={submit} noValidate>
                    {forced && (
                        <Callout.Root color="amber" role="status" mb="4">
                            <Callout.Icon><InfoCircledIcon /></Callout.Icon>
                            <Callout.Text>An administrator set this password. Choose your own before you continue.</Callout.Text>
                        </Callout.Root>
                    )}
                    {[
                        ['current_password', 'Current password', 'current-password'],
                        ['password', 'New password', 'new-password'],
                        ['password_confirmation', 'Confirm new password', 'new-password'],
                    ].map(([key, label, autoComplete]) => (
                        <AuthField key={key} id={key} label={label} required error={form.errors[key]}>
                            <TextField.Root
                                id={key}
                                type="password"
                                autoComplete={autoComplete}
                                value={form.data[key]}
                                onChange={(e) => form.setData(key, e.target.value)}
                                aria-invalid={form.errors[key] ? true : undefined}
                                aria-describedby={form.errors[key] ? `${key}-error` : undefined}
                                required
                                size="2"
                            />
                        </AuthField>
                    ))}
                    <Button type="submit" size="3" variant="outline" color="gray" className="dl-auth__submit" disabled={form.processing}>
                        Change password
                    </Button>
                    <Flex justify="center">
                        <Button type="button" variant="ghost" color="gray" size="2" onClick={() => router.post(route('logout'))}>Sign out</Button>
                    </Flex>
                </form>
            </AuthLayout>
        </>
    );
};

export default ChangePassword;
