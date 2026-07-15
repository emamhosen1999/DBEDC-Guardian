import { Panel } from '@/Components/ui/Panel';
import React, { useState, useEffect } from 'react';
import {
    ShieldCheckIcon
} from '@heroicons/react/24/outline';

import { useRadixTheme } from '@/Contexts/RadixThemeContext';

import logo from '../../../public/assets/images/logo.png';

const AuthLayout = ({ children, title, subtitle }) => {
    const [isDesktop, setIsDesktop] = useState(false);
    const { settings: themeSettings } = useRadixTheme();

    // Check if screen is desktop for showing floating elements
    useEffect(() => {
        const checkScreenSize = () => {
            setIsDesktop(window.innerWidth > 768);
        };
        
        checkScreenSize();
        window.addEventListener('resize', checkScreenSize);
        
        return () => window.removeEventListener('resize', checkScreenSize);
    }, []);

    return (
        <div 
            className="min-h-screen flex items-center justify-center p-1 sm:p-2 relative overflow-hidden"
            style={{
                fontFamily: `var(--fontFamily, 'Inter')`,
                transform: `scale(var(--scale, 1))`,
                transformOrigin: 'center center',
                // Use transparent background to allow the global theme background to show through
                // The global theme background is applied to document.body by the theme system
                backgroundColor: 'transparent',
                background: 'transparent'
            }}
        >
            {/* Floating Background Elements - Responsive positioning */}
            {isDesktop && (
                <>
                    <div
                        className="w-12 h-12 rounded-full"
                        style={{
                            position: 'absolute',
                            top: '10%',
                            left: '8%',
                            background: `linear-gradient(135deg,
                                color-mix(in srgb, var(--theme-primary, #006FEE) 15%, transparent),
                                color-mix(in srgb, var(--theme-secondary, #7C3AED) 15%, transparent)
                            )`,
                            backdropFilter: 'blur(8px)',
                            borderRadius: `var(--borderRadius, 50%)`,
                            border: `var(--borderWidth, 1px) solid color-mix(in srgb, var(--theme-primary, #006FEE) 20%, transparent)`,
                            transform: `scale(var(--scale, 1))`
                        }}
                    />
                    <div
                        className="w-8 h-8 rounded-full"
                        style={{
                            position: 'absolute',
                            bottom: '15%',
                            right: '10%',
                            background: `linear-gradient(135deg,
                                color-mix(in srgb, var(--theme-secondary, #7C3AED) 20%, transparent),
                                color-mix(in srgb, var(--theme-primary, #006FEE) 20%, transparent)
                            )`,
                            backdropFilter: 'blur(6px)',
                            borderRadius: `var(--borderRadius, 50%)`,
                            border: `var(--borderWidth, 1px) solid color-mix(in srgb, var(--theme-secondary, #7C3AED) 20%, transparent)`,
                            transform: `scale(var(--scale, 1))`
                        }}
                    />
                </>
            )}

            <div className="w-full max-w-md px-1 sm:px-2">
                <div className="flex flex-col items-center justify-center min-h-screen sm:min-h-[80vh] py-2 sm:py-4">
                    {/* Auth Form Card */}
                    <div
                        className="w-full max-w-[420px]"
                    >
                        <Panel 
                            className="p-3 sm:p-4 md:p-6 relative overflow-visible w-full rounded-2xl sm:rounded-3xl"
                            style={{
                                borderRadius: `var(--borderRadius, 24px)`,
                                borderWidth: `var(--borderWidth, 2px)`,
                                borderStyle: 'solid',
                                borderColor: 'color-mix(in srgb, var(--theme-divider, #E4E4E7) 50%, transparent)',
                                fontFamily: `var(--fontFamily, 'Inter')`,
                                transform: `scale(var(--scale, 1))`,
                                background: `linear-gradient(to bottom right, 
                                    color-mix(in srgb, var(--theme-content1, #FAFAFA) 98%, transparent), 
                                    color-mix(in srgb, var(--theme-content2, #F4F4F5) 95%, transparent)
                                )`,
                                backdropFilter: 'blur(20px) saturate(200%)',
                                boxShadow: `
                                    0 20px 40px color-mix(in srgb, var(--theme-shadow, #000000) 10%, transparent),
                                    0 8px 16px color-mix(in srgb, var(--theme-shadow, #000000) 8%, transparent),
                                    inset 0 1px 0 color-mix(in srgb, var(--theme-background, #FFFFFF) 30%, transparent)
                                `
                            }}
                        >
                            {/* Logo at top of form card */}
                            <div
                                className="text-center mb-3 sm:mb-4"
                            >
                                <div className="flex justify-center mb-2 sm:mb-3">
                                    <div
                                        className="inline-flex items-center justify-center rounded-xl"
                                    >
                                        <img
                                            src={logo}
                                            alt="Logo"
                                            className="w-40 h-40 object-contain"
                                            onError={(e) => {
                                                // Fallback to text logo if image fails to load
                                                e.target.style.display = 'none';
                                                e.target.nextSibling.style.display = 'block';
                                            }}
                                        />
                                    </div>
                                </div>
                            </div>

                            {/* Header */}
                            <div className="mb-3 sm:mb-4 text-center">
                                <div>
                                    <h1
                                        className="text-2xl sm:text-3xl md:text-4xl font-semibold mb-1 sm:mb-2"
                                        style={{
                                            background: `linear-gradient(135deg,
                                                var(--theme-foreground, #11181C),
                                                color-mix(in srgb, var(--theme-foreground, #11181C) 80%, var(--theme-primary, #006FEE))
                                            )`,
                                            backgroundClip: 'text',
                                            WebkitBackgroundClip: 'text',
                                            WebkitTextFillColor: 'transparent',
                                            fontFamily: `var(--fontFamily, 'Inter')`
                                        }}
                                    >
                                        {title}
                                    </h1>
                                    {subtitle && (
                                        <p
                                            className="text-foreground-600 text-sm sm:text-base leading-relaxed px-1 sm:px-0"
                                            style={{
                                                color: 'color-mix(in srgb, var(--theme-foreground, #11181C) 70%, transparent)',
                                                fontFamily: `var(--fontFamily, 'Inter')`
                                            }}
                                        >
                                            {subtitle}
                                        </p>
                                    )}
                                </div>
                            </div>

                            {/* Form Content */}
                            <div>
                                {children}
                            </div>

                            {/* Decorative Elements - Minimized */}
                            <div
                                className="absolute -top-1 -right-1 w-3 h-3 rounded-full opacity-40"
                                style={{
                                    background: `linear-gradient(135deg,
                                        var(--theme-primary, #006FEE),
                                        var(--theme-secondary, #7C3AED)
                                    )`,
                                    borderRadius: `var(--borderRadius, 50%)`,
                                    transform: `scale(var(--scale, 1))`
                                }}
                            />
                            <div
                                className="absolute -bottom-1 -left-1 w-2 h-2 rounded-full opacity-40"
                                style={{
                                    background: `linear-gradient(135deg,
                                        var(--theme-secondary, #7C3AED),
                                        var(--theme-primary, #006FEE)
                                    )`,
                                    borderRadius: `var(--borderRadius, 50%)`,
                                    transform: `scale(var(--scale, 1))`
                                }}
                            />
                        </Panel>
                    </div>
                </div>
            </div>
        </div>
    );
};

export default AuthLayout;
