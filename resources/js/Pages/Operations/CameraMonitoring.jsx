import React, { useState, useEffect, useCallback, useRef } from 'react';
import { Head } from '@inertiajs/react';
import { Box, Flex, Text, Heading } from '@radix-ui/themes';
import {
    VideoCameraIcon,
    PlayIcon,
    PauseIcon,
    CameraIcon,
    SpeakerWaveIcon,
    SpeakerXMarkIcon,
    ArrowsPointingOutIcon,
    MinusIcon,
    PlusIcon,
} from '@heroicons/react/24/outline';
import App from '@/Layouts/App.jsx';

/**
 * TMC Monitoring Center Staff & Operator Surveillance
 *
 * Exact Web UI matching the reference design:
 * - Clean Aero Header with Video Icon, Title, Subtitle, and Live Badge
 * - 16/9 Video Viewport with OSD Badges ("● Monitoring Hall 2" & Timestamp)
 * - White Floating Controls Card with:
 *     - [ HD | SD ] Segmented Quality Switcher
 *     - Pause / Play (with cyan circular background)
 *     - Snapshot (with camera icon + label)
 *     - Mute (with speaker icon + label)
 *     - Fullscreen (with expand icon + label)
 *     - Zoom slider with [-] and [+] controls
 */

const STATUS_POLL_INTERVAL = 30000;

