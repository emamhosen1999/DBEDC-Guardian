import { describe, expect, it } from 'vitest';
import { judge, CLARITY_MESSAGES } from '../documentPrecheck';

/** A synthetic "printed page": light paper with rows of dark 2px strokes in glyph-like blocks. */
function page(w = 1000, h = 1400, { paper = 245, ink = 20, density = 1 } = {}) {
    const g = new Uint8Array(w * h).fill(paper);
    for (let row = 40; row < h - 40; row += 26) {
        for (let x = 30; x < w - 30; x += 11) {
            if (((x * 7 + row * 13) % 9) / 9 > density * 0.6) continue;
            for (let dy = 0; dy < 14; dy++) for (let dx = 0; dx < 6; dx++) if ((dx < 3 || dy < 3 || dy > 10) && (dx + dy) % 3 !== 0) g[(row + dy) * w + x + dx] = ink;
        }
    }
    return g;
}
function boxBlur(g, w, h, radius, passes = 3) {
    let src = Float32Array.from(g);
    for (let p = 0; p < passes; p++) {
        const out = new Float32Array(src.length);
        for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) {
            let s = 0, c = 0;
            for (let dy = -radius; dy <= radius; dy++) for (let dx = -radius; dx <= radius; dx++) {
                const yy = y + dy, xx = x + dx;
                if (yy >= 0 && yy < h && xx >= 0 && xx < w) { s += src[yy * w + xx]; c++; }
            }
            out[y * w + x] = s / c;
        }
        src = out;
    }
    return Uint8Array.from(src.map(Math.round));
}
const run = (grey, w = 1000, h = 1400, size = { width: 2400, height: 3300 }) => judge({ ...size, grey, gw: w, gh: h });

describe('documentPrecheck (mirrors the server clarity rules)', () => {
    it('passes a sharp page, including a sparse one', () => {
        expect(run(page()).passed).toBe(true);
        expect(run(page(1000, 1400, { density: 0.5 })).passed).toBe(true);
    });

    it('fails a blurred page with the blurry guidance', () => {
        const w = 1000, h = 1400;
        const sharp = page(w, h);
        expect(judge({ width: 2400, height: 3300, grey: sharp, gw: w, gh: h }).passed).toBe(true);
        const r = judge({ width: 2400, height: 3300, grey: boxBlur(sharp, w, h, 2, 2), gw: w, gh: h });
        expect(r.passed).toBe(false);
        expect(r.code).toBe('blurry');
        expect(r.message).toBe(CLARITY_MESSAGES.blurry);
        expect(r.message).toContain('Hold the phone steady');
    });

    it('fails dark, washed-out and blank pages with their own message', () => {
        expect(run(page(1000, 1400, { paper: 38, ink: 4 })).code).toBe('dark');
        expect(run(page(1000, 1400, { paper: 250, ink: 200 })).code).toBe('bright');
        expect(run(new Uint8Array(1000 * 1400).fill(244)).code).toBe('blank');
    });

    it('fails a low-resolution image before looking at its pixels', () => {
        const r = run(page(), 1000, 1400, { width: 700, height: 495 });
        expect(r.code).toBe('too_small');
        expect(r.message).toContain('too small');
        expect(run(page(), 1000, 1400, { width: 1100, height: 1500 }).passed).toBe(true); // short edge 1100 >= 800, long 1500 >= 1200
        expect(run(page(), 1000, 1400, { width: 1100, height: 700 }).code).toBe('too_small'); // short edge under 800
    });
});
