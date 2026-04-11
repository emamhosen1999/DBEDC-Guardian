import React, { useState, useEffect } from 'react';
import { Head } from '@inertiajs/react';

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
            <style>{`
                @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Fraunces:ital,opsz,wght@0,9..144,300;0,9..144,600;0,9..144,700;1,9..144,300&display=swap');

                *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

                :root {
                    --blue:        #1650F0;
                    --blue-light:  #EEF2FF;
                    --blue-mid:    #C7D3FD;
                    --blue-dark:   #0F3ACC;
                    --ink:         #0A0F1E;
                    --ink-2:       #3A4260;
                    --ink-3:       #8892B0;
                    --surface:     #FFFFFF;
                    --bg:          #F4F6FB;
                    --border:      #E4E8F5;
                    --red:         #E63946;
                    --red-light:   #FFF0F0;
                    --green:       #0D9955;
                    --green-light: #ECFDF5;
                    --jakarta:     'Plus Jakarta Sans', sans-serif;
                    --fraunces:    'Fraunces', serif;
                    --radius-lg:   18px;
                    --radius-md:   12px;
                    --shadow-sm:   0 1px 4px rgba(10,15,40,0.06), 0 4px 16px rgba(10,15,40,0.05);
                    --shadow-blue: 0 8px 32px rgba(22,80,240,0.18);
                }

                body { background: var(--bg); }

                .page {
                    min-height: 100vh;
                    background: var(--bg);
                    background-image:
                        radial-gradient(ellipse 70% 40% at 60% -5%, rgba(22,80,240,0.07) 0%, transparent 55%),
                        radial-gradient(ellipse 50% 30% at 100% 100%, rgba(22,80,240,0.04) 0%, transparent 50%);
                    font-family: var(--jakarta);
                    color: var(--ink);
                    padding: 2rem 1rem 3rem;
                    display: flex;
                    flex-direction: column;
                    align-items: center;
                }

                /* ── TOP NAV ── */
                .topbar {
                    width: 100%; max-width: 1080px;
                    display: flex; align-items: center; justify-content: space-between;
                    margin-bottom: 3rem;
                    animation: fadeDown 0.5s ease both;
                }
                .logo {
                    display: flex; align-items: center; gap: 10px;
                    font-family: var(--fraunces);
                    font-size: 1.2rem; font-weight: 700;
                    color: var(--ink); text-decoration: none;
                }
                .logo-mark {
                    width: 36px; height: 36px;
                    background: var(--blue); border-radius: 10px;
                    display: flex; align-items: center; justify-content: center;
                }
                .logo-mark svg { width: 20px; height: 20px; color: #fff; }
                .topbar-link {
                    font-size: 0.82rem; font-weight: 600;
                    color: var(--ink-3); text-decoration: none;
                    padding: 6px 14px; border-radius: 999px;
                    border: 1px solid var(--border); background: var(--surface);
                    transition: all 0.15s;
                }
                .topbar-link:hover { color: var(--blue); border-color: var(--blue-mid); }

                /* ── HERO ── */
                .hero {
                    text-align: center; max-width: 580px;
                    margin-bottom: 3rem;
                    animation: fadeUp 0.55s 0.05s ease both;
                }
                .eyebrow {
                    display: inline-flex; align-items: center; gap: 7px;
                    background: var(--blue-light); color: var(--blue);
                    font-size: 0.72rem; font-weight: 700;
                    letter-spacing: 0.1em; text-transform: uppercase;
                    padding: 5px 14px; border-radius: 999px;
                    border: 1px solid var(--blue-mid); margin-bottom: 1.25rem;
                }
                .pulse-dot {
                    width: 7px; height: 7px; background: var(--blue);
                    border-radius: 50%; animation: pulse 2s ease infinite;
                }
                .hero h1 {
                    font-family: var(--fraunces);
                    font-size: clamp(2rem, 5.5vw, 3.1rem);
                    font-weight: 700; line-height: 1.15;
                    color: var(--ink); margin-bottom: 1rem; letter-spacing: -0.02em;
                }
                .hero h1 em { font-style: italic; color: var(--blue); }
                .hero p { font-size: 1rem; color: var(--ink-2); line-height: 1.7; font-weight: 400; }

                /* ── GRID ── */
                .main-grid {
                    width: 100%; max-width: 1080px;
                    display: grid; grid-template-columns: 1fr; gap: 1.5rem;
                    animation: fadeUp 0.55s 0.12s ease both;
                }
                @media (min-width: 900px) {
                    .main-grid { grid-template-columns: 1.1fr 0.9fr; align-items: start; }
                }

                /* ── CARD ── */
                .card {
                    background: var(--surface);
                    border: 1px solid var(--border);
                    border-radius: var(--radius-lg);
                    box-shadow: var(--shadow-sm);
                    overflow: hidden;
                }

                /* ── APP HEADER ── */
                .app-showcase { padding: 2rem; }
                .app-header {
                    display: flex; align-items: center; gap: 1rem;
                    margin-bottom: 1.75rem; padding-bottom: 1.75rem;
                    border-bottom: 1px solid var(--border);
                }
                .app-icon-wrap {
                    width: 68px; height: 68px; border-radius: 18px;
                    background: linear-gradient(140deg, #1650F0 0%, #4F79F8 100%);
                    box-shadow: 0 6px 20px rgba(22,80,240,0.28);
                    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
                }
                .app-icon-wrap svg { width: 34px; height: 34px; color: #fff; }
                .app-info-title { font-size: 1.1rem; font-weight: 800; color: var(--ink); margin-bottom: 3px; }
                .app-info-sub { font-size: 0.8rem; color: var(--ink-3); font-weight: 500; margin-bottom: 5px; }
                .verified-badge {
                    display: inline-flex; align-items: center; gap: 4px;
                    background: var(--green-light); color: var(--green);
                    font-size: 0.68rem; font-weight: 700;
                    letter-spacing: 0.06em; text-transform: uppercase;
                    padding: 3px 9px; border-radius: 999px;
                }
                .rating-col { margin-left: auto; text-align: center; flex-shrink: 0; }
                .stars { color: #F59E0B; font-size: 0.9rem; letter-spacing: 1px; }
                .rating-num { font-size: 0.7rem; color: var(--ink-3); margin-top: 2px; font-weight: 600; }

                /* ── META ── */
                .meta-grid {
                    display: grid; grid-template-columns: repeat(4, 1fr);
                    gap: 0.75rem; margin-bottom: 1.75rem;
                }
                @media (max-width: 440px) { .meta-grid { grid-template-columns: repeat(2, 1fr); } }
                .meta-cell {
                    background: var(--bg); border: 1px solid var(--border);
                    border-radius: var(--radius-md); padding: 0.85rem 0.6rem; text-align: center;
                }
                .meta-val { font-size: 0.88rem; font-weight: 800; color: var(--ink); margin-bottom: 3px; }
                .meta-key { font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.1em; color: var(--ink-3); font-weight: 600; }

                /* ── BUTTON ── */
                .btn {
                    width: 100%; padding: 1rem 1.5rem;
                    border-radius: var(--radius-md); border: none; cursor: pointer;
                    font-family: var(--jakarta); font-size: 0.95rem; font-weight: 700;
                    display: flex; align-items: center; justify-content: center; gap: 9px;
                    transition: all 0.2s; text-decoration: none;
                }
                .btn-primary { background: var(--blue); color: #fff; box-shadow: var(--shadow-blue); }
                .btn-primary:hover { background: var(--blue-dark); transform: translateY(-2px); box-shadow: 0 12px 36px rgba(22,80,240,0.26); }
                .btn-primary:active { transform: none; }
                .btn-success { background: var(--green); color: #fff; box-shadow: 0 6px 24px rgba(13,153,85,0.22); cursor: default; }
                .btn-muted { background: var(--bg); color: var(--ink-3); border: 1px solid var(--border); cursor: not-allowed; }
                .btn-loading { background: var(--blue); color: #fff; opacity: 0.8; cursor: wait; }
                .spinner {
                    width: 17px; height: 17px;
                    border: 2px solid rgba(255,255,255,0.35);
                    border-top-color: #fff; border-radius: 50%;
                    animation: spin 0.75s linear infinite;
                }
                .not-android {
                    background: var(--red-light); border: 1px solid #FFCCD0;
                    border-radius: var(--radius-md); padding: 0.9rem 1.1rem;
                    display: flex; gap: 10px; align-items: flex-start;
                    color: var(--red); font-size: 0.84rem; line-height: 1.55;
                    margin-bottom: 0.85rem;
                }
                .not-android svg { flex-shrink: 0; margin-top: 1px; width: 17px; height: 17px; }

                /* ── PROGRESS ── */
                .progress-wrap { margin-top: 0.85rem; display: none; }
                .progress-wrap.show { display: block; }
                .progress-bar-bg { height: 5px; background: var(--blue-light); border-radius: 99px; overflow: hidden; }
                .progress-fill { height: 100%; width: 0%; background: var(--blue); border-radius: 99px; animation: fillBar 2.8s ease forwards; }
                .progress-label { font-size: 0.73rem; color: var(--ink-3); margin-top: 6px; font-weight: 500; }

                /* ── OR DIVIDER ── */
                .or-divider {
                    display: flex; align-items: center; gap: 10px;
                    margin: 1.25rem 0; color: var(--ink-3);
                    font-size: 0.75rem; font-weight: 600;
                    letter-spacing: 0.08em; text-transform: uppercase;
                }
                .or-divider::before, .or-divider::after { content: ''; flex: 1; height: 1px; background: var(--border); }

                /* ── QR ROW ── */
                .qr-row {
                    display: flex; align-items: center; gap: 1rem;
                    background: var(--bg); border: 1px solid var(--border);
                    border-radius: var(--radius-md); padding: 0.9rem 1rem;
                }
                .qr-box {
                    width: 60px; height: 60px; background: var(--surface);
                    border: 1px solid var(--border); border-radius: 10px;
                    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
                }
                .qr-box svg { width: 40px; height: 40px; color: var(--ink); }
                .qr-text { font-size: 0.8rem; color: var(--ink-2); line-height: 1.55; }
                .qr-text strong { color: var(--ink); font-weight: 700; display: block; margin-bottom: 2px; }

                /* ── STEPS ── */
                .steps-card { padding: 0; }
                .steps-header { padding: 1.5rem 1.75rem 1.25rem; border-bottom: 1px solid var(--border); }
                .steps-header h2 { font-family: var(--fraunces); font-size: 1.25rem; font-weight: 700; color: var(--ink); margin-bottom: 3px; }
                .steps-header p { font-size: 0.82rem; color: var(--ink-3); }

                .step-item {
                    display: flex; align-items: flex-start; gap: 1rem;
                    padding: 1.1rem 1.75rem; cursor: pointer;
                    transition: background 0.15s; border-bottom: 1px solid var(--border);
                }
                .step-item:last-child { border-bottom: none; }
                .step-item:hover { background: var(--bg); }
                .step-item.active { background: var(--blue-light); }

                .step-num-badge {
                    width: 30px; height: 30px; border-radius: 8px;
                    background: var(--border); color: var(--ink-2);
                    font-size: 0.72rem; font-weight: 800;
                    display: flex; align-items: center; justify-content: center;
                    flex-shrink: 0; margin-top: 2px; transition: all 0.15s;
                }
                .step-item.active .step-num-badge { background: var(--blue); color: #fff; box-shadow: 0 3px 10px rgba(22,80,240,0.3); }

                .step-icon-wrap {
                    width: 38px; height: 38px; border-radius: 10px;
                    background: var(--surface); border: 1px solid var(--border);
                    display: flex; align-items: center; justify-content: center;
                    color: var(--ink-3); flex-shrink: 0; transition: all 0.15s;
                }
                .step-item.active .step-icon-wrap { border-color: var(--blue-mid); color: var(--blue); background: #fff; }
                .step-icon-wrap svg { width: 18px; height: 18px; }

                .step-content { flex: 1; min-width: 0; }
                .step-title { font-size: 0.88rem; font-weight: 700; color: var(--ink); }
                .step-desc {
                    font-size: 0.8rem; color: var(--ink-2); line-height: 1.6;
                    max-height: 0; overflow: hidden;
                    transition: max-height 0.25s ease, margin-top 0.2s; margin-top: 0;
                }
                .step-item.active .step-desc { max-height: 80px; margin-top: 5px; }
                .step-chevron { color: var(--ink-3); flex-shrink: 0; margin-top: 5px; transition: transform 0.2s; }
                .step-item.active .step-chevron { transform: rotate(180deg); color: var(--blue); }

                /* ── INFO STRIP ── */
                .info-strip {
                    width: 100%; max-width: 1080px;
                    display: grid; grid-template-columns: repeat(3, 1fr);
                    gap: 1rem; margin-top: 1.5rem;
                    animation: fadeUp 0.55s 0.25s ease both;
                }
                @media (max-width: 600px) { .info-strip { grid-template-columns: 1fr; } }
                .info-tile {
                    background: var(--surface); border: 1px solid var(--border);
                    border-radius: var(--radius-md); padding: 1rem 1.25rem;
                    display: flex; align-items: center; gap: 0.85rem;
                    box-shadow: var(--shadow-sm);
                }
                .info-tile-icon {
                    width: 40px; height: 40px; border-radius: 11px;
                    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
                }
                .info-tile-icon svg { width: 20px; height: 20px; }
                .info-tile-title { font-size: 0.82rem; font-weight: 700; color: var(--ink); margin-bottom: 2px; }
                .info-tile-sub { font-size: 0.74rem; color: var(--ink-3); line-height: 1.4; }

                /* ── FOOTER ── */
                .footer {
                    width: 100%; max-width: 1080px; margin-top: 2rem;
                    padding-top: 1.5rem; border-top: 1px solid var(--border);
                    display: flex; flex-wrap: wrap; align-items: center;
                    justify-content: space-between; gap: 0.75rem;
                    font-size: 0.77rem; color: var(--ink-3);
                    animation: fadeUp 0.55s 0.3s ease both;
                }
                .footer a { color: var(--blue); text-decoration: none; font-weight: 600; }
                .footer a:hover { text-decoration: underline; }
                .footer-links { display: flex; gap: 1.25rem; flex-wrap: wrap; }

                /* ── ANIMATIONS ── */
                @keyframes fadeUp   { from { opacity:0; transform:translateY(16px); } to { opacity:1; transform:translateY(0); } }
                @keyframes fadeDown { from { opacity:0; transform:translateY(-10px); } to { opacity:1; transform:translateY(0); } }
                @keyframes pulse    { 0%,100% { opacity:1; } 50% { opacity:0.3; } }
                @keyframes spin     { to { transform:rotate(360deg); } }
                @keyframes fillBar  { 0% { width:0%; } 60% { width:72%; } 100% { width:100%; } }

                /* ── RESPONSIVE ── */
                @media (max-width: 480px) {
                    .page { padding: 1.25rem 0.85rem 2.5rem; }
                    .topbar { margin-bottom: 2rem; }
                    .hero { margin-bottom: 2rem; }
                    .app-showcase { padding: 1.35rem; }
                    .step-item { padding: 1rem 1.25rem; }
                    .steps-header { padding: 1.25rem; }
                }
                @media (min-width: 640px) { .page { padding: 2.5rem 2rem 3.5rem; } }
            `}</style>

            <div className="page">

           
                {/* HERO */}
                <div className="hero">
                    <div className="eyebrow">
                        <span className="pulse-dot" />
                        Official Android Release
                    </div>
                    <h1>Install the <em>DBEDC</em><br />Mobile App</h1>
                    <p>Access DBEDC services on the go. Download the official Android app and get set up in under two minutes.</p>
                </div>

                {/* MAIN GRID */}
                <div className="main-grid">
                    {/* LEFT — Download card */}
                    <div className="card app-showcase">
                 

                        <div className="meta-grid">
                            {meta.map(m => (
                                <div className="meta-cell" key={m.label}>
                                    <div className="meta-val">{m.value}</div>
                                    <div className="meta-key">{m.label}</div>
                                </div>
                            ))}
                        </div>

                        {!isAndroid && (
                            <div className="not-android">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
                                </svg>
                                <span><strong>Android device required.</strong> Please open this page on your Android phone or tablet to download.</span>
                            </div>
                        )}

                        {done ? (
                            <button className="btn btn-success">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2.5}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" />
                                </svg>
                                Download Complete!
                            </button>
                        ) : isAndroid ? (
                            <a href={APK_URL} download onClick={handleDownload}
                                className={`btn ${downloading ? 'btn-loading' : 'btn-primary'}`}>
                                {downloading ? (
                                    <><span className="spinner" /> Downloading…</>
                                ) : (
                                    <>
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2.3}>
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                                        </svg>
                                        Download APK · Free
                                    </>
                                )}
                            </a>
                        ) : (
                            <button className="btn btn-muted" disabled>
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                                </svg>
                                Android Only
                            </button>
                        )}

                        <div className={`progress-wrap ${downloading ? 'show' : ''}`}>
                            <div className="progress-bar-bg">
                                <div className="progress-fill" />
                            </div>
                            <div className="progress-label">Downloading DBEDC-v{APK_VERSION}.apk ({APK_SIZE})</div>
                        </div>

                       
                    </div>

                    {/* RIGHT — Steps */}
                    <div className="card steps-card">
                        <div className="steps-header">
                            <h2>How to Install</h2>
                            <p>Follow these four steps to get the app running on your device.</p>
                        </div>
                        {steps.map((s, i) => (
                            <div key={s.number}
                                className={`step-item ${openStep === i ? 'active' : ''}`}
                                onClick={() => setOpenStep(openStep === i ? -1 : i)}>
                                <div className="step-num-badge">{s.number}</div>
                                <div className="step-icon-wrap">{s.icon}</div>
                                <div className="step-content">
                                    <div className="step-title">{s.title}</div>
                                    <div className="step-desc">{s.desc}</div>
                                </div>
                                <svg className="step-chevron" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2.2}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M19 9l-7 7-7-7" />
                                </svg>
                            </div>
                        ))}
                    </div>
                </div>

              
                {/* FOOTER */}
                <footer className="footer">
                    <span>© {new Date().getFullYear()} Emam Hosen. All rights reserved.</span>
                    
                </footer>
            </div>
        </>
    );
}