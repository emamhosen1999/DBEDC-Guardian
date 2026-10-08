import React from 'react';
import { BI } from './icons.js';

/**
 * Bootstrap Icons glyph (Cyber's icon set). `name` is the icon's Bootstrap name
 * ("calendar-check"); add new glyphs to icons.js. Decorative unless `label` is given.
 */
export default function Icon({ name, label, className = '', ...props }) {
    const body = BI[name];
    if (!body) return null;
    return (
        <svg
            className={`cy-icon ${className}`.trim()}
            width="1em"
            height="1em"
            viewBox="0 0 16 16"
            fill="currentColor"
            role={label ? 'img' : undefined}
            aria-label={label}
            aria-hidden={label ? undefined : 'true'}
            focusable="false"
            dangerouslySetInnerHTML={{ __html: body }}
            {...props}
        />
    );
}
