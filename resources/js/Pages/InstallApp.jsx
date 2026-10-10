import React, { useEffect, useState } from 'react';
import { Head } from '@inertiajs/react';
import AppFooter from '@/Layouts/AppFooter.jsx';
import { Accordion, Button, Icon } from '@/Components/Cyber';

const APK_URL = '/apk/latest.apk';

const STEPS = [
    { key: 'download', title: 'Download the APK', content: 'Tap the download button to get the official DBEDC APK file on your Android device.' },
    { key: 'locate', title: 'Locate the file', content: 'Open your notifications bar or your Downloads folder to find the APK file.' },
    { key: 'allow', title: 'Allow installation', content: 'When Android asks, tap "Settings" and switch on "Allow from this source" so the install can continue.' },
    { key: 'launch', title: 'Launch and sign in', content: 'Once installed, open the DBEDC app and sign in with your credentials.' },
];

/** 92668185 -> "88.4 MB" (binary megabytes, as Android reports them). */
export function formatApkSize(bytes) {
    if (!Number.isFinite(bytes) || bytes <= 0) return null;
    return `${(bytes / 1048576).toFixed(1)} MB`;
}

export function formatReleased(isoDate) {
    if (!isoDate) return null;
    const date = new Date(`${isoDate}T00:00:00`);
    if (Number.isNaN(date.getTime())) return null;
    return date.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}

/* Cyber page_coming_soon composition (the closest standalone, centred, single-action page; profile.html is a
   shell page with a sidebar, which this public page does not have): large icon, heading, text, one action row,
   a small caption, then the steps as a Cyber accordion and the fixed footer strip. */
export default function InstallApp({ apk = null }) {
    const [isAndroid, setIsAndroid] = useState(false);
    const [started, setStarted] = useState(false);

    useEffect(() => {
        setIsAndroid(/android/i.test(navigator.userAgent));
    }, []);

    const size = formatApkSize(apk?.size_bytes);
    const released = formatReleased(apk?.released_at);

    return (
        <>
            <Head title="Install DBEDC Mobile App" />
            <main className="cy-soon">
                <div className="cy-soon__content">
                    <div className="cy-soon__icon" aria-hidden="true"><Icon name="android2" /></div>
                    <h1 className="cy-soon__title">Install the DBEDC mobile app</h1>
                    <p className="cy-soon__text">Download the official Android app and get set up in a few minutes.</p>

                    {!isAndroid && (
                        <p className="cy-error__detail" role="note">
                            <Icon name="exclamation-triangle" />
                            <span>Android device required. Open this page on your Android phone or tablet.</span>
                        </p>
                    )}

                    <div className="cy-soon__actions">
                        {isAndroid ? (
                            <Button as="a" color="secondary" href={APK_URL} download onClick={() => setStarted(true)}>
                                <Icon name="download" /> DOWNLOAD APK
                            </Button>
                        ) : (
                            <Button color="secondary" disabled>
                                <Icon name="download" /> ANDROID ONLY
                            </Button>
                        )}
                    </div>

                    {started && (
                        <p className="cy-soon__note" role="status">Download started. Open the file from your notifications or Downloads folder.</p>
                    )}
                    {(size || released) && (
                        <p className="cy-soon__note">{[size, released && `Released ${released}`].filter(Boolean).join(' · ')}</p>
                    )}

                    <div className="cy-soon__steps">
                        <Accordion items={STEPS} />
                    </div>
                </div>
                <AppFooter />
            </main>
        </>
    );
}
