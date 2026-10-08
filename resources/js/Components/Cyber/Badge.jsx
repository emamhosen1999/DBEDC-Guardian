import React from 'react';

/** Cyber .badge. color: theme | success | warning | danger | info | secondary; outline draws a hairline instead of a fill. */
export default function Badge({ color = 'theme', outline = false, className = '', children, ...props }) {
    return <span className={['cy-badge', `cy-badge--${outline ? 'outline-' : ''}${color}`, className].filter(Boolean).join(' ')} {...props}>{children}</span>;
}
