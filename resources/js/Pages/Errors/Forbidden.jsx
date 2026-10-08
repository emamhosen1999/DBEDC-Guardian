import React from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { Button, Callout, Flex, Text } from '@radix-ui/themes';
import { ArrowLeftIcon, HomeIcon, LockClosedIcon } from '@radix-ui/react-icons';
import App from '@/Layouts/App.jsx';
import PageHeader from '@/Components/PageHeader';

/* Cyber page_404_error: HUD rules framing the code, uppercase message, actions. */
export default function Forbidden({ message, accessType, accessPath }) {
    return (
        <App>
            <Head title="Access Denied" />
            <PageHeader title="Access Denied" subtitle="Error 403" />
            <div className="dl-error">
                <div className="dl-error__frame">
                    <div className="dl-hud-line" aria-hidden="true" />
                    <p className="dl-error__code" aria-hidden="true" style={{ padding: '18px 0' }}>403</p>
                    <div className="dl-hud-line" aria-hidden="true" />

                    <h2 className="dl-error__title" style={{ margin: '28px 0 6px' }}>Access denied</h2>
                    <Text as="p" className="dl-error__text" mb="4">
                        {message || "You don't have permission to access this resource."}
                    </Text>

                    {(accessType || accessPath) && (
                        <Callout.Root color="red" mb="4" style={{ textAlign: 'start' }}>
                            <Callout.Icon><LockClosedIcon /></Callout.Icon>
                            <Callout.Text>
                                {accessType && <span style={{ textTransform: 'capitalize' }}>{accessType}</span>}
                                {accessPath && <span style={{ opacity: 0.7 }}> ({accessPath})</span>}
                            </Callout.Text>
                        </Callout.Root>
                    )}

                    <Text as="p" size="1" color="gray" mb="4" style={{ textTransform: 'uppercase' }}>
                        If you believe you should have access, please contact your administrator.
                    </Text>

                    <Flex gap="3" justify="center" wrap="wrap">
                        <Button variant="outline" color="gray" onClick={() => router.back()}>
                            <ArrowLeftIcon /> Go Back
                        </Button>
                        <Button asChild variant="outline">
                            <Link href={route('dashboard')}>
                                <HomeIcon /> Dashboard
                            </Link>
                        </Button>
                    </Flex>
                </div>
            </div>
        </App>
    );
}
