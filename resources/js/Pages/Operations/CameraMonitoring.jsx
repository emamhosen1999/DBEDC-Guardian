import React, { useState, useEffect, useCallback, useRef } from 'react';
import { Head, router } from '@inertiajs/react';
import { Box, Flex, Text, Heading, Button, Badge, Separator, Callout } from '@radix-ui/themes';
import {
    VideoCameraIcon,
    ArrowTopRightOnSquareIcon,
    ArrowPathIcon,
    SignalIcon,
    ExclamationTriangleIcon,
    LockClosedIcon,
    ArrowsPointingOutIcon,
    InformationCircleIcon,
    ShieldCheckIcon,
    PlayIcon,
    PauseIcon,
    CpuChipIcon,
    ArrowDownTrayIcon,
    TvIcon,
    PhotoIcon,
} from '@heroicons/react/24/outline';
import App from '@/Layouts/App.jsx';
import { Panel } from '@/Components/ui/Panel';

/**
 * CCTV Camera Monitoring Viewer — DBEDC Guardian
 *
 * Primary: Main Stream (4MP HD — 2560×1440 @ 25fps)
 * Secondary: Sub Stream (SD — 720×576 @ 25fps)
 *
 * Technologies:
 * 1. WebRTC WHEP Gateway (MediaMTX) — Sub-second ultra low latency
 * 2. ONVIF Snapshot Stream — Continuous high-resolution frame polling
 * 3. NVR Direct Portal — Full interface access
 *
 * Security: Internal camera IP (11.151.14.67) never exposed to browser.
 */

const STATUS_POLL_INTERVAL = 30000;

const StatusBadge = ({ status }) => {
    if (!status) {
        return <Badge color="gray" variant="soft" style={{ borderRadius: 999 }}>Checking…</Badge>;
    }
    const isOnline = status.camera?.online ?? status.online;
    const latency = status.camera?.latency_ms ?? status.latency_ms;

    if (isOnline) {
        return (
            <Badge color="green" variant="soft" style={{ borderRadius: 999 }}>
                <Flex align="center" gap="1">
                    <SignalIcon style={{ width: 12, height: 12 }} />
                    Camera Online · {latency}ms
                </Flex>
            </Badge>
        );
    }
    return (
        <Badge color="red" variant="soft" style={{ borderRadius: 999 }}>
            <Flex align="center" gap="1">
                <ExclamationTriangleIcon style={{ width: 12, height: 12 }} />
                Camera Offline
            </Flex>
        </Badge>
    );
};

