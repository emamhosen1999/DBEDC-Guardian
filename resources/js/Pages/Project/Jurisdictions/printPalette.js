/**
 * The print palette: LIGHT whatever the screen theme, the Okabe-Ito colour-blind-safe qualitative colours, each with a
 * fill pattern so the map also reads in greyscale (the pattern, the band label and the legend carry the identity, not
 * the colour alone). Print colours are a cartographic specification, not a screen theme, so they are fixed here.
 */
export const PRINT_COLOURS = {
    blue: '#7fb6d9', orange: '#f3cf7a', green: '#7fcfb6', vermillion: '#eba87a', sky: '#b9ddf4', purple: '#e4bcd3', yellow: '#f6f1a0',
};
export const PRINT_STROKES = {
    blue: '#0072b2', orange: '#b87a00', green: '#007a58', vermillion: '#a34900', sky: '#2b8fc4', purple: '#9d4f7c', yellow: '#9a8f00',
};
/** Pattern per token: distinct line directions and dots, readable in black and white. */
export const PATTERNS = {
    blue: { kind: 'diag', angle: 45 },
    orange: { kind: 'diag', angle: -45 },
    green: { kind: 'cross' },
    vermillion: { kind: 'dots' },
    sky: { kind: 'horizontal' },
    purple: { kind: 'vertical' },
    yellow: { kind: 'dense-dots' },
};
