import React, { createContext, useContext, useState, useEffect, useCallback } from 'react';

const STORAGE_KEY = 'radix-theme-settings';

export const ACCENT_COLORS = [
  'gray', 'gold', 'bronze', 'brown', 'yellow', 'amber', 'orange',
  'tomato', 'red', 'ruby', 'crimson', 'pink', 'plum', 'purple',
  'violet', 'iris', 'indigo', 'blue', 'cyan', 'teal', 'jade',
  'green', 'grass', 'lime', 'mint', 'sky',
];

export const GRAY_COLORS = ['auto', 'gray', 'mauve', 'slate', 'sage', 'olive', 'sand'];

export const RADIUS_OPTIONS = ['none', 'small', 'medium', 'large', 'full'];

export const SCALING_OPTIONS = ['90%', '95%', '100%', '105%', '110%'];

export const PANEL_BACKGROUNDS = ['solid', 'translucent'];

/*
 * The design languages, plus 'none' (stock Radix).
 * `id` is the value written to <html data-design="...">, and is also the
 * filename in resources/css/design/<id>.css.
 * `lockRadius` marks languages where corner radius is constitutive of the
 * style rather than decorative -- Brutalism and Cyber are not themselves with
 * rounded corners, Claymorphism is not Claymorphism without them. For those
 * the Radius control is disabled rather than silently ignored.
 * Cyber (seantheme.com/cyber) is the app's design; the others are dormant.
 */
export const DESIGN_LANGUAGES = [
  { id: 'cyber',          label: 'Cyber',          blurb: 'Dark operations console, square HUD surfaces', lockRadius: true },
  { id: 'none',           label: 'None',           blurb: 'Stock Radix Themes' },
  { id: 'skeuomorphism',  label: 'Skeuomorphism',  blurb: 'Physical materials, bevels, real textures' },
  { id: 'neomorphism',    label: 'Neomorphism',    blurb: 'Soft extruded surfaces, dual light source' },
  { id: 'glassmorphism',  label: 'Glassmorphism',  blurb: 'Frosted translucency over a lit backdrop' },
  { id: 'claymorphism',   label: 'Claymorphism',   blurb: 'Puffy 3D clay, deep radii', lockRadius: true },
  { id: 'minimalism',     label: 'Minimalism',     blurb: 'Whitespace, hairlines, near-no shadow' },
  { id: 'maximalism',     label: 'Maximalism',     blurb: 'Dense, saturated, layered, expressive' },
  { id: 'brutalism',      label: 'Brutalism',      blurb: 'Hard edges, raw borders, offset shadow', lockRadius: true },
  { id: 'liquidglass',    label: 'Liquid Glass',   blurb: 'Refractive glass with specular edges' },
  { id: 'bentogrid',      label: 'Bento Grid',     blurb: 'Tiled cells, tight gutters, clear bounds' },
  { id: 'spatialui',      label: 'Spatial UI',     blurb: 'Layered depth, parallax, elevation rank' },
];

export const DESIGN_LANGUAGE_IDS = DESIGN_LANGUAGES.map((d) => d.id);

export const FONT_FAMILIES = [
  { label: 'Auto (match design language)', value: 'auto' },
  { label: 'Space Grotesk', value: '"Space Grotesk", system-ui, sans-serif' },
  { label: 'Inter', value: 'Inter, system-ui, sans-serif' },
  { label: 'Roboto', value: 'Roboto, sans-serif' },
  { label: 'Outfit', value: 'Outfit, sans-serif' },
  { label: 'Nunito', value: 'Nunito, sans-serif' },
  { label: 'Exo 2', value: '"Exo 2", sans-serif' },
  { label: 'Josefin Sans', value: '"Josefin Sans", sans-serif' },
  { label: 'System UI', value: 'system-ui, sans-serif' },
];

/*
 * Bumped when the default design changes. Settings saved under an older
 * version get the new default design once; anything chosen after that sticks.
 */
export const DESIGN_LANGUAGE_VERSION = 1;

export const DEFAULT_THEME_SETTINGS = Object.freeze({
  accentColor: 'blue', // Cyber's theme colour #67ceff (resources/css/design/cyber/palette.css)
  grayColor: 'auto',
  radius: 'medium',
  scaling: '100%',
  appearance: 'dark',
  panelBackground: 'solid',
  fontFamily: 'auto',
  customAccentHex: '',
  bgStyle: 'grid',
  designLanguage: 'cyber',
  designLanguageVersion: DESIGN_LANGUAGE_VERSION,
});
const DEFAULT_SETTINGS = DEFAULT_THEME_SETTINGS;

/*
 * One-time move to Cyber. Settings saved before Cyber existed carry no
 * version: if they were on stock Radix ('none' or unset) they switch to Cyber
 * in its native dark appearance. An explicit language choice is kept, and
 * once versioned, every later choice (including 'none' or light) is honoured.
 */
