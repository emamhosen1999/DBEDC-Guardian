import React, { forwardRef } from 'react';
import { CheckIcon } from '@heroicons/react/24/solid';
import { clsx } from 'clsx';

const Checkbox = forwardRef(({ 
    label, 
    description,
    error,
    checked = false,
    className = '',
    ...props 
}, ref) => {
    // Custom theme for consistent styling
    const glassTheme = {
        palette: {
            primary: { 
                main: '#3b82f6', 
                contrastText: '#ffffff' 
            },
            secondary: { 
                main: '#64748b' 
            },
            error: { 
                main: '#ef4444' 
            },
            text: { 
                primary: '#ffffff', 
                secondary: '#94a3b8' 
            }
        },
        mode: 'dark'
    };
    
    return (
        <div className={clsx('space-y-1', className)}>
            <div className="flex items-start">
                <div className="flex items-center h-6">
                    <div className="relative">
                        <input
                            ref={ref}
                            type="checkbox"
                            checked={checked}
                            className="sr-only"
                            {...props}
                        />

                        <div
                            className="w-5 h-5 rounded-lg cursor-pointer transition-all duration-300 flex items-center justify-center relative overflow-hidden"
                            style={{
                                background: checked
                                    ? `linear-gradient(135deg, ${glassTheme.palette.primary.main}, ${glassTheme.palette.secondary.main})`
                                    : glassTheme.mode === 'dark'
                                    ? 'rgba(255, 255, 255, 0.05)'
                                    : 'rgba(255, 255, 255, 0.8)',
                                backdropFilter: 'blur(10px) saturate(180%)',
                                border: checked
                                    ? `2px solid ${glassTheme.palette.primary.main}`
                                    : error
                                    ? `2px solid ${glassTheme.palette.error.main}40`
                                    : `2px solid ${glassTheme.mode === 'dark' ? 'rgba(255, 255, 255, 0.1)' : 'rgba(0, 0, 0, 0.1)'}`,
                                boxShadow: checked
                                    ? `0 2px 8px ${glassTheme.palette.primary.main}30, 0 0 0 3px ${glassTheme.palette.primary.main}15`
                                    : '0 2px 4px rgba(0, 0, 0, 0.05)',
                            }}
                            onClick={() => {
                                if (props.onChange) {
                                    props.onChange({ target: { checked: !checked } });
                                }
                            }}
                        >
                            {checked && (
                                <CheckIcon
                                    className="w-3 h-3"
                                    style={{ color: glassTheme.palette.primary.contrastText }}
                                />
                            )}
                        </div>
                    </div>
                </div>

                {(label || description) && (
                    <div className="ml-3">
                        {label && (
                            <label 
                                className="text-sm font-medium cursor-pointer transition-colors duration-200"
                                style={{ 
                                    color: glassTheme.palette.text.primary,
                                }}
                                onClick={() => {
                                    if (props.onChange) {
                                        props.onChange({ target: { checked: !checked } });
                                    }
                                }}
                            >
                                {label}
                            </label>
                        )}
                        {description && (
                            <p
                                className="text-sm mt-1"
                                style={{ color: glassTheme.palette.text.secondary }}
                            >
                                {description}
                            </p>
                        )}
                    </div>
                )}
            </div>

            {error && (
                <p
                    className="text-sm ml-8 flex items-center gap-2"
                    style={{ color: glassTheme.palette.error.main }}
                >
                    <svg className="w-4 h-4 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                        <path fillRule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clipRule="evenodd" />
                    </svg>
                    {error}
                </p>
            )}
        </div>
    );
});

Checkbox.displayName = 'Checkbox';

export default Checkbox;