export default function CameraMonitoring({ auth, cameraUrl, cameraStatus: initialStatus }) {
    const [status, setStatus] = useState(initialStatus || null);

    // Stream Selection: 'HD' (cam-main) | 'SD' (cam-sub)
    const [streamQuality, setStreamQuality] = useState('HD');
    const [isPlaying, setIsPlaying] = useState(true);
    const [isMuted, setIsMuted] = useState(true);
    const [zoomLevel, setZoomLevel] = useState(1);

    // Dynamic Live Clock
    const [currentClock, setCurrentClock] = useState('');

    const streamPath = streamQuality === 'SD' ? 'cam-sub' : 'cam-main';

    // ── Live Clock Updater ──
    useEffect(() => {
        const updateClock = () => {
            const now = new Date();
            const year = now.getFullYear();
            const month = String(now.getMonth() + 1).padStart(2, '0');
            const day = String(now.getDate()).padStart(2, '0');
            const hours = String(now.getHours()).padStart(2, '0');
            const minutes = String(now.getMinutes()).padStart(2, '0');
            const seconds = String(now.getSeconds()).padStart(2, '0');
            setCurrentClock(`${year}-${month}-${day} ${hours}:${minutes}:${seconds}`);
        };
        updateClock();
        const interval = setInterval(updateClock, 1000);
        return () => clearInterval(interval);
    }, []);

    // ── Status Probe ──
    const checkStatus = useCallback(async () => {
        try {
            const response = await fetch('/om/camera/status', {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            if (response.ok) {
                setStatus(await response.json());
            }
        } catch (_) {}
    }, []);

    useEffect(() => {
        const interval = setInterval(checkStatus, STATUS_POLL_INTERVAL);
        return () => clearInterval(interval);
    }, [checkStatus]);

    // ── Fullscreen Toggle ──
    const handleFullscreen = () => {
        const el = document.getElementById('cctv-stage-viewport');
        if (el) {
            if (document.fullscreenElement) {
                document.exitFullscreen?.();
            } else {
                el.requestFullscreen?.();
            }
        }
    };

    // ── Snapshot Download ──
    const handleCaptureSnapshot = () => {
        const profile = streamQuality === 'SD' ? 'sub' : 'main';
        const a = document.createElement('a');
        a.href = `/om/camera/snapshot/${profile}?download=1&t=${Date.now()}`;
        a.download = `tmc-cctv-${new Date().toISOString().slice(0, 19).replace(/:/g, '-')}.jpg`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
    };

    // ── Zoom Handlers ──
    const handleZoomIn = () => setZoomLevel(z => Math.min(3, +(z + 0.25).toFixed(2)));
    const handleZoomOut = () => setZoomLevel(z => Math.max(1, +(z - 0.25).toFixed(2)));

    const isOnline = status?.camera?.online ?? status?.online ?? true;
    const iframeSrc = `https://stream.dhakabypass.com/${streamPath}/?controls=0&autoplay=${isPlaying ? '1' : '0'}&muted=${isMuted ? '1' : '0'}&playsinline=1`;

    return (
        <App auth={auth}>
            <Head title="Monitoring Center Staff & Operator Surveillance" />

            <Box style={{
                minHeight: '100vh',
                background: 'var(--color-background, #f4f7fb)',
                padding: '16px 24px 48px',
                fontFamily: `'Inter', system-ui, -apple-system, sans-serif`,
            }}>
                <Box style={{ maxWidth: 1520, margin: '0 auto' }}>

                    {/* ── 1. Page Header ── */}
                    <Flex align="center" justify="between" mb="3" wrap="wrap" gap="3">
                        <Flex align="center" gap="3">
                            <Box style={{
                                width: 44,
                                height: 44,
                                borderRadius: 12,
                                background: '#e0f2fe',
                                border: '1px solid #bae6fd',
                                display: 'flex',
                                alignItems: 'center',
                                justifyContent: 'center',
                            }}>
                                <VideoCameraIcon style={{ width: 22, height: 22, color: '#0284c7' }} />
                            </Box>
                            <Box>
                                <Flex align="center" gap="2">
                                    <Heading size="4" style={{
                                        color: '#0f172a',
                                        fontWeight: 800,
                                        letterSpacing: '-0.02em',
                                    }}>
                                        Monitoring Center Staff & Operator Surveillance
                                    </Heading>
                                    {/* Green Pill Badge */}
                                    <Box style={{
                                        display: 'flex',
                                        alignItems: 'center',
                                        gap: 6,
                                        background: isOnline ? '#dcfce7' : '#fee2e2',
                                        border: `1px solid ${isOnline ? '#bbf7d0' : '#fecaca'}`,
                                        borderRadius: 999,
                                        padding: '2px 10px',
                                    }}>
                                        <Box style={{
                                            width: 6,
                                            height: 6,
                                            borderRadius: '50%',
                                            background: isOnline ? '#16a34a' : '#dc2626',
                                        }} />
                                        <Text size="1" weight="bold" style={{ color: isOnline ? '#16a34a' : '#dc2626' }}>
                                            {isOnline ? 'Live' : 'Offline'}
                                        </Text>
                                    </Box>
                                </Flex>
                                <Text size="2" style={{ color: '#64748b' }}>
                                    TMC Control Room Floor · Staff & Duty Operator Surveillance (4MP Ultra-HD)
                                </Text>
                            </Box>
                        </Flex>
                    </Flex>

                    {/* ── 2. Live Surveillance Viewport (16/9 with OSD Badges) ── */}
                    <Box
                        id="cctv-stage-viewport"
                        style={{
                            position: 'relative',
                            width: '100%',
                            maxHeight: 'min(64vh, 720px)',
                            aspectRatio: '16/9',
                            overflow: 'hidden',
                            background: '#040711',
                            borderRadius: 20,
                            border: '1px solid #cbd5e1',
                            boxShadow: '0 8px 30px rgba(0, 0, 0, 0.12)',
                            margin: '0 auto 16px',
                        }}
                    >
                        {/* Zoomable Stream Container */}
                        <div style={{
                            width: '100%',
                            height: '100%',
                            transform: `scale(${zoomLevel})`,
                            transformOrigin: 'center center',
                            transition: 'transform 0.25s cubic-bezier(0.4, 0, 0.2, 1)',
                        }}>
                            <iframe
                                src={iframeSrc}
                                style={{
                                    width: '100%',
                                    height: '100%',
                                    border: 'none',
                                    backgroundColor: '#040711',
                                    display: 'block',
                                }}
                                allow="autoplay"
                                title="TMC Staff CCTV Feed"
                            />
                        </div>

                        {/* Top-Left OSD Badge: "● Monitoring Hall 2" */}
                        <Box style={{
                            position: 'absolute',
                            top: 14,
                            left: 14,
                            zIndex: 10,
                            background: 'rgba(0, 0, 0, 0.65)',
                            backdropFilter: 'blur(8px)',
                            borderRadius: 8,
                            padding: '6px 14px',
                            display: 'flex',
                            alignItems: 'center',
                            gap: 8,
                            pointerEvents: 'none',
                        }}>
                            <Box style={{
                                width: 8,
                                height: 8,
                                borderRadius: '50%',
                                background: '#ef4444',
                                animation: 'pulse 1.5s infinite',
                            }} />
                            <Text size="2" weight="bold" style={{ color: '#ffffff', letterSpacing: 0.3 }}>
                                Monitoring Hall 2
                            </Text>
                        </Box>

                        {/* Top-Right OSD Badge: Real-time Dynamic Clock */}
                        <Box style={{
                            position: 'absolute',
                            top: 14,
                            right: 14,
                            zIndex: 10,
                            background: 'rgba(0, 0, 0, 0.65)',
                            backdropFilter: 'blur(8px)',
                            borderRadius: 8,
                            padding: '6px 14px',
                            pointerEvents: 'none',
                        }}>
                            <Text size="2" style={{
                                color: '#ffffff',
                                fontFamily: `'SF Mono', 'Courier New', monospace`,
                                letterSpacing: 0.5,
                                fontWeight: 600,
                            }}>
                                {currentClock}
                            </Text>
                        </Box>
                    </Box>

                    {/* ── 3. White Floating Controls Card (Identical to reference) ── */}
                    <Box style={{
                        background: '#ffffff',
                        borderRadius: 16,
                        border: '1px solid #e2e8f0',
                        boxShadow: '0 4px 20px rgba(0, 0, 0, 0.05)',
                        padding: '14px 24px',
                    }}>
                        <Flex justify="between" align="center" wrap="wrap" gap="4">

                            {/* Left: [ HD | SD ] Segmented Quality Switcher */}
                            <Box style={{
                                display: 'flex',
                                alignItems: 'center',
                                background: '#f1f5f9',
                                borderRadius: 10,
                                padding: 4,
                            }}>
                                <button
                                    onClick={() => setStreamQuality('HD')}
                                    style={{
                                        padding: '7px 22px',
                                        borderRadius: 8,
                                        fontSize: 13,
                                        fontWeight: 800,
                                        border: 'none',
                                        cursor: 'pointer',
                                        transition: 'all 0.2s ease',
                                        background: streamQuality === 'HD' ? '#0088ff' : 'transparent',
                                        color: streamQuality === 'HD' ? '#ffffff' : '#64748b',
                                        boxShadow: streamQuality === 'HD' ? '0 2px 6px rgba(0, 136, 255, 0.35)' : 'none',
                                    }}
                                >
                                    HD
                                </button>
                                <button
                                    onClick={() => setStreamQuality('SD')}
                                    style={{
                                        padding: '7px 22px',
                                        borderRadius: 8,
                                        fontSize: 13,
                                        fontWeight: 800,
                                        border: 'none',
                                        cursor: 'pointer',
                                        transition: 'all 0.2s ease',
                                        background: streamQuality === 'SD' ? '#0088ff' : 'transparent',
                                        color: streamQuality === 'SD' ? '#ffffff' : '#64748b',
                                        boxShadow: streamQuality === 'SD' ? '0 2px 6px rgba(0, 136, 255, 0.35)' : 'none',
                                    }}
                                >
                                    SD
                                </button>
                            </Box>

                            {/* Vertical Separator */}
                            <Box style={{ width: 1, height: 38, background: '#e2e8f0' }} />

                            {/* Center Action Buttons: Pause, Snapshot, Mute, Fullscreen */}
                            <Flex align="center" gap="5">
                                {/* Pause / Play Button */}
                                <Flex direction="column" align="center" gap="1">
                                    <button
                                        onClick={() => setIsPlaying(p => !p)}
                                        title={isPlaying ? 'Pause Feed' : 'Play Feed'}
                                        style={{
                                            width: 48,
                                            height: 48,
                                            borderRadius: '50%',
                                            background: '#e0f2fe',
                                            border: 'none',
                                            display: 'flex',
                                            alignItems: 'center',
                                            justifyContent: 'center',
                                            cursor: 'pointer',
                                            transition: 'transform 0.15s ease',
                                        }}
                                        onMouseEnter={e => e.currentTarget.style.transform = 'scale(1.06)'}
                                        onMouseLeave={e => e.currentTarget.style.transform = 'scale(1)'}
                                    >
                                        {isPlaying ? (
                                            <PauseIcon style={{ width: 20, height: 20, color: '#0284c7', strokeWidth: 2.5 }} />
                                        ) : (
                                            <PlayIcon style={{ width: 20, height: 20, color: '#0284c7', strokeWidth: 2.5, marginLeft: 2 }} />
                                        )}
                                    </button>
                                    <Text size="1" weight="medium" style={{ color: '#475569' }}>
                                        {isPlaying ? 'Pause' : 'Play'}
                                    </Text>
                                </Flex>

                                {/* Snapshot Button */}
                                <Flex direction="column" align="center" gap="1">
                                    <button
                                        onClick={handleCaptureSnapshot}
                                        title="Capture Snapshot"
                                        style={{
                                            width: 48,
                                            height: 48,
                                            borderRadius: '50%',
                                            background: '#f8fafc',
                                            border: '1px solid #e2e8f0',
                                            display: 'flex',
                                            alignItems: 'center',
                                            justifyContent: 'center',
                                            cursor: 'pointer',
                                            transition: 'all 0.15s ease',
                                        }}
                                        onMouseEnter={e => {
                                            e.currentTarget.style.background = '#f1f5f9';
                                            e.currentTarget.style.borderColor = '#cbd5e1';
                                        }}
                                        onMouseLeave={e => {
                                            e.currentTarget.style.background = '#f8fafc';
                                            e.currentTarget.style.borderColor = '#e2e8f0';
                                        }}
                                    >
                                        <CameraIcon style={{ width: 20, height: 20, color: '#334155' }} />
                                    </button>
                                    <Text size="1" weight="medium" style={{ color: '#475569' }}>Snapshot</Text>
                                </Flex>

                                {/* Mute Button */}
                                <Flex direction="column" align="center" gap="1">
                                    <button
                                        onClick={() => setIsMuted(m => !m)}
                                        title={isMuted ? 'Unmute' : 'Mute'}
                                        style={{
                                            width: 48,
                                            height: 48,
                                            borderRadius: '50%',
                                            background: '#f8fafc',
                                            border: '1px solid #e2e8f0',
                                            display: 'flex',
                                            alignItems: 'center',
                                            justifyContent: 'center',
                                            cursor: 'pointer',
                                            transition: 'all 0.15s ease',
                                        }}
                                        onMouseEnter={e => {
                                            e.currentTarget.style.background = '#f1f5f9';
                                            e.currentTarget.style.borderColor = '#cbd5e1';
                                        }}
                                        onMouseLeave={e => {
                                            e.currentTarget.style.background = '#f8fafc';
                                            e.currentTarget.style.borderColor = '#e2e8f0';
                                        }}
                                    >
                                        {isMuted ? (
                                            <SpeakerXMarkIcon style={{ width: 20, height: 20, color: '#334155' }} />
                                        ) : (
                                            <SpeakerWaveIcon style={{ width: 20, height: 20, color: '#0284c7' }} />
                                        )}
                                    </button>
                                    <Text size="1" weight="medium" style={{ color: '#475569' }}>
                                        {isMuted ? 'Mute' : 'Unmute'}
                                    </Text>
                                </Flex>

                                {/* Fullscreen Button */}
                                <Flex direction="column" align="center" gap="1">
                                    <button
                                        onClick={handleFullscreen}
                                        title="Toggle Fullscreen"
                                        style={{
                                            width: 48,
                                            height: 48,
                                            borderRadius: '50%',
                                            background: '#f8fafc',
                                            border: '1px solid #e2e8f0',
                                            display: 'flex',
                                            alignItems: 'center',
                                            justifyContent: 'center',
                                            cursor: 'pointer',
                                            transition: 'all 0.15s ease',
                                        }}
                                        onMouseEnter={e => {
                                            e.currentTarget.style.background = '#f1f5f9';
                                            e.currentTarget.style.borderColor = '#cbd5e1';
                                        }}
                                        onMouseLeave={e => {
                                            e.currentTarget.style.background = '#f8fafc';
                                            e.currentTarget.style.borderColor = '#e2e8f0';
                                        }}
                                    >
                                        <ArrowsPointingOutIcon style={{ width: 20, height: 20, color: '#334155' }} />
                                    </button>
                                    <Text size="1" weight="medium" style={{ color: '#475569' }}>Fullscreen</Text>
                                </Flex>
                            </Flex>

                            {/* Vertical Separator */}
                            <Box style={{ width: 1, height: 38, background: '#e2e8f0' }} />

                            {/* Right: Zoom Slider */}
                            <Flex direction="column" gap="1" style={{ minWidth: 260 }}>
                                <Text size="1" weight="bold" style={{ color: '#64748b' }}>Zoom</Text>
                                <Flex align="center" gap="2">
                                    <button
                                        onClick={handleZoomOut}
                                        disabled={zoomLevel <= 1}
                                        style={{
                                            width: 32,
                                            height: 32,
                                            borderRadius: '50%',
                                            background: '#f1f5f9',
                                            border: 'none',
                                            display: 'flex',
                                            alignItems: 'center',
                                            justifyContent: 'center',
                                            cursor: zoomLevel <= 1 ? 'not-allowed' : 'pointer',
                                            opacity: zoomLevel <= 1 ? 0.4 : 1,
                                        }}
                                    >
                                        <MinusIcon style={{ width: 16, height: 16, color: '#334155' }} />
                                    </button>

                                    <input
                                        type="range"
                                        min="1"
                                        max="3"
                                        step="0.05"
                                        value={zoomLevel}
                                        onChange={e => setZoomLevel(parseFloat(e.target.value))}
                                        style={{
                                            flex: 1,
                                            accentColor: '#0088ff',
                                            height: 6,
                                            cursor: 'pointer',
                                        }}
                                    />

                                    <button
                                        onClick={handleZoomIn}
                                        disabled={zoomLevel >= 3}
                                        style={{
                                            width: 32,
                                            height: 32,
                                            borderRadius: '50%',
                                            background: '#f1f5f9',
                                            border: 'none',
                                            display: 'flex',
                                            alignItems: 'center',
                                            justifyContent: 'center',
                                            cursor: zoomLevel >= 3 ? 'not-allowed' : 'pointer',
                                            opacity: zoomLevel >= 3 ? 0.4 : 1,
                                        }}
                                    >
                                        <PlusIcon style={{ width: 16, height: 16, color: '#334155' }} />
                                    </button>
                                </Flex>
                            </Flex>

                        </Flex>
                    </Box>

                </Box>
            </Box>
        </App>
    );
}
