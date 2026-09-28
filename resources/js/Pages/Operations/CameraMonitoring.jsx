import React, { useState, useEffect, useCallback, useRef } from 'react';
import { Head } from '@inertiajs/react';
import { Box, Flex, Text, Heading, Button, Badge, Separator } from '@radix-ui/themes';
import {
    VideoCameraIcon,
    ArrowTopRightOnSquareIcon,
    ArrowPathIcon,
    SignalIcon,
    ArrowsPointingOutIcon,
    PlayIcon,
    PauseIcon,
    ArrowDownTrayIcon,
    InformationCircleIcon,
    MagnifyingGlassPlusIcon,
    MagnifyingGlassMinusIcon,
    ArrowUturnLeftIcon,
    TvIcon,
    PhotoIcon,
} from '@heroicons/react/24/outline';
import App from '@/Layouts/App.jsx';
import { Panel } from '@/Components/ui/Panel';

/**
 * TMC Monitoring Center — Staff & Operator Surveillance
 *
 * Stream Hierarchy:
 * 1. Primary Default: Main 4MP WebRTC Live Stream (2560×1440 @ 25fps)
 * 2. Secondary: Sub SD WebRTC Stream (720×576 @ 25fps)
 * 3. Tertiary / Optional: Snapshot Mode (Periodic frame polling)
 */

const STATUS_POLL_INTERVAL = 30000;

