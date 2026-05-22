import React, { useEffect } from 'react';
import * as Toast from '@radix-ui/react-toast';
import { Cross2Icon } from '@radix-ui/react-icons';
import { useRadixTheme } from '@/Contexts/RadixThemeContext';
import { useToastStore } from '@/utils/toastUtils';

/**
 * RadixToaster Component
 * 
 * Provides the toast viewport for @radix-ui/react-toast.
 * Styled to match the previous sonner configuration with CSS variables.
 */
export default function RadixToaster({
    position = 'top-right',
    duration = 4000,
    richColors = true,
    closeButton = true,
}) {
    const { settings } = useRadixTheme();
    const theme = settings.appearance || 'light';
    const toasts = useToastStore((state) => state.toasts);
    const removeToast = useToastStore((state) => state.removeToast);

    return (
        <Toast.Provider swipeDirection="right" duration={duration}>
            <Toast.Viewport
                className="radix-toast-viewport"
                style={{
                    position: 'fixed',
                    top: position.includes('top') ? 24 : 'auto',
                    bottom: position.includes('bottom') ? 24 : 'auto',
                    left: position.includes('left') ? 24 : 'auto',
                    right: position.includes('right') ? 24 : 'auto',
                    display: 'flex',
                    flexDirection: 'column',
                    gap: 12,
                    padding: 16,
                    width: 356,
                    maxWidth: '100vw',
                    zIndex: 9999,
                    listStyle: 'none',
                    outline: 'none',
                }}
            />
            {toasts.map((toast) => (
                <ToastItem
                    key={toast.id}
                    {...toast}
                    onClose={removeToast}
                />
            ))}
            <style>{`
                .radix-toast-viewport {
                    --border-radius: var(--radius-3);
                    --normal-bg: var(--color-panel-solid);
                    --normal-border: var(--gray-a5);
                    --normal-text: var(--gray-12);
                    --success-bg: var(--green-a3);
                    --success-border: var(--green-a6);
                    --success-text: var(--green-11);
                    --error-bg: var(--red-a3);
                    --error-border: var(--red-a6);
                    --error-text: var(--red-11);
                    --warning-bg: var(--amber-a3);
                    --warning-border: var(--amber-a6);
                    --warning-text: var(--amber-11);
                }
            `}</style>
        </Toast.Provider>
    );
}

/**
 * Individual Toast Item Component
 * Used internally by toastUtils for rendering individual toasts
 */
function ToastItem({ id, title, description, variant = 'default', onClose }) {
    const variantStyles = {
        default: {
            bg: 'var(--normal-bg)',
            border: 'var(--normal-border)',
            text: 'var(--normal-text)',
        },
        success: {
            bg: 'var(--success-bg)',
            border: 'var(--success-border)',
            text: 'var(--success-text)',
        },
        error: {
            bg: 'var(--error-bg)',
            border: 'var(--error-border)',
            text: 'var(--error-text)',
        },
        warning: {
            bg: 'var(--warning-bg)',
            border: 'var(--warning-border)',
            text: 'var(--warning-text)',
        },
    };

    const styles = variantStyles[variant] || variantStyles.default;

    return (
        <Toast.Root
            className="radix-toast-root"
            duration={4000}
            onOpenChange={(open) => !open && onClose?.(id)}
            style={{
                backgroundColor: styles.bg,
                border: `1px solid ${styles.border}`,
                borderRadius: 'var(--border-radius)',
                padding: 16,
                display: 'flex',
                alignItems: 'flex-start',
                gap: 12,
                boxShadow: '0 4px 12px rgba(0, 0, 0, 0.1)',
                color: styles.text,
                fontSize: 14,
                lineHeight: '20px',
                minWidth: 300,
            }}
        >
            <div style={{ flex: 1 }}>
                {title && (
                    <Toast.Title
                        className="radix-toast-title"
                        style={{ fontWeight: 600, marginBottom: description ? 4 : 0 }}
                    >
                        {title}
                    </Toast.Title>
                )}
                {description && (
                    <Toast.Description
                        className="radix-toast-description"
                        style={{ opacity: 0.9 }}
                    >
                        {description}
                    </Toast.Description>
                )}
            </div>
            <Toast.Close
                className="radix-toast-close"
                style={{
                    background: 'transparent',
                    border: 'none',
                    cursor: 'pointer',
                    padding: 4,
                    borderRadius: 4,
                    color: 'inherit',
                    opacity: 0.6,
                    transition: 'opacity 0.15s',
                }}
                onMouseEnter={(e) => e.currentTarget.style.opacity = '1'}
                onMouseLeave={(e) => e.currentTarget.style.opacity = '0.6'}
            >
                <Cross2Icon style={{ width: 16, height: 16 }} />
            </Toast.Close>
        </Toast.Root>
    );
}
