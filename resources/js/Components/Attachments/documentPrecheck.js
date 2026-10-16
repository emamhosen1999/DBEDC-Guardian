/**
 * Instant client-side clarity pre-check for a photographed document: the SAME rules and messages as the server's
 * AttachmentClarityService (resolution, ink contrast / exposure, not blank, sharpness via the variance of the Laplacian over
 * the busiest tiles of a ~1000 px grey copy). It only saves a round trip: the server stays authoritative.
 * The values mirror config/attachments.php.
 */
export const CLARITY = {
    minLongEdge: 1200,
    minShortEdge: 800,
    sharpnessLongEdge: 1000,
    tile: 32,
    topTileFraction: 0.15,
    minSharpness: 110,
    minMean: 40,
    maxMean: 225,
    minInkContrast: 70,
    minInkFraction: 0.002,
};

export const CLARITY_MESSAGES = {
    ok: 'The document is clear.',
    blurry: 'This scan is too blurry to read. Hold the phone steady, use good light and fill the frame with the document, then upload it again.',
    dark: 'This photo is too dark to read. Move to a brighter place or turn on a light, then take it again.',
    bright: 'This photo is too bright and washed out. Avoid direct glare or flash on the paper, then take it again.',
    low_contrast: 'The text does not stand out from the paper. Use even light, hold the phone parallel to the page and take it again.',
    too_small: 'This image is too small to read. Move closer so the document fills the frame, or use a higher-resolution photo, then upload it again.',
    blank: 'The page looks blank. Make sure the document is facing the camera and fully in the frame, then upload it again.',
    unreadable: 'This file could not be read as an image or PDF. Please upload a clear photo, JPEG, PNG or PDF.',
};

const verdict = (code, metrics) => ({ passed: code === 'ok', code, message: CLARITY_MESSAGES[code], metrics });

/** Grey (0-255) pixels in row-major order -> exposure, ink and sharpness metrics. */
export function analyzeGrey(grey, w, h, cfg = CLARITY) {
    const n = w * h;
    const hist = new Array(256).fill(0);
    let sum = 0;
    for (let i = 0; i < n; i++) { hist[grey[i]]++; sum += grey[i]; }
    const mean = sum / n;
    const percentile = (p) => {
        const target = p * n;
        let acc = 0;
        for (let v = 0; v < 256; v++) { acc += hist[v]; if (acc >= target) return v; }
        return 255;
    };
    const paper = percentile(0.5);
    const inkLevel = percentile(0.01);
    const inkCut = Math.max(0, paper - 45);
    let ink = 0;
    for (let v = 0; v < inkCut; v++) ink += hist[v];

    return { mean, inkContrast: paper - inkLevel, inkFraction: ink / n, sharpness: sharpness(grey, w, h, cfg) };
}

function sharpness(grey, w, h, cfg) {
    const tile = cfg.tile;
    const tx = Math.max(1, Math.floor(w / tile));
    const ty = Math.max(1, Math.floor(h / tile));
    const sum = new Float64Array(tx * ty);
    const sq = new Float64Array(tx * ty);
    const cnt = new Uint32Array(tx * ty);
    for (let y = 1; y < h - 1; y++) {
        const row = y * w;
        const tyi = Math.min(ty - 1, Math.floor(y / tile));
        for (let x = 1; x < w - 1; x++) {
            const i = row + x;
            const lap = 4 * grey[i] - grey[i - 1] - grey[i + 1] - grey[i - w] - grey[i + w];
            const t = tyi * tx + Math.min(tx - 1, Math.floor(x / tile));
            sum[t] += lap; sq[t] += lap * lap; cnt[t]++;
        }
    }
    const vars = [];
    for (let t = 0; t < cnt.length; t++) {
        if (cnt[t] > 0) { const m = sum[t] / cnt[t]; vars.push(sq[t] / cnt[t] - m * m); }
    }
    vars.sort((a, b) => b - a);
    const take = Math.max(1, Math.ceil(vars.length * cfg.topTileFraction));
    return vars.slice(0, take).reduce((a, b) => a + b, 0) / take;
}

/** The verdict for an image of the given ORIGINAL size whose grey ~1000 px copy is passed in (same order of checks as the server). */
export function judge({ width, height, grey, gw, gh }, cfg = CLARITY) {
    const metrics = { width, height };
    if (Math.max(width, height) < cfg.minLongEdge || Math.min(width, height) < cfg.minShortEdge) return verdict('too_small', metrics);
    const m = analyzeGrey(grey, gw, gh, cfg);
    Object.assign(metrics, { mean: m.mean, inkContrast: m.inkContrast, inkFraction: m.inkFraction, sharpness: m.sharpness });
    if (m.mean < cfg.minMean) return verdict('dark', metrics);
    if (m.inkFraction < cfg.minInkFraction) return verdict('blank', metrics);
    if (m.inkContrast < cfg.minInkContrast) return verdict(m.mean > cfg.maxMean - 25 ? 'bright' : 'low_contrast', metrics);
    if (m.sharpness < cfg.minSharpness) return verdict('blurry', metrics);
    return verdict('ok', metrics);
}

/** Browser: decode the file onto a canvas (about 1000 px long edge) and judge it. PDFs are not pre-checked (the server reads them). */
export async function precheckImageFile(file, cfg = CLARITY) {
    if (!file || !/^image\/(jpeg|png)$/.test(file.type)) return { passed: true, code: 'skipped', message: '', metrics: {} };
    let bitmap;
    try {
        bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
    } catch {
        return verdict('unreadable', {});
    }
    const { width, height } = bitmap;
    const scale = Math.min(1, cfg.sharpnessLongEdge / Math.max(width, height));
    const gw = Math.max(8, Math.round(width * scale));
    const gh = Math.max(8, Math.round(height * scale));
    const canvas = document.createElement('canvas');
    canvas.width = gw; canvas.height = gh;
    const ctx = canvas.getContext('2d', { willReadFrequently: true });
    ctx.drawImage(bitmap, 0, 0, gw, gh);
    bitmap.close?.();
    const { data } = ctx.getImageData(0, 0, gw, gh);
    const grey = new Uint8Array(gw * gh);
    for (let i = 0, p = 0; i < grey.length; i++, p += 4) grey[i] = Math.round(0.299 * data[p] + 0.587 * data[p + 1] + 0.114 * data[p + 2]);

    return judge({ width, height, grey, gw, gh }, cfg);
}
