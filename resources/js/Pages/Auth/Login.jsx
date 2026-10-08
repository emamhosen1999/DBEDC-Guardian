import React, { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { Box, Button, Callout, Checkbox, Flex, IconButton, Spinner, Text, TextField, Tooltip } from '@radix-ui/themes';
import {
    CheckCircledIcon, ClockIcon, Cross2Icon,
    DesktopIcon, ExclamationTriangleIcon,
    EyeNoneIcon, EyeOpenIcon, GlobeIcon, MobileIcon,
} from '@radix-ui/react-icons';
import { showToast } from '@/utils/toastUtils';
import { getDeviceHeaders, getDeviceLoginPayload } from '@/utils/deviceAuth';
import AuthLayout, { AuthField } from '@/Components/AuthLayout';

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
    const emailError = validationErrors.email || (validationErrors.hasAttemptedSubmit && validationResults.email.message);
    const passwordError = validationErrors.password || (validationErrors.hasAttemptedSubmit && validationResults.password.message);

    return (
        <>
            <Head title="Sign In" />

            <AuthLayout
                title="Welcome back"
                subtitle="Sign in to your account"
                style={{
                    opacity: uiState.isLoaded ? 1 : 0,
                    transform: uiState.isLoaded ? 'translateY(0)' : 'translateY(16px)',
                    transition: 'opacity 0.35s ease, transform 0.35s ease',
                }}
            >
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
                                        <Flex gap="2" align="center" mt="2" wrap="wrap">
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
                    <AuthField id="login-email" label="Email address" required error={emailError}>
                        <TextField.Root
                            id="login-email"
                            ref={emailInputRef}
                            type="email"
                            placeholder="your@email.com"
                            value={formData.email}
                            onChange={e => updateFormField('email', e.target.value)}
                            autoComplete="username"
                            autoFocus
                            required
                            size="2"
                            aria-invalid={emailError ? true : undefined}
                            aria-describedby={emailError ? 'login-email-error' : undefined}
                            color={(validationErrors.email || (validationErrors.hasAttemptedSubmit && !validationResults.email.isValid)) ? 'red' : undefined}
                        />
                    </AuthField>

                    <AuthField
                        id="login-password"
                        label="Password"
                        required
                        error={passwordError}
                        aside={canResetPassword && (
                            <Link href={route('password.request')} className="dl-auth__link">Forgot password?</Link>
                        )}
                    >
                        <TextField.Root
                            id="login-password"
                            ref={passwordInputRef}
                            type={uiState.isPasswordVisible ? 'text' : 'password'}
                            placeholder="••••••••"
                            value={formData.password}
                            onChange={e => updateFormField('password', e.target.value)}
                            autoComplete="current-password"
                            required
                            size="2"
                            aria-invalid={passwordError ? true : undefined}
                            aria-describedby={passwordError ? 'login-password-error' : undefined}
                            color={(validationErrors.password || (validationErrors.hasAttemptedSubmit && !validationResults.password.isValid)) ? 'red' : undefined}
                        >
                            <TextField.Slot side="right">
                                <Tooltip content={uiState.isPasswordVisible ? 'Hide password' : 'Show password'}>
                                    <IconButton size="1" variant="ghost" color="gray" type="button" onClick={togglePasswordVisibility}
                                        aria-label={uiState.isPasswordVisible ? 'Hide password' : 'Show password'}>
                                        {uiState.isPasswordVisible ? <EyeNoneIcon /> : <EyeOpenIcon />}
                                    </IconButton>
                                </Tooltip>
                            </TextField.Slot>
                        </TextField.Root>
                    </AuthField>

                    {/* Remember me */}
                    <Text as="label" size="2" style={{ display: 'block', marginBottom: 14, textTransform: 'uppercase' }}>
                        <Flex gap="2" align="center">
                            <Checkbox
                                checked={formData.remember}
                                onCheckedChange={handleRememberChange}
                            />
                            Remember me
                        </Flex>
                    </Text>

                    {/* Submit */}
                    <Button type="submit" size="3" variant="outline" color="gray" className="dl-auth__submit" disabled={uiState.isSubmitting}>
                        {uiState.isSubmitting ? <><Spinner size="1" /> Signing in…</> : 'Sign In'}
                    </Button>
                </form>

                <p className="dl-auth__foot">© 2025 Emam Hosen. All rights reserved.</p>
            </AuthLayout>
        </>
    );
}

