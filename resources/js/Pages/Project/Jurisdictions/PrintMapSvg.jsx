import React, { useMemo } from 'react';

import {
    buildGraticule, formatChainage, layoutLabels, linePath, metresPerUnit, pointAtChainage, projectedBounds, projectMercator,
} from '@/Components/Cyber/Map/geo.js';
import { initialsOf } from '@/Components/Cyber/Map/util.js';

import { PATTERNS, PRINT_COLOURS, PRINT_STROKES } from './printPalette.js';

export const GUTTER_L = 400;
export const GUTTER_R = 330;
const INK = '#0b1a22';

/** Pattern definitions shared by the map, the ruler and the legend swatches (same ids, same content everywhere). */
export function PatternDefs() {
    return (
        <defs>
            {Object.entries(PATTERNS).map(([token, p]) => {
                const bg = PRINT_COLOURS[token];
                const ink = PRINT_STROKES[token];
                const id = `jp-${token}`;
                const mark = {
                    diag: <path d="M-2 2 L2 -2 M0 8 L8 0 M6 10 L10 6" stroke={INK} strokeWidth="1.1" opacity="0.7" />,
                    cross: <path d="M0 4 H8 M4 0 V8" stroke={INK} strokeWidth="1" opacity="0.7" />,
                    dots: <circle cx="4" cy="4" r="1.5" fill={INK} opacity="0.75" />,
                    horizontal: <path d="M0 2 H8 M0 6 H8" stroke={INK} strokeWidth="1" opacity="0.7" />,
                    vertical: <path d="M2 0 V8 M6 0 V8" stroke={INK} strokeWidth="1" opacity="0.7" />,
                    'dense-dots': <path d="M1 1 h1 M5 1 h1 M3 3 h1 M7 3 h1 M1 5 h1 M5 5 h1 M3 7 h1 M7 7 h1" stroke={INK} strokeWidth="1.4" opacity="0.75" />,
                }[p.kind];
                const rotate = p.kind === 'diag' && p.angle < 0 ? 'scale(-1 1) translate(-8 0)' : undefined;
                return (
                    <pattern key={token} id={id} width="8" height="8" patternUnits="userSpaceOnUse">
                        <rect width="8" height="8" fill={bg} />
                        <g transform={rotate}>{mark}</g>
                        <rect width="8" height="8" fill="none" stroke={ink} strokeWidth="0" />
                    </pattern>
                );
            })}
            <pattern id="jp-gap" width="8" height="8" patternUnits="userSpaceOnUse"><rect width="8" height="8" fill="#fff" /><path d="M-2 2 L2 -2 M0 8 L8 0 M6 10 L10 6" stroke="#c0392b" strokeWidth="1" /></pattern>
        </defs>
    );
}

export const patternFill = (token) => `url(#jp-${token})`;

/** 1-2-5 scale bar length in metres that fits in `maxUnits` world units. */
function niceLength(maxMetres) {
    const pow = 10 ** Math.floor(Math.log10(maxMetres));
    return [5, 2, 1].map((k) => k * pow).find((v) => v <= maxMetres) ?? pow;
}

/**
 * The jurisdiction map as a print-ready vector drawing: graticule, centreline with kilometre ticks, jurisdiction bands in
 * patterned colour with their code, structures labelled in the left gutter with leader lines, the in-charge avatar on each
 * band, a north arrow and a metric scale bar. Web Mercator (north up); the scale is true at the corridor's mid latitude.
 */