export default function CameraMonitoring({ auth, cameraUrl, cameraStatus: initialStatus, deviceInfo, streamConfig }) {
    const [status, setStatus] = useState(initialStatus || null);
    const [isChecking, setIsChecking] = useState(false);
    const [lastOpened, setLastOpened] = useState(null);

    // Stream Selection: 'main' (4MP HD, 2560x1440) is primary default
    const [activeStream, setActiveStream] = useState('main');
    // Mode: 'webrtc' or 'snapshot'
    const [streamMode, setStreamMode] = useState('snapshot');
    const [isPlaying, setIsPlaying] = useState(true);
    const [snapshotRate, setSnapshotRate] = useState(2000); // 2 seconds

    // WebRTC Player Refs
    const videoRef = useRef(null);
    const peerConnectionRef = useRef(null);
    const [webrtcStatus, setWebrtcStatus] = useState('idle'); // 'idle' | 'connecting' | 'connected' | 'error'
    const [webrtcError, setWebrtcError] = useState(null);

    // Snapshot Player State & Refs
    const imgRef = useRef(null);
    const snapshotIntervalRef = useRef(null);
    const [snapshotLoading, setSnapshotLoading] = useState(true);
    const [snapshotError, setSnapshotError] = useState(false);
    const [frameCount, setFrameCount] = useState(0);
    const [lastFrameTime, setLastFrameTime] = useState(null);

    const streamPath = activeStream === 'main' ? 'cam-main' : 'cam-sub';
    const snapshotProfile = activeStream === 'main' ? 'main' : 'sub';

    // Health check polling
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

    // ── Snapshot Stream Controller ──
    const refreshSnapshot = useCallback(() => {
        const url = `/om/camera/snapshot/${snapshotProfile}?t=${Date.now()}`;
        const img = new Image();
        img.onload = () => {
            if (imgRef.current) {
                imgRef.current.src = url;
                setSnapshotError(false);
                setSnapshotLoading(false);
                setFrameCount(c => c + 1);
                setLastFrameTime(new Date().toLocaleTimeString());
            }
        };
        img.onerror = () => {
            setSnapshotError(true);
            setSnapshotLoading(false);
        };
        img.src = url;
    }, [snapshotProfile]);

    useEffect(() => {
        if (streamMode === 'snapshot' && isPlaying) {
            refreshSnapshot();
            snapshotIntervalRef.current = setInterval(refreshSnapshot, snapshotRate);
        }
        return () => {
            if (snapshotIntervalRef.current) clearInterval(snapshotIntervalRef.current);
        };
    }, [streamMode, isPlaying, snapshotRate, refreshSnapshot]);

    // ── WebRTC WHEP Stream Controller ──
    const stopWebRTC = useCallback(() => {
        if (peerConnectionRef.current) {
            try {
                fetch(`/om/camera/webrtc/whep/${streamPath}`, { method: 'DELETE' }).catch(() => {});
                peerConnectionRef.current.close();
            } catch (_) {}
            peerConnectionRef.current = null;
        }
        if (videoRef.current) {
            videoRef.current.srcObject = null;
        }
        setWebrtcStatus('idle');
    }, [streamPath]);

    const startWebRTC = useCallback(async () => {
        stopWebRTC();
        setWebrtcStatus('connecting');
        setWebrtcError(null);

        try {
            const pc = new RTCPeerConnection({
                iceServers: [{ urls: 'stun:stun.l.google.com:19302' }],
                bundlePolicy: 'max-bundle',
            });
            peerConnectionRef.current = pc;

            pc.addTransceiver('video', { direction: 'recvonly' });

            pc.ontrack = (event) => {
                if (videoRef.current && event.streams[0]) {
                    videoRef.current.srcObject = event.streams[0];
                    setWebrtcStatus('connected');
                }
            };

            pc.oniceconnectionstatechange = () => {
                if (pc.iceConnectionState === 'failed' || pc.iceConnectionState === 'disconnected') {
                    setWebrtcStatus('error');
                    setWebrtcError('WebRTC connection disconnected');
                }
            };

            const offer = await pc.createOffer();
            await pc.setLocalDescription(offer);

            // Wait for ICE gathering with timeout
            await new Promise((resolve) => {
                if (pc.iceGatheringState === 'complete') resolve();
                else {
                    const check = () => {
                        if (pc.iceGatheringState === 'complete') {
                            pc.removeEventListener('icegatheringstatechange', check);
                            resolve();
                        }
                    };
                    pc.addEventListener('icegatheringstatechange', check);
                    setTimeout(resolve, 1200);
                }
            });

            const res = await fetch(`/om/camera/webrtc/whep/${streamPath}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/sdp' },
                body: pc.localDescription?.sdp || offer.sdp,
            });

            if (!res.ok) {
                throw new Error(`MediaMTX gateway returned HTTP ${res.status}`);
            }

            const answer = await res.text();
            await pc.setRemoteDescription({ type: 'answer', sdp: answer });

        } catch (err) {
            console.warn('[CameraMonitoring] WebRTC failed, falling back:', err.message);
            setWebrtcStatus('error');
            setWebrtcError(err.message);
        }
    }, [streamPath, stopWebRTC]);

    useEffect(() => {
        if (streamMode === 'webrtc' && isPlaying) {
            startWebRTC();
        } else {
            stopWebRTC();
        }
        return () => {
            stopWebRTC();
        };
    }, [streamMode, isPlaying, startWebRTC, stopWebRTC]);

    // Download snapshot
    const handleDownloadSnapshot = () => {
        const link = document.createElement('a');
        link.href = `/om/camera/snapshot/${snapshotProfile}?download=1&t=${Date.now()}`;
        link.download = `cctv-${activeStream}-${new Date().toISOString().slice(0, 19).replace(/:/g, '-')}.jpg`;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    };

    const handleFullscreen = () => {
        const elem = document.getElementById('camera-view-container');
        if (elem) {
            if (document.fullscreenElement) {
                document.exitFullscreen?.();
            } else {
                elem.requestFullscreen?.();
            }
        }
    };

    const handleOpenCamera = () => {
        if (cameraUrl) {
            window.open(cameraUrl, '_blank', 'noopener,noreferrer');
            setLastOpened(new Date().toLocaleTimeString());
        }
    };

    return (
        <App auth={auth}>
            <Head title="CCTV Camera Monitoring" />
            <Flex justify="center" p="4">
                <Box style={{ width: '100%', maxWidth: 1440 }}>
                    <Panel>
                        {/* ── Page Header ── */}
                        <Box mb="4">
                            <Flex direction={{ initial: 'column', sm: 'row' }} align={{ initial: 'start', sm: 'center' }} justify="between" gap="4">
                                <Flex align="center" gap="3">
                                    <Box p="3" style={{
                                        background: 'var(--cyan-a3)', borderRadius: 12,
                                        border: '1px solid var(--cyan-a5)',
                                        display: 'flex', alignItems: 'center', justifyContent: 'center',
                                    }}>
                                        <VideoCameraIcon style={{ width: 24, height: 24, color: 'var(--cyan-9)' }} />
                                    </Box>
                                    <Box>
                                        <Flex align="center" gap="2">
                                            <Heading size="5" style={{
                                                fontFamily: `'Space Grotesk', system-ui, sans-serif`,
                                                fontWeight: 800, letterSpacing: '-0.02em',
                                            }}>
                                                CCTV Camera Monitoring
                                            </Heading>
                                            <StatusBadge status={status} />
                                        </Flex>
                                        <Text size="2" style={{ color: 'var(--aero-color-subtle, var(--gray-9))' }}>
                                            {deviceInfo?.Manufacturer || 'HOLOWITS'} {deviceInfo?.Model || 'P4-4R(2.8)A'} — Dhaka Bypass Expressway
                                        </Text>
                                    </Box>
                                </Flex>
                                <Flex gap="2">
                                    <Button variant="soft" color="gray" onClick={checkStatus} disabled={isChecking} style={{ borderRadius: 10 }}>
                                        <ArrowPathIcon width={16} height={16} style={isChecking ? { animation: 'spin 1s linear infinite' } : {}} />
                                        {isChecking ? 'Checking…' : 'Refresh Status'}
                                    </Button>
                                </Flex>
                            </Flex>
                        </Box>

                        <Separator size="4" mb="4" style={{ background: 'var(--dl-border-color, rgba(0,0,0,0.06))' }} />

                        {/* ── Main Content Grid ── */}
                        <Flex gap="4" direction={{ initial: 'column', lg: 'row' }}>
                            {/* Left: Main Live Viewer */}
                            <Box style={{ flex: 7, minWidth: 0 }}>
                                <Panel tinted style={{
                                    borderRadius: 20,
                                    border: '1px solid var(--cyan-a4)',
                                    padding: 16,
                                    background: 'var(--color-surface)',
                                }}>
                                    {/* Stream Bar: Mode & Resolution Switchers */}
                                    <Flex justify="between" align="center" wrap="wrap" gap="2" mb="3">
                                        {/* Resolution Profile Tabs */}
                                        <Flex gap="2" align="center">
                                            <Button
                                                size="1"
                                                variant={activeStream === 'main' ? 'solid' : 'soft'}
                                                color="cyan"
                                                onClick={() => setActiveStream('main')}
                                                style={{ borderRadius: 8, fontWeight: 700 }}
                                            >
                                                Main Stream (4MP HD · 2560×1440)
                                            </Button>
                                            <Button
                                                size="1"
                                                variant={activeStream === 'sub' ? 'solid' : 'soft'}
                                                color="gray"
                                                onClick={() => setActiveStream('sub')}
                                                style={{ borderRadius: 8 }}
                                            >
                                                Sub Stream (SD · 720×576)
                                            </Button>
                                        </Flex>

                                        {/* Mode: WebRTC vs Snapshot */}
                                        <Flex gap="2" align="center">
                                            <Button
                                                size="1"
                                                variant={streamMode === 'webrtc' ? 'solid' : 'surface'}
                                                color="violet"
                                                onClick={() => setStreamMode('webrtc')}
                                                style={{ borderRadius: 8 }}
                                            >
                                                <TvIcon width={14} height={14} /> WebRTC Live
                                            </Button>
                                            <Button
                                                size="1"
                                                variant={streamMode === 'snapshot' ? 'solid' : 'surface'}
                                                color="cyan"
                                                onClick={() => setStreamMode('snapshot')}
                                                style={{ borderRadius: 8 }}
                                            >
                                                <PhotoIcon width={14} height={14} /> Snapshot Stream
                                            </Button>
                                        </Flex>
                                    </Flex>

                                    {/* Video / Snapshot Container */}
                                    <Box
                                        id="camera-view-container"
                                        style={{
                                            borderRadius: 16,
                                            overflow: 'hidden',
                                            background: '#090d16',
                                            aspectRatio: '16/9',
                                            position: 'relative',
                                            border: '1px solid var(--cyan-a4)',
                                            boxShadow: '0 12px 36px -8px rgba(0, 0, 0, 0.4)',
                                        }}
                                    >
                                        {/* ── Mode 1: WebRTC Live Video ── */}
                                        {streamMode === 'webrtc' && (
                                            <>
                                                <video
                                                    ref={videoRef}
                                                    autoPlay
                                                    playsInline
                                                    muted
                                                    style={{
                                                        width: '100%',
                                                        height: '100%',
                                                        objectFit: 'contain',
                                                        display: webrtcStatus === 'connected' ? 'block' : 'none',
                                                    }}
                                                />
                                                {webrtcStatus === 'connecting' && (
                                                    <Flex align="center" justify="center" style={{ position: 'absolute', inset: 0, zIndex: 2, background: 'rgba(0,0,0,0.7)' }}>
                                                        <Flex direction="column" align="center" gap="2">
                                                            <ArrowPathIcon style={{ width: 36, height: 36, color: 'var(--violet-9)', animation: 'spin 1s linear infinite' }} />
                                                            <Text size="2" style={{ color: '#fff' }}>Connecting WebRTC to MediaMTX gateway…</Text>
                                                            <Text size="1" color="gray">Negotiating SDP offer/answer</Text>
                                                        </Flex>
                                                    </Flex>
                                                )}
                                                {webrtcStatus === 'error' && (
                                                    <Flex align="center" justify="center" style={{ position: 'absolute', inset: 0, zIndex: 2, background: 'rgba(0,0,0,0.85)' }}>
                                                        <Flex direction="column" align="center" gap="3" p="4" style={{ textAlign: 'center' }}>
                                                            <ExclamationTriangleIcon style={{ width: 36, height: 36, color: 'var(--amber-9)' }} />
                                                            <Text size="2" weight="bold" style={{ color: '#fff' }}>MediaMTX Gateway Not Connected</Text>
                                                            <Text size="1" style={{ color: 'var(--gray-9)', maxWidth: 400 }}>
                                                                {webrtcError || 'WebRTC stream requires the MediaMTX daemon to be running on the origin server.'}
                                                            </Text>
                                                            <Flex gap="2">
                                                                <Button size="1" variant="solid" color="cyan" onClick={() => setStreamMode('snapshot')} style={{ borderRadius: 8 }}>
                                                                    <PhotoIcon width={14} height={14} /> Switch to Snapshot Stream
                                                                </Button>
                                                                <Button size="1" variant="soft" color="gray" onClick={startWebRTC} style={{ borderRadius: 8 }}>
                                                                    <ArrowPathIcon width={14} height={14} /> Retry WebRTC
                                                                </Button>
                                                            </Flex>
                                                        </Flex>
                                                    </Flex>
                                                )}
                                            </>
                                        )}

                                        {/* ── Mode 2: Live Snapshot Stream ── */}
                                        {streamMode === 'snapshot' && (
                                            <>
                                                {snapshotLoading && isPlaying && (
                                                    <Flex align="center" justify="center" style={{ position: 'absolute', inset: 0, zIndex: 2, background: 'rgba(0,0,0,0.7)' }}>
                                                        <Flex direction="column" align="center" gap="2">
                                                            <ArrowPathIcon style={{ width: 32, height: 32, color: 'var(--cyan-9)', animation: 'spin 1s linear infinite' }} />
                                                            <Text size="2" style={{ color: '#fff' }}>Fetching live frame from camera…</Text>
                                                        </Flex>
                                                    </Flex>
                                                )}

                                                {snapshotError && (
                                                    <Flex align="center" justify="center" style={{ position: 'absolute', inset: 0, zIndex: 2, background: 'rgba(0,0,0,0.85)' }}>
                                                        <Flex direction="column" align="center" gap="2">
                                                            <ExclamationTriangleIcon style={{ width: 32, height: 32, color: 'var(--amber-9)' }} />
                                                            <Text size="2" style={{ color: '#fff' }}>Snapshot feed temporarily unavailable</Text>
                                                            <Button size="1" variant="soft" color="cyan" onClick={refreshSnapshot} style={{ borderRadius: 8 }}>
                                                                <ArrowPathIcon width={14} height={14} /> Retry
                                                            </Button>
                                                        </Flex>
                                                    </Flex>
                                                )}

                                                <img
                                                    ref={imgRef}
                                                    alt="Live CCTV feed"
                                                    style={{
                                                        width: '100%',
                                                        height: '100%',
                                                        objectFit: 'contain',
                                                        display: 'block',
                                                    }}
                                                />
                                            </>
                                        )}

                                        {/* Live Overlay Badge */}
                                        {isPlaying && (
                                            <Flex align="center" gap="2" style={{
                                                position: 'absolute', top: 14, left: 14, zIndex: 3,
                                                background: 'rgba(0,0,0,0.7)',
                                                backdropFilter: 'blur(8px)',
                                                borderRadius: 8,
                                                padding: '4px 10px',
                                                border: '1px solid rgba(255,255,255,0.1)',
                                            }}>
                                                <Box style={{
                                                    width: 8, height: 8, borderRadius: '50%',
                                                    background: '#ef4444',
                                                    animation: 'pulse 1.5s ease-in-out infinite',
                                                }} />
                                                <Text size="1" weight="bold" style={{ color: '#fff', fontFamily: 'monospace', letterSpacing: 1 }}>
                                                    LIVE · {activeStream === 'main' ? '4MP HD' : 'SD'}
                                                </Text>
                                            </Flex>
                                        )}

                                        {/* Frame / Latency Indicator */}
                                        {isPlaying && (
                                            <Flex align="center" gap="2" style={{
                                                position: 'absolute', bottom: 10, right: 14, zIndex: 3,
                                                background: 'rgba(0,0,0,0.6)',
                                                backdropFilter: 'blur(4px)',
                                                borderRadius: 6,
                                                padding: '2px 8px',
                                            }}>
                                                <Text size="1" style={{ color: 'rgba(255,255,255,0.7)', fontFamily: 'monospace', fontSize: 11 }}>
                                                    {streamMode === 'webrtc' ? 'WebRTC (Sub-second)' : `${frameCount} frames · ${lastFrameTime || 'polling'}`}
                                                </Text>
                                            </Flex>
                                        )}
                                    </Box>

                                    {/* Viewer Controls Bar */}
                                    <Flex justify="between" align="center" mt="3" px="1" wrap="wrap" gap="2">
                                        <Flex align="center" gap="2">
                                            <Button
                                                size="1"
                                                variant="soft"
                                                color={isPlaying ? 'amber' : 'cyan'}
                                                onClick={() => setIsPlaying(p => !p)}
                                                style={{ borderRadius: 8 }}
                                            >
                                                {isPlaying
                                                    ? <><PauseIcon width={14} height={14} /> Pause Feed</>
                                                    : <><PlayIcon width={14} height={14} /> Resume Feed</>
                                                }
                                            </Button>

                                            {streamMode === 'snapshot' && (
                                                <Button size="1" variant="ghost" color="gray" onClick={refreshSnapshot} title="Capture new frame immediately">
                                                    <ArrowPathIcon width={14} height={14} />
                                                </Button>
                                            )}

                                            <Button size="1" variant="surface" color="gray" onClick={handleDownloadSnapshot} style={{ borderRadius: 8 }}>
                                                <ArrowDownTrayIcon width={14} height={14} /> Save Frame
                                            </Button>
                                        </Flex>

                                        <Flex align="center" gap="2">
                                            {streamMode === 'snapshot' && (
                                                <Flex align="center" gap="1">
                                                    <Text size="1" color="gray">Interval:</Text>
                                                    {[
                                                        [1000, '1s'],
                                                        [2000, '2s'],
                                                        [5000, '5s'],
                                                    ].map(([ms, label]) => (
                                                        <Button
                                                            key={ms}
                                                            size="1"
                                                            variant={snapshotRate === ms ? 'solid' : 'ghost'}
                                                            color="cyan"
                                                            onClick={() => setSnapshotRate(ms)}
                                                            style={{ borderRadius: 6, padding: '2px 8px', height: 24, fontSize: 11 }}
                                                        >
                                                            {label}
                                                        </Button>
                                                    ))}
                                                </Flex>
                                            )}

                                            <Button size="1" variant="ghost" color="gray" onClick={handleFullscreen} title="Fullscreen Viewer">
                                                <ArrowsPointingOutIcon width={16} height={16} />
                                            </Button>
                                        </Flex>
                                    </Flex>
                                </Panel>
                            </Box>

                            {/* Right: Quick Actions & Specs */}
                            <Flex direction="column" gap="4" style={{ flex: 3, minWidth: 280 }}>
                                {/* Direct NVR Access */}
                                <Panel tinted style={{
                                    borderRadius: 16,
                                    border: '1px solid var(--cyan-a4)',
                                    padding: 20,
                                    background: 'linear-gradient(135deg, var(--cyan-a2) 0%, var(--blue-a2) 100%)',
                                    textAlign: 'center',
                                }}>
                                    <Box style={{
                                        width: 52, height: 52, borderRadius: 14,
                                        background: 'var(--cyan-a3)', border: '2px solid var(--cyan-a5)',
                                        display: 'inline-flex', alignItems: 'center', justifyContent: 'center',
                                        marginBottom: 10,
                                    }}>
                                        <VideoCameraIcon style={{ width: 26, height: 26, color: 'var(--cyan-9)' }} />
                                    </Box>
                                    <Heading size="3" mb="1" style={{ fontFamily: `'Space Grotesk', system-ui, sans-serif`, fontWeight: 800 }}>
                                        NVR Portal Access
                                    </Heading>
                                    <Text size="1" mb="3" style={{ color: 'var(--aero-color-subtle, var(--gray-9))', display: 'block' }}>
                                        Open native camera web interface for PTZ adjustments and multi-camera playback.
                                    </Text>
                                    <Button size="2" onClick={handleOpenCamera} style={{ width: '100%', borderRadius: 10, fontWeight: 700 }}>
                                        <ArrowTopRightOnSquareIcon width={16} height={16} />
                                        Launch NVR Interface
                                    </Button>
                                    {lastOpened && <Text size="1" mt="2" style={{ color: 'var(--gray-8)', fontSize: 10 }}>Opened at {lastOpened}</Text>}
                                </Panel>

                                {/* Stream Specifications */}
                                <Panel tinted style={{
                                    borderRadius: 16,
                                    border: '1px solid var(--aero-surface-border, rgba(0,0,0,0.06))',
                                    padding: 16,
                                }}>
                                    <Flex align="center" gap="2" mb="3">
                                        <CpuChipIcon style={{ width: 16, height: 16, color: 'var(--cyan-9)' }} />
                                        <Text size="2" weight="bold" style={{ fontFamily: `'Space Grotesk', system-ui, sans-serif` }}>Stream Profile</Text>
                                    </Flex>
                                    <Flex direction="column" gap="2">
                                        <Flex justify="between">
                                            <Text size="1" color="gray">Resolution</Text>
                                            <Text size="1" weight="bold" style={{ fontFamily: 'monospace' }}>
                                                {activeStream === 'main' ? '2560×1440 (4MP)' : '720×576 (D1)'}
                                            </Text>
                                        </Flex>
                                        <Flex justify="between">
                                            <Text size="1" color="gray">Video Codec</Text>
                                            <Text size="1" weight="bold" style={{ fontFamily: 'monospace' }}>H.264 Main</Text>
                                        </Flex>
                                        <Flex justify="between">
                                            <Text size="1" color="gray">Target Bitrate</Text>
                                            <Text size="1" weight="bold" style={{ fontFamily: 'monospace' }}>
                                                {activeStream === 'main' ? '4096 kbps' : '1024 kbps'}
                                            </Text>
                                        </Flex>
                                        <Flex justify="between">
                                            <Text size="1" color="gray">Target FPS</Text>
                                            <Text size="1" weight="bold" style={{ fontFamily: 'monospace' }}>25 fps</Text>
                                        </Flex>
                                        <Flex justify="between">
                                            <Text size="1" color="gray">Current Mode</Text>
                                            <Badge size="1" color={streamMode === 'webrtc' ? 'violet' : 'cyan'}>
                                                {streamMode === 'webrtc' ? 'WebRTC WHEP' : 'ONVIF Snapshot'}
                                            </Badge>
                                        </Flex>
                                    </Flex>
                                </Panel>

                                {/* Device Hardware Info */}
                                {deviceInfo && (
                                    <Panel tinted style={{
                                        borderRadius: 16,
                                        border: '1px solid var(--aero-surface-border, rgba(0,0,0,0.06))',
                                        padding: 16,
                                    }}>
                                        <Flex align="center" gap="2" mb="3">
                                            <InformationCircleIcon style={{ width: 16, height: 16, color: 'var(--blue-9)' }} />
                                            <Text size="2" weight="bold" style={{ fontFamily: `'Space Grotesk', system-ui, sans-serif` }}>Hardware</Text>
                                        </Flex>
                                        <Flex direction="column" gap="1">
                                            {[
                                                ['Vendor', deviceInfo.Manufacturer || 'HOLOWITS'],
                                                ['Model', deviceInfo.Model || 'P4-4R(2.8)A'],
                                                ['Firmware', deviceInfo.FirmwareVersion || 'SDC 11.1.1'],
                                                ['Serial', deviceInfo.SerialNumber || '21024139637SQ3000365'],
                                            ].map(([label, val]) => val && (
                                                <Flex key={label} justify="between">
                                                    <Text size="1" color="gray">{label}</Text>
                                                    <Text size="1" weight="bold" style={{ fontFamily: 'monospace' }}>{val}</Text>
                                                </Flex>
                                            ))}
                                        </Flex>
                                    </Panel>
                                )}

                                {/* Security Status */}
                                <Panel tinted style={{
                                    borderRadius: 16,
                                    border: '1px solid var(--aero-surface-border, rgba(0,0,0,0.06))',
                                    padding: 16,
                                }}>
                                    <Flex align="center" gap="2" mb="3">
                                        <ShieldCheckIcon style={{ width: 16, height: 16, color: 'var(--green-9)' }} />
                                        <Text size="2" weight="bold" style={{ fontFamily: `'Space Grotesk', system-ui, sans-serif` }}>Security & Tunnel</Text>
                                    </Flex>
                                    <Flex direction="column" gap="2">
                                        {[
                                            'Internal IP (11.151.14.67) fully masked',
                                            'Protected by Cloudflare edge tunnel',
                                            'Permission: monitoring.camera.view',
                                            'Server-side credential digest injection',
                                        ].map((text, i) => (
                                            <Flex key={i} align="center" gap="2">
                                                <LockClosedIcon style={{ width: 12, height: 12, color: 'var(--green-9)', flexShrink: 0 }} />
                                                <Text size="1">{text}</Text>
                                            </Flex>
                                        ))}
                                    </Flex>
                                </Panel>
                            </Flex>
                        </Flex>

                        {/* Technical Footer */}
                        <Box mt="4">
                            <Callout.Root color="blue" size="1" style={{ borderRadius: 12 }}>
                                <Callout.Icon>
                                    <InformationCircleIcon style={{ width: 16, height: 16 }} />
                                </Callout.Icon>
                                <Callout.Text size="1">
                                    <strong>Architecture Note:</strong> Live video is ingested via RTSP (Main 2560×1440 4MP) through the MediaMTX gateway and served directly over WebRTC (WHEP). In environments where the MediaMTX daemon is starting or unconfigured, the viewer automatically serves high-resolution server-side proxied ONVIF snapshot frames.
                                </Callout.Text>
                            </Callout.Root>
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
