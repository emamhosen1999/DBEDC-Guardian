#!/usr/bin/env node
/*
 * Generates resources/css/design/cyber/palette.css — the Cyber colour bridge.
 *
 *   node scripts/design/cyber-palette.mjs > resources/css/design/cyber/palette.css
 *
 * Why generated: Radix Themes resolves every colour through its 12-step scales
 * (--red-1 … --red-12, --red-a1 … --red-a12, --red-contrast …). Re-pointing those
 * scales is what lets all 115 pages inherit Cyber colours without touching a
 * component, but it is ~30 declarations per hue. The formulas live here so the
 * file can be rebuilt when a Cyber hex changes; the output is static CSS (no
 * runtime color-mix) so devtools shows real values.
 *
 * Dark is the faithful Cyber palette (kit tokens.js / app.min.css). Light is a
 * derived "Cyber Light": Radix's own light scales stay (they are AA-tuned), only
 * the neutral scale and the default azure accent are re-pointed. Contrast is
 * checked below and the script exits non-zero if a text step fails WCAG AA.
 */

const CYBER = {
  theme: '#67ceff', // --bs-theme / primary
  info: '#6aebef',
  teal: '#64ffda',
  green: '#82ff9b', // success
  lime: '#d4ff92',
  yellow: '#ebef6a',
  warning: '#ffbf89', // orange
  danger: '#ff9999', // red
  pink: '#ff96cd',
  purple: '#e89dff',
  indigo: '#a59ffd',
};

// Radix hue -> Cyber colour. Every Radix accent a user can pick lands on the
// Cyber palette, so badges, callouts and charts never show a non-Cyber hue.
const RADIX_TO_CYBER = {
  blue: 'theme', sky: 'theme', cyan: 'info', teal: 'teal', mint: 'teal',
  jade: 'green', green: 'green', grass: 'green', lime: 'lime',
  yellow: 'yellow', gold: 'yellow', amber: 'warning', orange: 'warning',
  bronze: 'warning', brown: 'warning', tomato: 'danger', red: 'danger',
  ruby: 'danger', crimson: 'pink', pink: 'pink', plum: 'purple',
  purple: 'purple', violet: 'indigo', iris: 'indigo', indigo: 'indigo',
};

const DARK_BG = '#0a151a'; // --bs-body-bg
const LIGHT_BG = '#edf2f4'; // Cyber Light canvas (derived)
const INK = '#0a151a'; // Cyber Light text base

/* ── colour maths ─────────────────────────────────────────────────────── */
const hex2rgb = (h) => [1, 3, 5].map((i) => parseInt(h.slice(i, i + 2), 16));
const rgb2hex = (c) => '#' + c.map((v) => Math.round(Math.min(255, Math.max(0, v))).toString(16).padStart(2, '0')).join('');
const mix = (a, b, t) => rgb2hex(hex2rgb(a).map((v, i) => v * t + hex2rgb(b)[i] * (1 - t))); // t of a
const rgba = (h, a) => `rgba(${hex2rgb(h).join(', ')}, ${+a.toFixed(3)})`;
const lin = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; };
const lum = (h) => { const [r, g, b] = hex2rgb(h).map(lin); return 0.2126 * r + 0.7152 * g + 0.0722 * b; };
const contrast = (a, b) => { const [x, y] = [lum(a), lum(b)].sort((p, q) => q - p); return (x + 0.05) / (y + 0.05); };
const over = (h, a, bg) => mix(h, bg, a); // alpha-composite h at alpha a over bg

const failures = [];
const check = (label, fg, bg, min = 4.5) => {
  const r = contrast(fg, bg);
  if (r < min) failures.push(`${label}: ${fg} on ${bg} = ${r.toFixed(2)} < ${min}`);
  return r;
};

/* ── scales ───────────────────────────────────────────────────────────── */
const SOLID_STEPS = [0.03, 0.06, 0.12, 0.16, 0.21, 0.28, 0.4, 0.6];
const ALPHA_STEPS = [0.03, 0.06, 0.15, 0.2, 0.25, 0.32, 0.45, 0.7];

