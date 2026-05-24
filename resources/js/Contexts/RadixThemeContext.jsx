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

export const PANEL_BACKGROUNDS = ['solid', 'translucent', 'flat'];

export const FONT_FAMILIES = [
  { label: 'Inter', value: 'Inter, system-ui, sans-serif' },
  { label: 'Roboto', value: 'Roboto, sans-serif' },
  { label: 'Outfit', value: 'Outfit, sans-serif' },
  { label: 'Nunito', value: 'Nunito, sans-serif' },
  { label: 'Exo 2', value: '"Exo 2", sans-serif' },
  { label: 'Josefin Sans', value: '"Josefin Sans", sans-serif' },
  { label: 'System UI', value: 'system-ui, sans-serif' },
];

const DEFAULT_SETTINGS = {
  accentColor: 'indigo',
  grayColor: 'auto',
  radius: 'medium',
  scaling: '100%',
  appearance: 'light',
  panelBackground: 'solid',
  fontFamily: 'Inter, system-ui, sans-serif',
  customAccentHex: '',
  bgStyle: 'grid',
};
const RadixThemeContext = createContext(null);

export const useRadixTheme = () => {
  const ctx = useContext(RadixThemeContext);
  if (!ctx) throw new Error('useRadixTheme must be used within RadixThemeProvider');
  return ctx;
};

export const RadixThemeProvider = ({ children }) => {
  const [settings, setSettings] = useState(() => {
    try {
      const stored = localStorage.getItem(STORAGE_KEY);
      if (stored) {
        return { ...DEFAULT_SETTINGS, ...JSON.parse(stored) };
      }
    } catch (_) {}
    const prefersDark = window.matchMedia?.('(prefers-color-scheme: dark)').matches;
    return { ...DEFAULT_SETTINGS, appearance: prefersDark ? 'dark' : 'light' };
  });

  useEffect(() => {
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(settings));
    } catch (_) {}
    applyFontFamily(settings.fontFamily);
    applyCustomAccent(settings.customAccentHex);
    syncAppearanceClass(settings.appearance);
  }, [settings]);

  const updateSettings = useCallback((patch) => {
    setSettings((prev) => ({ ...prev, ...patch }));
  }, []);

  const resetSettings = useCallback(() => {
    setSettings(DEFAULT_SETTINGS);
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
  if (fontFamily) {
    document.documentElement.style.setProperty('--default-font-family', fontFamily);
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

export { RadixThemeContext };
