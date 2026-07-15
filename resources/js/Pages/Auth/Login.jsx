import { Panel } from '@/Components/ui/Panel';
import React, { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { Badge, Box, Button, Callout, Checkbox, Flex, Heading, IconButton, Separator, Spinner, Text, TextField, Tooltip } from '@radix-ui/themes';
import {
    ArrowRightIcon, CheckCircledIcon, ClockIcon, Cross2Icon,
    DesktopIcon, EnvelopeClosedIcon, ExclamationTriangleIcon,
    EyeNoneIcon, EyeOpenIcon, GlobeIcon, LockClosedIcon, MobileIcon,
    LockOpen2Icon, InfoCircledIcon,
} from '@radix-ui/react-icons';
import { showToast } from '@/utils/toastUtils';
import { getDeviceHeaders, getDeviceLoginPayload } from '@/utils/deviceAuth';

// ── Constants ──────────────────────────────────────────────────────────────
const VALIDATION_CONFIG = {
    email: {
        maxLength: 254, // RFC 5321 limit
        pattern: /^[a-zA-Z0-9.!#$%&'*+/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/
    },
    password: {
        minLength: 6,
        maxLength: 128
    }
};

const ALERT_TIMEOUT = {
    success: 8000,
    error: 12000
};

// ===== UTILITY FUNCTIONS =====

/**
 * Validates email address according to enterprise standards
 * @param {string} email - Email to validate
 * @returns {object} Validation result
 */
const validateEmail = (email) => {
    if (!email || typeof email !== 'string') {
        return { isValid: false, message: 'Email is required' };
    }
    
    const trimmedEmail = email.trim();
    
    if (trimmedEmail.length === 0) {
        return { isValid: false, message: 'Email is required' };
    }
    
    if (trimmedEmail.length > VALIDATION_CONFIG.email.maxLength) {
        return { isValid: false, message: 'Email address is too long' };
    }
    
    if (!VALIDATION_CONFIG.email.pattern.test(trimmedEmail)) {
        return { isValid: false, message: 'Please enter a valid email address' };
    }
    
    return { isValid: true, message: null };
};

/**
 * Validates password according to enterprise security policies
 * @param {string} password - Password to validate
 * @returns {object} Validation result
 */
const validatePassword = (password) => {
    if (!password || typeof password !== 'string') {
        return { isValid: false, message: 'Password is required' };
    }
    
    if (password.length < VALIDATION_CONFIG.password.minLength) {
        return { 
            isValid: false, 
            message: `Password must be at least ${VALIDATION_CONFIG.password.minLength} characters` 
        };
    }
    
    if (password.length > VALIDATION_CONFIG.password.maxLength) {
        return { isValid: false, message: 'Password is too long' };
    }
    
    return { isValid: true, message: null };
};

// ── SVG Background ─────────────────────────────────────────────────────────
function RadixBg() {
    return (
        <Box style={{ position: 'fixed', inset: 0, overflow: 'hidden', pointerEvents: 'none', zIndex: 0 }}>
            <svg width="100%" height="100%" viewBox="0 0 2560 1920" preserveAspectRatio="xMidYMid slice" fill="none" xmlns="http://www.w3.org/2000/svg" style={{ opacity: 0.55 }}>
                <g>
                    <path d="M-119.809 -1055.99L859.027 -684.98C915.435 -663.6 955.626 -624.994 968.519 -579.807L1129.49 -15.6245L1860.47 -241.727C1919.02 -259.836 1985.68 -257.939 2042.09 -236.559L3020.93 134.453C3124.79 173.822 3164.97 266.777 3110.66 342.073L2850.06 703.385C2827.36 734.857 2790.34 759.666 2745.28 773.604L1467.45 1168.86L1748.58 2154.16C1758.67 2189.52 1751.28 2226.32 1727.72 2258.12L1361.75 2752.01L203.258 2312.91C146.85 2291.53 106.659 2252.92 93.7664 2207.73L-67.2076 1643.55L-798.184 1869.65C-856.73 1887.76 -923.398 1885.87 -979.806 1864.48L-2138.3 1425.38L-1787.63 925.687C-1765.05 893.507 -1727.57 868.111 -1681.77 853.942L-405.167 459.07L-686.568 -527.183C-696.491 -561.961 -689.511 -598.157 -666.811 -629.629L-406.21 -990.941C-351.902 -1066.24 -223.676 -1095.36 -119.809 -1055.99Z" fill="url(#lb0)"/>
                    <path d="M885.9 -99.2158L1864.74 271.796C1921.14 293.177 1961.34 331.783 1974.23 376.97L2135.2 941.152L2866.18 715.049C2924.72 696.94 2991.39 698.837 3047.8 720.218L4026.64 1091.23C4130.5 1130.6 4170.68 1223.55 4116.37 1298.85L3855.77 1660.16C3833.07 1691.63 3796.05 1716.44 3750.99 1730.38L2473.16 2125.63L2754.29 3110.94C2764.38 3146.29 2756.99 3183.09 2733.43 3214.9L2367.46 3708.79L1208.97 3269.68C1152.56 3248.3 1112.37 3209.7 1099.48 3164.51C816.824 2173.87 747.087 1929.46 319.141 429.593C309.218 394.815 316.198 358.619 338.898 327.147L599.499 -34.1647C653.807 -109.461 782.033 -138.585 885.9 -99.2158Z" fill="url(#lb1)"/>
                    <path d="M1597.13 169.784L2575.97 540.796C2632.38 562.177 2672.57 600.783 2685.46 645.97L2846.44 1210.15L3577.41 984.049C3635.96 965.94 3702.63 967.837 3759.03 989.218L4737.87 1360.23C4841.74 1399.6 4881.91 1492.55 4827.61 1567.85L4567 1929.16C4544.3 1960.63 4507.28 1985.44 4462.22 1999.38L3184.4 2394.63L3465.53 3379.94C3475.61 3415.29 3468.23 3452.09 3444.66 3483.9L3078.69 3977.79L1920.2 3538.68C1863.79 3517.3 1823.6 3478.7 1810.71 3433.51L1649.74 2869.33L918.759 3095.43C860.213 3113.54 793.545 3111.64 737.138 3090.26L-421.356 2651.15L-70.6875 2151.46C-48.1049 2119.28 -10.63 2093.89 35.1782 2079.72L1311.78 1684.85L1030.38 698.593C1020.45 663.815 1027.43 627.619 1050.13 596.147L1310.73 234.835C1365.04 159.539 1493.27 130.415 1597.13 169.784Z" fill="url(#lb2)"/>
                    <path d="M3059.26 767.932L3310.25 1618.16C3324.72 1667.15 3315.74 1727.88 3285.79 1783.6L2911.89 2479.3L3514.51 2558.36C3562.77 2564.69 3599.15 2596.78 3613.62 2645.77L3864.61 3496C3891.25 3586.22 3837.41 3706.98 3744.37 3765.74L3297.91 4047.66C3259.03 4072.22 3217.48 4082.97 3180.34 4078.1L2126.89 3939.89L1473.9 5154.88C1450.47 5198.48 1415.9 5235.81 1376.24 5260.35L760.412 5641.34L463.348 4635.06C448.884 4586.06 457.863 4525.33 487.81 4469.61L861.713 3773.92L259.094 3694.86C210.828 3688.53 174.448 3656.44 159.984 3607.44L-137.08 2601.17L474.823 2206.89C514.228 2181.5 556.514 2170.3 594.278 2175.25L1646.71 2313.32L2300.33 1097.17C2323.38 1054.28 2357.22 1017.43 2396.11 992.876L2842.57 710.953C2935.61 652.202 3032.62 677.712 3059.26 767.932Z" fill="url(#lb3)"/>
                </g>
                <defs>
                    <radialGradient id="lb0" cx="0" cy="0" r="1" gradientUnits="userSpaceOnUse" gradientTransform="translate(-804.109 -2036.8) rotate(64.9401) scale(6436.87 6304.81)">
                        <stop stopColor="var(--color-background)"/><stop offset="0.0833333" stopColor="var(--accent-7)"/><stop offset="0.364583" stopColor="var(--accent-5)"/><stop offset="0.658041" stopColor="var(--color-background)"/><stop offset="0.798521" stopColor="var(--accent-9)"/><stop offset="1" stopColor="var(--color-background)"/>
                    </radialGradient>
                    <radialGradient id="lb1" cx="0" cy="0" r="1" gradientUnits="userSpaceOnUse" gradientTransform="translate(201.6 -1080.02) rotate(64.9401) scale(6436.87 6304.81)">
                        <stop stopColor="var(--color-background)"/><stop offset="0.0833333" stopColor="var(--accent-2)"/><stop offset="0.333803" stopColor="var(--accent-1)"/><stop offset="0.658041" stopColor="var(--color-background)"/><stop offset="0.798521" stopColor="var(--accent-9)"/><stop offset="1" stopColor="var(--color-background)"/>
                    </radialGradient>
                    <radialGradient id="lb2" cx="0" cy="0" r="1" gradientUnits="userSpaceOnUse" gradientTransform="translate(912.834 -811.021) rotate(64.9401) scale(6436.87 6304.81)">
                        <stop stopColor="var(--color-background)"/><stop offset="0.140625" stopColor="var(--accent-3)"/><stop offset="0.333803" stopColor="var(--accent-7)"/><stop offset="0.658041" stopColor="var(--color-background)"/><stop offset="0.798521" stopColor="var(--accent-9)"/><stop offset="1" stopColor="var(--color-background)"/>
                    </radialGradient>
                    <radialGradient id="lb3" cx="0" cy="0" r="1" gradientUnits="userSpaceOnUse" gradientTransform="translate(3479.06 -623.459) rotate(113.028) scale(8332.26 4870.62)">
                        <stop stopColor="var(--color-background)"/><stop offset="0.0833333" stopColor="var(--accent-7)"/><stop offset="0.333803" stopColor="var(--accent-1)"/><stop offset="0.658041" stopColor="var(--color-background)"/><stop offset="0.798521" stopColor="var(--accent-9)"/><stop offset="1" stopColor="var(--color-background)"/>
                    </radialGradient>
                </defs>
            </svg>
        </Box>
    );
}

function FormField({ label, error, children }) {
    return (
        <Flex direction="column" gap="1">
            <Text as="label" size="2" weight="medium">{label}</Text>
            {children}
            {error && <Text size="1" color="red">{error}</Text>}
        </Flex>
    );
}

export default function Login({
    status,
    canResetPassword,
    deviceBlocked,
    deviceMessage,
    blockedDeviceInfo
}) {

    // ===== REFS FOR FORM MANAGEMENT =====
    const emailInputRef = useRef(null);
    const passwordInputRef = useRef(null);
    const submitTimeoutRef = useRef(null);

    // ===== CORE FORM STATE =====
    const [formData, setFormData] = useState({
        email: '',
        password: '',
        remember: false
    });

    // ===== UI STATE =====
    const [uiState, setUiState] = useState({
        isPasswordVisible: false,
        isSubmitting: false,
        isLoaded: false,
        showSuccessAlert: !!status,
        showDeviceAlert: !!deviceBlocked,
        deviceBlockingData: null
    });

    // ===== VALIDATION STATE =====
    const [validationErrors, setValidationErrors] = useState({
        email: null,
        password: null,
        hasAttemptedSubmit: false
    });

    // ===== MEMOIZED VALIDATION RESULTS =====
    const validationResults = useMemo(() => {
        const emailValidation = validateEmail(formData.email);
        const passwordValidation = validatePassword(formData.password);
        
        return {
            email: emailValidation,
            password: passwordValidation,
            isFormValid: emailValidation.isValid && passwordValidation.isValid && 
                        formData.email.trim() !== '' && formData.password !== ''
        };
    }, [formData.email, formData.password]);

    // ===== STABLE EVENT HANDLERS =====
    
    /**
     * Updates form field values with validation clearing
     * Separated from other handlers to prevent circular dependencies
     */
    const updateFormField = useCallback((fieldName, value) => {
        // Update form data
        setFormData(prevData => ({
            ...prevData,
            [fieldName]: value
        }));

        // Clear validation errors when user starts typing
        if (validationErrors.hasAttemptedSubmit && validationErrors[fieldName]) {
            setValidationErrors(prevErrors => ({
                ...prevErrors,
                [fieldName]: null
            }));
        }
    }, [validationErrors.hasAttemptedSubmit]); // Only depend on hasAttemptedSubmit flag

    /**
     * Toggles password visibility
     */
    const togglePasswordVisibility = useCallback(() => {
        setUiState(prevState => ({
            ...prevState,
            isPasswordVisible: !prevState.isPasswordVisible
        }));
    }, []);

    /**
     * Handles remember me checkbox
     */
    const handleRememberChange = useCallback((isSelected) => {
        setFormData(prevData => ({
            ...prevData,
            remember: isSelected
        }));
    }, []);

    /**
     * Dismisses alert notifications
     */
    const dismissAlert = useCallback((alertType) => {
        setUiState(prevState => ({
            ...prevState,
            [`show${alertType}Alert`]: false
        }));
    }, []);

    /**
     * Focuses first invalid field for better UX
     */
    const focusFirstInvalidField = useCallback(() => {
        if (!validationResults.email.isValid && emailInputRef.current) {
            emailInputRef.current.focus();
        } else if (!validationResults.password.isValid && passwordInputRef.current) {
            passwordInputRef.current.focus();
        }
    }, [validationResults.email.isValid, validationResults.password.isValid]);

    /**
     * Main form submission handler
     * Isolated to prevent circular dependencies
     */
    const handleFormSubmit = useCallback(async (e) => {
        e.preventDefault();
        
        // Clear any existing timeouts
        if (submitTimeoutRef.current) {
            clearTimeout(submitTimeoutRef.current);
        }

        // Prevent double submission
        if (uiState.isSubmitting) {
            return;
        }

        // Mark submission attempt for validation feedback
        setValidationErrors(prevErrors => ({
            ...prevErrors,
            hasAttemptedSubmit: true
        }));

        // Validate form
        if (!validationResults.isFormValid) {
            setValidationErrors(prevErrors => ({
                ...prevErrors,
                email: validationResults.email.message,
                password: validationResults.password.message
            }));
            
            // Focus first invalid field after state update
            setTimeout(focusFirstInvalidField, 0);
            return;
        }

        // Set submitting state
        setUiState(prevState => ({
            ...prevState,
            isSubmitting: true
        }));

        try {
            // Prepare submission data with unified device payload.
            const deviceLoginPayload = getDeviceLoginPayload();
            const submissionData = {
                email: formData.email.trim(),
                password: formData.password,
                remember: formData.remember,
                ...deviceLoginPayload,
            };
            
            // Add device headers
            const deviceHeaders = getDeviceHeaders();

            // Submit using Inertia router
            router.post(route('login'), submissionData, {
                preserveState: true,
                preserveScroll: true,
                headers: {
                    ...deviceHeaders
                },
                
                onError: (errors) => {
                    console.error('Login validation errors:', errors);
                    
                    // Handle device blocking errors
                    if (errors.device_blocking) {
                       
                        
                        setUiState(prevState => {
                            const newState = {
                                ...prevState,
                                showDeviceAlert: true,
                                deviceBlockingData: {
                                    message: errors.device_blocking.device_message || 'Login blocked: Account is active on another device',
                                    blockedDeviceInfo: errors.device_blocking.blocked_device_info || null
                                }
                            };
                          
                            return newState;
                        });
                        
                        // Don't clear password for device blocking
                        setUiState(prevState => ({
                            ...prevState,
                            isSubmitting: false
                        }));
                        
                        return;
                    }
                    
                    // Handle regular server validation errors
                    const newErrors = { ...validationErrors };
                    
                    if (errors.email) {
                        newErrors.email = errors.email;
                    }
                    if (errors.password) {
                        newErrors.password = errors.password;
                    }
                    
                    setValidationErrors(newErrors);

                    // Show error toasts for non-field-specific errors
                    Object.entries(errors).forEach(([key, error]) => {
                        if (key !== 'email' && key !== 'password' && key !== 'device_blocked' && key !== 'device_blocked_data' && typeof error === 'string') {
                            showToast.error(error, {
                                style: {
                                    backdropFilter: 'blur(16px) saturate(200%)',
                                    background: 'var(--theme-danger)',
                                    color: 'var(--theme-danger-foreground)',
                                }
                            });
                        }
                    });
                    
                    // Clean up submission state for regular errors
                    setUiState(prevState => ({
                        ...prevState,
                        isSubmitting: false
                    }));

                    // Clear password for security (except for device blocking)
                    setFormData(prevData => ({
                        ...prevData,
                        password: ''
                    }));
                },
                onFinish: (visit) => {
                    // Only clean up for successful submissions or non-device-blocking errors
                    // Device blocking is handled in onError
                    setUiState(prevState => ({
                        ...prevState,
                        isSubmitting: false
                    }));
                }
            });

        } catch (error) {
            console.error('Login submission error:', error);

            showToast.error('An unexpected error occurred. Please try again.', {
                style: {
                    background: 'var(--theme-danger)',
                    color: 'var(--theme-danger-foreground)',
                }
            });

            setUiState(prevState => ({
                ...prevState,
                isSubmitting: false
            }));
        }
    }, [
        uiState.isSubmitting, 
        validationResults.isFormValid, 
        formData, 
        validationResults.email.message, 
        validationResults.password.message,
        focusFirstInvalidField,
        validationErrors
    ]);

    // ===== EFFECTS =====
    useEffect(() => {
        setUiState(prevState => ({ ...prevState, isLoaded: true }));
    }, []);

    // Handle success status
    useEffect(() => {
        if (status) {
            setUiState(prevState => ({ ...prevState, showSuccessAlert: true }));
            
            
            const timer = setTimeout(() => {
                dismissAlert('Success');
            }, ALERT_TIMEOUT.success);
            
            return () => clearTimeout(timer);
        }
    }, [status, dismissAlert]);

    // Handle device blocking
    useEffect(() => {
        if (deviceBlocked) {
            setUiState(prevState => ({ ...prevState, showDeviceAlert: true }));
            showToast.error(deviceMessage || 'Device access blocked');
            
            const timer = setTimeout(() => {
                dismissAlert('Device');
            }, ALERT_TIMEOUT.error);
            
            return () => clearTimeout(timer);
        }
    }, [deviceBlocked, deviceMessage, dismissAlert]);

    // Initialize device alert state based on props
    useEffect(() => {
        if (deviceBlocked) {
            setUiState(prevState => ({ ...prevState, showDeviceAlert: true }));
        }
    }, [deviceBlocked]);

    // Cleanup on unmount
    useEffect(() => {
        return () => {
            if (submitTimeoutRef.current) {
                clearTimeout(submitTimeoutRef.current);
            }
        };
    }, []);

    // ===== RENDER =====
    const deviceInfo = uiState.deviceBlockingData?.blockedDeviceInfo || blockedDeviceInfo;
    const showDeviceAlert = (deviceBlocked || uiState.deviceBlockingData) && uiState.showDeviceAlert;

    return (
        <>
            <Head title="Sign In" />
            <RadixBg />

            <Box style={{
                minHeight: '100vh',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                padding: 16,
                position: 'relative',
                zIndex: 1,
                overflowX: 'hidden',
                opacity: uiState.isLoaded ? 1 : 0,
                transform: uiState.isLoaded ? 'translateY(0)' : 'translateY(16px)',
                transition: 'opacity 0.35s ease, transform 0.35s ease',
            }}>
                <Box style={{ width: '100%', maxWidth: 420 }}>
                    <Panel size="4">
                        {/* Logo + Title */}
                        <Flex direction="column" align="center" gap="2" mb="5">
                            <img src="/assets/images/logo.png" alt="Logo" style={{ width: 96, height: 96, objectFit: 'contain' }}
                                onError={e => { e.target.style.display = 'none'; }} />
                            <Heading size="6" align="center">Welcome back</Heading>
                            <Text size="2" color="gray" align="center">Sign in to your account</Text>
                        </Flex>

                        {/* Status alert */}
                        {status && uiState.showSuccessAlert && (
                            <Callout.Root color="green" mb="4">
                                <Callout.Icon><CheckCircledIcon /></Callout.Icon>
                                <Callout.Text>{status}</Callout.Text>
                            </Callout.Root>
                        )}

                        {/* Device blocking alert */}
                        {showDeviceAlert && (
                            <Callout.Root color="red" mb="4">
                                <Callout.Icon><ExclamationTriangleIcon /></Callout.Icon>
                                <Callout.Text>
                                    <Flex justify="between" align="start" gap="2">
                                        <Box>
                                            <Text size="2" weight="bold" style={{ display: 'block' }}>Device Access Blocked</Text>
                                            <Text size="2">{uiState.deviceBlockingData?.message || deviceMessage || 'Account is active on another device.'}</Text>
                                            {deviceInfo && (
                                                <Flex gap="2" align="center" mt="2">
                                                    {deviceInfo.device_type === 'mobile' ? <MobileIcon /> : <DesktopIcon />}
                                                    <Text size="1">{deviceInfo.device_name || 'Unknown Device'}</Text>
                                                    {deviceInfo.last_activity && <><ClockIcon /><Text size="1">{deviceInfo.last_activity}</Text></>}
                                                    {deviceInfo.ip_address && <><GlobeIcon /><Text size="1">{deviceInfo.ip_address}</Text></>}
                                                </Flex>
                                            )}
                                            <Text size="1" color="gray" mt="1" style={{ display: 'block' }}>Contact your administrator to reset device access.</Text>
                                        </Box>
                                        <IconButton size="1" variant="ghost" color="red" onClick={() => dismissAlert('Device')} aria-label="Dismiss">
                                            <Cross2Icon />
                                        </IconButton>
                                    </Flex>
                                </Callout.Text>
                            </Callout.Root>
                        )}

                        {/* Form */}
                        <form onSubmit={handleFormSubmit} noValidate>
                            <Flex direction="column" gap="4">
                                {/* Email */}
                                <FormField label="Email address" error={validationErrors.email || (validationErrors.hasAttemptedSubmit && validationResults.email.message)}>
                                    <TextField.Root
                                        ref={emailInputRef}
                                        type="email"
                                        placeholder="your@email.com"
                                        value={formData.email}
                                        onChange={e => updateFormField('email', e.target.value)}
                                        autoComplete="username"
                                        autoFocus
                                        size="3"
                                        color={(validationErrors.email || (validationErrors.hasAttemptedSubmit && !validationResults.email.isValid)) ? 'red' : undefined}
                                    >
                                        <TextField.Slot><EnvelopeClosedIcon /></TextField.Slot>
                                    </TextField.Root>
                                </FormField>

                                {/* Password */}
                                <FormField label="Password" error={validationErrors.password || (validationErrors.hasAttemptedSubmit && validationResults.password.message)}>
                                    <TextField.Root
                                        ref={passwordInputRef}
                                        type={uiState.isPasswordVisible ? 'text' : 'password'}
                                        placeholder="••••••••"
                                        value={formData.password}
                                        onChange={e => updateFormField('password', e.target.value)}
                                        autoComplete="current-password"
                                        size="3"
                                        color={(validationErrors.password || (validationErrors.hasAttemptedSubmit && !validationResults.password.isValid)) ? 'red' : undefined}
                                    >
                                        <TextField.Slot><LockClosedIcon /></TextField.Slot>
                                        <TextField.Slot>
                                            <Tooltip content={uiState.isPasswordVisible ? 'Hide password' : 'Show password'}>
                                                <IconButton size="1" variant="ghost" type="button" onClick={togglePasswordVisibility} aria-label="Toggle password">
                                                    {uiState.isPasswordVisible ? <EyeNoneIcon /> : <EyeOpenIcon />}
                                                </IconButton>
                                            </Tooltip>
                                        </TextField.Slot>
                                    </TextField.Root>
                                </FormField>

                                {/* Remember + Forgot */}
                                <Flex justify="between" align="center">
                                    <Text as="label" size="2">
                                        <Flex gap="2" align="center">
                                            <Checkbox
                                                checked={formData.remember}
                                                onCheckedChange={handleRememberChange}
                                            />
                                            Remember me
                                        </Flex>
                                    </Text>
                                    {canResetPassword && (
                                        <Link href={route('password.request')}>
                                            <Text size="2" color="accent">Forgot password?</Text>
                                        </Link>
                                    )}
                                </Flex>

                                {/* Submit */}
                                <Button type="submit" size="3" disabled={uiState.isSubmitting} style={{ width: '100%' }}>
                                    {uiState.isSubmitting ? <><Spinner size="1" /> Signing in…</> : <><ArrowRightIcon /> Sign In</>}
                                </Button>
                            </Flex>
                        </form>

                        <Separator size="4" my="4" />
                         <Text size="1" color="gray" align="center" mt="4" style={{ display: 'block' }}>
                        © 2025 Emam Hosen. All rights reserved.
                    </Text>

                       

                       
                    </Panel>

                   
                </Box>
            </Box>
        </>
    );
}

