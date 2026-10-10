import React, { memo, useCallback, useEffect, useMemo, useRef, useState } from 'react';

import Icon from '../Icon.jsx';
import { buildGraticule, formatChainage, linePath, metresPerUnit, pointAtChainage, projectedBounds, projectMercator, ringPath } from './geo.js';

const MIN_Z = 1;
const MAX_Z = 32;
const DENSE = 150; // layers with more features than this draw small dots

const clamp = (v, lo, hi) => Math.min(hi, Math.max(lo, v));

function useSize(ref) {
    const [size, setSize] = useState({ w: 0, h: 0 });
    useEffect(() => {
        const el = ref.current;
        if (!el) return undefined;
        const read = () => setSize({ w: el.clientWidth, h: el.clientHeight });
        read();
        if (typeof ResizeObserver === 'undefined') return undefined;
        const ro = new ResizeObserver(read);
        ro.observe(el);
        return () => ro.disconnect();
    }, [ref]);
    return size;
}

/** Everything drawn inside the pan/zoom group. Memoised: a pan only changes the group's transform. */
const Scene = memo(function Scene({ districts, context, layers, alignment, toWorld, bounds, S, z, selectedId, onSelect, onTip }) {
    const pts = useMemo(() => alignment?.points ?? [], [alignment]);
    const line = useMemo(() => (pts.length > 1 ? linePath(pts.map(([lat, lng]) => [lat, lng]), toWorld) : ''), [pts, toWorld]);
    const regions = useMemo(() => (districts?.districts ?? []).map((d) => ({
        name: d.name,
        d: d.rings.map((ring) => ringPath(ring, toWorld)).join(''),
        label: (() => {
            const ring = d.rings.reduce((a, b) => (b.length > a.length ? b : a), d.rings[0] ?? []);
            if (!ring.length) return null;
            const lngs = ring.map((p) => p[0]);
            const lats = ring.map((p) => p[1]);
            return toWorld((Math.min(...lats) + Math.max(...lats)) / 2, (Math.min(...lngs) + Math.max(...lngs)) / 2);
        })(),
    })), [districts, toWorld]);
    const roads = useMemo(() => (context?.highlights ?? []).map((h, i) => ({
        id: `${h.id}-${i}`, ref: h.ref, major: !!h.major, name: h.name,
        d: linePath(h.coordinates.map(([lng, lat]) => [lat, lng]), toWorld),
        at: h.position ? toWorld(h.position[1], h.position[0]) : null,
    })), [context, toWorld]);
    const grid = useMemo(() => {
        if (!bounds) return null;
        const g = buildGraticule(bounds);
        const x0 = 0; const y0 = 0;
        const x1 = bounds.w * S; const y1 = bounds.h * S;
        return {
            v: g.meridians.map((m) => ({ k: m.lng, x: (m.x - bounds.minX) * S })),
            h: g.parallels.map((p) => ({ k: p.lat, y: (p.y - bounds.minY) * S })),
            box: { x0: x0 - x1, y0: y0 - y1, x1: x1 * 2, y1: y1 * 2 },
        };
    }, [bounds, S]);
    const ticks = useMemo(() => {
        const out = [];
        const length = alignment?.length_m ?? 0;
        for (let m = 0; m <= length; m += 1000) {
            const a = pointAtChainage(pts, Math.max(0, m - 60));
            const b = pointAtChainage(pts, Math.min(length, m + 60));
            const p = pointAtChainage(pts, m);
            if (!a || !b || !p) continue;
            const wa = toWorld(a.lat, a.lng);
            const wb = toWorld(b.lat, b.lng);
            const wp = toWorld(p.lat, p.lng);
            out.push({ m, x: wp.x, y: wp.y, angle: (Math.atan2(wb.y - wa.y, wb.x - wa.x) * 180) / Math.PI });
        }
        const end = pointAtChainage(pts, length);
        if (end && length % 1000 !== 0) {
            const wp = toWorld(end.lat, end.lng);
            out.push({ m: length, x: wp.x, y: wp.y, angle: 0, terminal: true });
        }
        return out;
    }, [pts, alignment, toWorld]);

    const inv = 1 / z;
    const pick = (f, l) => (e) => { e.stopPropagation(); onSelect(f, l); };

    return (
        <>
            {grid && (
                <g aria-hidden="true">
                    {grid.v.map((l) => <line key={`v${l.k}`} className="cy-map__grid" x1={l.x} x2={l.x} y1={grid.box.y0} y2={grid.box.y1} />)}
                    {grid.h.map((l) => <line key={`h${l.k}`} className="cy-map__grid" y1={l.y} y2={l.y} x1={grid.box.x0} x2={grid.box.x1} />)}
                </g>
            )}
            <g>
                {regions.map((r) => (
                    <g key={r.name}>
                        <title>{r.name} district</title>
                        <path className="cy-map__region" d={r.d} />
                    </g>
                ))}
            </g>
            <g aria-hidden="true">
                {regions.map((r) => r.label && (
                    <text key={r.name} className="cy-map__regionname" transform={`translate(${r.label.x} ${r.label.y}) scale(${inv})`} textAnchor="middle">{r.name}</text>
                ))}
                {roads.map((r) => <path key={r.id} className="cy-map__road" data-major={r.major} d={r.d} />)}
                {z >= 2.5 && roads.filter((r) => r.major && r.ref && r.at).map((r) => (
                    <text key={`t${r.id}`} className="cy-map__roadref" transform={`translate(${r.at.x} ${r.at.y}) scale(${inv})`} dy="-3" textAnchor="middle">{r.ref}</text>
                ))}
            </g>

            {layers.filter((l) => l.features.some((f) => f.shape === 'band' || f.shape === 'polygon' || f.shape === 'route' || f.shape === 'circle')).map((l) => (
                <g key={`s${l.key}`}>
                    {l.features.filter((f) => f.shape === 'polygon' && f.path).map((f) => (
                        <path key={f.id} className="cy-map__zone" data-tone={f.tone} d={`${linePath(f.path, toWorld)}Z`} onClick={pick(f, l)} />
                    ))}
                    {l.features.filter((f) => f.shape === 'circle').map((f) => {
                        const c = toWorld(f.lat, f.lng);
                        const rpx = (f.radius_m / metresPerUnit(f.lat)) * S;
                        return <circle key={f.id} className="cy-map__zone-ring" data-tone={f.tone} cx={c.x} cy={c.y} r={Math.max(rpx, 7 * inv)} />;
                    })}
                </g>
            ))}

            {layers.map((l) => l.features.filter((f) => f.shape === 'band' && f.path).map((f) => {
                const d = linePath(f.path, toWorld);
                return (
                    <g key={f.id} data-tone={f.tone}>
                        <path className="cy-map__band" d={d} data-selected={selectedId === f.id} />
                        <path
                            className="cy-map__hit" d={d} role="button" tabIndex={-1} aria-label={`${f.title}, ${l.label}`}
                            onClick={pick(f, l)} onPointerMove={(e) => onTip(e, f, l)} onPointerLeave={() => onTip(null)}
                        />
                    </g>
                );
            }))}

            {line && (
                <g aria-hidden="true">
                    <path className="cy-map__linecase" d={line} />
                    <path className="cy-map__line" d={line} />
                </g>
            )}

            {layers.map((l) => l.features.filter((f) => f.shape === 'route' && f.path).map((f) => (
                <g key={f.id} data-tone={f.tone}>
                    <path className="cy-map__route" d={linePath(f.path, toWorld)} />
                    <path className="cy-map__hit" d={linePath(f.path, toWorld)} onClick={pick(f, l)} onPointerMove={(e) => onTip(e, f, l)} onPointerLeave={() => onTip(null)} />
                </g>
            )))}

            <g aria-hidden="true">
                {ticks.map((t) => (
                    <g key={t.m} transform={`translate(${t.x} ${t.y}) rotate(${t.angle}) scale(${inv})`}>
                        <line className="cy-map__tick" x1="0" x2="0" y1={t.m % 5000 === 0 || t.terminal ? -6 : -3} y2={t.m % 5000 === 0 || t.terminal ? 6 : 3} />
                        {(t.m % 5000 === 0 || t.terminal) && (z >= 1.5 || t.m % 10000 === 0 || t.terminal || t.m === 0) && (
                            <text className="cy-map__kmlabel" transform={`rotate(${-t.angle})`} x="9" y="-7">{formatChainage(t.m)}</text>
                        )}
                    </g>
                ))}
            </g>

            {layers.map((l) => {
                const pointFeatures = l.features.filter((f) => (f.shape === 'point' || f.shape === 'circle') && Number.isFinite(f.lat));
                const dense = pointFeatures.length > DENSE;
                const focusable = pointFeatures.length <= 40;
                return (
                    <g key={`p${l.key}`}>
                        {pointFeatures.map((f) => {
                            const p = toWorld(f.lat, f.lng);
                            const selected = selectedId === f.id;
                            const r = dense ? 2.6 : 4.6;
                            const pulse = !dense && (f.tone === 'crit' || f.person?.status === 'active') && pointFeatures.length <= 80;
                            const diamond = l.key === 'corridor.structures';
                            return (
                                <g
                                    key={f.id} className="cy-map__marker" data-tone={f.tone} data-selected={selected}
                                    transform={`translate(${p.x} ${p.y}) scale(${inv})`}
                                    role="button" tabIndex={focusable ? 0 : -1} aria-label={`${f.title}, ${l.label}`}
                                    onClick={pick(f, l)}
                                    onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onSelect(f, l); } }}
                                    onPointerMove={(e) => onTip(e, f, l)} onPointerLeave={() => onTip(null)}
                                >
                                    {pulse && <circle className="cy-map__pulse" r={r} />}
                                    <circle className="cy-map__focus" r={r + 4} />
                                    {diamond
                                        ? <rect className="cy-map__dot" x={-r} y={-r} width={r * 2} height={r * 2} transform="rotate(45)" />
                                        : <circle className="cy-map__dot" r={selected ? r + 1.5 : r} />}
                                </g>
                            );
                        })}
                    </g>
                );
            })}
        </>
    );
});