export default function PrintMapSvg({ alignment, jurisdictions, structures, labelId = 'jp-map-title' }) {
    const g = useMemo(() => {
        const pts = alignment.points;
        const bounds = projectedBounds(pts.map(([lat, lng]) => ({ lat, lng })), 0.07);
        if (!bounds) return null;
        const S = 1000 / Math.max(bounds.w, bounds.h);
        const toWorld = (lat, lng) => { const p = projectMercator(lat, lng); return { x: (p.x - bounds.minX) * S, y: (p.y - bounds.minY) * S }; };
        const W = bounds.w * S;
        const H = bounds.h * S;
        const midLat = (pts[0][0] + pts[pts.length - 1][0]) / 2;
        const mPerUnit = metresPerUnit(midLat) / S;
        const line = linePath(pts.map(([lat, lng]) => [lat, lng]), toWorld);
        const grid = buildGraticule(bounds);

        const ticks = [];
        for (let m = 0; m <= alignment.length_m; m += 1000) {
            const a = pointAtChainage(pts, Math.max(0, m - 60)); const b = pointAtChainage(pts, Math.min(alignment.length_m, m + 60)); const p = pointAtChainage(pts, m);
            if (!a || !b || !p) continue;
            const wa = toWorld(a.lat, a.lng); const wb = toWorld(b.lat, b.lng); const wp = toWorld(p.lat, p.lng);
            const len = Math.hypot(wb.x - wa.x, wb.y - wa.y) || 1;
            ticks.push({ m, x: wp.x, y: wp.y, nx: -(wb.y - wa.y) / len, ny: (wb.x - wa.x) / len });
        }

        const bands = jurisdictions.map((j) => {
            const feature = j.map_path;
            return feature ? { j, d: linePath(feature, toWorld), mid: toWorld(...j.map_mid) } : null;
        }).filter(Boolean);

        const labelled = layoutLabels(bands.map((band, i) => ({ key: `b${i}`, y: band.mid.y, x: band.mid.x, band })), { x: W + 36, top: 40, bottom: H - 40, gap: 62 });
        const structureItems = (structures ?? []).filter((s) => Number.isFinite(s.lat)).map((s) => ({ s, ...toWorld(s.lat, s.lng) }));
        const structureLabels = layoutLabels(structureItems.map((it, i) => ({ key: `s${i}`, y: it.y, x: it.x, s: it.s })), { x: -36, top: 24, bottom: H - 24, gap: 31 });

        return { bounds, S, W, H, toWorld, mPerUnit, line, grid, ticks, bands, labelled, structureLabels };
    }, [alignment, jurisdictions, structures]);

    if (!g) return <p role="status">The corridor centreline is not available.</p>;

    const scaleM = niceLength(g.mPerUnit * 240);
    const scaleW = scaleM / g.mPerUnit;
    const view = `${-GUTTER_L} -10 ${g.W + GUTTER_L + GUTTER_R} ${g.H + 20}`;

    return (
        <svg viewBox={view} role="img" aria-labelledby={labelId} preserveAspectRatio="xMidYMid meet">
            <title id={labelId}>Jurisdiction map of the Dhaka Bypass Expressway</title>
            <PatternDefs />
            <rect x={-GUTTER_L} y="-10" width={g.W + GUTTER_L + GUTTER_R} height={g.H + 20} fill="#fff" />
            <g aria-hidden="true" stroke="#d5dce0" strokeWidth="0.8" fill="none">
                {g.grid.meridians.map((m) => <line key={`m${m.lng}`} x1={(m.x - g.bounds.minX) * g.S} x2={(m.x - g.bounds.minX) * g.S} y1="0" y2={g.H} />)}
                {g.grid.parallels.map((p) => <line key={`p${p.lat}`} y1={(p.y - g.bounds.minY) * g.S} y2={(p.y - g.bounds.minY) * g.S} x1="0" x2={g.W} />)}
            </g>
            <g aria-hidden="true" fontSize="11" fill="#4a5a63">
                {g.grid.meridians.map((m) => <text key={`ml${m.lng}`} x={(m.x - g.bounds.minX) * g.S} y={g.H - 3} textAnchor="middle" style={{ fill: '#4a5a63' }}>{`${m.lng.toFixed(2)}°E`}</text>)}
                {g.grid.parallels.map((p) => <text key={`pl${p.lat}`} x={g.W - 3} y={(p.y - g.bounds.minY) * g.S - 3} textAnchor="end" style={{ fill: '#4a5a63' }}>{`${p.lat.toFixed(2)}°N`}</text>)}
            </g>

            {g.bands.map(({ j, d }) => (
                <g key={j.id}>
                    <path d={d} fill="none" stroke={INK} strokeWidth="15" strokeLinecap="butt" />
                    <path d={d} fill="none" stroke={patternFill(j.colour_token)} strokeWidth="12" strokeLinecap="butt" />
                </g>
            ))}
            <path d={g.line} fill="none" stroke="#fff" strokeWidth="3.2" strokeLinecap="round" strokeLinejoin="round" />
            <path d={g.line} fill="none" stroke={INK} strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" />

            <g aria-hidden="true">
                {g.ticks.map((t) => {
                    const major = t.m % 5000 === 0;
                    const r = major ? 11 : 7;
                    const nearAvatar = g.bands.some(({ j, mid }) => j.in_charge && Math.hypot(mid.x - t.x, mid.y - t.y) < 46);
                    return (
                        <g key={t.m}>
                            <line x1={t.x - t.nx * r} y1={t.y - t.ny * r} x2={t.x + t.nx * r} y2={t.y + t.ny * r} stroke={INK} strokeWidth={major ? 1.6 : 1} />
                            {major && !nearAvatar && <text x={t.x - t.nx * 22} y={t.y - t.ny * 22} fontSize="12" fontWeight="600" textAnchor="middle" dy="0.35em" stroke="#fff" strokeWidth="3" paintOrder="stroke">{formatChainage(t.m)}</text>}
                        </g>
                    );
                })}
            </g>

            {g.structureLabels.map(({ key, s, x, y, labelX, labelY }) => (
                <g key={key}>
                    <line x1={x} y1={y} x2={labelX + 6} y2={labelY} stroke="#7b8a92" strokeWidth="0.8" />
                    <path d={`M${x} ${y - 6} l6 6 l-6 6 l-6 -6 z`} fill="#fff" stroke={INK} strokeWidth="1.4" />
                    <text x={labelX} y={labelY} fontSize="13" textAnchor="end" dy="-0.1em">{s.title}</text>
                    <text x={labelX} y={labelY + 13} fontSize="10.5" textAnchor="end" style={{ fill: '#4a5a63' }}>{(s.fields?.find((f) => f.label === 'Chainage')?.value) ?? ''}</text>
                </g>
            ))}

            {g.labelled.map(({ key, band, x, y, labelX, labelY }) => {
                const { j } = band;
                return (
                    <g key={key}>
                        <line x1={x} y1={y} x2={labelX - 6} y2={labelY - 6} stroke={INK} strokeWidth="1" />
                        <rect x={labelX - 4} y={labelY - 24} width="266" height="54" fill="#fff" stroke={INK} strokeWidth="1" />
                        <rect x={labelX - 4} y={labelY - 24} width="9" height="54" fill={patternFill(j.colour_token)} stroke={INK} strokeWidth="1" />
                        <text x={labelX + 12} y={labelY - 7} fontSize="15" fontWeight="700">{j.code} {j.name}</text>
                        <text x={labelX + 12} y={labelY + 9} fontSize="12.5">{`${formatChainage(j.start_m)} to ${formatChainage(j.end_m)}`}</text>
                        <text x={labelX + 12} y={labelY + 23} fontSize="11.5" style={{ fill: '#4a5a63' }}>{`${(j.length_m / 1000).toFixed(1)} km${j.in_charge?.name ? `, ${j.in_charge.name}` : ', no in-charge'}`}</text>
                    </g>
                );
            })}

            {g.bands.map(({ j, mid }) => (j.in_charge ? (
                <g key={`av${j.id}`} transform={`translate(${mid.x} ${mid.y})`}>
                    <circle r="14" fill="#fff" stroke={INK} strokeWidth="2.2" />
                    <clipPath id={`jp-clip-${j.id}`}><circle r="11" /></clipPath>
                    {j.in_charge.photo ? <image href={j.in_charge.photo} x="-11" y="-11" width="22" height="22" clipPath={`url(#jp-clip-${j.id})`} preserveAspectRatio="xMidYMid slice" /> : <text fontSize="10" fontWeight="700" textAnchor="middle" dy="0.35em">{initialsOf(j.in_charge.name)}</text>}
                </g>
            ) : null))}

            <g transform={`translate(${-GUTTER_L + 24} ${g.H - 36})`} aria-label={`Scale bar, ${scaleM / 1000} kilometres`}>
                <rect x="0" y="0" width={scaleW / 2} height="7" fill={INK} /><rect x={scaleW / 2} y="0" width={scaleW / 2} height="7" fill="#fff" stroke={INK} strokeWidth="1" />
                <rect x="0" y="0" width={scaleW} height="7" fill="none" stroke={INK} strokeWidth="1" />
                {[0, 0.5, 1].map((k) => <text key={k} x={scaleW * k} y="22" fontSize="12" textAnchor="middle">{k === 0 ? '0' : `${(scaleM * k) / 1000}${k === 1 ? ' km' : ''}`}</text>)}
                <text x="0" y="38" fontSize="10" style={{ fill: '#4a5a63' }}>Web Mercator, true at {((alignment.points[0][0] + alignment.points[alignment.points.length - 1][0]) / 2).toFixed(2)}°N</text>
            </g>

            <g transform={`translate(${g.W + GUTTER_R - 54} 52)`} aria-label="North arrow">
                <circle r="26" fill="#fff" stroke={INK} strokeWidth="1.4" />
                <path d="M0 -20 L8 14 L0 8 L-8 14 Z" fill={INK} />
                <path d="M0 -20 L8 14 L0 8 Z" fill="#fff" stroke={INK} strokeWidth="1" />
                <text y="-30" fontSize="16" fontWeight="700" textAnchor="middle">N</text>
            </g>
        </svg>
    );
}

