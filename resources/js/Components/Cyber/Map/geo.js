/**
 * Pure geography for the Cyber corridor map: Web Mercator projection, chainage maths, graticule and label
 * layout. Ported from dhakabypass lib/corridor/map.js and chainage.js (projectMercator, haversineMetres,
 * buildGraticule, layoutLabels, formatChainage, parseChainage). No DOM, no React, so it is unit-tested against
 * known values. Nothing is interpolated beyond a straight segment between two real vertices.
 */

const MAX_LAT = 85.05112878;
export const EARTH_EQUATOR_M = 40075016.686;

/** Web Mercator normalised to the unit square. Latitude is clamped so a bad row cannot produce Infinity. */
export function projectMercator(lat, lng) {
    const phi = (Math.max(-MAX_LAT, Math.min(MAX_LAT, Number(lat))) * Math.PI) / 180;
    return { x: (Number(lng) + 180) / 360, y: (1 - Math.log(Math.tan(phi) + 1 / Math.cos(phi)) / Math.PI) / 2 };
}

/** Metres between two coordinates (haversine, Earth radius 6371.0088 km as the survey workbook used). */
export function haversineMetres(a, b) {
    const R = 6371008.8;
    const rad = (d) => (Number(d) * Math.PI) / 180;
    const dLat = rad(b.lat - a.lat);
    const dLng = rad(b.lng - a.lng);
    const s = Math.sin(dLat / 2) ** 2 + Math.cos(rad(a.lat)) * Math.cos(rad(b.lat)) * Math.sin(dLng / 2) ** 2;
    return 2 * R * Math.asin(Math.min(1, Math.sqrt(s)));
}

/** Highway chainage in integer metres, shown K<km>+<mmm>. */
export function formatChainage(metres) {
    if (!Number.isFinite(metres) || metres < 0) return '';
    const m = Math.round(metres);
    return `K${Math.floor(m / 1000)}+${String(m % 1000).padStart(3, '0')}`;
}

/** "K3+900", "Ch 3+900" or a bare metre count; null when unreadable. */
export function parseChainage(text) {
    if (typeof text === 'number') return Number.isInteger(text) && text >= 0 ? text : null;
    if (typeof text !== 'string') return null;
    const s = text.trim().toLowerCase();
    const pair = /^(?:k|km|ch)?\s*(\d{1,3})\s*\+\s*(\d{3})$/.exec(s);
    if (pair) return Number(pair[1]) * 1000 + Number(pair[2]);
    return /^\d+$/.test(s) ? Number(s) : null;
}

/** Position at a chainage along `points` ([lat, lng, chainage_m], ascending), or null outside the line. */
export function pointAtChainage(points, metres) {
    if (!points?.length || metres < points[0][2] || metres > points[points.length - 1][2]) return null;
    for (let i = 1; i < points.length; i += 1) {
        if (metres <= points[i][2]) {
            const [la, ga, ma] = points[i - 1];
            const [lb, gb, mb] = points[i];
            const t = mb > ma ? (metres - ma) / (mb - ma) : 0;
            return { lat: la + (lb - la) * t, lng: ga + (gb - ga) * t };
        }
    }
    return null;
}

/** Ground metres per unit of projected x at a latitude (Mercator x spans the equator's circumference). */
export const metresPerUnit = (lat) => EARTH_EQUATOR_M * Math.cos((lat * Math.PI) / 180);

/**
 * Fit a set of coordinates: the projected bounding box (padded) every pixel scale is derived from.
 * @returns {{minX:number,minY:number,maxX:number,maxY:number,w:number,h:number}|null}
 */
export function projectedBounds(coords, pad = 0.08) {
    const pts = coords.filter((c) => Number.isFinite(c.lat) && Number.isFinite(c.lng)).map((c) => projectMercator(c.lat, c.lng));
    if (!pts.length) return null;
    const xs = pts.map((p) => p.x);
    const ys = pts.map((p) => p.y);
    let minX = Math.min(...xs);
    let maxX = Math.max(...xs);
    let minY = Math.min(...ys);
    let maxY = Math.max(...ys);
    const w = Math.max(maxX - minX, 1e-6);
    const h = Math.max(maxY - minY, 1e-6);
    minX -= w * pad; maxX += w * pad; minY -= h * pad; maxY += h * pad;
    return { minX, minY, maxX, maxY, w: maxX - minX, h: maxY - minY };
}

/** Ladder of round fractions of a degree, so a grid line is never at 0.0741 degrees. */
const GRID_STEPS = [1, 0.5, 0.25, 0.1, 0.05, 0.025, 0.01, 0.005]; // not-data: graticule step ladder in degrees

/** Meridians and parallels across a projected box, placed by the same projection as the road. */
export function buildGraticule({ minX, maxX, minY, maxY }) {
    const lngOf = (x) => x * 360 - 180;
    const latOf = (y) => (Math.atan(Math.sinh(Math.PI * (1 - 2 * y))) * 180) / Math.PI;
    const west = lngOf(minX);
    const east = lngOf(maxX);
    const north = latOf(minY);
    const south = latOf(maxY);
    const pick = (span) => GRID_STEPS.find((st) => span / st >= 3) || GRID_STEPS[GRID_STEPS.length - 1];
    const lngStep = pick(east - west);
    const latStep = pick(north - south);
    const ticks = (lo, hi, step) => {
        const out = [];
        for (let v = Math.ceil(lo / step) * step; v <= hi + 1e-9; v += step) out.push(Number(v.toFixed(6)));
        return out;
    };
    return {
        meridians: ticks(west, east, lngStep).map((lng) => ({ lng, x: projectMercator(0, lng).x })),
        parallels: ticks(south, north, latStep).map((lat) => ({ lat, y: projectMercator(lat, 0).y })),
        lngStep,
        latStep,
    };
}

/**
 * Stack labels down a gutter without overlapping: each wants its own y, a forward pass pushes it below the one
 * above, a backward pass lifts the stack back if it ran past the bottom. The leader line keeps the truth.
 */
export function layoutLabels(items, { x, top, bottom, gap = 26 } = {}) {
    const sorted = (items || []).slice().sort((a, b) => a.y - b.y);
    if (!sorted.length) return [];
    let cursor = top;
    const placed = sorted.map((it) => {
        const labelY = Math.max(it.y, cursor);
        cursor = labelY + gap;
        return { ...it, labelX: x, labelY };
    });
    let limit = bottom;
    for (let i = placed.length - 1; i >= 0; i -= 1) {
        if (placed[i].labelY > limit) placed[i].labelY = Math.max(top, limit);
        limit = placed[i].labelY - gap;
    }
    return placed;
}

/** Ring -> SVG path in projected-then-scaled space. */
export function ringPath(ring, toWorld) {
    return `${ring.map(([lng, lat], i) => {
        const p = toWorld(lat, lng);
        return `${i === 0 ? 'M' : 'L'}${p.x.toFixed(1)},${p.y.toFixed(1)}`;
    }).join('')}Z`;
}

/** [[lat, lng], ...] -> open SVG path. */
export function linePath(points, toWorld) {
    return points.map(([lat, lng], i) => {
        const p = toWorld(lat, lng);
        return `${i === 0 ? 'M' : 'L'}${p.x.toFixed(1)},${p.y.toFixed(1)}`;
    }).join('');
}
