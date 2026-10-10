import React from 'react';
import { Head, Link, router } from '@inertiajs/react';
import App from '@/Layouts/App.jsx';
import PageHeader from '@/Components/PageHeader';
import { Button, Icon } from '@/Components/Cyber';

/* Cyber page_404_error: HUD stripes framing the code, uppercase heading and message, a short rule, a row of
   helpful links separated by 3px squares, and a BACK button. Links are the always-available pages. */
export default function Forbidden({ message, accessType, accessPath }) {
    return (
        <App>
            <Head title="Access Denied" />
            <PageHeader upper title="Access" muted="Denied" chips={[{ value: 403, label: 'Error', tone: 'danger' }]} />
            <div className="cy-error">
                <div className="cy-error__content">
                    <div className="dl-hud-line dl-hud-line--lg" aria-hidden="true" />
                    <p className="cy-error__code" aria-hidden="true">403</p>
                    <div className="dl-hud-line dl-hud-line--lg" aria-hidden="true" />

                    <h2 className="cy-error__title">Access denied</h2>
                    <p className="cy-error__text">{message || "You don't have permission to access this resource."}</p>

                    {(accessType || accessPath) && (
                        <p className="cy-error__detail" role="note">
                            <Icon name="lock" />
                            <span>
                                {accessType && <span>{accessType}</span>}
                                {accessPath && <span style={{ opacity: 0.7 }}> ({accessPath})</span>}
                            </span>
                        </p>
                    )}

                    <hr className="cy-error__rule" />
                    <p className="cy-error__text">If you believe you should have access, contact your administrator.</p>
                    <p className="cy-error__lead">Here are some helpful links instead:</p>
                    <ul className="cy-links">
                        <li><Link href={route('dashboard')}>Home</Link></li>
                        <li><Link href={route('search')}>Search</Link></li>
                    </ul>
                    <Button color="secondary" variant="outline" onClick={() => router.back()}>
                        BACK
                    </Button>
                </div>
            </div>
        </App>
    );
}
