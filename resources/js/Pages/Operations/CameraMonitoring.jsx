import React, { useState, useEffect, useCallback, useRef } from 'react';
import { Head } from '@inertiajs/react';
import { Box, Flex, Text, Heading, Button, Badge } from '@radix-ui/themes';
import {
    VideoCameraIcon,
    ArrowTopRightOnSquareIcon,
    ArrowPathIcon,
    ArrowsPointingOutIcon,
    PlayIcon,
    PauseIcon,
    CameraIcon,
    SpeakerWaveIcon,
    SpeakerXMarkIcon,
    InformationCircleIcon,
    CheckCircleIcon,
} from '@heroicons/react/24/outline';
import App from '@/Layouts/App.jsx';

/**
 * TMC Monitoring Center CCTV Surveillance Portal
 *
 * Stream Modes:
 * - HD: 4MP UHD 2560×1440 @ 25fps (WebRTC WHEP)
 * - SD: Standard Def 720×576 @ 25fps (WebRTC WHEP)
 * - Snapshot: Frame capture proxy
 */

const STATUS_POLL_INTERVAL = 30000;

export default function CameraMonitoring({ auth, cameraUrl, cameraStatus: initialStatus }) {
    const [status, setStatus] = useState(initialStatus || null);
    const [isChecking, setIsChecking] = useState(false);

    // Stream Selection: 'HD' (cam-main) | 'SD' (cam-sub) | 'SNAP' (snapshot)
    const [streamQuality, setStreamQuality] = useState('HD');
    const [isPlaying, setIsPlaying] = useState(true);
    const [isMuted, setIsMuted] = useState(true);

    // Live dynamic clock overlay
    const [currentClock, setCurrentClock] = useState('');

    // Double-buffered frame state (for fallback / snapshot mode)
    const [currentFrameUrl, setCurrentFrameUrl] = useState('');
    const [frameCount, setFrameCount] = useState(0);
    const [isBuffering, setIsBuffering] = useState(true);
    const [snapshotToast, setSnapshotToast] = useState(false);

    // WebRTC refs
    const videoRef = useRef(null);
    const pcRef = useRef(null);
    const [webrtcConnected, setWebrtcConnected] = useState(false);
    const isFetchingRef = useRef(false);
    const timerRef = useRef(null);

    const streamPath = streamQuality === 'SD' ? 'cam-sub' : 'cam-main';
    const isWebRtcMode = streamQuality === 'HD' || streamQuality === 'SD';

    // ── Live Clock Tick ──
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

    // ── Snapshot Mode Frame Fetcher ──
    const fetchNextFrame = useCallback(() => {
        if (!isPlaying || isFetchingRef.current || isWebRtcMode) return;

        isFetchingRef.current = true;
        const profile = streamQuality === 'SD' ? 'sub' : 'main';
        const nextUrl = `/om/camera/snapshot/${profile}?t=${Date.now()}`;
        const bufferImg = new Image();

        bufferImg.onload = () => {
            isFetchingRef.current = false;
            setCurrentFrameUrl(nextUrl);
            setIsBuffering(false);
            setFrameCount(c => c + 1);

            if (isPlaying && streamQuality === 'SNAP') {
                timerRef.current = setTimeout(fetchNextFrame, 350);
            }
        };

        bufferImg.onerror = () => {
            isFetchingRef.current = false;
            if (isPlaying && streamQuality === 'SNAP') {
                timerRef.current = setTimeout(fetchNextFrame, 600);
            }
        };

        bufferImg.src = nextUrl;
    }, [isPlaying, streamQuality, isWebRtcMode]);

    useEffect(() => {
        if (streamQuality === 'SNAP' && isPlaying) {
            fetchNextFrame();
        }
        return () => {
            if (timerRef.current) clearTimeout(timerRef.current);
        };
    }, [streamQuality, isPlaying, fetchNextFrame]);

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
        setIsBuffering(true);
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

            // Brief wait for ICE gathering
            await new Promise((resolve) => {
                if (pc.iceGatheringState === 'complete') {
                    resolve();
                } else {
                    const check = () => {
                        if (pc.iceGatheringState === 'complete') {
                            pc.removeEventListener('icegatheringstatechange', check);
                            resolve();
                        }
                    };
                    pc.addEventListener('icegatheringstatechange', check);
                    setTimeout(resolve, 600);
                }
            });

            // 1. Direct WebRTC WHEP connection to stream.dhakabypass.com
            let res;
            try {
                res = await fetch(`https://stream.dhakabypass.com/${streamPath}/whep`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/sdp' },
                    body: pc.localDescription?.sdp || offer.sdp,
                });
            } catch (directErr) {
                console.warn('[WebRTC] Direct WHEP failed, falling back to ERP proxy:', directErr);
            }

            // 2. Fallback to ERP proxy endpoint
            if (!res || !res.ok) {
                res = await fetch(`/om/camera/webrtc/whep/${streamPath}`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/sdp' },
                    body: pc.localDescription?.sdp || offer.sdp,
                });
            }

            if (res && res.ok) {
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

    // ── Mute Toggle ──
    const handleToggleMute = () => {
        setIsMuted(prev => {
            const next = !prev;
            if (videoRef.current) {
                videoRef.current.muted = next;
            }
            return next;
        });
    };

    // ── Capture Snapshot ──
    const handleCaptureSnapshot = () => {
        const profile = streamQuality === 'SD' ? 'sub' : 'main';
        const a = document.createElement('a');
        a.href = `/om/camera/snapshot/${profile}?download=1&t=${Date.now()}`;
        a.download = `tmc-cctv-${new Date().toISOString().slice(0, 19).replace(/:/g, '-')}.jpg`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);

        setSnapshotToast(true);
        setTimeout(() => setSnapshotToast(false), 2500);
    };

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

    // ── Launch NVR ──
    const handleOpenNVR = () => {
        if (cameraUrl) {
            window.open(cameraUrl, '_blank', 'noopener,noreferrer');
        }
    };

    const isOnline = status?.camera?.online ?? status?.online ?? true;

    return (
        <App auth={auth}>
            <Head title="Monitoring Center CCTV — TMC Surveillance" />

            <Box style={{
                minHeight: '100vh',
                background: '#070b14',
                color: '#f1f5f9',
                padding: '16px 20px 48px',
                fontFamily: `'Inter', system-ui, -apple-system, sans-serif`,
            }}>
                <Box style={{ maxWidth: 1400, margin: '0 auto' }}>

                    {/* ── Top Header Bar ── */}
                    <Flex justify="between" align="center" mb="4" wrap="wrap" gap="3">
                        <Flex align="center" gap="3">
                            <Box style={{
                                width: 44,
                                height: 44,
                                borderRadius: 12,
                                background: 'rgba(6, 182, 212, 0.12)',
                                border: '1px solid rgba(6, 182, 212, 0.3)',
                                display: 'flex',
                                alignItems: 'center',
                                justifyContent: 'center',
                            }}>
                                <VideoCameraIcon style={{ width: 24, height: 24, color: '#06b6d4' }} />
                            </Box>
                            <Box>
                                <Heading size="5" style={{
                                    color: '#ffffff',
                                    fontWeight: 800,
                                    letterSpacing: '-0.02em',
                                    fontFamily: `'Space Grotesk', system-ui, sans-serif`,
                                }}>
                                    Monitoring Center CCTV
                                </Heading>
                                <Text size="2" style={{ color: '#94a3b8' }}>
                                    TMC Control Room Floor · Consoles 01–06 Staff Surveillance
                                </Text>
                            </Box>
                        </Flex>

                        <Flex align="center" gap="3">
                            {/* Glowing Live Badge */}
                            <Box style={{
                                display: 'flex',
                                alignItems: 'center',
                                gap: 7,
                                background: isOnline ? 'rgba(16, 185, 129, 0.12)' : 'rgba(239, 68, 68, 0.12)',
                                border: `1px solid ${isOnline ? 'rgba(16, 185, 129, 0.4)' : 'rgba(239, 68, 68, 0.4)'}`,
                                borderRadius: 999,
                                padding: '5px 14px',
                            }}>
                                <Box style={{
                                    width: 8,
                                    height: 8,
                                    borderRadius: '50%',
                                    background: isOnline ? '#10b981' : '#ef4444',
                                    boxShadow: isOnline ? '0 0 10px #10b981' : '0 0 8px #ef4444',
                                    animation: 'pulse 1.5s infinite',
                                }} />
                                <Text size="2" weight="bold" style={{ color: isOnline ? '#10b981' : '#ef4444' }}>
                                    {isOnline ? 'Live' : 'Offline'}
                                </Text>
                            </Box>

                            <Button
                                variant="soft"
                                color="gray"
                                onClick={checkStatus}
                                disabled={isChecking}
                                style={{
                                    background: 'rgba(255, 255, 255, 0.06)',
                                    color: '#e2e8f0',
                                    borderRadius: 10,
                                    border: '1px solid rgba(255, 255, 255, 0.1)',
                                }}
                            >
                                <ArrowPathIcon width={16} height={16} style={isChecking ? { animation: 'spin 1s linear infinite' } : {}} />
                                {isChecking ? 'Checking…' : 'Refresh'}
                            </Button>

                            <Button
                                onClick={handleOpenNVR}
                                style={{
                                    background: 'linear-gradient(135deg, #0284c7 0%, #0369a1 100%)',
                                    color: '#ffffff',
                                    borderRadius: 10,
                                    fontWeight: 700,
                                    border: '1px solid rgba(56, 189, 248, 0.3)',
                                }}
                            >
                                <ArrowTopRightOnSquareIcon width={16} height={16} />
                                Launch NVR
                            </Button>
                        </Flex>
                    </Flex>

                    {/* ── Surveillance Video Viewport ── */}
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
                            border: '1.5px solid rgba(14, 165, 233, 0.35)',
                            boxShadow: '0 0 30px rgba(14, 165, 233, 0.12), 0 20px 45px rgba(0, 0, 0, 0.7)',
                            margin: '0 auto 16px',
                            display: 'flex',
                            alignItems: 'center',
                            justifyContent: 'center',
                        }}
                    >
                        {/* Video Element */}
                        {isWebRtcMode && (
                            <video
                                ref={videoRef}
                                autoPlay
                                playsInline
                                muted={isMuted}
                                style={{
                                    width: '100%',
                                    height: '100%',
                                    objectFit: 'contain',
                                    display: webrtcConnected ? 'block' : 'none',
                                }}
                            />
                        )}

                        {/* Fallback Image Frame */}
                        {(!webrtcConnected || !isWebRtcMode) && currentFrameUrl ? (
                            <img
                                src={currentFrameUrl}
                                alt="Monitoring Center CCTV"
                                style={{
                                    width: '100%',
                                    height: '100%',
                                    objectFit: 'contain',
                                    display: 'block',
                                    userSelect: 'none',
                                }}
                            />
                        ) : null}

                        {/* Buffering Indicator */}
                        {isBuffering && !currentFrameUrl && !webrtcConnected && (
                            <Flex align="center" justify="center" style={{ position: 'absolute', inset: 0, zIndex: 2, background: '#040711' }}>
                                <Flex direction="column" align="center" gap="3">
                                    <ArrowPathIcon style={{ width: 42, height: 42, color: '#06b6d4', animation: 'spin 1s linear infinite' }} />
                                    <Text size="3" style={{ color: '#ffffff', fontWeight: 700 }}>Connecting to TMC CCTV Feed…</Text>
                                    <Text size="1" style={{ color: '#94a3b8' }}>Establishing 25 FPS WebRTC Stream</Text>
                                </Flex>
                            </Flex>
                        )}

                        {/* ── Viewport OSD Badges ── */}
                        {/* Top-Left: Hall / Location Pill */}
                        <Box style={{
                            position: 'absolute',
                            top: 16,
                            left: 16,
                            zIndex: 3,
                            background: 'rgba(10, 16, 30, 0.82)',
                            backdropFilter: 'blur(10px)',
                            borderRadius: 10,
                            padding: '6px 14px',
                            border: '1px solid rgba(255, 255, 255, 0.15)',
                            pointerEvents: 'none',
                        }}>
                            <Text size="2" weight="bold" style={{ color: '#ffffff', letterSpacing: 0.3 }}>
                                Monitoring Hall 2
                            </Text>
                        </Box>

                        {/* Top-Right: Live Dynamic Timestamp */}
                        <Box style={{
                            position: 'absolute',
                            top: 16,
                            right: 16,
                            zIndex: 3,
                            background: 'rgba(10, 16, 30, 0.82)',
                            backdropFilter: 'blur(10px)',
                            borderRadius: 10,
                            padding: '6px 14px',
                            border: '1px solid rgba(255, 255, 255, 0.15)',
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

                        {/* Bottom-Left: Camera ID Pill */}
                        <Box style={{
                            position: 'absolute',
                            bottom: 16,
                            left: 16,
                            zIndex: 3,
                            background: 'rgba(10, 16, 30, 0.82)',
                            backdropFilter: 'blur(10px)',
                            borderRadius: 10,
                            padding: '6px 14px',
                            border: '1px solid rgba(255, 255, 255, 0.15)',
                            display: 'flex',
                            alignItems: 'center',
                            gap: 8,
                            pointerEvents: 'none',
                        }}>
                            <VideoCameraIcon style={{ width: 16, height: 16, color: '#06b6d4' }} />
                            <Text size="2" weight="bold" style={{ color: '#ffffff' }}>
                                Cam 01 · {streamQuality === 'SD' ? 'SD' : '4MP UHD'}
                            </Text>
                        </Box>

                        {/* Bottom-Right: Expand / Fullscreen Button */}
                        <button
                            onClick={handleFullscreen}
                            title="Toggle Fullscreen"
                            style={{
                                position: 'absolute',
                                bottom: 16,
                                right: 16,
                                zIndex: 3,
                                width: 42,
                                height: 42,
                                borderRadius: 10,
                                background: 'rgba(10, 16, 30, 0.82)',
                                backdropFilter: 'blur(10px)',
                                border: '1px solid rgba(255, 255, 255, 0.2)',
                                color: '#ffffff',
                                display: 'flex',
                                alignItems: 'center',
                                justifyContent: 'center',
                                cursor: 'pointer',
                                transition: 'all 0.2s ease',
                            }}
                            onMouseEnter={e => e.currentTarget.style.background = 'rgba(6, 182, 212, 0.3)'}
                            onMouseLeave={e => e.currentTarget.style.background = 'rgba(10, 16, 30, 0.82)'}
                        >
                            <ArrowsPointingOutIcon style={{ width: 20, height: 20 }} />
                        </button>
                    </Box>

                    {/* ── Sleek Media Controls Card ── */}
                    <Box style={{
                        background: '#0c1322',
                        borderRadius: 18,
                        border: '1px solid rgba(255, 255, 255, 0.08)',
                        boxShadow: '0 8px 32px rgba(0, 0, 0, 0.4)',
                        padding: '14px 20px',
                        marginBottom: 20,
                    }}>
                        <Flex justify="between" align="center" wrap="wrap" gap="3">

                            {/* Left: Quality Switcher Pill [ HD | SD | SNAP ] */}
                            <Box style={{
                                display: 'flex',
                                alignItems: 'center',
                                background: '#070c18',
                                padding: 4,
                                borderRadius: 12,
                                border: '1px solid rgba(255, 255, 255, 0.1)',
                                gap: 4,
                            }}>
                                <button
                                    onClick={() => setStreamQuality('HD')}
                                    style={{
                                        padding: '7px 18px',
                                        borderRadius: 8,
                                        fontSize: 13,
                                        fontWeight: 800,
                                        border: 'none',
                                        cursor: 'pointer',
                                        transition: 'all 0.2s ease',
                                        background: streamQuality === 'HD' ? '#0284c7' : 'transparent',
                                        color: streamQuality === 'HD' ? '#ffffff' : '#94a3b8',
                                        boxShadow: streamQuality === 'HD' ? '0 2px 8px rgba(2, 132, 199, 0.5)' : 'none',
                                    }}
                                >
                                    HD
                                </button>
                                <button
                                    onClick={() => setStreamQuality('SD')}
                                    style={{
                                        padding: '7px 18px',
                                        borderRadius: 8,
                                        fontSize: 13,
                                        fontWeight: 800,
                                        border: 'none',
                                        cursor: 'pointer',
                                        transition: 'all 0.2s ease',
                                        background: streamQuality === 'SD' ? '#0284c7' : 'transparent',
                                        color: streamQuality === 'SD' ? '#ffffff' : '#94a3b8',
                                        boxShadow: streamQuality === 'SD' ? '0 2px 8px rgba(2, 132, 199, 0.5)' : 'none',
                                    }}
                                >
                                    SD
                                </button>
                                <button
                                    onClick={() => setStreamQuality('SNAP')}
                                    style={{
                                        padding: '7px 14px',
                                        borderRadius: 8,
                                        fontSize: 12,
                                        fontWeight: 700,
                                        border: 'none',
                                        cursor: 'pointer',
                                        transition: 'all 0.2s ease',
                                        background: streamQuality === 'SNAP' ? '#0284c7' : 'transparent',
                                        color: streamQuality === 'SNAP' ? '#ffffff' : '#64748b',
                                        boxShadow: streamQuality === 'SNAP' ? '0 2px 8px rgba(2, 132, 199, 0.5)' : 'none',
                                    }}
                                >
                                    Snap
                                </button>
                            </Box>

                            {/* Center & Right Action Buttons */}
                            <Flex align="center" gap="3">
                                {/* Large Glowing Circular Play/Pause Button */}
                                <button
                                    onClick={() => setIsPlaying(p => !p)}
                                    title={isPlaying ? 'Pause Stream' : 'Play Stream'}
                                    style={{
                                        width: 54,
                                        height: 54,
                                        borderRadius: '50%',
                                        background: '#080f1e',
                                        border: '2px solid #06b6d4',
                                        boxShadow: '0 0 16px rgba(6, 182, 212, 0.45), inset 0 0 10px rgba(6, 182, 212, 0.2)',
                                        color: '#ffffff',
                                        display: 'flex',
                                        alignItems: 'center',
                                        justifyContent: 'center',
                                        cursor: 'pointer',
                                        transition: 'transform 0.15s ease, box-shadow 0.2s ease',
                                    }}
                                    onMouseEnter={e => {
                                        e.currentTarget.style.transform = 'scale(1.08)';
                                        e.currentTarget.style.boxShadow = '0 0 24px rgba(6, 182, 212, 0.7), inset 0 0 12px rgba(6, 182, 212, 0.3)';
                                    }}
                                    onMouseLeave={e => {
                                        e.currentTarget.style.transform = 'scale(1)';
                                        e.currentTarget.style.boxShadow = '0 0 16px rgba(6, 182, 212, 0.45), inset 0 0 10px rgba(6, 182, 212, 0.2)';
                                    }}
                                >
                                    {isPlaying ? (
                                        <PauseIcon style={{ width: 22, height: 22, color: '#38bdf8' }} />
                                    ) : (
                                        <PlayIcon style={{ width: 22, height: 22, color: '#38bdf8', marginLeft: 2 }} />
                                    )}
                                </button>

                                {/* Snapshot Camera Button */}
                                <button
                                    onClick={handleCaptureSnapshot}
                                    title="Take Snapshot"
                                    style={{
                                        width: 46,
                                        height: 46,
                                        borderRadius: '50%',
                                        background: 'rgba(255, 255, 255, 0.05)',
                                        border: '1px solid rgba(255, 255, 255, 0.12)',
                                        color: '#ffffff',
                                        display: 'flex',
                                        alignItems: 'center',
                                        justifyContent: 'center',
                                        cursor: 'pointer',
                                        transition: 'all 0.2s ease',
                                    }}
                                    onMouseEnter={e => {
                                        e.currentTarget.style.background = 'rgba(255, 255, 255, 0.12)';
                                        e.currentTarget.style.borderColor = '#06b6d4';
                                    }}
                                    onMouseLeave={e => {
                                        e.currentTarget.style.background = 'rgba(255, 255, 255, 0.05)';
                                        e.currentTarget.style.borderColor = 'rgba(255, 255, 255, 0.12)';
                                    }}
                                >
                                    <CameraIcon style={{ width: 20, height: 20, color: '#e2e8f0' }} />
                                </button>

                                {/* Audio / Mute Button */}
                                <button
                                    onClick={handleToggleMute}
                                    title={isMuted ? 'Unmute Audio' : 'Mute Audio'}
                                    style={{
                                        width: 46,
                                        height: 46,
                                        borderRadius: '50%',
                                        background: 'rgba(255, 255, 255, 0.05)',
                                        border: '1px solid rgba(255, 255, 255, 0.12)',
                                        color: '#ffffff',
                                        display: 'flex',
                                        alignItems: 'center',
                                        justifyContent: 'center',
                                        cursor: 'pointer',
                                        transition: 'all 0.2s ease',
                                    }}
                                    onMouseEnter={e => {
                                        e.currentTarget.style.background = 'rgba(255, 255, 255, 0.12)';
                                        e.currentTarget.style.borderColor = '#06b6d4';
                                    }}
                                    onMouseLeave={e => {
                                        e.currentTarget.style.background = 'rgba(255, 255, 255, 0.05)';
                                        e.currentTarget.style.borderColor = 'rgba(255, 255, 255, 0.12)';
                                    }}
                                >
                                    {isMuted ? (
                                        <SpeakerXMarkIcon style={{ width: 20, height: 20, color: '#94a3b8' }} />
                                    ) : (
                                        <SpeakerWaveIcon style={{ width: 20, height: 20, color: '#38bdf8' }} />
                                    )}
                                </button>

                                {/* Fullscreen Button */}
                                <button
                                    onClick={handleFullscreen}
                                    title="Toggle Fullscreen"
                                    style={{
                                        width: 46,
                                        height: 46,
                                        borderRadius: '50%',
                                        background: 'rgba(255, 255, 255, 0.05)',
                                        border: '1px solid rgba(255, 255, 255, 0.12)',
                                        color: '#ffffff',
                                        display: 'flex',
                                        alignItems: 'center',
                                        justifyContent: 'center',
                                        cursor: 'pointer',
                                        transition: 'all 0.2s ease',
                                    }}
                                    onMouseEnter={e => {
                                        e.currentTarget.style.background = 'rgba(255, 255, 255, 0.12)';
                                        e.currentTarget.style.borderColor = '#06b6d4';
                                    }}
                                    onMouseLeave={e => {
                                        e.currentTarget.style.background = 'rgba(255, 255, 255, 0.05)';
                                        e.currentTarget.style.borderColor = 'rgba(255, 255, 255, 0.12)';
                                    }}
                                >
                                    <ArrowsPointingOutIcon style={{ width: 20, height: 20, color: '#e2e8f0' }} />
                                </button>
                            </Flex>
                        </Flex>
                    </Box>

                    {/* Snapshot Captured Toast */}
                    {snapshotToast && (
                        <Box style={{
                            position: 'fixed',
                            bottom: 24,
                            right: 24,
                            zIndex: 50,
                            background: '#0c1322',
                            border: '1px solid #10b981',
                            borderRadius: 12,
                            padding: '12px 20px',
                            boxShadow: '0 10px 30px rgba(0,0,0,0.5)',
                            display: 'flex',
                            alignItems: 'center',
                            gap: 10,
                        }}>
                            <CheckCircleIcon style={{ width: 20, height: 20, color: '#10b981' }} />
                            <Text size="2" weight="bold" style={{ color: '#ffffff' }}>Snapshot saved successfully!</Text>
                        </Box>
                    )}

                    {/* ── Scope Notice ── */}
                    <Box style={{
                        background: 'rgba(6, 182, 212, 0.05)',
                        borderRadius: 12,
                        border: '1px solid rgba(6, 182, 212, 0.2)',
                        padding: '12px 16px',
                        display: 'flex',
                        alignItems: 'center',
                        gap: 12,
                    }}>
                        <InformationCircleIcon style={{ width: 22, height: 22, color: '#06b6d4', flexShrink: 0 }} />
                        <Text size="2" style={{ color: '#94a3b8', lineHeight: 1.5 }}>
                            <strong style={{ color: '#e2e8f0' }}>Monitoring Scope:</strong> Live camera stream is dedicated exclusively to supervising Traffic Monitoring Center (TMC) floor staff, duty operators at Consoles 01–06, and operational shifts.
                        </Text>
                    </Box>

                </Box>
            </Box>
        </App>
    );
}
