import React, { useState, useEffect } from 'react';
import { Head } from '@inertiajs/react';
import { Callout } from '@radix-ui/themes';

const APK_URL = '/apk/latest.apk';
const APK_VERSION = '1.0.0';
const APK_SIZE = '90 MB';
const APK_RELEASE_DATE = 'Apr 2025';

const steps = [
    {
        number: '01',
        title: 'Download the APK',
        desc: 'Tap the button above to download the official DBEDC APK file to your Android device.',
        icon: (
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.8}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
            </svg>
        ),
    },
    {
        number: '02',
        title: 'Locate the File',
        desc: 'Open your notifications bar or navigate to your Downloads folder to find the APK file.',
        icon: (
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.8}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" />
            </svg>
        ),
    },
    {
        number: '03',
        title: 'Allow Installation',
        desc: 'When prompted by Android, tap "Settings" and toggle "Allow from this source" to enable the install.',
        icon: (
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.8}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
            </svg>
        ),
    },
    {
        number: '04',
        title: 'Launch & Sign In',
        desc: 'Once installed, open the DBEDC app and log in with your credentials to get started.',
        icon: (
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.8}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1" />
            </svg>
        ),
    },
];

const meta = [
    { label: 'Version', value: APK_VERSION },
    { label: 'Size', value: APK_SIZE },
    { label: 'Platform', value: 'Android 6+' },
    { label: 'Released', value: APK_RELEASE_DATE },
];

export default function InstallApp() {
    const [isAndroid, setIsAndroid] = useState(false);
    const [downloading, setDownloading] = useState(false);
    const [done, setDone] = useState(false);
    const [openStep, setOpenStep] = useState(0);

    useEffect(() => {
        setIsAndroid(/android/i.test(navigator.userAgent));
    }, []);

    const handleDownload = () => {
        setDownloading(true);
        setTimeout(() => { setDownloading(false); setDone(true); }, 2800);
    };

    return (
        <>
            <Head title="Install DBEDC Mobile App" />
            <style>{INSTALL_CSS}</style>

            <main className="ia-page">
                {/* HERO */}
                <header className="ia-hero">
                    <div className="ia-eyebrow">
                        <span className="ia-pulse" aria-hidden="true" />
                        Official Android Release
                    </div>
                    <h1>Install the <em>DBEDC</em> Mobile App</h1>
                    <p>Access DBEDC services on the go. Download the official Android app and get set up in under two minutes.</p>
                </header>

                {/* MAIN GRID */}
                <div className="ia-grid">
                    {/* LEFT — Download card */}
                    <section className="dl-card" aria-labelledby="ia-download-title">
                        <header className="dl-card__header">
                            <h2 className="dl-card__title" id="ia-download-title">Download</h2>
                            <span className="dl-hud-line" aria-hidden="true" />
                        </header>
                        <div className="dl-card__body">
                            <dl className="ia-meta">
                                {meta.map(m => (
                                    <div className="ia-meta__cell" key={m.label}>
                                        <dt className="ia-meta__key">{m.label}</dt>
                                        <dd className="ia-meta__val">{m.value}</dd>
                                    </div>
                                ))}
                            </dl>

                            {!isAndroid && (
                                <Callout.Root color="red" mb="3" role="note">
                                    <Callout.Icon>
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" strokeWidth={2} aria-hidden="true">
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
                                        </svg>
                                    </Callout.Icon>
                                    <Callout.Text><strong>Android device required.</strong> Please open this page on your Android phone or tablet to download.</Callout.Text>
                                </Callout.Root>
                            )}

                            {done ? (
                                <button type="button" className="ia-btn ia-btn--success">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2.5} aria-hidden="true">
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" />
                                    </svg>
                                    Download Complete!
                                </button>
                            ) : isAndroid ? (
                                <a href={APK_URL} download onClick={handleDownload}
                                    className={`ia-btn ${downloading ? 'ia-btn--loading' : 'ia-btn--primary'}`}
                                    aria-busy={downloading || undefined}>
                                    {downloading ? (
                                        <><span className="ia-spinner" aria-hidden="true" /> Downloading…</>
                                    ) : (
                                        <>
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2.3} aria-hidden="true">
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                                            </svg>
                                            Download APK · Free
                                        </>
                                    )}
                                </a>
                            ) : (
                                <button type="button" className="ia-btn ia-btn--muted" disabled>
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} aria-hidden="true">
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                                    </svg>
                                    Android Only
                                </button>
                            )}

                            {downloading && (
                                <div className="ia-progress" role="status">
                                    <div className="ia-progress__track"><div className="ia-progress__fill" /></div>
                                    <div className="ia-progress__label">Downloading DBEDC-v{APK_VERSION}.apk ({APK_SIZE})</div>
                                </div>
                            )}
                        </div>
                    </section>

                    {/* RIGHT — Steps */}
                    <section className="dl-card" aria-labelledby="ia-steps-title">
                        <header className="dl-card__header">
                            <h2 className="dl-card__title" id="ia-steps-title">How to Install</h2>
                            <span className="dl-hud-line" aria-hidden="true" />
                        </header>
                        <p className="ia-steps-intro">Follow these four steps to get the app running on your device.</p>
                        <ol className="ia-steps">
                            {steps.map((s, i) => {
                                const open = openStep === i;
                                return (
                                    <li key={s.number} className={`ia-step${open ? ' ia-step--open' : ''}`}>
                                        <button
                                            type="button"
                                            className="ia-step__toggle"
                                            aria-expanded={open}
                                            aria-controls={`ia-step-${s.number}`}
                                            onClick={() => setOpenStep(open ? -1 : i)}
                                        >
                                            <span className="ia-step__num">{s.number}</span>
                                            <span className="ia-step__icon" aria-hidden="true">{s.icon}</span>
                                            <span className="ia-step__title">{s.title}</span>
                                            <svg className="ia-step__chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2.2} aria-hidden="true">
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M19 9l-7 7-7-7" />
                                            </svg>
                                        </button>
                                        <p className="ia-step__desc" id={`ia-step-${s.number}`} hidden={!open}>{s.desc}</p>
                                    </li>
                                );
                            })}
                        </ol>
                    </section>
                </div>

                {/* FOOTER */}
                <footer className="ia-footer">
                    <span>© {new Date().getFullYear()} Emam Hosen. All rights reserved.</span>
                </footer>
            </main>
        </>
    );
}