export default function CameraMonitoring({ auth, cameraUrl, cameraStatus: initialStatus }) {
    const [status, setStatus] = useState(initialStatus || null);
    const [isChecking, setIsChecking] = useState(false);

    // ── 3-Tier Stream Selection ──
    // 'main-webrtc' (Primary 4MP) | 'sub-webrtc' (Secondary SD) | 'snapshot' (Optional 3rd)
    const [streamTier, setStreamTier] = useState('main-webrtc');
    const [isPlaying, setIsPlaying] = useState(true);
    const [refreshInterval, setRefreshInterval] = useState(350); // 350ms default for ultra-smooth low latency
    const isFetchingRef = useRef(false);
    const timerRef = useRef(null);

    // Digital Zoom & Pan
    const [zoomLevel, setZoomLevel] = useState(1);
    const [panX, setPanX] = useState(0);
    const [panY, setPanY] = useState(0);

    // Double-buffered frame state
    const [currentFrameUrl, setCurrentFrameUrl] = useState('');
    const [frameCount, setFrameCount] = useState(0);
    const [lastFrameTime, setLastFrameTime] = useState('');
    const [isBuffering, setIsBuffering] = useState(true);

    // WebRTC refs
    const videoRef = useRef(null);
    const pcRef = useRef(null);
    const [webrtcConnected, setWebrtcConnected] = useState(false);

    const activeProfile = streamTier === 'sub-webrtc' ? 'sub' : 'main';
    const streamPath = streamTier === 'sub-webrtc' ? 'cam-sub' : 'cam-main';
    const isWebRtcMode = streamTier === 'main-webrtc' || streamTier === 'sub-webrtc';

    // ── Telemetry Health Probe ──
    const checkStatus = useCallback(async () => {
        setIsChecking(true);
        try {
            const response = await fetch('/om/camera/status', {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            if (response.ok) {
                setStatus(await response.json());
            }
        } catch (err) {
            console.warn('[CameraMonitoring] Status check failed:', err);
        } finally {
            setIsChecking(false);
        }
    }, []);

    useEffect(() => {
        const interval = setInterval(checkStatus, STATUS_POLL_INTERVAL);
        return () => clearInterval(interval);
    }, [checkStatus]);

    // ── Ultra-Low Latency Adaptive Pipelined Frame Fetcher ──
    const fetchNextFrame = useCallback(() => {
        if (!isPlaying || isFetchingRef.current) return;

        isFetchingRef.current = true;
        const nextUrl = `/om/camera/snapshot/${activeProfile}?t=${Date.now()}`;
        const bufferImg = new Image();

        bufferImg.onload = () => {
            isFetchingRef.current = false;
            setCurrentFrameUrl(nextUrl);
            setIsBuffering(false);
            setFrameCount(c => c + 1);
            setLastFrameTime(new Date().toLocaleTimeString());

            if (isPlaying) {
                timerRef.current = setTimeout(fetchNextFrame, refreshInterval);
            }
        };

        bufferImg.onerror = () => {
            isFetchingRef.current = false;
            if (isPlaying) {
                timerRef.current = setTimeout(fetchNextFrame, Math.max(refreshInterval, 400));
            }
        };

        bufferImg.src = nextUrl;
    }, [isPlaying, activeProfile, refreshInterval]);

    useEffect(() => {
        if (isPlaying) {
            fetchNextFrame();
        }
        return () => {
            if (timerRef.current) clearTimeout(timerRef.current);
        };
    }, [isPlaying, activeProfile, refreshInterval, fetchNextFrame]);

    // ── WebRTC WHEP Engine ──
    const stopWebRTC = useCallback(() => {
        if (pcRef.current) {
            try {
                fetch(`/om/camera/webrtc/whep/${streamPath}`, { method: 'DELETE' }).catch(() => {});
                pcRef.current.close();
            } catch (_) {}
            pcRef.current = null;
        }
        if (videoRef.current) {
            videoRef.current.srcObject = null;
        }
        setWebrtcConnected(false);
    }, [streamPath]);

    const startWebRTC = useCallback(async () => {
        stopWebRTC();
        try {
            const pc = new RTCPeerConnection({
                iceServers: [{ urls: 'stun:stun.l.google.com:19302' }],
                bundlePolicy: 'max-bundle',
            });
            pcRef.current = pc;

            pc.addTransceiver('video', { direction: 'recvonly' });

            pc.ontrack = (event) => {
                if (videoRef.current && event.streams[0]) {
                    videoRef.current.srcObject = event.streams[0];
                    setWebrtcConnected(true);
                    setIsBuffering(false);
                }
            };

            const offer = await pc.createOffer();
            await pc.setLocalDescription(offer);

            await new Promise(r => setTimeout(r, 600));

            const res = await fetch(`/om/camera/webrtc/whep/${streamPath}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/sdp' },
                body: pc.localDescription?.sdp || offer.sdp,
            });

            if (res.ok) {
                const answer = await res.text();
                await pc.setRemoteDescription({ type: 'answer', sdp: answer });
            }
        } catch (err) {
            console.warn('[CameraMonitoring] WebRTC initializing:', err);
        }
    }, [streamPath, stopWebRTC]);

    useEffect(() => {
        if (isWebRtcMode && isPlaying) {
            startWebRTC();
        } else {
            stopWebRTC();
        }
        return () => stopWebRTC();
    }, [isWebRtcMode, isPlaying, startWebRTC, stopWebRTC]);

    // ── Actions ──
    const handleDownloadFrame = () => {
        const a = document.createElement('a');
        a.href = `/om/camera/snapshot/${activeProfile}?download=1&t=${Date.now()}`;
        a.download = `tmc-staff-cctv-${new Date().toISOString().slice(0, 19).replace(/:/g, '-')}.jpg`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
    };

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

    const handleOpenNVR = () => {
        if (cameraUrl) {
            window.open(cameraUrl, '_blank', 'noopener,noreferrer');
        }
    };

    // Zoom & Presets
    const handleZoomIn = () => setZoomLevel(z => Math.min(3, +(z + 0.25).toFixed(2)));
    const handleZoomOut = () => setZoomLevel(z => Math.max(1, +(z - 0.25).toFixed(2)));
    const handleResetZoom = () => {
        setZoomLevel(1);
        setPanX(0);
        setPanY(0);
    };

    const handlePreset = (zone) => {
        switch (zone) {
            case 'consoles_left':
                setZoomLevel(1.6);
                setPanX(15);
                setPanY(-5);
                break;
            case 'consoles_right':
                setZoomLevel(1.6);
                setPanX(-15);
                setPanY(-5);
                break;
            case 'supervisor':
                setZoomLevel(1.8);
                setPanX(0);
                setPanY(15);
                break;
            default:
                handleResetZoom();
                break;
        }
    };

    const isOnline = status?.camera?.online ?? status?.online ?? true;
    const latency = status?.camera?.latency_ms ?? status?.latency_ms ?? 35;

    return (
        <App auth={auth}>
            <Head title="Monitoring Center Staff & Operator Surveillance — TMC" />
            <Flex justify="center" p={{ initial: '2', sm: '4' }}>
                <Box style={{ width: '100%', maxWidth: 1600 }}>
                    <Panel>
                        {/* ── Page Header ── */}
                        <Box mb="3">
                            <Flex direction={{ initial: 'column', sm: 'row' }} align={{ initial: 'start', sm: 'center' }} justify="between" gap="3">
                                <Flex align="center" gap="3">
                                    <Box p="3" style={{
                                        background: 'var(--cyan-a3)',
                                        borderRadius: 12,
                                        border: '1px solid var(--cyan-a5)',
                                        display: 'flex',
                                        alignItems: 'center',
                                        justifyContent: 'center',
                                    }}>
                                        <VideoCameraIcon style={{ width: 22, height: 22, color: 'var(--cyan-9)' }} />
                                    </Box>
                                    <Box>
                                        <Flex align="center" gap="2" wrap="wrap">
                                            <Heading size="5" style={{
                                                fontFamily: `'Space Grotesk', system-ui, sans-serif`,
                                                fontWeight: 800,
                                                letterSpacing: '-0.02em',
                                            }}>
                                                Monitoring Center Staff & Operator Surveillance
                                            </Heading>
                                            <Badge color={isOnline ? 'green' : 'red'} variant="soft" style={{ borderRadius: 999 }}>
                                                <Flex align="center" gap="1">
                                                    <SignalIcon style={{ width: 12, height: 12 }} />
                                                    {isOnline ? `Live · ${latency}ms` : 'Offline'}
                                                </Flex>
                                            </Badge>
                                        </Flex>
                                        <Text size="2" style={{ color: 'var(--aero-color-subtle, var(--gray-9))' }}>
                                            TMC Control Room Floor · Staff & Duty Operator Surveillance (4MP Ultra-HD)
                                        </Text>
                                    </Box>
                                </Flex>

                                <Flex gap="2" align="center">
                                    <Button variant="soft" color="gray" onClick={checkStatus} disabled={isChecking} style={{ borderRadius: 10 }}>
                                        <ArrowPathIcon width={16} height={16} style={isChecking ? { animation: 'spin 1s linear infinite' } : {}} />
                                        {isChecking ? 'Checking…' : 'Refresh'}
                                    </Button>
                                    <Button onClick={handleOpenNVR} style={{ borderRadius: 10, fontWeight: 700 }}>
                                        <ArrowTopRightOnSquareIcon width={16} height={16} />
                                        Launch NVR Console
                                    </Button>
                                </Flex>
                            </Flex>
                        </Box>

                        {/* ── Scope Notice Banner ── */}
                        <Box mb="3" p="2.5" style={{
                            background: 'var(--cyan-a2)',
                            borderRadius: 10,
                            border: '1px solid var(--cyan-a4)',
                            display: 'flex',
                            alignItems: 'center',
                            gap: 10,
                        }}>
                            <InformationCircleIcon style={{ width: 20, height: 20, color: 'var(--cyan-9)', flexShrink: 0 }} />
                            <Text size="2" style={{ color: 'var(--cyan-11)', lineHeight: 1.4 }}>
                                <strong>Monitoring Scope:</strong> This live camera feed is dedicated exclusively to supervising TMC monitoring center staff, duty operators at consoles 01–06, and shift operations inside the central monitoring room.
                            </Text>
                        </Box>

                        <Separator size="4" mb="3" style={{ background: 'var(--dl-border-color, rgba(0,0,0,0.06))' }} />

                        {/* ── Controls Toolbar (3-Tier Stream Selection) ── */}
                        <Panel tinted style={{
                            borderRadius: 12,
                            border: '1px solid var(--aero-surface-border, rgba(0,0,0,0.08))',
                            padding: '10px 14px',
                            marginBottom: 12,
                            background: 'var(--aero-surface, var(--color-background))',
                        }}>
                            <Flex justify="between" align="center" wrap="wrap" gap="3">
                                {/* 3-Tier Stream Mode Buttons */}
                                <Flex align="center" gap="2" wrap="wrap">
                                    {/* Primary 4MP UHD */}
                                    <Button
                                        size="1"
                                        variant={streamTier === 'main-webrtc' ? 'solid' : 'soft'}
                                        color="cyan"
                                        onClick={() => setStreamTier('main-webrtc')}
                                        style={{ borderRadius: 8, fontWeight: 700, padding: '5px 12px' }}
                                    >
                                        <TvIcon width={14} height={14} /> 4MP UHD Live (Primary)
                                    </Button>

                                    {/* Secondary SD Stream */}
                                    <Button
                                        size="1"
                                        variant={streamTier === 'sub-webrtc' ? 'solid' : 'soft'}
                                        color="gray"
                                        onClick={() => setStreamTier('sub-webrtc')}
                                        style={{ borderRadius: 8, padding: '5px 10px' }}
                                    >
                                        <TvIcon width={14} height={14} /> SD Stream (Secondary)
                                    </Button>

                                    {/* Optional 3rd Snapshot Mode */}
                                    <Button
                                        size="1"
                                        variant={streamTier === 'snapshot' ? 'solid' : 'soft'}
                                        color="gray"
                                        onClick={() => setStreamTier('snapshot')}
                                        style={{ borderRadius: 8, padding: '5px 10px' }}
                                    >
                                        <PhotoIcon width={14} height={14} /> Snapshot (Optional)
                                    </Button>

                                    <Separator orientation="vertical" style={{ height: 18, margin: '0 4px' }} />

                                    <Button
                                        size="1"
                                        variant="soft"
                                        color={isPlaying ? 'amber' : 'cyan'}
                                        onClick={() => setIsPlaying(p => !p)}
                                        style={{ borderRadius: 8, padding: '5px 10px' }}
                                    >
                                        {isPlaying
                                            ? <><PauseIcon width={14} height={14} /> Pause</>
                                            : <><PlayIcon width={14} height={14} /> Play</>
                                        }
                                    </Button>

                                    <Button size="1" variant="surface" color="gray" onClick={handleDownloadFrame} style={{ borderRadius: 8 }}>
                                        <ArrowDownTrayIcon width={14} height={14} /> Save Frame
                                    </Button>

                                    <Flex align="center" gap="1" style={{ marginLeft: 4 }}>
                                        <Text size="1" color="gray">Rate:</Text>
                                        {[
                                            [300, '300ms'],
                                            [600, '600ms'],
                                            [1500, '1.5s'],
                                        ].map(([ms, label]) => (
                                            <Button
                                                key={ms}
                                                size="1"
                                                variant={refreshInterval === ms ? 'solid' : 'ghost'}
                                                color="cyan"
                                                onClick={() => setRefreshInterval(ms)}
                                                style={{ borderRadius: 4, padding: '2px 6px', height: 22, fontSize: 10 }}
                                            >
                                                {label}
                                            </Button>
                                        ))}
                                    </Flex>
                                </Flex>

                                {/* Focus Presets & Zoom */}
                                <Flex align="center" gap="2" wrap="wrap">
                                    <Text size="1" color="gray" weight="medium">Focus Area:</Text>
                                    <Button size="1" variant={zoomLevel === 1 ? 'solid' : 'soft'} color="gray" onClick={() => handlePreset('full')} style={{ borderRadius: 6, fontSize: 11 }}>
                                        Full Room
                                    </Button>
                                    <Button size="1" variant="soft" color="gray" onClick={() => handlePreset('consoles_left')} style={{ borderRadius: 6, fontSize: 11 }}>
                                        Consoles 01–03
                                    </Button>
                                    <Button size="1" variant="soft" color="gray" onClick={() => handlePreset('consoles_right')} style={{ borderRadius: 6, fontSize: 11 }}>
                                        Consoles 04–06
                                    </Button>
                                    <Button size="1" variant="soft" color="gray" onClick={() => handlePreset('supervisor')} style={{ borderRadius: 6, fontSize: 11 }}>
                                        Supervisor Desk
                                    </Button>

                                    <Separator orientation="vertical" style={{ height: 18, margin: '0 4px' }} />

                                    {/* Zoom In / Out */}
                                    <Flex align="center" gap="1" style={{ background: 'var(--gray-a3)', borderRadius: 6, padding: '2px 4px' }}>
                                        <Button size="1" variant="ghost" color="gray" onClick={handleZoomOut} disabled={zoomLevel <= 1} title="Zoom Out">
                                            <MagnifyingGlassMinusIcon width={14} height={14} />
                                        </Button>
                                        <Text size="1" style={{ minWidth: 28, textAlign: 'center', fontFamily: 'monospace', fontWeight: 700 }}>
                                            {zoomLevel}x
                                        </Text>
                                        <Button size="1" variant="ghost" color="gray" onClick={handleZoomIn} disabled={zoomLevel >= 3} title="Zoom In">
                                            <MagnifyingGlassPlusIcon width={14} height={14} />
                                        </Button>
                                        {zoomLevel > 1 && (
                                            <Button size="1" variant="ghost" color="gray" onClick={handleResetZoom} title="Reset Zoom">
                                                <ArrowUturnLeftIcon width={12} height={12} />
                                            </Button>
                                        )}
                                    </Flex>

                                    <Button size="1" variant="ghost" color="gray" onClick={handleFullscreen} title="Fullscreen View">
                                        <ArrowsPointingOutIcon width={16} height={16} />
                                    </Button>
                                </Flex>
                            </Flex>
                        </Panel>

                        {/* ── Main Surveillance Screen ── */}
                        <Box
                            id="cctv-stage-viewport"
                            style={{
                                position: 'relative',
                                width: '100%',
                                maxHeight: 'min(62vh, 640px)',
                                aspectRatio: '16/9',
                                overflow: 'hidden',
                                background: '#050811',
                                borderRadius: 16,
                                border: '1px solid var(--aero-surface-border, rgba(0,0,0,0.12))',
                                boxShadow: '0 12px 36px -8px rgba(0, 0, 0, 0.4)',
                                margin: '0 auto',
                                display: 'flex',
                                alignItems: 'center',
                                justifyContent: 'center',
                            }}
                        >
                            {/* Zoomed / Panned Screen Content */}
                            <div style={{
                                width: '100%',
                                height: '100%',
                                transform: `scale(${zoomLevel}) translate(${panX}%, ${panY}%)`,
                                transformOrigin: 'center center',
                                transition: 'transform 0.3s cubic-bezier(0.4, 0, 0.2, 1)',
                                display: 'flex',
                                alignItems: 'center',
                                justifyContent: 'center',
                            }}>
                                {/* WebRTC Video Stream Element */}
                                {isWebRtcMode && (
                                    <video
                                        ref={videoRef}
                                        autoPlay
                                        playsInline
                                        muted
                                        style={{
                                            width: '100%',
                                            height: '100%',
                                            objectFit: 'contain',
                                            display: webrtcConnected ? 'block' : 'none',
                                        }}
                                    />
                                )}

                                {/* Double-Buffered Frame Image (Rendered in Snapshot mode & as WebRTC fallback) */}
                                {(!webrtcConnected || !isWebRtcMode) && currentFrameUrl ? (
                                    <img
                                        src={currentFrameUrl}
                                        alt="TMC Monitoring Center Staff Surveillance"
                                        style={{
                                            width: '100%',
                                            height: '100%',
                                            objectFit: 'contain',
                                            display: 'block',
                                            userSelect: 'none',
                                        }}
                                    />
                                ) : null}
                            </div>

                            {/* Buffering State */}
                            {isBuffering && !currentFrameUrl && !webrtcConnected && (
                                <Flex align="center" justify="center" style={{ position: 'absolute', inset: 0, zIndex: 2, background: '#050811' }}>
                                    <Flex direction="column" align="center" gap="2">
                                        <ArrowPathIcon style={{ width: 36, height: 36, color: 'var(--cyan-9)', animation: 'spin 1s linear infinite' }} />
                                        <Text size="2" style={{ color: '#fff', fontWeight: 600 }}>Connecting to TMC Staff Camera…</Text>
                                        <Text size="1" color="gray">Acquiring 4MP frame buffer</Text>
                                    </Flex>
                                </Flex>
                            )}

                            {/* Top-Left OSD Badge */}
                            <Flex align="center" gap="2" style={{
                                position: 'absolute', top: 12, left: 14, zIndex: 3,
                                background: 'rgba(0,0,0,0.75)',
                                backdropFilter: 'blur(8px)',
                                borderRadius: 8,
                                padding: '4px 10px',
                                border: '1px solid rgba(255,255,255,0.15)',
                                pointerEvents: 'none',
                            }}>
                                <Box style={{
                                    width: 8, height: 8, borderRadius: '50%',
                                    background: '#ef4444',
                                    animation: 'pulse 1.5s ease-in-out infinite',
                                }} />
                                <Text size="1" weight="bold" style={{ color: '#fff', fontFamily: 'monospace', letterSpacing: 0.5 }}>
                                    LIVE · {activeProfile === 'main' ? '4MP UHD' : 'SD'}
                                </Text>
                                <Text size="1" style={{ color: 'rgba(255,255,255,0.7)', fontFamily: 'monospace' }}>
                                    TMC FLOOR · OPERATOR DESKS
                                </Text>
                            </Flex>

                            {/* Top-Right Timestamp */}
                            <Flex align="center" gap="2" style={{
                                position: 'absolute', top: 12, right: 14, zIndex: 3,
                                background: 'rgba(0,0,0,0.75)',
                                backdropFilter: 'blur(8px)',
                                borderRadius: 8,
                                padding: '4px 10px',
                                border: '1px solid rgba(255,255,255,0.15)',
                                pointerEvents: 'none',
                            }}>
                                <Text size="1" style={{ color: '#fff', fontFamily: 'monospace', fontVariantNumeric: 'tabular-nums' }}>
                                    {lastFrameTime || new Date().toLocaleTimeString()}
                                </Text>
                            </Flex>

                            {/* Bottom-Right Stream Info */}
                            <Box style={{
                                position: 'absolute', bottom: 10, right: 14, zIndex: 3,
                                background: 'rgba(0,0,0,0.7)',
                                borderRadius: 6,
                                padding: '2px 8px',
                                pointerEvents: 'none',
                            }}>
                                <Text size="1" style={{ color: 'rgba(255,255,255,0.8)', fontFamily: 'monospace', fontSize: 10 }}>
                                    {zoomLevel > 1 ? `Zoom ${zoomLevel}x · ` : ''}
                                    {webrtcConnected ? 'WebRTC WHEP Live' : `4MP Stream · Frame #${frameCount}`}
                                </Text>
                            </Box>
                        </Box>
                    </Panel>
                </Box>
            </Flex>

            <style>{`
                @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
                @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.3; } }
            `}</style>
        </App>
    );
}
