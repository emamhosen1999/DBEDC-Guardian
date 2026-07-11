import React from 'react';
import { useQuery } from '@tanstack/react-query';
import axios from 'axios';
import { Card, Flex, Box, Heading, Text, Skeleton } from '@radix-ui/themes';

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
export const MONO = "'Roboto Mono', ui-monospace, 'Cascadia Code', monospace";

/* ── panel (card with a header) ─────────────────────────────────────── */
export function Panel({ title, sub, right, children, style, minHeight }) {
    return (
        <Card className="cc-card" style={{ display: 'flex', flexDirection: 'column', minHeight, ...style }}>
            {(title || right) && (
                <Flex align="center" justify="between" gap="3" mb="3" style={{ flexShrink: 0 }}>
                    <Box style={{ minWidth: 0 }}>
                        {title && <Heading size="3" style={{ letterSpacing: '-0.01em' }}>{title}</Heading>}
                        {sub && <Text size="1" color="gray" style={{ fontFamily: MONO }}>{sub}</Text>}
                    </Box>
                    {right && <Box style={{ flexShrink: 0 }}>{right}</Box>}
                </Flex>
            )}
            <Box style={{ flex: 1, minHeight: 0 }}>{children}</Box>
        </Card>
    );
}

export function SectionLabel({ children, right }) {
    return (
        <Flex align="center" gap="3" style={{ gridColumn: '1 / -1', margin: '10px 2px 0' }}>
            <Text size="1" style={{ fontFamily: MONO, letterSpacing: '0.14em', textTransform: 'uppercase',
                color: 'var(--gray-11)', fontWeight: 600, whiteSpace: 'nowrap' }}>{children}</Text>
            <Box style={{ flex: 1, height: 1, background: 'var(--gray-a4)' }} />
            {right}
        </Flex>
    );
}

/* ── KPI tile ───────────────────────────────────────────────────────── */
export function Kpi({ icon, label, value, unit, foot, tone = 'accent', spark }) {
    const t = TONE[tone] ?? TONE.accent;
    return (
        <Card className="cc-card cc-kpi">
            <Flex direction="column" gap="2" style={{ height: '100%' }}>
                <Flex align="center" gap="2">
                    <Flex align="center" justify="center" style={{ width: 28, height: 28, borderRadius: 8,
                        background: t.soft, color: t.text, flexShrink: 0 }}>{icon}</Flex>
                    <Text size="1" style={{ fontFamily: MONO, textTransform: 'uppercase', letterSpacing: '0.06em',
                        color: 'var(--gray-11)', lineHeight: 1.2 }}>{label}</Text>
                </Flex>
                <Flex align="baseline" gap="1">
                    <Text style={{ fontFamily: MONO, fontWeight: 700, fontSize: 26, letterSpacing: '-0.03em',
                        lineHeight: 1 }}>{value}</Text>
                    {unit && <Text size="2" color="gray" weight="medium">{unit}</Text>}
                </Flex>
                <Flex align="center" gap="2" style={{ marginTop: 'auto' }}>
                    {foot}
                    {spark && <Box style={{ flex: 1, height: 26, minWidth: 0 }}>{spark}</Box>}
                </Flex>
            </Flex>
        </Card>
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
                <Box style={{ width: 120, height: 7, borderRadius: 5, background: 'var(--gray-a4)', overflow: 'hidden', flexShrink: 0 }}>
                    <Box style={{ width: `${barPct}%`, height: '100%', background: barColor || 'var(--accent-9)', borderRadius: 5 }} />
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
