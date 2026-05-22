import React, { forwardRef } from 'react';
import { clsx } from 'clsx';

const Button = forwardRef(({ 
    children, 
    as: Component = 'button',
    type = 'button',
    variant = 'primary',
    size = 'md',
    loading = false,
    disabled = false,
    className = '',
    icon: Icon,
    iconPosition = 'left',
    ...props 
}, ref) => {
    // Custom theme for consistent styling
    const glassTheme = {
        palette: {
            primary: { 
                main: '#3b82f6', 
                dark: '#2563eb',
                contrastText: '#ffffff' 
            },
            secondary: { 
                main: '#64748b', 
                dark: '#475569',
                contrastText: '#ffffff' 
            },
            error: { 
                main: '#ef4444', 
                dark: '#dc2626',
                contrastText: '#ffffff' 
            },
            success: { 
                main: '#10b981', 
                dark: '#059669',
                contrastText: '#ffffff' 
            },
            text: { 
                primary: '#ffffff' 
            }
        },
        mode: 'dark'
    };
    
    const baseStyles = 'inline-flex items-center justify-center font-medium rounded-xl transition-all duration-300 focus:outline-hidden disabled:opacity-50 disabled:cursor-not-allowed relative overflow-hidden';
    
    const getVariantStyles = () => {
        switch (variant) {
            case 'primary':
                return {
                    background: `linear-gradient(135deg, ${glassTheme.palette.primary.main}, ${glassTheme.palette.secondary.main})`,
                    color: glassTheme.palette.primary.contrastText,
                    border: 'none',
                    boxShadow: `0 4px 15px ${glassTheme.palette.primary.main}30, 0 2px 8px rgba(0, 0, 0, 0.1)`,
                    '&:hover': {
                        boxShadow: `0 6px 20px ${glassTheme.palette.primary.main}40, 0 4px 12px rgba(0, 0, 0, 0.15)`,
                        transform: 'translateY(-2px)'
                    }
                };
            case 'secondary':
                return {
                    background: glassTheme.mode === 'dark'
                        ? 'rgba(255, 255, 255, 0.1)'
                        : 'rgba(255, 255, 255, 0.8)',
                    backdropFilter: 'blur(10px) saturate(180%)',
                    color: glassTheme.palette.text.primary,
                    border: `1px solid ${glassTheme.mode === 'dark' ? 'rgba(255, 255, 255, 0.2)' : 'rgba(0, 0, 0, 0.1)'}`,
                    boxShadow: '0 2px 8px rgba(0, 0, 0, 0.05)',
                    '&:hover': {
                        background: glassTheme.mode === 'dark'
                            ? 'rgba(255, 255, 255, 0.15)'
                            : 'rgba(255, 255, 255, 0.9)',
                        boxShadow: '0 4px 12px rgba(0, 0, 0, 0.1)',
                        transform: 'translateY(-1px)'
                    }
                };
            case 'outline-solid':
                return {
                    background: 'transparent',
                    color: glassTheme.palette.primary.main,
                    border: `2px solid ${glassTheme.palette.primary.main}40`,
                    backdropFilter: 'blur(5px)',
                    '&:hover': {
                        background: `${glassTheme.palette.primary.main}10`,
                        border: `2px solid ${glassTheme.palette.primary.main}60`,
                        transform: 'translateY(-1px)'
                    }
                };
            case 'ghost':
                return {
                    background: 'transparent',
                    color: glassTheme.palette.text.primary,
                    border: 'none',
                    '&:hover': {
                        background: glassTheme.mode === 'dark'
                            ? 'rgba(255, 255, 255, 0.05)'
                            : 'rgba(0, 0, 0, 0.05)',
                    }
                };
            case 'danger':
                return {
                    background: `linear-gradient(135deg, ${glassTheme.palette.error.main}, ${glassTheme.palette.error.dark})`,
                    color: glassTheme.palette.error.contrastText,
                    border: 'none',
                    boxShadow: `0 4px 15px ${glassTheme.palette.error.main}30, 0 2px 8px rgba(0, 0, 0, 0.1)`,
                    '&:hover': {
                        boxShadow: `0 6px 20px ${glassTheme.palette.error.main}40, 0 4px 12px rgba(0, 0, 0, 0.15)`,
                        transform: 'translateY(-2px)'
                    }
                };
            case 'success':
                return {
                    background: `linear-gradient(135deg, ${glassTheme.palette.success.main}, ${glassTheme.palette.success.dark})`,
                    color: glassTheme.palette.success.contrastText,
                    border: 'none',
                    boxShadow: `0 4px 15px ${glassTheme.palette.success.main}30, 0 2px 8px rgba(0, 0, 0, 0.1)`,
                    '&:hover': {
                        boxShadow: `0 6px 20px ${glassTheme.palette.success.main}40, 0 4px 12px rgba(0, 0, 0, 0.15)`,
                        transform: 'translateY(-2px)'
                    }
                };
            default:
                return {};
        }
    };
    
    const sizes = {
        sm: 'px-3 py-2 text-sm',
        md: 'px-5 py-2.5 text-sm',
        lg: 'px-6 py-3 text-base',
        xl: 'px-8 py-4 text-lg',
    };

    const isDisabled = disabled || loading;
    const variantStyles = getVariantStyles();

    // If using Link component, don't pass type and disabled props
    const componentProps = Component === 'button' 
        ? { type, disabled: isDisabled, ...props }
        : props;

    return (
        <div
            className={clsx(baseStyles, sizes[size], className)}
            style={variantStyles}
        >
            <Component
                ref={ref}
                className="w-full h-full flex items-center justify-center relative z-10"
                {...componentProps}
            >
                {loading && (
                    <div
                        className="mr-3"
                        style={{
                            animation: 'spin 1s linear infinite'
                        }}
                    >
                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24">
                            <circle
                                className="opacity-25"
                                cx="12"
                                cy="12"
                                r="10"
                                stroke="currentColor"
                                strokeWidth="4"
                            />
                            <path
                                className="opacity-75"
                                fill="currentColor"
                                d="m4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
                            />
                        </svg>
                    </div>
                )}

                {Icon && iconPosition === 'left' && !loading && (
                    <div className="mr-2">
                        <Icon className="w-4 h-4" />
                    </div>
                )}

                <span>
                    {children}
                </span>

                {Icon && iconPosition === 'right' && !loading && (
                    <div className="ml-2">
                        <Icon className="w-4 h-4" />
                    </div>
                )}
            </Component>
        </div>
    );
});

Button.displayName = 'Button';

export default Button;
