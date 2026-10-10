import React from 'react';

/**
 * Cyber .badge. color: theme | success | warning | danger | info | secondary; outline draws a hairline instead of a fill;
 * soft is Cyber's status badge (15% tone background, tone text, 9px) as used on its order tables.
 */
export default function Badge({ color = 'theme', outline = false, soft = false, className = '', children, ...props }) {
    const variant = outline ? `cy-badge--outline-${color}` : soft ? `cy-badge--soft cy-badge--${color}` : `cy-badge--${color}`;
    return <span className={['cy-badge', variant, className].filter(Boolean).join(' ')} {...props}>{children}</span>;
}
