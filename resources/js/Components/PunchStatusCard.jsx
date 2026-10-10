import { Panel } from '@/Components/ui/Panel';
import React, { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import { Badge as CyBadge, Button as CyButton, Icon, StatTile } from '@/Components/Cyber';
import { Avatar as CyAvatar } from '@/Components/Cyber/Map/Avatar.jsx';
import CyDialog from '@/Components/Cyber/Map/Dialog.jsx';
import { Box, Flex, Grid, Text, Heading, Badge, Separator, Skeleton, Avatar, Button, TextField, Dialog, Tooltip, Spinner } from '@radix-ui/themes';
import {
    ClockIcon,
    DrawingPinIcon,
    LightningBoltIcon,
    CheckCircledIcon,
    ExclamationTriangleIcon,
    EnterIcon,
    ExitIcon,
    CalendarIcon,
    HomeIcon,
    ReloadIcon,
    ChevronDownIcon,
    ChevronUpIcon,
    LockClosedIcon,
    GlobeIcon,
    Cross2Icon,
    InfoCircledIcon,
    VideoIcon,
    BarChartIcon,
} from '@radix-ui/react-icons';
import { showToast } from '@/utils/toastUtils';
import axios from 'axios';
import { usePage } from '@inertiajs/react';

/**
 * Enhanced PunchStatusCard Component for Enterprise ERP System
 * 
 * @description A comprehensive attendance tracking component with real-time status monitoring,
 * location-based validation, and enterprise-grade security features.
 * 
 * @features
 * - Real-time attendance tracking with simplified location validation
 * - Role-based access control integration
 * - Progressive Web App (PWA) ready with offline capabilities
 * - Enterprise security with device fingerprinting
 * - Responsive design with HeroUI theming
 * - Performance optimized with memoization and efficient state management
 * 
 * @author Emam Hosen - Final Year CSE Project
 * @version 3.0.0 - Simplified Location Management
 */

// ===== UTILITY FUNCTIONS =====

/**
 * Debounce utility for performance optimization
 */
const debounce = (func, wait) => {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
};

/**
 * Enhanced device type detection hook
 */
const useDeviceType = () => {
    const [deviceState, setDeviceState] = useState({
        isMobile: false,
        isTablet: false,
        isDesktop: false
    });

    const updateDeviceType = useCallback(() => {
        const width = window.innerWidth;
        const newState = {
            isMobile: width <= 768,
            isTablet: width > 768 && width <= 1024,
            isDesktop: width > 1024
        };
        setDeviceState(prevState => 
            JSON.stringify(prevState) !== JSON.stringify(newState) ? newState : prevState
        );
    }, []);

    useEffect(() => {
        updateDeviceType();
        const debouncedUpdate = debounce(updateDeviceType, 150);
        window.addEventListener('resize', debouncedUpdate);
        return () => window.removeEventListener('resize', debouncedUpdate);
    }, [updateDeviceType]);

    return deviceState;
};

/**
 * GPS Status Types
 */
const GPS_STATUS = {
    CHECKING: 'checking',
    ACTIVE: 'active',
    DENIED: 'denied',
    INACTIVE: 'inactive'
};

/**
 * Main PunchStatusCard Component
 */
const PunchStatusCard = React.memo(() => {
    // ===== CORE STATE MANAGEMENT =====
    const { auth } = usePage().props;
    const user = auth.user;
    const { isMobile, isTablet } = useDeviceType();

    // Attendance state
    const [attendanceState, setAttendanceState] = useState({
        currentStatus: null,
        todayPunches: [],
        totalWorkTime: '00:00:00',
        realtimeWorkTime: '00:00:00',
        userOnLeave: null,
        loading: false,
        lastRefresh: null
    });

    // Location state - simplified
    const [locationState, setLocationState] = useState({
        status: GPS_STATUS.CHECKING,
        coordinates: null,
        error: null,
        lastUpdate: null
    });

    // UI state
    const [uiState, setUiState] = useState({
        sessionDialogOpen: false,
        expandedSections: {
            punches: false,
            stats: false,
            validation: false
        }
    });

    // System state
    const [systemState, setSystemState] = useState({
        currentTime: new Date(),
        connectionStatus: {
            network: true,
            device: true
        },
        sessionInfo: {
            ip: 'Unknown',
            accuracy: 'N/A',
            timestamp: null
        }
    });

    // Camera state for photo capture (polygon/route types)
    const [cameraState, setCameraState] = useState({
        isOpen: false,
        capturedPhoto: null,
        isCapturing: false,
        stream: null,
        pendingPunchData: null,
        facingMode: 'user', // 'user' for front camera (default), 'environment' for back camera
        isSwitching: false,
    });
    const videoRef = useRef(null);
    const canvasRef = useRef(null);

    const [qrCodeValue, setQrCodeValue] = useState('');

    const attendanceType = user?.attendance_type;
    const attendanceTypeConfig = attendanceType?.config || {};

    const attendanceTypeBaseSlug = useMemo(() => {
        if (!attendanceType?.slug) {
            return '';
        }

        return attendanceType.slug.replace(/_\d+$/, '');
    }, [attendanceType]);

    const primaryQrConfig = useMemo(() => {
        const configuredCodes = Array.isArray(attendanceTypeConfig.qr_codes) ? attendanceTypeConfig.qr_codes : [];
        const activeCode = configuredCodes.find((code) => code?.is_active !== false);

        return activeCode || configuredCodes[0] || null;
    }, [attendanceTypeConfig]);

    const requiresQrCode = attendanceTypeBaseSlug === 'qr_code';
    const requiresNetworkValidation = attendanceTypeBaseSlug === 'wifi_ip';
    const requiresBiometric = attendanceTypeBaseSlug === 'biometric';

    const requiresLocationForPunch = useMemo(() => {
        if (attendanceTypeBaseSlug === 'geo_polygon' || attendanceTypeBaseSlug === 'route_waypoint') {
            return !(attendanceTypeConfig.allow_without_location ?? false);
        }

        if (attendanceTypeBaseSlug === 'qr_code') {
            return Boolean(attendanceTypeConfig.require_location || primaryQrConfig?.require_location);
        }

        return false;
    }, [attendanceTypeBaseSlug, attendanceTypeConfig, primaryQrConfig]);

    const usesLocationRequirement = ['geo_polygon', 'route_waypoint'].includes(attendanceTypeBaseSlug) || requiresLocationForPunch;

    // Check if user's attendance type requires photo capture
    const requiresPhotoCapture = useMemo(() => {
        return ['geo_polygon', 'route_waypoint'].includes(attendanceTypeBaseSlug);
    }, [attendanceTypeBaseSlug]);

    // Biometric users always punch via device — disable web punch
    const isBiometricUser = requiresBiometric;

    // Specific device assigned to this employee, or fall back to pool names
    const assignedDeviceName = useMemo(() => {
        if (user?.biometric_device_name) return user.biometric_device_name;
        const pool = user?.attendance_type_devices ?? [];
        if (pool.length > 0) return pool.map(d => d.name).join(', ');
        return null;
    }, [user?.biometric_device_name, user?.attendance_type_devices]);

    // ===== LOCATION MANAGEMENT - SIMPLIFIED =====

    /**
     * Core location getter - Single unified function
     */
    const getLocation = useCallback(() => {
        return new Promise((resolve, reject) => {
            // Check browser support
            if (!navigator.geolocation) {
                reject(new Error('Geolocation is not supported by this browser'));
                return;
            }

            const options = {
                enableHighAccuracy: true,
                timeout: 15000,
                maximumAge: 30000 // Allow 30 seconds cache
            };

            navigator.geolocation.getCurrentPosition(
                (position) => {
                    const locationData = {
                        latitude: position.coords.latitude,
                        longitude: position.coords.longitude,
                        accuracy: position.coords.accuracy,
                        timestamp: new Date(position.timestamp)
                    };
                    resolve(locationData);
                },
                (error) => {
                    let message = 'Location unavailable';
                    switch (error.code) {
                        case 1: // PERMISSION_DENIED
                            message = 'Location access denied. Please allow location permission in your browser settings.';
                            break;
                        case 2: // POSITION_UNAVAILABLE
                            message = 'Location unavailable. Please ensure GPS is enabled.';
                            break;
                        case 3: // TIMEOUT
                            message = 'Location request timed out. Please try again.';
                            break;
                        default:
                            message = 'Unable to retrieve location. Please try again.';
                    }
                    reject(new Error(message));
                },
                options
            );
        });
    }, []);

    /**
     * Check location permission and update GPS status
     */
    const checkLocationPermission = useCallback(async () => {
        setLocationState(prev => ({ ...prev, status: GPS_STATUS.CHECKING, error: null }));

        try {
            const coordinates = await getLocation();
            setLocationState({
                status: GPS_STATUS.ACTIVE,
                coordinates,
                error: null,
                lastUpdate: new Date()
            });
        } catch (error) {
            const isPermissionDenied = error.message.includes('denied') || error.message.includes('Permission');
            setLocationState({
                status: isPermissionDenied ? GPS_STATUS.DENIED : GPS_STATUS.INACTIVE,
                coordinates: null,
                error: error.message,
                lastUpdate: new Date()
            });
        }
    }, [getLocation]);

    /**
     * Request location permission reset (for denied status)
     */
    const requestLocationPermissionReset = useCallback(async () => {
        if (locationState.status !== GPS_STATUS.DENIED) return;

        try {
            setLocationState(prev => ({ ...prev, status: GPS_STATUS.CHECKING, error: null }));
            const coordinates = await getLocation();
            
            setLocationState({
                status: GPS_STATUS.ACTIVE,
                coordinates,
                error: null,
                lastUpdate: new Date()
            });

            showToast.success('Location access granted successfully!', {
                style: {
                    backdropFilter: 'blur(16px) saturate(200%)',
                    background: 'var(--theme-success)',
                    color: 'var(--theme-success-foreground)',
                }
            });
        } catch (error) {
            setLocationState(prev => ({
                ...prev,
                status: GPS_STATUS.DENIED,
                error: 'Location access still denied. Please check your browser settings.'
            }));

            showToast.error('Please enable location in your browser settings manually.', {
                style: {
                    backdropFilter: 'blur(16px) saturate(200%)',
                    background: 'var(--theme-danger)',
                    color: 'var(--theme-danger-foreground)',
                }
            });
        }
    }, [locationState.status, getLocation]);

    // ===== MEMOIZED VALUES =====
    const statusConfig = useMemo(() => {
        if (attendanceState.userOnLeave) {
            return {
                color: 'warning',
                text: 'On Leave',
                action: 'On Leave',
                icon: <ExclamationTriangleIcon />
            };
        }

        switch (attendanceState.currentStatus) {
            case 'punched_in':
                return {
                    color: 'success',
                    text: 'Checked In',
                    action: 'Check Out',
                    icon: <ExitIcon />
                };
            case 'punched_out':
                return {
                    color: 'primary',
                    text: 'Checked Out',
                    action: 'Check In',
                    icon: <EnterIcon />
                };
            default:
                return {
                    color: 'primary',
                    text: 'Ready to Check In',
                    action: 'Check In',
                    icon: <ClockIcon />
                };
        }
    }, [attendanceState.currentStatus, attendanceState.userOnLeave]);

    const workStats = useMemo(() => ({
        sessionsToday: attendanceState.todayPunches.length,
        averageSessionTime: attendanceState.todayPunches.length > 0 
            ? Math.round(parseFloat(attendanceState.realtimeWorkTime.split(':')[0]) / attendanceState.todayPunches.length * 100) / 100 
            : 0,
        productivity: Math.min(100, (parseFloat(attendanceState.realtimeWorkTime.split(':')[0]) / 8) * 100)
    }), [attendanceState.todayPunches, attendanceState.realtimeWorkTime]);

    const gpsChipConfig = useMemo(() => {
        if (!requiresLocationForPunch) {
            return {
                color: locationState.status === GPS_STATUS.ACTIVE ? 'success' : 'default',
                variant: 'flat',
                text: locationState.status === GPS_STATUS.ACTIVE ? 'GPS Optional' : 'GPS Optional',
                clickable: locationState.status === GPS_STATUS.DENIED || locationState.status === GPS_STATUS.INACTIVE,
                tooltip: 'Location is optional for your attendance type.',
            };
        }

        switch (locationState.status) {
            case GPS_STATUS.CHECKING:
                return {
                    color: 'default',
                    variant: 'flat',
                    text: 'Checking',
                    clickable: false,
                    tooltip: 'Checking location permissions...'
                };
            case GPS_STATUS.ACTIVE:
                return {
                    color: 'success',
                    variant: 'flat',
                    text: 'GPS',
                    clickable: false,
                    tooltip: `Location: Active (±${Math.round(locationState.coordinates?.accuracy || 0)}m)`
                };
            case GPS_STATUS.DENIED:
                return {
                    color: 'danger',
                    variant: 'bordered',
                    text: 'GPS',
                    clickable: true,
                    tooltip: 'Location access denied. Click to retry.'
                };
            case GPS_STATUS.INACTIVE:
                return {
                    color: 'warning',
                    variant: 'bordered',
                    text: 'GPS',
                    clickable: true,
                    tooltip: 'Location unavailable. Click to retry.'
                };
            default:
                return {
                    color: 'default',
                    variant: 'flat',
                    text: 'GPS',
                    clickable: false,
                    tooltip: 'Location status unknown'
                };
        }
    }, [locationState.status, locationState.coordinates?.accuracy, requiresLocationForPunch]);

    const isPunchActionDisabled = useMemo(() => {
        if (attendanceState.loading || attendanceState.userOnLeave) {
            return true;
        }

        // Biometric users cannot punch via web interface
        if (isBiometricUser) {
            return true;
        }

        if (requiresLocationForPunch && locationState.status !== GPS_STATUS.ACTIVE) {
            return true;
        }

        if (requiresQrCode && qrCodeValue.trim() === '') {
            return true;
        }

        return false;
    }, [attendanceState.loading, attendanceState.userOnLeave, isBiometricUser, requiresLocationForPunch, locationState.status, requiresQrCode, qrCodeValue]);

    // ===== CORE FUNCTIONS =====

    /**
     * Calculate real-time work time
     */
    const calculateRealtimeWorkTime = useCallback(() => {
        const currentTime = new Date();
        let totalSeconds = 0;
        let hasActivePunch = false;

        attendanceState.todayPunches.forEach((punch) => {
            if (punch.punchin_time) {
                let punchInTime;
                
                // Handle different time formats - try parsing as ISO date first
                try {
                    punchInTime = new Date(punch.punchin_time);
                    
                    // If invalid or string with just time format
                    if (isNaN(punchInTime.getTime()) || (typeof punch.punchin_time === 'string' && punch.punchin_time.includes(':') && !punch.punchin_time.includes('T'))) {
                        // Use the punch row's date (not today) — critical for overnight sessions
                        // where punch.date is yesterday but the time is an evening hour.
                        const baseDate = punch.date ? new Date(punch.date + 'T00:00:00') : new Date();
                        const [hours, minutes, seconds] = punch.punchin_time.split(':');
                        punchInTime = new Date(baseDate.getFullYear(), baseDate.getMonth(), baseDate.getDate(), 
                            parseInt(hours), parseInt(minutes), parseInt(seconds || 0));
                    }
                } catch (error) {
                    console.warn('Invalid punch in time format:', punch.punchin_time);
                    return;
                }

                if (isNaN(punchInTime.getTime())) return;

                if (punch.punchout_time) {
                    let punchOutTime;
                    
                    try {
                        punchOutTime = new Date(punch.punchout_time);
                        
                        // If invalid or string with just time format — use punch row's date
                        if (isNaN(punchOutTime.getTime()) || (typeof punch.punchout_time === 'string' && punch.punchout_time.includes(':') && !punch.punchout_time.includes('T'))) {
                            const baseDate = punch.date ? new Date(punch.date + 'T00:00:00') : new Date();
                            const [hours, minutes, seconds] = punch.punchout_time.split(':');
                            punchOutTime = new Date(baseDate.getFullYear(), baseDate.getMonth(), baseDate.getDate(), 
                                parseInt(hours), parseInt(minutes), parseInt(seconds || 0));
                        }
                    } catch (error) {
                        console.warn('Invalid punch out time format:', punch.punchout_time);
                        return;
                    }

                    if (isNaN(punchOutTime.getTime())) return;

                    const sessionSeconds = Math.floor((punchOutTime - punchInTime) / 1000);
                    if (sessionSeconds > 0) totalSeconds += sessionSeconds;
                } else {
                    // Active session - calculate from punch in to now
                    hasActivePunch = true;
                    const sessionSeconds = Math.floor((currentTime - punchInTime) / 1000);
                    if (sessionSeconds > 0) totalSeconds += sessionSeconds;
                }
            }
        });

        // If no active punch and we have backend total time, use that instead
        if (!hasActivePunch && attendanceState.totalWorkTime && attendanceState.totalWorkTime !== '00:00:00') {
            setAttendanceState(prev => ({
                ...prev,
                realtimeWorkTime: attendanceState.totalWorkTime
            }));
            return;
        }

        if (isNaN(totalSeconds) || totalSeconds < 0) totalSeconds = 0;

        const hours = Math.floor(totalSeconds / 3600);
        const minutes = Math.floor((totalSeconds % 3600) / 60);
        const seconds = totalSeconds % 60;

        const formattedTime = `${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
        
        setAttendanceState(prev => ({
            ...prev,
            realtimeWorkTime: formattedTime
        }));
    }, [attendanceState.todayPunches, attendanceState.totalWorkTime]);

    /**
     * Fetch current attendance status - Always fetch latest data
     */
    const fetchCurrentStatus = useCallback(async () => {
        try {
            // Add timestamp to prevent caching
            const response = await axios.get(route('attendance.current-user-punch'), {
                params: { t: Date.now() }
            });
            const data = response.data;

            setAttendanceState(prev => ({
                ...prev,
                todayPunches: data.punches || [],
                totalWorkTime: data.total_production_time || '00:00:00',
                realtimeWorkTime: data.total_production_time || '00:00:00',
                userOnLeave: data.isUserOnLeave,
                lastRefresh: new Date(),
                currentStatus: (() => {
                    if (data.punches && data.punches.length > 0) {
                        const lastPunch = data.punches[data.punches.length - 1];
                        return lastPunch.punchout_time ? 'punched_out' : 'punched_in';
                    }
                    return 'not_punched';
                })()
            }));

        } catch (error) {
            console.error('Error fetching current status:', error);
            showToast.error('Failed to fetch attendance status');
        }
    }, []);

    /**
     * Get device fingerprint for security
     */
    const getDeviceFingerprint = useCallback(() => {
        const canvas = document.createElement('canvas');
        const ctx = canvas.getContext('2d');
        ctx.textBaseline = 'top';
        ctx.font = '14px Arial';
        ctx.fillText('Device fingerprint', 2, 2);

        return {
            userAgent: navigator.userAgent,
            screen: `${screen.width}x${screen.height}`,
            timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
            language: navigator.language,
            canvasFingerprint: canvas.toDataURL(),
            timestamp: Date.now()
        };
    }, []);

    // ===== CAMERA FUNCTIONS FOR PHOTO CAPTURE =====
    
    /**
     * Start camera for photo capture
     */
    const startCamera = useCallback(async (facingMode = 'user') => {
        try {
            // Stop existing stream first
            if (cameraState.stream) {
                cameraState.stream.getTracks().forEach(track => track.stop());
            }
            
            const stream = await navigator.mediaDevices.getUserMedia({
                video: { 
                    facingMode: facingMode, 
                    width: { ideal: 1280 }, 
                    height: { ideal: 720 } 
                }
            });
            
            if (videoRef.current) {
                videoRef.current.srcObject = stream;
                await videoRef.current.play();
            }
            
            setCameraState(prev => ({ 
                ...prev, 
                stream, 
                isCapturing: false, 
                isSwitching: false,
                facingMode 
            }));
        } catch (error) {
            console.error('Camera access error:', error);
            // Try fallback to any available camera
            try {
                const fallbackStream = await navigator.mediaDevices.getUserMedia({
                    video: { width: { ideal: 1280 }, height: { ideal: 720 } }
                });
                
                if (videoRef.current) {
                    videoRef.current.srcObject = fallbackStream;
                    await videoRef.current.play();
                }
                
                setCameraState(prev => ({ 
                    ...prev, 
                    stream: fallbackStream, 
                    isCapturing: false, 
                    isSwitching: false 
                }));
            } catch (fallbackError) {
                showToast.error('Unable to access camera. Please check permissions.');
                setCameraState(prev => ({ ...prev, isOpen: false, isSwitching: false }));
            }
        }
    }, [cameraState.stream]);

    /**
     * Switch between front and back camera
     */
    const switchCamera = useCallback(async () => {
        const newFacingMode = cameraState.facingMode === 'environment' ? 'user' : 'environment';
        setCameraState(prev => ({ ...prev, isSwitching: true }));
        await startCamera(newFacingMode);
    }, [cameraState.facingMode, startCamera]);

    /**
     * Stop camera stream
     */
    const stopCamera = useCallback(() => {
        if (cameraState.stream) {
            cameraState.stream.getTracks().forEach(track => track.stop());
        }
        setCameraState(prev => ({ 
            ...prev, 
            stream: null, 
            isOpen: false, 
            capturedPhoto: null,
            facingMode: 'user',
            isSwitching: false 
        }));
    }, [cameraState.stream]);

    /**
     * Capture photo with coordinate watermark
     */
    const capturePhoto = useCallback(async () => {
        if (!videoRef.current || !canvasRef.current) return;

        setCameraState(prev => ({ ...prev, isCapturing: true }));

        try {
            const video = videoRef.current;
            const canvas = canvasRef.current;
            const ctx = canvas.getContext('2d');

            // Set canvas dimensions
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;

            // Draw video frame
            ctx.drawImage(video, 0, 0);

            // Add coordinate watermark
            const coordinates = locationState.coordinates;
            if (coordinates) {
                const watermarkText = `📍 ${coordinates.latitude.toFixed(6)}, ${coordinates.longitude.toFixed(6)} | ${new Date().toLocaleString()}`;
                
                // Watermark background
                ctx.fillStyle = 'rgba(0, 0, 0, 0.7)';
                ctx.fillRect(0, canvas.height - 40, canvas.width, 40);
                
                // Watermark text
                ctx.fillStyle = '#ffffff';
                ctx.font = 'bold 16px Arial';
                ctx.textBaseline = 'middle';
                ctx.fillText(watermarkText, 10, canvas.height - 20);
            }

            // Convert to base64
            const photoData = canvas.toDataURL('image/jpeg', 0.85);
            
            setCameraState(prev => ({ 
                ...prev, 
                capturedPhoto: photoData, 
                isCapturing: false 
            }));

            showToast.success('Photo captured successfully!');
        } catch (error) {
            console.error('Photo capture error:', error);
            showToast.error('Failed to capture photo. Please try again.');
            setCameraState(prev => ({ ...prev, isCapturing: false }));
        }
    }, [locationState.coordinates]);

    /**
     * Retake photo
     */
    const retakePhoto = useCallback(() => {
        setCameraState(prev => ({ ...prev, capturedPhoto: null }));
    }, []);

    /**
     * Confirm photo and submit punch
     */
    const confirmPhotoAndPunch = useCallback(async () => {
        if (!cameraState.capturedPhoto || !cameraState.pendingPunchData) {
            showToast.error('Please capture a photo first.');
            return;
        }

        setAttendanceState(prev => ({ ...prev, loading: true }));
        stopCamera();

        try {
            // Add photo to punch data
            const punchDataWithPhoto = {
                ...cameraState.pendingPunchData,
                photo: cameraState.capturedPhoto,
            };

            // Submit punch
            const response = await axios.post(route('attendance.punch'), punchDataWithPhoto);

            if (response.data.status === 'success') {
                setUiState(prev => ({
                    ...prev,
                    sessionDialogOpen: true
                }));

                if (requiresQrCode) {
                    setQrCodeValue('');
                }

                // Immediately fetch latest data after successful punch
                setTimeout(() => {
                    fetchCurrentStatus();
                }, 500);
            } else {
                showToast.error(response.data.message);
            }
        } catch (error) {
            console.error('Punch operation failed:', error);
            const errorMessage = error.response?.data?.message || 'Unable to record attendance. Please try again.';
            showToast.error(errorMessage);
        } finally {
            setAttendanceState(prev => ({ ...prev, loading: false }));
            setCameraState(prev => ({ ...prev, pendingPunchData: null, capturedPhoto: null }));
        }
    }, [cameraState.capturedPhoto, cameraState.pendingPunchData, stopCamera, fetchCurrentStatus, requiresQrCode]);

    /**
     * Open camera modal and prepare punch data
     */
    const openCameraForPunch = useCallback(async (punchData) => {
        setCameraState(prev => ({ 
            ...prev, 
            isOpen: true, 
            pendingPunchData: punchData,
            capturedPhoto: null 
        }));
        
        // Start camera after modal opens
        setTimeout(() => startCamera(), 300);
    }, [startCamera]);

    /**
     * Handle punch action - Main attendance function
     */
    const handlePunch = useCallback(async () => {
        // Check if user is on leave
        if (attendanceState.userOnLeave) {
            showToast.warning('You are on leave today. Cannot punch in/out.');
            return;
        }

        if (requiresLocationForPunch && locationState.status !== GPS_STATUS.ACTIVE) {
            showToast.error('Location access required for attendance. Please enable GPS and try again.');
            return;
        }

        if (requiresQrCode && qrCodeValue.trim() === '') {
            showToast.error('QR code is required for this attendance type.');

            return;
        }

        setAttendanceState(prev => ({ ...prev, loading: true }));

        try {
            let coordinates = null;

            if (requiresLocationForPunch) {
                coordinates = await getLocation();
            } else {
                try {
                    coordinates = await getLocation();
                } catch (error) {
                    coordinates = null;
                }
            }

            const deviceFingerprint = getDeviceFingerprint();

            // Get IP address
            let currentIp = 'Unknown';
            try {
                const ipResponse = await axios.get(route('getClientIp'));
                currentIp = ipResponse.data.ip;
            } catch (ipError) {
                console.warn('Could not fetch IP address:', ipError);
            }

            // Update session info
            setSystemState(prev => ({
                ...prev,
                sessionInfo: {
                    ip: currentIp,
                    accuracy: coordinates?.accuracy ? `${Math.round(coordinates.accuracy)}m` : 'N/A',
                    timestamp: new Date().toLocaleString()
                }
            }));

            // Prepare punch data
            const punchData = {
                ip: currentIp,
                wifi_ssid: 'Unknown',
                device_fingerprint: JSON.stringify(deviceFingerprint),
                user_agent: navigator.userAgent,
                timestamp: new Date().toISOString(),
            };

            if (coordinates) {
                punchData.lat = coordinates.latitude;
                punchData.lng = coordinates.longitude;
                punchData.accuracy = coordinates.accuracy;
            }

            if (requiresQrCode) {
                punchData.qr_code = qrCodeValue.trim();
            }

            // Check if photo capture is required (polygon/route types)
            if (requiresPhotoCapture) {
                setAttendanceState(prev => ({ ...prev, loading: false }));
                openCameraForPunch(punchData);
                return;
            }

            // Submit punch directly (no photo required)
            const response = await axios.post(route('attendance.punch'), punchData);

            if (response.data.status === 'success') {
                

                setUiState(prev => ({
                    ...prev,
                    sessionDialogOpen: true
                }));

                // Immediately fetch latest data after successful punch
                setTimeout(() => {
                    fetchCurrentStatus();
                }, 500);

                if (requiresQrCode) {
                    setQrCodeValue('');
                }
            } else {
                showToast.error(response.data.message);
            }
        } catch (error) {
            console.error('Punch operation failed:', error);
            
            const errorMessage = error.response?.data?.message || 'Unable to record attendance. Please try again.';
            
            showToast.error(errorMessage, {
                style: {
                    backdropFilter: 'blur(16px) saturate(200%)',
                    background: 'var(--theme-danger)',
                    color: 'var(--theme-danger-foreground)',
                }
            });
        } finally {
            setAttendanceState(prev => ({ ...prev, loading: false }));
        }
    }, [attendanceState.userOnLeave, requiresLocationForPunch, locationState.status, requiresQrCode, qrCodeValue, getLocation, getDeviceFingerprint, fetchCurrentStatus, requiresPhotoCapture, openCameraForPunch]);

    /**
     * Handle GPS chip click
     */
    const handleGpsChipClick = useCallback(() => {
        if (!gpsChipConfig.clickable) return;

        if (locationState.status === GPS_STATUS.DENIED) {
            requestLocationPermissionReset();
        } else if (locationState.status === GPS_STATUS.INACTIVE) {
            checkLocationPermission();
        }
    }, [gpsChipConfig.clickable, locationState.status, requestLocationPermissionReset, checkLocationPermission]);

    /**
     * Format time utility
     */
    const formatTime = useCallback((timeString) => {
        if (!timeString) return '--:--';

        try {
            let date;
            if (typeof timeString === 'string' && timeString.includes(':') && !timeString.includes('T')) {
                const today = new Date();
                const [hours, minutes, seconds] = timeString.split(':');
                date = new Date(today.getFullYear(), today.getMonth(), today.getDate(), 
                    parseInt(hours), parseInt(minutes), parseInt(seconds || 0));
            } else {
                date = new Date(timeString);
            }

            if (isNaN(date.getTime())) return '--:--';

            return date.toLocaleTimeString('en-US', { 
                hour12: false, 
                hour: '2-digit', 
                minute: '2-digit' 
            });
        } catch (error) {
            return '--:--';
        }
    }, []);

    /**
     * Format location utility
     */
    const formatLocation = useCallback((locationData) => {
        if (!locationData) return 'Location not available';

        try {
            if (typeof locationData === 'object' && locationData.lat && locationData.lng) {
                return locationData.address?.trim() 
                    ? locationData.address.substring(0, 30)
                    : `${locationData.lat.toFixed(4)}, ${locationData.lng.toFixed(4)}`;
            }

            if (typeof locationData === 'string') {
                try {
                    const parsed = JSON.parse(locationData);
                    if (parsed.lat && parsed.lng) {
                        return parsed.address?.trim() 
                            ? parsed.address.substring(0, 30)
                            : `${parsed.lat.toFixed(4)}, ${parsed.lng.toFixed(4)}`;
                    }
                } catch {
                    return locationData.substring(0, 30);
                }
            }

            return 'Location not available';
        } catch (error) {
            return 'Location not available';
        }
    }, []);

    // ===== EFFECTS =====

    /**
     * Real-time clock and work time calculation
     */
    useEffect(() => {
        const timer = setInterval(() => {
            setSystemState(prev => ({ ...prev, currentTime: new Date() }));
            
            // Only calculate if we have active session
            if (attendanceState.currentStatus === 'punched_in' && attendanceState.todayPunches.length > 0) {
                calculateRealtimeWorkTime();
            }
        }, 1000);

        return () => clearInterval(timer);
    }, [attendanceState.currentStatus, attendanceState.todayPunches.length, calculateRealtimeWorkTime]);

    /**
     * Initial setup and data fetching
     */
    useEffect(() => {
        // Fetch initial attendance data
        fetchCurrentStatus();
        
        // Check location permission
        checkLocationPermission();

        // Network status handlers
        const handleOnline = () => {
            setSystemState(prev => ({
                ...prev,
                connectionStatus: { ...prev.connectionStatus, network: true }
            }));
            // Refresh data when coming back online
            fetchCurrentStatus();
        };

        const handleOffline = () => {
            setSystemState(prev => ({
                ...prev,
                connectionStatus: { ...prev.connectionStatus, network: false }
            }));
        };

        const handleFocus = () => {
            // Refresh data when window regains focus
            fetchCurrentStatus();
            checkLocationPermission();
        };

        // Add event listeners
        window.addEventListener('online', handleOnline);
        window.addEventListener('offline', handleOffline);
        window.addEventListener('focus', handleFocus);

        // Cleanup
        return () => {
            window.removeEventListener('online', handleOnline);
            window.removeEventListener('offline', handleOffline);
            window.removeEventListener('focus', handleFocus);
        };
    }, [fetchCurrentStatus, checkLocationPermission]);

    /**
     * Auto-refresh attendance data every 30 seconds
     */
    useEffect(() => {
        const refreshInterval = setInterval(() => {
            fetchCurrentStatus();
        }, 30000); // 30 seconds

        return () => clearInterval(refreshInterval);
    }, [fetchCurrentStatus]);

    // ===== RENDER =====
    const rc = (c) => ({ primary: 'blue', success: 'green', warning: 'amber', danger: 'red', default: 'gray', secondary: 'violet' }[c] || 'gray');
    // Cyber badge colours and Bootstrap Icons for the punch state (the card body is Cyber; dialogs keep Radix).
    const cyTone = (c) => ({ primary: 'theme', success: 'success', warning: 'warning', danger: 'danger', default: 'secondary', secondary: 'secondary' }[c] || 'secondary');
    const statusIcon = attendanceState.userOnLeave ? 'exclamation-triangle'
        : statusConfig.text === 'Checked In' ? 'person-check'
        : statusConfig.text === 'Checked Out' ? 'box-arrow-right' : 'clock';
    const actionIcon = statusConfig.action === 'Check Out' ? 'box-arrow-right' : 'box-arrow-in-right';
    const checks = [
        isBiometricUser && { key: 'bio', tone: 'theme', icon: 'fingerprint', text: assignedDeviceName ?? 'Biometric: no device', title: assignedDeviceName ? `Assigned device: ${assignedDeviceName}` : 'No biometric device assigned yet' },
        !isBiometricUser && usesLocationRequirement && { key: 'gps', tone: cyTone(gpsChipConfig.color), icon: 'geo-alt', text: gpsChipConfig.text, title: gpsChipConfig.tooltip, onClick: gpsChipConfig.clickable ? handleGpsChipClick : undefined, busy: locationState.status === GPS_STATUS.CHECKING },
        !isBiometricUser && requiresNetworkValidation && { key: 'net', tone: systemState.connectionStatus.network ? 'success' : 'danger', icon: 'hdd-network', text: 'IP network', title: `WiFi/IP attendance. Network: ${systemState.connectionStatus.network ? 'online' : 'offline'}` },
        !isBiometricUser && requiresQrCode && { key: 'qr', tone: qrCodeValue.trim() ? 'success' : 'warning', icon: 'list-check', text: 'QR', title: qrCodeValue.trim() ? 'QR code entered.' : 'QR code required.' },
        !isBiometricUser && requiresPhotoCapture && { key: 'photo', tone: 'warning', icon: 'camera-video', text: 'Photo', title: 'Photo verification required.' },
        !isBiometricUser && !usesLocationRequirement && !requiresNetworkValidation && !requiresQrCode && !requiresPhotoCapture && { key: 'std', tone: 'success', icon: 'shield-check', text: 'Standard', title: 'Standard attendance validation is active.' },
    ].filter(Boolean);
    const toggleActivity = () => setUiState(prev => ({ ...prev, expandedSections: { ...prev.expandedSections, punches: !prev.expandedSections.punches } }));

    return (
        <div className="cy-punchcard" aria-busy={attendanceState.loading || undefined}>
            {/* Who and when */}
            <div className="cy-punchcard__head">
                <span className="cy-punchcard__avatar">
                    <CyAvatar name={user?.name} photo={user?.profile_image_url || user?.profile_image} />
                    <i className="cy-punchcard__presence" data-tone={cyTone(statusConfig.color)} aria-hidden="true" />
                </span>
                <span className="cy-punchcard__who">
                    <span className="cy-punchcard__name">{user?.name}</span>
                    <span className="cy-punchcard__id">ID {user?.employee_id || user?.id}</span>
                </span>
                <span className="cy-punchcard__clock" aria-live="off">
                    <span className="cy-punchcard__time">{systemState.currentTime.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: true })}</span>
                    <span className="cy-punchcard__date">{systemState.currentTime.toLocaleDateString('en-US', { weekday: 'short', day: 'numeric', month: 'short' })}</span>
                </span>
            </div>

            {/* Today's figures (Cyber .row-grid tiles) */}
            <div className="dl-tiles">
                <StatTile label="Hours today" value={attendanceState.realtimeWorkTime} tone="theme" />
                <StatTile label="Sessions" value={workStats.sessionsToday} />
            </div>

            <div className="cy-punchcard__body">
                <div className="cy-punchcard__status" role="status">
                    <CyBadge color={cyTone(statusConfig.color)}><Icon name={statusIcon} /> {statusConfig.text}</CyBadge>
                </div>

                <CyButton
                    className="cy-punchcard__action"
                    color="theme"
                    variant={isPunchActionDisabled ? 'outline' : 'solid'}
                    disabled={isPunchActionDisabled}
                    onClick={handlePunch}
                >
                    {isBiometricUser
                        ? <><Icon name="fingerprint" /> {assignedDeviceName ? `Use ${assignedDeviceName}` : 'Use biometric device'}</>
                        : attendanceState.loading
                            ? <><Icon name="arrow-repeat" className="cy-spin" /> Processing…</>
                            : <><Icon name={actionIcon} /> {statusConfig.action}</>}
                </CyButton>

                {isBiometricUser && (
                    <div className="cy-alert" data-tone="theme">
                        <Icon name="fingerprint" />
                        <span><strong>{assignedDeviceName ?? 'Biometric device'}</strong>{assignedDeviceName ? 'Use this device to punch in and out.' : 'No device assigned yet: contact HR.'}</span>
                    </div>
                )}

                {requiresQrCode && (
                    <label className="cy-punchcard__field">
                        <span>Attendance QR code</span>
                        <input className="cy-input" placeholder="Scan or enter the QR code" value={qrCodeValue} onChange={(e) => setQrCodeValue(e.target.value)} />
                    </label>
                )}

                <ul className="cy-punchcard__checks" aria-label="Punch requirements">
                    {checks.map((c) => (
                        <li key={c.key}>
                            {c.onClick
                                ? <button type="button" className={`cy-badge cy-badge--outline-${c.tone}`} title={c.title} onClick={c.onClick}><Icon name={c.busy ? 'arrow-repeat' : c.icon} className={c.busy ? 'cy-spin' : undefined} /> {c.text}</button>
                                : <CyBadge color={c.tone} outline title={c.title}><Icon name={c.icon} /> {c.text}</CyBadge>}
                        </li>
                    ))}
                </ul>

                {requiresLocationForPunch && locationState.error && locationState.status !== GPS_STATUS.ACTIVE && (
                    <div className="cy-alert" data-tone="danger" role="alert">
                        <Icon name="exclamation-triangle" />
                        <span>{locationState.error}</span>
                    </div>
                )}

                {attendanceState.userOnLeave && (
                    <div className="cy-alert" data-tone="warning">
                        <Icon name="calendar-event" />
                        <span>
                            <strong>On {attendanceState.userOnLeave.leave_type} leave</strong>
                            {new Date(attendanceState.userOnLeave.from_date).toLocaleDateString()} to {new Date(attendanceState.userOnLeave.to_date).toLocaleDateString()}
                        </span>
                    </div>
                )}
            </div>

            {/* Today's activity */}
            <div className="cy-punchcard__activity">
                <button type="button" className="cy-punchcard__toggle" aria-expanded={!!uiState.expandedSections.punches} onClick={toggleActivity}>
                    <span><Icon name="calendar-check" /> Today's activity</span>
                    <span className="cy-punchcard__meta">{workStats.sessionsToday} sessions · {attendanceState.realtimeWorkTime} <Icon name="chevron-right" className="cy-punchcard__chev" /></span>
                </button>
                {uiState.expandedSections.punches && (
                    attendanceState.todayPunches.length > 0 ? (
                        <ul className="dl-list cy-punchcard__sessions">
                            {attendanceState.todayPunches.map((punch, index) => (
                                <li key={punch.id ?? punch.punchin_time ?? index} className="dl-list__row">
                                    <div className="cy-punchcard__session">
                                        <span className="cy-punchcard__leg" data-kind="in">
                                            <span className="cy-punchcard__leg-label">Check in</span>
                                            <span className="cy-punchcard__leg-time">{formatTime(punch.punchin_time)}</span>
                                            <span className="cy-punchcard__leg-place"><Icon name="geo-alt" /> {formatLocation(punch.punchin_location)}</span>
                                        </span>
                                        {punch.punchout_time ? (
                                            <span className="cy-punchcard__leg" data-kind="out">
                                                <span className="cy-punchcard__leg-label">Check out</span>
                                                <span className="cy-punchcard__leg-time">{formatTime(punch.punchout_time)}</span>
                                                <span className="cy-punchcard__leg-place"><Icon name="geo-alt" /> {formatLocation(punch.punchout_location)}</span>
                                            </span>
                                        ) : (
                                            <span className="cy-punchcard__leg" data-kind="active">
                                                <span className="cy-punchcard__leg-label">Active</span>
                                                <span className="cy-punchcard__leg-time">--:--</span>
                                                <span className="cy-punchcard__leg-place"><Icon name="clock" /> In progress</span>
                                            </span>
                                        )}
                                        {punch.duration && <CyBadge color="info" outline className="cy-punchcard__duration">{punch.duration}</CyBadge>}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <div className="dl-empty"><span className="cy-row__sub">No activity recorded today.</span></div>
                    )
                )}
            </div>

            {/* Attendance recorded (Cyber modal) */}
            {uiState.sessionDialogOpen && (
                <CyDialog title="Attendance recorded" onClose={() => setUiState(prev => ({ ...prev, sessionDialogOpen: false }))}>
                    <div className="cy-modal__section">
                        <div className="cy-alert" data-tone="success"><Icon name="check2-circle" /><span>Your attendance has been captured.</span></div>
                    </div>
                    <div className="dl-tiles cy-modal__tiles">
                        <StatTile label="IP address" value={systemState.sessionInfo.ip} />
                        <StatTile label="GPS accuracy" value={systemState.sessionInfo.accuracy} />
                    </div>
                    <div className="cy-modal__section"><span className="cy-row__sub"><Icon name="clock" /> Recorded at {systemState.sessionInfo.timestamp}</span></div>
                    <div className="cy-modal__foot">
                        <CyButton onClick={() => setUiState(prev => ({ ...prev, sessionDialogOpen: false }))}>Continue</CyButton>
                    </div>
                </CyDialog>
            )}

            {/* Photo capture (Cyber modal) */}
            {cameraState.isOpen && (
                <CyDialog title="Capture attendance photo" onClose={stopCamera} className="cy-modal cy-modal--wide">
                    <div className="cy-modal__section">
                        <span className="cy-row__sub">Take a photo for verification. Location coordinates are added automatically.</span>
                    </div>
                    <div className="cy-camera">
                        {cameraState.capturedPhoto ? (
                            <img src={cameraState.capturedPhoto} alt="Captured attendance photo" className="cy-camera__media" />
                        ) : (
                            <>
                                <video ref={videoRef} autoPlay playsInline muted className="cy-camera__media" style={{ transform: cameraState.facingMode === 'user' ? 'scaleX(-1)' : 'none' }} />
                                <button type="button" className="cy-camera__switch" onClick={switchCamera} disabled={!cameraState.stream || cameraState.isSwitching} aria-label="Switch camera">
                                    <Icon name="arrow-repeat" className={cameraState.isSwitching ? 'cy-spin' : undefined} />
                                </button>
                                <span className="cy-camera__facing">{cameraState.facingMode === 'user' ? 'Front camera' : 'Back camera'}</span>
                            </>
                        )}
                        <canvas ref={canvasRef} style={{ display: 'none' }} />
                        {locationState.coordinates && (
                            <span className="cy-camera__coords"><Icon name="geo-alt" /> {locationState.coordinates.latitude.toFixed(6)}, {locationState.coordinates.longitude.toFixed(6)}</span>
                        )}
                    </div>
                    <div className="cy-modal__section">
                        <div className="cy-alert" data-tone="theme">
                            <Icon name="camera-video" />
                            <span>{cameraState.capturedPhoto ? 'Review your photo. You can retake it if needed.' : 'Position yourself clearly in the frame, then capture the photo.'}</span>
                        </div>
                    </div>
                    <div className="cy-modal__foot">
                        <CyButton variant="outline" color="secondary" onClick={stopCamera}>Cancel</CyButton>
                        {cameraState.capturedPhoto ? (
                            <>
                                <CyButton variant="outline" onClick={retakePhoto}><Icon name="arrow-repeat" /> Retake</CyButton>
                                <CyButton disabled={attendanceState.loading} onClick={confirmPhotoAndPunch}>
                                    {attendanceState.loading
                                        ? <><Icon name="arrow-repeat" className="cy-spin" /> Processing…</>
                                        : <><Icon name="check2-circle" /> Confirm and {statusConfig.action.toLowerCase()}</>}
                                </CyButton>
                            </>
                        ) : (
                            <CyButton disabled={!cameraState.stream} onClick={capturePhoto}>
                                {cameraState.isCapturing
                                    ? <><Icon name="arrow-repeat" className="cy-spin" /> Capturing…</>
                                    : <><Icon name="camera-video" /> Capture photo</>}
                            </CyButton>
                        )}
                    </div>
                </CyDialog>
            )}
        </div>
    );
});

PunchStatusCard.displayName = 'PunchStatusCard';

export default PunchStatusCard;

/**
 * =========================
 * IMPLEMENTATION NOTES v3.0.0
 * =========================
 * 
 * This completely redesigned PunchStatusCard implements the requested simplified location management:
 * 
 * 1. **Simplified Location Management**:
 *    - Single `getLocation()` function for all location requests
 *    - Clear GPS status states: CHECKING, ACTIVE, DENIED, INACTIVE
 *    - Unified permission checking with `checkLocationPermission()`
 *    - Simple retry mechanism with `requestLocationPermissionReset()`
 * 
 * 2. **Enhanced Data Freshness**:
 *    - Always fetches latest punch data with cache-busting timestamp
 *    - Auto-refresh every 30 seconds
 *    - Immediate refresh after successful punch
 *    - Window focus refresh for real-time updates
 * 
 * 3. **Improved UX**:
 *    - GPS chip shows clear status and is clickable when needed
 *    - Punch button disabled until GPS is active
 *    - Real-time work time calculation
 *    - Better error messaging
 * 
 * 4. **Enterprise Features**:
 *    - Device fingerprinting for security
 *    - Comprehensive error handling
 *    - Network status monitoring
 *    - Leave status integration
 * 
 * 5. **Performance Optimizations**:
 *    - React.memo for preventing unnecessary re-renders
 *    - useCallback and useMemo for expensive operations
 *    - Debounced resize handlers
 *    - Efficient state management
 * 
 * 6. **Clean Code Practices**:
 *    - Clear separation of concerns
 *    - Modular state management
 *    - Comprehensive error handling
 *    - Professional styling with HeroUI theming
 * 
 * The component now provides a streamlined, enterprise-grade attendance tracking experience
 * with simplified location management and always-fresh data.
 */