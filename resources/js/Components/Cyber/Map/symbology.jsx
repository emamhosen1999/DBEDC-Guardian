import React from 'react';

import { BI } from '../icons.js';

/**
 * Map symbology, one source for the markers, the legend and the layer list, so a symbol always means the same thing:
 *
 * - colour = the layer's group, from the Okabe-Ito colour-blind-safe qualitative palette (map.css tokens
 *   --cy-map-g-*), with the Cyber theme colour reserved for the corridor itself;
 * - glyph  = the layer (its Bootstrap Icons name from the server), drawn inside the badge, so layers that share a
 *   group colour are still told apart;
 * - ring   = the record's status: red critical / urgent, amber needs attention (no ring = routine);
 * - people = their photo (or initials) in a ring: green on duty, blue checked out.
 */
export const GROUP_VAR = {
    corridor: 'var(--cy-map-g-corridor)',
    workforce: 'var(--cy-map-g-workforce)',
    works: 'var(--cy-map-g-works)',
    om: 'var(--cy-map-g-om)',
    tolls: 'var(--cy-map-g-tolls)',
    safety: 'var(--cy-map-g-safety)',
};

export const groupColor = (group) => GROUP_VAR[group] ?? 'var(--cy-map-g-other)';

export const glyphOf = (name) => BI[name] ?? BI['geo-alt'];

export const STATUS_KEY = [
    { tone: 'crit', label: 'Critical or urgent' },
    { tone: 'warn', label: 'Needs attention' },
];

export const PERSON_KEY = [
    { status: 'active', label: 'On duty (checked in)' },
    { status: 'completed', label: 'Checked out' },
];

/** The ring colour for a record's tone, or null for routine records. */
export const ringTone = (tone) => (tone === 'crit' || tone === 'warn' ? tone : null);

/** How a layer is drawn: point badges, chainage bands / routes (lines) or areas. */
export function layerKinds(layer) {
    const shapes = new Set((layer.features ?? []).map((f) => f.shape));
    return {
        point: shapes.has('point') || (shapes.has('circle') && !shapes.has('polygon')),
        line: shapes.has('band') || shapes.has('route'),
        area: shapes.has('polygon') || shapes.has('circle'),
    };
}

/** SVG badge body (centred on 0,0): coloured disc, halo, the layer glyph and an optional status ring. */
export function BadgeShape({ icon, r = 9, ring = null }) {
    const g = r * 1.2;
    return (
        <>
            {ring && <circle className="cy-map__ring" data-tone={ring} r={r + 3} />}
            <circle className="cy-map__badge" r={r} />
            {/* eslint-disable-next-line react/no-danger -- trusted, bundled Bootstrap Icons path data */}
            <g className="cy-map__glyph" transform={`translate(${-g / 2} ${-g / 2}) scale(${g / 16})`} dangerouslySetInnerHTML={{ __html: glyphOf(icon) }} />
        </>
    );
}

/** The same symbol as a small inline SVG, for the legend and the layer list. */
export function LayerSwatch({ layer, size = 18 }) {
    const kinds = layerKinds(layer);
    const style = { '--cy-map-c': groupColor(layer.group) };
    if (!kinds.point && kinds.line) {
        return (
            <svg className="cy-map__swatch" width={size} height={size} viewBox="-9 -9 18 18" aria-hidden="true" style={style}>
                <line className="cy-map__swatch-line" x1="-8" y1="0" x2="8" y2="0" />
            </svg>
        );
    }
    if (!kinds.point && kinds.area) {
        return (
            <svg className="cy-map__swatch" width={size} height={size} viewBox="-9 -9 18 18" aria-hidden="true" style={style}>
                <rect className="cy-map__swatch-area" x="-7" y="-6" width="14" height="12" />
            </svg>
        );
    }
    return (
        <svg className="cy-map__swatch" width={size} height={size} viewBox="-9 -9 18 18" aria-hidden="true" style={style}>
            <BadgeShape icon={layer.icon} r={8} />
        </svg>
    );
}