/**
 * Cyber's solid vector corridor map: district fills, road context, the centreline in the theme colour with
 * its glow and a chainage scale, and every visible layer on top. Pure SVG (no tile server, no API key).
 * Pan by dragging, zoom with the buttons, +/-, double click or Ctrl+wheel; both are bounded to the corridor's
 * extent. Keyboard: arrows pan, + - zoom, 0 resets, [ and ] step through the markers, Escape closes the popup.
 */
export default function CorridorSvgMap({ alignment, layers, districts, context, selectedId, onSelect, renderPopup, focus, hud, className = '', label = 'Corridor map' }) {
    const ref = useRef(null);
    const size = useSize(ref);
    const pts = useMemo(() => alignment?.points ?? [], [alignment]);
    const bounds = useMemo(() => projectedBounds(pts.map(([lat, lng]) => ({ lat, lng })), 0.12), [pts]);
    const S = bounds && size.w > 0 && size.h > 0 ? Math.min(size.w / bounds.w, size.h / bounds.h) : 1;
    const toWorld = useCallback((lat, lng) => {
        const p = projectMercator(lat, lng);
        return { x: (p.x - (bounds?.minX ?? 0)) * S, y: (p.y - (bounds?.minY ?? 0)) * S };
    }, [bounds, S]);

    const home = useMemo(() => (bounds ? { z: 1, cx: (bounds.minX + bounds.maxX) / 2, cy: (bounds.minY + bounds.maxY) / 2 } : { z: 1, cx: 0, cy: 0 }), [bounds]);
    const [view, setView] = useState(home);
    useEffect(() => { setView(home); }, [home]);
    const limit = useCallback((v) => (bounds ? { z: clamp(v.z, MIN_Z, MAX_Z), cx: clamp(v.cx, bounds.minX, bounds.maxX), cy: clamp(v.cy, bounds.minY, bounds.maxY) } : v), [bounds]);

    const tx = size.w / 2 - (view.cx - (bounds?.minX ?? 0)) * S * view.z;
    const ty = size.h / 2 - (view.cy - (bounds?.minY ?? 0)) * S * view.z;
    const toScreen = useCallback((lat, lng) => { const w = toWorld(lat, lng); return { x: tx + w.x * view.z, y: ty + w.y * view.z }; }, [toWorld, tx, ty, view.z]);

    const zoomBy = useCallback((factor, sx = size.w / 2, sy = size.h / 2) => {
        setView((v) => {
            const z2 = clamp(v.z * factor, MIN_Z, MAX_Z);
            const k = S * v.z; const k2 = S * z2;
            return limit({ z: z2, cx: v.cx + (sx - size.w / 2) / k - (sx - size.w / 2) / k2, cy: v.cy + (sy - size.h / 2) / k - (sy - size.h / 2) / k2 });
        });
    }, [S, size.w, size.h, limit]);

    // Drag to pan.
    const drag = useRef(null);
    const [dragging, setDragging] = useState(false);
    const moved = useRef(false);
    const onPointerDown = (e) => {
        if (e.button !== 0) return;
        drag.current = { x: e.clientX, y: e.clientY };
        moved.current = false;
    };
    const onPointerMove = (e) => {
        if (!drag.current) return;
        const dx = e.clientX - drag.current.x;
        const dy = e.clientY - drag.current.y;
        if (!moved.current && Math.hypot(dx, dy) < 4) return;
        if (!moved.current) { moved.current = true; setDragging(true); try { e.currentTarget.setPointerCapture(e.pointerId); } catch { /* not capturable */ } }
        drag.current = { x: e.clientX, y: e.clientY };
        setView((v) => limit({ ...v, cx: v.cx - dx / (S * v.z), cy: v.cy - dy / (S * v.z) }));
    };
    const endDrag = () => { drag.current = null; setDragging(false); };

    // Ctrl/Cmd + wheel zooms at the cursor; a plain wheel scrolls the page (Cyber's zoomOnScroll is off).
    useEffect(() => {
        const el = ref.current;
        if (!el) return undefined;
        const onWheel = (e) => {
            if (!(e.ctrlKey || e.metaKey)) return;
            e.preventDefault();
            const r = el.getBoundingClientRect();
            zoomBy(e.deltaY < 0 ? 1.4 : 1 / 1.4, e.clientX - r.left, e.clientY - r.top);
        };
        el.addEventListener('wheel', onWheel, { passive: false });
        return () => el.removeEventListener('wheel', onWheel);
    }, [zoomBy]);

    // Fly to a point asked for from outside (roster drawer, ruler).
    useEffect(() => {
        if (!focus || !bounds) return;
        const p = projectMercator(focus.lat, focus.lng);
        setView(limit({ z: focus.z ?? 6, cx: p.x, cy: p.y }));
    }, [focus, bounds]); // eslint-disable-line react-hooks/exhaustive-deps

    // Tooltip.
    const [tip, setTip] = useState(null);
    const onTip = useCallback((e, f, l) => {
        if (!e) { setTip(null); return; }
        const r = ref.current?.getBoundingClientRect();
        if (!r) return;
        setTip({ x: e.clientX - r.left, y: e.clientY - r.top, title: f.title, sub: l.label });
    }, []);

    // Keyboard.
    const all = useMemo(() => layers.flatMap((l) => l.features.map((f) => ({ f, l }))).sort((a, b) => (a.f.chainage_m ?? 1e9) - (b.f.chainage_m ?? 1e9)), [layers]);
    const onKeyDown = (e) => {
        if (e.target !== e.currentTarget && e.target.closest?.('.cy-pop, .cy-drawer, .cy-map__ctrl')) return;
        const step = 60 / (S * view.z);
        const key = e.key;
        if (key === '+' || key === '=') zoomBy(1.6);
        else if (key === '-' || key === '_') zoomBy(1 / 1.6);
        else if (key === '0') setView(home);
        else if (key === 'ArrowLeft') setView((v) => limit({ ...v, cx: v.cx - step }));
        else if (key === 'ArrowRight') setView((v) => limit({ ...v, cx: v.cx + step }));
        else if (key === 'ArrowUp') setView((v) => limit({ ...v, cy: v.cy - step }));
        else if (key === 'ArrowDown') setView((v) => limit({ ...v, cy: v.cy + step }));
        else if (key === ']' || key === '[') {
            if (!all.length) return;
            const i = all.findIndex((x) => x.f.id === selectedId);
            const next = all[(i + (key === ']' ? 1 : -1) + all.length) % all.length];
            onSelect(next.f, next.l);
        } else if (key === 'Escape' && selectedId) { e.stopPropagation(); onSelect(null); } else return;
        e.preventDefault();
    };

    const selected = useMemo(() => {
        for (const l of layers) {
            const f = l.features.find((x) => x.id === selectedId);
            if (f) return { f, l };
        }
        return null;
    }, [layers, selectedId]);
    const anchor = selected && Number.isFinite(selected.f.lat) ? toScreen(selected.f.lat, selected.f.lng) : null;

    const graticule = useMemo(() => (bounds ? buildGraticule(bounds) : null), [bounds]);
    const hasLine = pts.length > 1;

    return (
        <div
            ref={ref} className={`cy-map ${className}`.trim()} tabIndex={0} role="group" aria-label={label}
            aria-describedby="cy-map-help" onKeyDown={onKeyDown}
        >
            <span id="cy-map-help" className="visually-hidden" style={{ position: 'absolute', width: 1, height: 1, overflow: 'hidden', clip: 'rect(0 0 0 0)' }}>
                Arrow keys pan, plus and minus zoom, zero resets, left and right square brackets step through markers, Escape closes the popup.
            </span>
            {size.w > 0 && hasLine && (
                <svg
                    className="cy-map__svg" width={size.w} height={size.h} viewBox={`0 0 ${size.w} ${size.h}`} data-dragging={dragging}
                    style={{ touchAction: view.z > 1 ? 'none' : 'pan-y' }}
                    onPointerDown={onPointerDown} onPointerMove={onPointerMove} onPointerUp={endDrag} onPointerCancel={endDrag}
                    onDoubleClick={(e) => { const r = ref.current.getBoundingClientRect(); zoomBy(1.8, e.clientX - r.left, e.clientY - r.top); }}
                    onClickCapture={(e) => { if (moved.current) { e.stopPropagation(); moved.current = false; } }}
                    onClick={() => { if (selectedId) onSelect(null); }}
                >
                    <g transform={`translate(${tx} ${ty}) scale(${view.z})`}>
                        <Scene
                            districts={districts} context={context} layers={layers} alignment={alignment} toWorld={toWorld}
                            bounds={bounds} S={S} z={view.z} selectedId={selectedId} onSelect={(f, l) => onSelect(f, l)} onTip={onTip}
                        />
                    </g>
                    {graticule && (
                        <g aria-hidden="true">
                            {graticule.meridians.map((m) => {
                                const x = tx + (m.x - bounds.minX) * S * view.z;
                                return x > 8 && x < size.w - 24 ? <text key={`gx${m.lng}`} className="cy-map__gridlabel" x={x} y={size.h - 4} textAnchor="middle">{`${m.lng.toFixed(2)}°E`}</text> : null;
                            })}
                            {graticule.parallels.map((p) => {
                                const y = ty + (p.y - bounds.minY) * S * view.z;
                                return y > 12 && y < size.h - 14 ? <text key={`gy${p.lat}`} className="cy-map__gridlabel" x={size.w - 4} y={y - 2} textAnchor="end">{`${p.lat.toFixed(2)}°N`}</text> : null;
                            })}
                        </g>
                    )}
                </svg>
            )}
            {!hasLine && <div className="cy-map__empty">The corridor centreline is not available yet.</div>}

            <div className="cy-map__ctrl cy-map__ctrl--zoom">
                <button type="button" className="cy-map__btn" aria-label="Zoom in" onClick={() => zoomBy(1.6)} disabled={view.z >= MAX_Z}><Icon name="plus-lg" /></button>
                <button type="button" className="cy-map__btn" aria-label="Zoom out" onClick={() => zoomBy(1 / 1.6)} disabled={view.z <= MIN_Z}><Icon name="dash-lg" /></button>
                <button type="button" className="cy-map__btn" aria-label="Reset view" onClick={() => setView(home)} disabled={view.z === 1 && view.cx === home.cx && view.cy === home.cy}><Icon name="arrows-angle-expand" /></button>
            </div>
            {hud && <div className="cy-map__ctrl cy-map__ctrl--hud">{hud}</div>}

            <div className="cy-map__legend" aria-hidden="true">
                {layers.slice(0, 6).map((l) => <span key={l.key} data-tone={l.tone}><i />{l.label}</span>)}
                {layers.length > 6 && <span>+{layers.length - 6} more</span>}
            </div>

            {tip && !selected && <div className="cy-map__tip" style={{ left: tip.x, top: tip.y }} role="tooltip">{tip.title}<small>{tip.sub}</small></div>}
            {selected && anchor && renderPopup && (
                <div
                    style={{ position: 'absolute', left: clamp(anchor.x + 12, 8, Math.max(8, size.w - 280)), top: clamp(anchor.y - 36, 8, Math.max(8, size.h - 120)), zIndex: 6 }}
                >
                    {renderPopup({ feature: selected.f, layer: selected.l, close: () => onSelect(null) })}
                </div>
            )}
            {selected && !anchor && renderPopup && (
                <div style={{ position: 'absolute', left: 8, top: 8, zIndex: 6 }}>
                    {renderPopup({ feature: selected.f, layer: selected.l, close: () => onSelect(null) })}
                </div>
            )}
        </div>
    );
}
