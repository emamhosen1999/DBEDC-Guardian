import { describe, expect, it } from 'vitest';

import {
    buildGraticule, formatChainage, haversineMetres, layoutLabels, metresPerUnit, parseChainage, pointAtChainage, projectMercator, projectedBounds,
} from '../geo.js';

describe('projection', () => {
    it('is Web Mercator: the equator and the prime meridian sit at the centre of the unit square', () => {
        const p = projectMercator(0, 0);
        expect(p.x).toBeCloseTo(0.5, 10);
        expect(p.y).toBeCloseTo(0.5, 10);
        expect(projectMercator(0, 180).x).toBeCloseTo(1, 10);
    });

    it('clamps an impossible latitude instead of returning Infinity', () => {
        expect(Number.isFinite(projectMercator(90, 90).y)).toBe(true);
    });

    it('measures the corridor: S to waypoint 2 of the survey workbook is about 2.3 km', () => {
        const m = haversineMetres({ lat: 23.986737, lng: 90.362246 }, { lat: 23.977568, lng: 90.380874 });
        expect(m).toBeGreaterThan(2000);
        expect(m).toBeLessThan(2400);
    });

    it('metres per unit shrinks with latitude (Mercator x spans the equator)', () => {
        expect(metresPerUnit(0)).toBeCloseTo(40075016.686, 0);
        expect(metresPerUnit(60)).toBeCloseTo(40075016.686 / 2, 0);
    });
});

describe('chainage', () => {
    it('formats metres as K<km>+<mmm> and refuses nonsense', () => {
        expect(formatChainage(12500)).toBe('K12+500');
        expect(formatChainage(47611)).toBe('K47+611');
        expect(formatChainage(0)).toBe('K0+000');
        expect(formatChainage(-1)).toBe('');
        expect(formatChainage(NaN)).toBe('');
    });

    it('reads K, Ch and bare metres and rejects the rest', () => {
        expect(parseChainage('K12+500')).toBe(12500);
        expect(parseChainage('Ch 24+500')).toBe(24500);
        expect(parseChainage('37120')).toBe(37120);
        expect(parseChainage('somewhere')).toBeNull();
        expect(parseChainage(null)).toBeNull();
    });

    it('K12+500 maps to a point between the two vertices that bracket it', () => {
        const line = [[23.98, 90.36, 0], [23.93, 90.45, 12000], [23.83, 90.54, 26000]];
        const at = pointAtChainage(line, 12500);
        expect(at.lat).toBeLessThan(23.93);
        expect(at.lat).toBeGreaterThan(23.83);
        expect(at.lng).toBeGreaterThan(90.45);
        expect(pointAtChainage(line, 12000)).toEqual({ lat: 23.93, lng: 90.45 });
        expect(pointAtChainage(line, 26001)).toBeNull();
    });
});

describe('graticule', () => {
    it('places 3 to 8 lines each way on round fractions of a degree', () => {
        const b = projectedBounds([{ lat: 23.69, lng: 90.36 }, { lat: 23.99, lng: 90.59 }]);
        const g = buildGraticule(b);
        expect(g.meridians.length).toBeGreaterThanOrEqual(2);
        expect(g.meridians.length).toBeLessThanOrEqual(8);
        expect([1, 0.5, 0.25, 0.1, 0.05, 0.025, 0.01, 0.005]).toContain(g.lngStep);
        g.meridians.forEach((m) => expect(Math.abs(m.lng / g.lngStep - Math.round(m.lng / g.lngStep))).toBeLessThan(1e-6));
    });
});

describe('label layout', () => {
    it('never overlaps and keeps every label inside the frame', () => {
        const placed = layoutLabels([{ y: 10 }, { y: 12 }, { y: 14 }, { y: 95 }, { y: 99 }], { x: 0, top: 0, bottom: 100, gap: 20 });
        for (let i = 1; i < placed.length; i += 1) expect(placed[i].labelY - placed[i - 1].labelY).toBeGreaterThanOrEqual(20 - 1e-9);
        placed.forEach((p) => { expect(p.labelY).toBeGreaterThanOrEqual(0); expect(p.labelY).toBeLessThanOrEqual(100); });
    });
});