function darkHue(name, c) {
  const d = {};
  SOLID_STEPS.forEach((t, i) => { d[`--${name}-${i + 1}`] = mix(c, DARK_BG, t); });
  d[`--${name}-9`] = c;
  d[`--${name}-10`] = mix('#ffffff', c, 0.25); // Bootstrap tint: btn hover (#67ceff -> #8ddaff)
  d[`--${name}-11`] = c; // Cyber uses the hue itself as text (text-success etc.)
  d[`--${name}-12`] = mix('#ffffff', c, 0.4); // *-text-emphasis
  ALPHA_STEPS.forEach((a, i) => { d[`--${name}-a${i + 1}`] = rgba(c, a); });
  d[`--${name}-a9`] = c;
  d[`--${name}-a10`] = mix('#ffffff', c, 0.25);
  d[`--${name}-a11`] = c;
  d[`--${name}-a12`] = mix('#ffffff', c, 0.4);
  d[`--${name}-contrast`] = '#000000'; // Cyber puts black text on every coloured button
  d[`--${name}-surface`] = rgba(c, 0.08);
  d[`--${name}-indicator`] = c;
  d[`--${name}-track`] = c;
  check(`dark ${name}-11`, c, DARK_BG);
  check(`dark ${name}-11 on cover p99`, c, '#2b3438'); // 99th-percentile cover pixel
  check(`dark ${name}-contrast`, '#000000', c);
  return d;
}

// Neutral: white over the Cyber canvas, matching Bootstrap's text-opacity /
// bg-opacity utilities. a11 is 0.62 (kit uses .5) so secondary text keeps
// 4.5:1 even over the brightest pixels of the cover photo (#3b4448).
const GRAY_ALPHA_DARK = [0.02, 0.04, 0.08, 0.12, 0.15, 0.2, 0.28, 0.38, 0.5, 0.55, 0.62, 1];
function darkGray() {
  const d = {};
  GRAY_ALPHA_DARK.forEach((a, i) => {
    d[`--gray-${i + 1}`] = over('#ffffff', a, DARK_BG);
    d[`--gray-a${i + 1}`] = rgba('#ffffff', a);
  });
  d['--gray-contrast'] = '#ffffff';
  d['--gray-surface'] = 'rgba(0, 0, 0, 0.12)';
  d['--gray-indicator'] = over('#ffffff', 0.5, DARK_BG);
  d['--gray-track'] = over('#ffffff', 0.5, DARK_BG);
  check('dark gray-11 on canvas', over('#ffffff', 0.62, DARK_BG), DARK_BG);
  check('dark gray-11 on cover max', over('#ffffff', 0.62, '#3b4448'), '#3b4448');
  check('dark gray-12 on canvas', over('#ffffff', 1, DARK_BG), DARK_BG); // Cyber body text is #fff
  return d;
}

const GRAY_ALPHA_LIGHT = [0.02, 0.04, 0.07, 0.1, 0.13, 0.17, 0.23, 0.32, 0.45, 0.62, 0.7, 0.93];
function lightGray() {
  const d = {};
  GRAY_ALPHA_LIGHT.forEach((a, i) => {
    d[`--gray-${i + 1}`] = over(INK, a, LIGHT_BG);
    d[`--gray-a${i + 1}`] = rgba(INK, a);
  });
  d['--gray-contrast'] = '#ffffff';
  d['--gray-surface'] = 'rgba(255, 255, 255, 0.7)';
  d['--gray-indicator'] = over(INK, 0.45, LIGHT_BG);
  d['--gray-track'] = over(INK, 0.45, LIGHT_BG);
  check('light gray-11 on canvas', over(INK, 0.7, LIGHT_BG), LIGHT_BG);
  check('light gray-11 on white', over(INK, 0.7, '#ffffff'), '#ffffff');
  check('light gray-10 placeholder on white', over(INK, 0.62, '#ffffff'), '#ffffff');
  check('light gray-10 placeholder on canvas', over(INK, 0.62, LIGHT_BG), LIGHT_BG);
  return d;
}

