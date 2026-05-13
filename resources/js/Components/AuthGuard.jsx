import React, { useEffect, useState, useRef } from 'react';
import { router } from '@inertiajs/react';

/**
 * Global Authentication Guard
 * 
 * This component ensures that no authenticated content is rendered
 * when the user is not authenticated or session has expired.
 * It provides a seamless loading experience while checking auth status.
 */
const AuthGuard = ({ children, auth, url }) => {
    const [isCheckingAuth, setIsCheckingAuth] = useState(true);
    const [isAuthenticated, setIsAuthenticated] = useState(false);
    const hasInitialized = useRef(false);

    // List of routes that don't require authentication
    const publicRoutes = [
        '/login',
        '/register', 
        '/forgot-password',
        '/reset-password',
        '/verify-email'
    ];

    // Check if current route is public
    const isPublicRoute = publicRoutes.some(route => 
        url === route || url.startsWith(route + '/')
    );

    useEffect(() => {
        const checkAuthStatus = async () => {
            try {
                // If it's a public route, skip auth check
                if (isPublicRoute) {
                    setIsAuthenticated(true);
                    setIsCheckingAuth(false);
                    hasInitialized.current = true;
                    return;
                }

                // CRITICAL: For protected routes, verify auth immediately
                // Do NOT render until we confirm authentication
                
                // Check server-provided auth status first - this is most reliable
                if (auth?.isAuthenticated && auth?.sessionValid && auth?.user?.id) {
                    // Server says user is authenticated, trust it immediately
                    setIsAuthenticated(true);
                    setIsCheckingAuth(false);
                    hasInitialized.current = true;
                    return;
                }

                // If no valid auth data from server, redirect immediately
                // Do NOT do async checks that would show protected content
                console.warn('No valid authentication data from server, redirecting to login');
                router.visit('/login', {
                    method: 'get',
                    preserveState: false,
                    preserveScroll: false,
                    replace: true
                });
                return;
            } catch (error) {
                console.error('Auth check failed:', error);
                router.visit('/login', {
                    method: 'get',
                    preserveState: false,
                    preserveScroll: false, 
                    replace: true
                });
                return;
            }

            setIsCheckingAuth(false);
        };

        checkAuthStatus();
    }, [auth?.user?.id, auth?.isAuthenticated, auth?.sessionValid, url, isPublicRoute]);

    // CRITICAL: Show loading screen for ALL auth checks on protected routes
    // This prevents flashing authenticated content to unauthenticated users
    if (isCheckingAuth && !isPublicRoute) {
        return (
            <div style={{ position: 'fixed', inset: 0, zIndex: 9999, display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'var(--gray-12)' }}>
                <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 24, textAlign: 'center' }}>
                    <div style={{ fontSize: 28, fontWeight: 700, color: 'white' }}>DBEDC Guardian</div>
                    <div style={{ width: 48, height: 48, borderRadius: '50%', border: '4px solid rgba(255,255,255,0.2)', borderTopColor: 'var(--accent-9)', animation: 'spin 0.75s linear infinite' }} />
                    <div style={{ color: 'rgba(255,255,255,0.8)', fontSize: 16 }}>Verifying session...</div>
                    <div style={{ width: 200, height: 3, background: 'rgba(255,255,255,0.15)', borderRadius: 99 }}>
                        <div style={{ width: '60%', height: '100%', background: 'var(--accent-9)', borderRadius: 99 }} />
                    </div>
                </div>
            </div>
        );
    }

    // If authenticated or public route, render children
    if (isAuthenticated || isPublicRoute) {
        return children;
    }

    // Fallback loading state (should rarely be seen)
    return (
        <div className="fixed inset-0 z-9999 flex items-center justify-center bg-slate-900">
            <div className="w-12 h-12 rounded-full border-4 border-blue-200 border-t-blue-500 animate-spin" />
        </div>
    );
};

export default AuthGuard;