/** The chainage ruler: K0 to K48+999 with every jurisdiction as its patterned segment, a tick per km and a label per 5 km. */
export function PrintRuler({ jurisdictions, coverage }) {
    const total = coverage.corridor.length_m;
    const x = (m) => 30 + (m / total) * 940;
    return (
        <svg viewBox="0 0 1000 64" role="img" aria-label="Chainage ruler K0 to K48+999" preserveAspectRatio="none" className="jmp-ruler">
            <PatternDefs />
            {jurisdictions.map((j) => (
                <g key={j.id}>
                    <rect x={x(j.start_m)} y="10" width={x(j.end_m + 1) - x(j.start_m)} height="16" fill={patternFill(j.colour_token)} stroke={INK} strokeWidth="1" />
                    <text x={(x(j.start_m) + x(j.end_m + 1)) / 2} y="21.5" fontSize="10.5" fontWeight="700" textAnchor="middle" stroke="#fff" strokeWidth="3" paintOrder="stroke">{j.code}</text>
                </g>
            ))}
            {coverage.gaps.map((g) => <rect key={`g${g.start_m}`} x={x(g.start_m)} y="10" width={x(g.end_m + 1) - x(g.start_m)} height="16" fill="url(#jp-gap)" stroke="#c0392b" strokeWidth="1" />)}
            {Array.from({ length: Math.floor(total / 1000) + 1 }, (_, k) => (
                <g key={k}>
                    <line x1={x(k * 1000)} x2={x(k * 1000)} y1="26" y2={k % 5 === 0 ? 38 : 32} stroke={INK} strokeWidth={k % 5 === 0 ? 1.4 : 0.8} />
                    {k % 5 === 0 && total - k * 1000 > 5000 && <text x={x(k * 1000)} y="52" fontSize="11" textAnchor="middle">{`K${k}`}</text>}
                </g>
            ))}
            <text x={x(total)} y="52" fontSize="11" textAnchor="end" dx="-1">{formatChainage(total - 1)}</text>
        </svg>
    );
}