/* Cyber install page. Scoped to .ia-page: this page has no app shell, and the
   previous global :root / * / body rules leaked into Radix portals. Colours
   and type come from the Cyber tokens (resources/css/design/cyber). */
const INSTALL_CSS = `
.ia-page {
    min-height: 100vh;
    min-height: 100dvh;
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 2.625rem 0.875rem;
    color: var(--gray-12);
}
.ia-hero { max-width: 48rem; text-align: center; margin-bottom: 1.75rem; }
.ia-eyebrow {
    display: inline-flex; align-items: center; gap: 0.4375rem;
    padding: 0.25rem 0.65625rem; margin-bottom: 0.875rem;
    color: var(--accent-11); background: var(--accent-a3);
    box-shadow: inset 0 0 0 1px var(--accent-a6);
    border-radius: var(--dl-panel-radius, 999px);
    font-size: var(--font-size-1); font-weight: 700; text-transform: uppercase;
}
.ia-pulse { width: 0.4375rem; height: 0.4375rem; border-radius: 50%; background: var(--accent-9); animation: ia-pulse 2s ease infinite; }
.ia-hero h1 {
    margin: 0 0 0.65625rem;
    font-family: var(--heading-font-family);
    font-size: calc(1.375rem + 1.5vw);
    font-weight: 700; line-height: 1.2;
}
@media (min-width: 1200px) { .ia-hero h1 { font-size: 2.5rem; } }
.ia-hero h1 em { font-style: normal; color: var(--accent-11); }
.ia-hero p { margin: 0; color: rgba(var(--cy-fg-rgb, 255, 255, 255), 0.75); font-size: var(--font-size-3); line-height: 1.6; }
.ia-grid { width: 100%; max-width: 67.5rem; display: grid; grid-template-columns: 1fr; gap: 0.875rem; }
@media (min-width: 900px) { .ia-grid { grid-template-columns: 1.1fr 0.9fr; align-items: start; } }
.ia-meta { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 0.4375rem; margin: 0 0 0.875rem; }
@media (max-width: 440px) { .ia-meta { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
.ia-meta__cell {
    padding: 0.65625rem 0.4375rem; text-align: center;
    border: 1px solid var(--dl-border-color); background: var(--dl-surface-bg);
    border-radius: var(--dl-panel-radius, 12px);
}
.ia-meta__val { margin: 0; font-size: var(--font-size-4); font-weight: 600; }
.ia-meta__key { margin: 0 0 0.25rem; font-size: var(--font-size-1); font-weight: 700; text-transform: uppercase; color: var(--gray-11); }
.ia-btn {
    width: 100%; min-height: 2.375rem; padding: 0.525rem 1.05rem;
    display: flex; align-items: center; justify-content: center; gap: 0.5rem;
    border: 1px solid transparent; border-radius: var(--dl-panel-radius, 12px);
    font: inherit; font-size: var(--font-size-3); font-weight: 600; text-transform: uppercase;
    text-decoration: none; cursor: pointer;
    transition: background-color var(--dl-transition), color var(--dl-transition);
}
.ia-btn--primary { background: var(--accent-9); color: var(--accent-contrast); }
.ia-btn--primary:hover { background: var(--accent-10); }
.ia-btn--loading { background: var(--accent-9); color: var(--accent-contrast); opacity: 0.8; cursor: wait; }
.ia-btn--success { background: var(--green-9); color: var(--green-contrast); cursor: default; }
.ia-btn--muted { background: transparent; color: var(--gray-11); border-color: var(--gray-a7); cursor: not-allowed; }
.ia-btn:focus-visible { outline: 1px solid var(--focus-8); outline-offset: 2px; }
.ia-spinner { width: 1rem; height: 1rem; border: 2px solid currentColor; border-top-color: transparent; border-radius: 50%; animation: ia-spin 0.75s linear infinite; }
.ia-progress { margin-top: 0.65625rem; }
.ia-progress__track { height: 0.375rem; background: var(--gray-a5); overflow: hidden; }
.ia-progress__fill { height: 100%; width: 0; background: var(--accent-9); animation: ia-fill 2.8s ease forwards; }
.ia-progress__label { margin-top: 0.375rem; font-size: var(--font-size-1); color: var(--gray-11); text-transform: uppercase; font-weight: 600; }
.ia-steps-intro { margin: 0; padding: 0.65625rem 0.875rem; color: var(--gray-11); border-bottom: 1px solid var(--dl-border-color); }
.ia-steps { margin: 0; padding: 0; list-style: none; }
.ia-step + .ia-step { border-top: 1px solid var(--dl-border-color); }
.ia-step__toggle {
    width: 100%; display: flex; align-items: center; gap: 0.65625rem;
    padding: 0.65625rem 0.875rem; border: 0; background: none;
    color: inherit; font: inherit; text-align: start; cursor: pointer;
}
.ia-step__toggle:hover { background: var(--gray-a3); }
.ia-step__toggle:focus-visible { outline: 1px solid var(--focus-8); outline-offset: -1px; }
.ia-step--open .ia-step__toggle { background: var(--accent-a2); }
.ia-step__num {
    width: 1.75rem; height: 1.75rem; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    border: 1px solid var(--gray-a7); border-radius: var(--dl-panel-radius, 8px);
    font-size: var(--font-size-1); font-weight: 700;
}
.ia-step--open .ia-step__num { background: var(--accent-9); border-color: var(--accent-9); color: var(--accent-contrast); }
.ia-step__icon { width: 1.125rem; height: 1.125rem; flex-shrink: 0; color: var(--gray-11); }
.ia-step__icon svg { width: 100%; height: 100%; }
.ia-step--open .ia-step__icon { color: var(--accent-11); }
.ia-step__title { flex: 1; min-width: 0; font-weight: 600; text-transform: uppercase; }
.ia-step__chevron { flex-shrink: 0; color: var(--gray-11); transition: transform 0.2s; }
.ia-step--open .ia-step__chevron { transform: rotate(180deg); color: var(--accent-11); }
.ia-step__desc { margin: 0; padding: 0 0.875rem 0.875rem 5.0625rem; color: var(--gray-11); line-height: 1.6; background: var(--accent-a2); }
.ia-footer {
    width: 100%; max-width: 67.5rem; margin-top: 1.75rem; padding-top: 0.875rem;
    border-top: 1px solid var(--dl-border-color);
    color: var(--gray-11); font-size: var(--font-size-1); font-weight: 600; text-transform: uppercase;
}
@keyframes ia-pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.3; } }
@keyframes ia-spin { to { transform: rotate(360deg); } }
@keyframes ia-fill { 0% { width: 0%; } 60% { width: 72%; } 100% { width: 100%; } }
@media (prefers-reduced-motion: reduce) {
    .ia-pulse, .ia-spinner, .ia-progress__fill { animation: none; }
    .ia-progress__fill { width: 100%; }
}
`;
