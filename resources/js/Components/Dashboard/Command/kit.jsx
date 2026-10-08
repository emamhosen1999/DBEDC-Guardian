import React, { useEffect, useId, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import axios from 'axios';
import { Flex, Box, Text } from '@radix-ui/themes';
import { EnterFullScreenIcon, ExitFullScreenIcon, MinusIcon, PlusIcon } from '@radix-ui/react-icons';

/* ── data ───────────────────────────────────────────────────────────── */
export function useCommandData() {
    return useQuery({
        queryKey: ['command-center'],
        queryFn: async () => (await axios.get(route('dashboard.command'))).data,
        staleTime: 60_000,
        refetchOnWindowFocus: false,
        retry: 1,
    });
}

/* ── semantic tone → Radix colour scales (theme-aware, accent-independent) */
export const TONE = {
    accent: { solid: 'var(--accent-9)', text: 'var(--accent-11)', soft: 'var(--accent-a3)' },
    good:   { solid: 'var(--jade-9)',   text: 'var(--jade-11)',   soft: 'var(--jade-a3)' },
    warn:   { solid: 'var(--amber-9)',  text: 'var(--amber-11)',  soft: 'var(--amber-a3)' },
    crit:   { solid: 'var(--tomato-9)', text: 'var(--tomato-11)', soft: 'var(--tomato-a3)' },
    info:   { solid: 'var(--blue-9)',   text: 'var(--blue-11)',   soft: 'var(--blue-a3)' },
    design: { solid: 'var(--iris-9)',   text: 'var(--iris-11)',   soft: 'var(--iris-a3)' },
    mute:   { solid: 'var(--gray-a7)',  text: 'var(--gray-11)',   soft: 'var(--gray-a3)' },
};

/* series colours for multi-category charts (CVD-checked hues) */
export const SERIES = {
    completed: 'var(--jade-9)',
    resubmission: 'var(--amber-9)',
    new: 'var(--blue-9)',
    critical: 'var(--tomato-9)',
    major: 'var(--amber-9)',
    minor: 'var(--iris-9)',
};

/* ── formatting ─────────────────────────────────────────────────────── */
export const fmtNum = (n) => (n == null ? '—' : Number(n).toLocaleString('en-US'));
export const fmtCr = (n) => (n == null ? '—' : '৳' + Number(n).toLocaleString('en-US') + ' Cr');
export const chLabel = (km) => `Ch ${km}+000`;
/* Numeric/display face: the active design language's display font (Noto Sans in
   Cyber, Space Grotesk otherwise). A CSS var, so it also works inside CC_CSS. */
export const MONO = "var(--dl-font-display, 'Space Grotesk', system-ui, -apple-system, sans-serif)";
/* Corner radius that a design language may square off (Cyber → 0). */
export const R = (px) => `var(--dl-panel-radius, ${px}px)`;

/* ── command card (Cyber .card: header strip with title, HUD line and the
   .card-header-btn tools — collapse and full screen) ───────────────────── */
export function CommandCard({ title, sub, right, children, style, minHeight, flush = false, tools = true }) {
    const bodyId = useId();
    const [collapsed, setCollapsed] = useState(false);
    const [expanded, setExpanded] = useState(false);

    useEffect(() => {
        if (!expanded) return undefined;
        const onKeyDown = (event) => { if (event.key === 'Escape') setExpanded(false); };
        document.addEventListener('keydown', onKeyDown);
        return () => document.removeEventListener('keydown', onKeyDown);
    }, [expanded]);

    const className = ['cc-card', 'dl-card', collapsed ? 'dl-card--collapsed' : '', expanded ? 'dl-card--expanded' : '']
        .filter(Boolean).join(' ');
    const label = typeof title === 'string' ? title : 'panel';

    return (
        <section className={className} style={{ minHeight: collapsed ? undefined : minHeight, ...style }}
            role={expanded ? 'dialog' : undefined} aria-modal={expanded || undefined} aria-label={expanded ? label : undefined}>
            {(title || right) && (
                <header className="dl-card__header">
                    {title && <h3 className="dl-card__title" title={typeof title === 'string' ? title : undefined}>{title}</h3>}
                    <span className="dl-hud-line" aria-hidden="true" />
                    {right && <div className="dl-card__actions">{right}</div>}
                    {tools && (
                        <div className="dl-card__tools">
                            <button type="button" className="dl-card__tool" onClick={() => setCollapsed((c) => !c)}
                                aria-expanded={!collapsed} aria-controls={bodyId}
                                aria-label={collapsed ? `Show ${label}` : `Collapse ${label}`}>
                                {collapsed ? <PlusIcon aria-hidden="true" /> : <MinusIcon aria-hidden="true" />}
                            </button>
                            <button type="button" className="dl-card__tool" onClick={() => setExpanded((e) => !e)}
                                aria-pressed={expanded} aria-label={expanded ? `Exit full screen: ${label}` : `Full screen: ${label}`}>
                                {expanded ? <ExitFullScreenIcon aria-hidden="true" /> : <EnterFullScreenIcon aria-hidden="true" />}
                            </button>
                        </div>
                    )}
                </header>
            )}
            <div className={`dl-card__body${flush ? ' dl-card__body--flush' : ''}`} id={bodyId}>
                {sub && <Text as="p" className={`dl-card__sub${flush ? ' dl-card__sub--padded' : ''}`} style={{ fontVariantNumeric: 'tabular-nums' }}>{sub}</Text>}
                {children}
            </div>
        </section>
    );
}

export function SectionLabel({ children, right }) {
    return (
        <div className="dl-section-label">
            <h2 className="dl-section-label__text">{children}</h2>
            <span className="dl-hud-line" aria-hidden="true" />
            {right}
        </div>
    );
}

/* ── KPI tile ───────────────────────────────────────────────────────── */
export function Kpi({ icon, label, value, unit, foot, tone = 'accent', spark }) {
    const t = TONE[tone] ?? TONE.accent;
    return (
        <Box className="cc-card cc-kpi dl-card" style={{ padding: '14px' }}>
            <Flex direction="column" gap="2" style={{ height: '100%' }}>
                <Flex align="center" gap="2">
                    <Flex align="center" justify="center" style={{ width: 24, height: 24, borderRadius: R(10),
                        background: t.soft, color: t.text, flexShrink: 0 }}>{icon}</Flex>
                    <Text size="1" style={{ textTransform: 'uppercase',
                        color: 'var(--aero-color-subtle, var(--gray-10))', fontWeight: 700, lineHeight: 1.2 }}>{label}</Text>
                </Flex>
                <Flex align="baseline" gap="1">
                    <Text style={{ fontFamily: MONO, fontWeight: 600, fontSize: 'var(--font-size-5)',
                        fontVariantNumeric: 'tabular-nums', lineHeight: 1, color: 'var(--gray-12)' }}>{value}</Text>
                    {unit && <Text size="2" color="gray" weight="medium">{unit}</Text>}
                </Flex>
                <Flex align="center" gap="2" style={{ marginTop: 'auto' }}>
                    {foot}
                    {spark && <Box style={{ flex: 1, height: 26, minWidth: 0 }}>{spark}</Box>}
                </Flex>
            </Flex>
        </Box>
    );
}

/* ── inline sparkline (area + endpoint) ─────────────────────────────── */
export function Spark({ data = [], color = 'var(--accent-9)', height = 26 }) {
    if (!data.length) return null;
    const W = 120, H = height, pad = 2;
    const mn = Math.min(...data), mx = Math.max(...data), rng = (mx - mn) || 1;
    const X = (i) => pad + (i / (data.length - 1 || 1)) * (W - 2 * pad);
    const Y = (v) => H - pad - ((v - mn) / rng) * (H - 2 * pad);
    const line = data.map((v, i) => `${i ? 'L' : 'M'}${X(i).toFixed(1)} ${Y(v).toFixed(1)}`).join(' ');
    const area = `${line} L${X(data.length - 1)} ${H} L${X(0)} ${H} Z`;
    const gid = React.useId().replace(/:/g, '');
    return (
        <svg viewBox={`0 0 ${W} ${H}`} width="100%" height={H} preserveAspectRatio="none" aria-hidden="true">
            <defs><linearGradient id={gid} x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%" stopColor={color} stopOpacity="0.28" />
                <stop offset="100%" stopColor={color} stopOpacity="0" />
            </linearGradient></defs>
            <path d={area} fill={`url(#${gid})`} />
            <path d={line} fill="none" stroke={color} strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" />
            <circle cx={X(data.length - 1)} cy={Y(data[data.length - 1])} r="2.4" fill={color} />
        </svg>
    );
}

/* ── labelled progress row ──────────────────────────────────────────── */
export function StatRow({ dot, name, sub, value, barPct, barColor }) {
    return (
        <Flex align="center" gap="3" py="2" style={{ borderTop: '1px solid var(--gray-a3)' }}>
            {dot && <Box style={{ width: 8, height: 8, borderRadius: '50%', background: dot, flexShrink: 0 }} />}
            <Box style={{ flex: 1, minWidth: 0 }}>
                <Text size="2" style={{ display: 'block' }}>{name}</Text>
                {sub && <Text size="1" color="gray" style={{ fontFamily: MONO }}>{sub}</Text>}
            </Box>
            {barPct != null && (
                <Box style={{ width: 120, height: 7, borderRadius: R(5), background: 'var(--gray-a4)', overflow: 'hidden', flexShrink: 0 }}>
                    <Box style={{ width: `${barPct}%`, height: '100%', background: barColor || 'var(--accent-9)', borderRadius: R(5) }} />
                </Box>
            )}
            {value != null && <Text style={{ fontFamily: MONO, fontWeight: 700 }}>{value}</Text>}
        </Flex>
    );
}

export function DeltaChip({ dir = 'up', children }) {
    const map = { up: 'var(--jade-11)', down: 'var(--tomato-11)', flat: 'var(--gray-11)' };
    const glyph = dir === 'up' ? '▲' : dir === 'down' ? '▼' : '■';
    return (
        <Text style={{ fontFamily: MONO, fontSize: 11, fontWeight: 700, color: map[dir], whiteSpace: 'nowrap' }}>
            {glyph} {children}
        </Text>
    );
}

/* recharts tooltip styling shared across charts */
export const tooltipStyle = {
    background: 'var(--color-panel-solid)',
    border: '1px solid var(--gray-a6)',
    borderRadius: 'var(--radius-3)',
    fontSize: 12,
    boxShadow: 'var(--shadow-4)',
    fontFamily: MONO,
};
