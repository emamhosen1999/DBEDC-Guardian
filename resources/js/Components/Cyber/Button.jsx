import React, { forwardRef } from 'react';

/**
 * Cyber .btn. color: theme | default | secondary; variant: solid | outline; size: sm.
 * `as` renders another element (an Inertia <Link>, an <a>) with the same look.
 */
const Button = forwardRef(function Button({ as: Tag = 'button', color = 'theme', variant = 'solid', size, className = '', type, children, ...props }, ref) {
    const classes = ['cy-btn', `cy-btn--${variant === 'outline' ? 'outline-' : ''}${color}`, size ? `cy-btn--${size}` : '', className].filter(Boolean).join(' ');
    return <Tag ref={ref} type={Tag === 'button' ? (type ?? 'button') : undefined} className={classes} {...props}>{children}</Tag>;
});

export default Button;