export function normalizeThemeSettings(settings) {
  // Read the version from what was stored, before the defaults fill it in.
  const isLegacy = settings?.designLanguageVersion !== DESIGN_LANGUAGE_VERSION;
  const next = { ...DEFAULT_SETTINGS, ...settings, designLanguageVersion: DESIGN_LANGUAGE_VERSION };
  if (isLegacy) {
    const legacyDesign = settings?.designLanguage;
    if (!legacyDesign || legacyDesign === 'none') {
      next.designLanguage = 'cyber';
      next.appearance = 'dark';
    }
  }
  if (!DESIGN_LANGUAGE_IDS.includes(next.designLanguage)) next.designLanguage = DEFAULT_SETTINGS.designLanguage;
  if (next.appearance !== 'light' && next.appearance !== 'dark') next.appearance = DEFAULT_SETTINGS.appearance;
  return next;
}

/* Parse what localStorage holds; anything unreadable falls back to the defaults. */
export function loadThemeSettings(raw) {
  if (!raw) return { ...DEFAULT_SETTINGS };
  try {
    const parsed = typeof raw === 'string' ? JSON.parse(raw) : raw;
    return parsed && typeof parsed === 'object' ? normalizeThemeSettings(parsed) : { ...DEFAULT_SETTINGS };
  } catch (_) {
    return { ...DEFAULT_SETTINGS };
  }
}

const RadixThemeContext = createContext(null);

export const useRadixTheme = () => {
  const ctx = useContext(RadixThemeContext);
  if (!ctx) throw new Error('useRadixTheme must be used within RadixThemeProvider');
  return ctx;
};

export const RadixThemeProvider = ({ children }) => {
  const [settings, setSettings] = useState(() => {
    let stored = null;
    try { stored = localStorage.getItem(STORAGE_KEY); } catch (_) {}
    return loadThemeSettings(stored);
  });

  useEffect(() => {
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(settings));
    } catch (_) {}
    applyFontFamily(settings.fontFamily);
    applyCustomAccent(settings.customAccentHex);
    syncAppearanceClass(settings.appearance);
    syncDesignLanguage(settings.designLanguage);
    syncThemeColor(settings.designLanguage, settings.appearance);
  }, [settings]);

  const updateSettings = useCallback((patch) => {
    setSettings((prev) => ({ ...prev, ...patch }));
  }, []);

  const resetSettings = useCallback(() => {
    setSettings({ ...DEFAULT_SETTINGS });
  }, []);

  const toggleAppearance = useCallback(() => {
    setSettings((prev) => ({
      ...prev,
      appearance: prev.appearance === 'light' ? 'dark' : 'light',
    }));
  }, []);

  return (
    <RadixThemeContext.Provider value={{ settings, updateSettings, resetSettings, toggleAppearance }}>
      {children}
    </RadixThemeContext.Provider>
  );
};

function applyFontFamily(fontFamily) {
  // 'auto' clears the user override so --dl-font-body from the active design
  // language takes effect. Brutalism is not Brutalism in Inter.
  if (!fontFamily || fontFamily === 'auto') {
    document.documentElement.style.removeProperty('--custom-font-family');
    document.documentElement.style.removeProperty('--default-font-family');
    document.documentElement.style.removeProperty('--fontFamily');
    return;
  }
  if (fontFamily) {
    document.documentElement.style.setProperty('--default-font-family', fontFamily);
    document.documentElement.style.setProperty('--custom-font-family', fontFamily);
    document.documentElement.style.setProperty('--fontFamily', fontFamily);
  }
}

function applyCustomAccent(hex) {
  if (hex && /^#[0-9a-fA-F]{6}$/.test(hex)) {
    document.documentElement.style.setProperty('--accent-9', hex);
  } else {
    document.documentElement.style.removeProperty('--accent-9');
  }
}

function syncAppearanceClass(appearance) {
  const html = document.documentElement;
  if (appearance === 'dark') {
    html.classList.add('dark');
    html.classList.remove('light');
  } else {
    html.classList.add('light');
    html.classList.remove('dark');
  }
}

/*
 * Written to <html>, not to the <Theme> wrapper, so that React portals --
 * dialogs, popovers, tooltips, toasts -- inherit the language too. Those
 * render outside the Theme subtree, and skinning everything except them is
 * the most visible way a design system looks half-applied.
 */
function syncDesignLanguage(language) {
  const id = DESIGN_LANGUAGE_IDS.includes(language) ? language : 'none';
  document.documentElement.setAttribute('data-design', id);
}

/* Browser/OS chrome colour (mobile address bar, PWA title bar). */
const THEME_COLORS = {
  cyber: { dark: '#0a151a', light: '#edf2f4' },
  none: { dark: '#111113', light: '#134e9d' },
};

export function resolveThemeColor(language, appearance) {
  const palette = THEME_COLORS[language] || THEME_COLORS.none;
  return appearance === 'dark' ? palette.dark : palette.light;
}

function syncThemeColor(language, appearance) {
  const meta = document.querySelector('meta[name="theme-color"]');
  if (meta) meta.setAttribute('content', resolveThemeColor(language, appearance));
}

export { RadixThemeContext };
