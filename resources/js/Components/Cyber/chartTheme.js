/**
 * Cyber's ApexCharts defaults (kit: assets/js/demo/dashboard.demo.js, global `Apex = {...}`), expressed with the
 * Guardian token bridge so dark and Cyber Light both work: font and 10px sizes, body-colour labels, border-colour
 * grid and axes, 1px smooth strokes, vertical light gradient fills, bottom legend, 10px tooltip.
 */

const cssVar = (name, fallback) => {
    if (typeof document === 'undefined') return fallback;
    const value = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    return value || fallback;
};

/** Resolved token colours at the moment of rendering (they differ per theme and accent). */
export function cyberTokens() {
    const theme = cssVar('--accent-9', '#67ceff');
    return {
        theme,
        themeText: cssVar('--accent-11', theme),
        fg: cssVar('--cy-fg', '#ffffff'),
        muted: cssVar('--cy-muted', '#8c8c8c'),
        border: cssVar('--cy-border', '#4d4d4d'),
        borderSoft: cssVar('--cy-border-translucent', 'rgba(255,255,255,.15)'),
        success: cssVar('--green-9', '#82ff9b'),
        warning: cssVar('--amber-9', '#ffbf89'),
        danger: cssVar('--red-9', '#ff9999'),
        info: cssVar('--cyan-9', '#6aebef'),
        purple: cssVar('--purple-9', '#e89dff'),
        fontFamily: cssVar('--cy-font', '"Noto Sans", system-ui, sans-serif'),
        fontWeight: '500',
        isLight: typeof document !== 'undefined' && document.documentElement.classList.contains('light'),
    };
}

/** Semantic tone to colour, shared by every chart so a tone reads the same everywhere. */
export const toneColor = (t, tone) => ({
    theme: t.theme, good: t.success, success: t.success, warn: t.warning, warning: t.warning, crit: t.danger, danger: t.danger, info: t.info, muted: t.muted, purple: t.purple,
}[tone] ?? t.theme);

const axisLabelStyle = (t) => ({ colors: t.fg, fontSize: '10px', fontFamily: t.fontFamily, fontWeight: t.fontWeight });

/** The options every Cyber chart starts from. Callers merge their own on top (see CyberChart). */
export function baseOptions(t, { animate = true } = {}) {
    return {
        chart: {
            background: 'transparent',
            fontFamily: t.fontFamily,
            foreColor: t.fg,
            toolbar: { show: false },
            zoom: { enabled: false },
            animations: { enabled: animate, speed: 400 },
        },
        theme: { mode: t.isLight ? 'light' : 'dark' },
        colors: [t.theme],
        stroke: { width: 1, curve: 'smooth' },
        grid: { borderColor: t.border, xaxis: { lines: { show: true } }, yaxis: { lines: { show: true } } },
        legend: { position: 'bottom', horizontalAlign: 'center', fontSize: '10px', fontFamily: t.fontFamily, labels: { colors: t.fg }, itemMargin: { horizontal: 8 } },
        tooltip: { theme: t.isLight ? 'light' : 'dark', style: { fontSize: '10px', fontFamily: t.fontFamily } },
        dataLabels: { style: { fontSize: '10px', fontFamily: t.fontFamily, fontWeight: '500' } },
        xaxis: {
            axisBorder: { show: true, color: t.border, height: 1, width: '100%', offsetX: 0, offsetY: -1 },
            axisTicks: { show: true, borderType: 'solid', color: t.border, height: 6 },
            labels: { style: axisLabelStyle(t) },
        },
        yaxis: { labels: { style: axisLabelStyle(t) } },
        fill: { type: 'gradient', gradient: { shade: 'light', type: 'vertical', shadeIntensity: 0.4, opacityFrom: 0.5, opacityTo: 0.1, stops: [0, 100] } },
    };
}

/** Cyber's sparkline (dashboard.demo.js handleRenderSparkline): 20px line, 2px smooth stroke, theme colour, tooltip on. */
export function sparklineOptions(t, data, { color, height = 20, name = 'Value' } = {}) {
    return {
        chart: { type: 'line', height, sparkline: { enabled: true } },
        series: [{ name, data }],
        stroke: { curve: 'smooth', width: 2 },
        colors: [color ?? t.theme],
        tooltip: { enabled: true },
    };
}

export function isPlainObject(value) {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
}

/** Deep merge for option trees (arrays replace, they are never concatenated). */
export function mergeOptions(base, extra) {
    const out = { ...base };
    for (const [key, value] of Object.entries(extra ?? {})) {
        if (value === undefined) continue; // an unset option keeps Cyber's default
        out[key] = isPlainObject(value) && isPlainObject(base?.[key]) ? mergeOptions(base[key], value) : value;
    }
    return out;
}
