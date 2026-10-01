import React from 'react';
import { Head, useForm, router } from '@inertiajs/react';
import { Box, Button, Callout, Card, Flex, Heading, Text, TextField } from '@radix-ui/themes';
import { InfoCircledIcon } from '@radix-ui/react-icons';

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
        <Flex justify="center" align="center" style={{ minHeight: '100vh', padding: 16 }}>
            <Head title="Change your password" />
            <Card style={{ width: '100%', maxWidth: 440 }}>
                <form onSubmit={submit} noValidate>
                    <Flex direction="column" gap="3" p="2">
                        <Heading size="5">Change your password</Heading>
                        {forced && (
                            <Callout.Root color="amber" role="status">
                                <Callout.Icon><InfoCircledIcon /></Callout.Icon>
                                <Callout.Text>An administrator set this password. Choose your own before you continue.</Callout.Text>
                            </Callout.Root>
                        )}
                        {[
                            ['current_password', 'Current password', 'current-password'],
                            ['password', 'New password', 'new-password'],
                            ['password_confirmation', 'Confirm new password', 'new-password'],
                        ].map(([key, label, autoComplete]) => (
                            <Box key={key}>
                                <Text as="label" size="2" weight="medium" htmlFor={key}>{label}</Text>
                                <TextField.Root id={key} type="password" autoComplete={autoComplete} value={form.data[key]} onChange={(e) => form.setData(key, e.target.value)} />
                                {form.errors[key] && <Text size="1" color="red" role="alert">{form.errors[key]}</Text>}
                            </Box>
                        ))}
                        <Flex gap="3" justify="between" mt="2">
                            <Button type="button" variant="soft" color="gray" onClick={() => router.post(route('logout'))}>Sign out</Button>
                            <Button type="submit" disabled={form.processing}>Change password</Button>
                        </Flex>
                    </Flex>
                </form>
            </Card>
        </Flex>
    );
};

export default ChangePassword;