// Cyber Light azure: the kit hue stays for tints; solid/text steps are darkened
// until they clear AA on the light canvas and on white panels.
function lightAzure(name) {
  const c = CYBER.theme;
  const solid = '#0b6f9f'; // white text 5.4:1
  const ink = '#085f88'; // text on canvas 6.4:1
  const deep = '#063f5a';
  const d = {};
  [0.06, 0.1, 0.16, 0.22, 0.28, 0.36].forEach((t, i) => { d[`--${name}-${i + 1}`] = mix(c, LIGHT_BG, t); });
  d[`--${name}-7`] = mix(solid, LIGHT_BG, 0.45);
  d[`--${name}-8`] = mix(solid, LIGHT_BG, 0.7);
  d[`--${name}-9`] = solid;
  d[`--${name}-10`] = mix(solid, '#000000', 0.88);
  d[`--${name}-11`] = ink;
  d[`--${name}-12`] = deep;
  [0.08, 0.12, 0.2, 0.26, 0.32, 0.42].forEach((a, i) => { d[`--${name}-a${i + 1}`] = rgba(c, a); });
  d[`--${name}-a7`] = rgba(solid, 0.55);
  d[`--${name}-a8`] = rgba(solid, 0.8);
  d[`--${name}-a9`] = solid;
  d[`--${name}-a10`] = mix(solid, '#000000', 0.88);
  d[`--${name}-a11`] = ink;
  d[`--${name}-a12`] = deep;
  d[`--${name}-contrast`] = '#ffffff';
  d[`--${name}-surface`] = rgba(c, 0.12);
  d[`--${name}-indicator`] = solid;
  d[`--${name}-track`] = solid;
  check(`light ${name}-9 + contrast`, '#ffffff', solid);
  check(`light ${name}-11 on canvas`, ink, LIGHT_BG);
  check(`light ${name}-11 on white`, ink, '#ffffff');
  check(`light ${name}-11 on a3 soft bg`, ink, over(c, 0.2, '#ffffff'));
  return d;
}

/* ── output ───────────────────────────────────────────────────────────── */
const block = (selectors, decls, indent = '  ') => `${selectors.join(',\n')} {\n${Object.entries(decls).map(([k, v]) => `${indent}${k}: ${v};`).join('\n')}\n}\n`;

const primitives = {};
for (const [k, v] of Object.entries(CYBER)) { primitives[`--cy-${k}`] = v; primitives[`--cy-${k}-rgb`] = hex2rgb(v).join(', '); }

const DARK = [
  'html[data-design="cyber"].dark',
  'html[data-design="cyber"] .dark',
  'html[data-design="cyber"] .dark-theme',
];
const LIGHT = [
  'html[data-design="cyber"].light',
  'html[data-design="cyber"] .light',
  'html[data-design="cyber"] .light-theme',
];

let out = `/* ==========================================================================
   Cyber palette — GENERATED by scripts/design/cyber-palette.mjs. Do not edit
   by hand; change the generator and re-run it.

   Re-points Radix's raw colour scales, so every component and every inline
   var(--red-9) / color="green" in the app resolves to the Cyber palette.
   Dark  = faithful Cyber (kit app.min.css): hue N = mix(hue, #0a151a), a-steps
           are the hue at Bootstrap's opacity utilities, 10 = 25% white tint
           (btn hover), 12 = 40% tint (text-emphasis), contrast = black.
   Light = Cyber Light: Radix light hues kept (AA-tuned); neutral + azure
           accent re-pointed.
   ========================================================================== */

html[data-design="cyber"] {
${Object.entries(primitives).map(([k, v]) => `  ${k}: ${v};`).join('\n')}
}

/* ── Dark: neutral scale ─────────────────────────────────────────────── */
${block(DARK, darkGray())}
`;

for (const [radix, cy] of Object.entries(RADIX_TO_CYBER)) {
  out += `/* ${radix} -> Cyber ${cy} ${CYBER[cy]} */\n${block(DARK, darkHue(radix, CYBER[cy]))}\n`;
}

out += `/* ── Light: neutral scale ────────────────────────────────────────────── */
${block(LIGHT, lightGray())}
/* ── Light: Cyber azure accent (blue = default accent, sky = alias) ─────── */
${block(LIGHT, { ...lightAzure('blue'), ...lightAzure('sky') })}`;

if (failures.length) {
  console.error('Contrast failures:\n' + failures.join('\n'));
  process.exit(1);
}
process.stdout.write(out);
