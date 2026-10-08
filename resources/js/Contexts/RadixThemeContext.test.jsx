// @vitest-environment jsdom
import React from 'react';
import { createRoot } from 'react-dom/client';
import { act } from 'react-dom/test-utils';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import {
  DEFAULT_THEME_SETTINGS,
  DESIGN_LANGUAGES,
  DESIGN_LANGUAGE_VERSION,
  RadixThemeProvider,
  loadThemeSettings,
  normalizeThemeSettings,
  resolveThemeColor,
  useRadixTheme,
} from './RadixThemeContext';
import { resolvePanelStyle } from '@/Components/ui/Panel';

const STORAGE_KEY = 'radix-theme-settings';

describe('Cyber design language registration', () => {
  it('registers Cyber as a square (radius-locked) language and makes it the default', () => {
    expect(DESIGN_LANGUAGES).toContainEqual(expect.objectContaining({ id: 'cyber', lockRadius: true }));
    expect(DEFAULT_THEME_SETTINGS).toMatchObject({ designLanguage: 'cyber', appearance: 'dark', accentColor: 'blue' });
  });
});

describe('normalizeThemeSettings', () => {
  it('moves legacy stock-Radix settings to Cyber in dark appearance, once', () => {
    expect(normalizeThemeSettings({ designLanguage: 'none', appearance: 'light', accentColor: 'violet' })).toMatchObject({
      designLanguage: 'cyber',
      appearance: 'dark',
      accentColor: 'violet',
      designLanguageVersion: DESIGN_LANGUAGE_VERSION,
    });
    expect(normalizeThemeSettings({ appearance: 'light' })).toMatchObject({ designLanguage: 'cyber', appearance: 'dark' });
  });

  it('keeps a legacy explicit language choice and its appearance', () => {
    expect(normalizeThemeSettings({ designLanguage: 'brutalism', appearance: 'light' })).toMatchObject({
      designLanguage: 'brutalism',
      appearance: 'light',
      designLanguageVersion: DESIGN_LANGUAGE_VERSION,
    });
  });

  it('honours choices made after the migration, including light Cyber and stock Radix', () => {
    const version = DESIGN_LANGUAGE_VERSION;
    expect(normalizeThemeSettings({ designLanguage: 'cyber', appearance: 'light', designLanguageVersion: version }))
      .toMatchObject({ designLanguage: 'cyber', appearance: 'light' });
    expect(normalizeThemeSettings({ designLanguage: 'none', appearance: 'light', designLanguageVersion: version }))
      .toMatchObject({ designLanguage: 'none', appearance: 'light' });
  });

  it('repairs unknown languages and appearances', () => {
    expect(normalizeThemeSettings({ designLanguage: 'vaporwave', appearance: 'sepia', designLanguageVersion: 1 }))
      .toMatchObject({ designLanguage: 'cyber', appearance: 'dark' });
  });
});

describe('loadThemeSettings', () => {
  it('falls back to the defaults for missing or unreadable storage', () => {
    expect(loadThemeSettings(null)).toEqual({ ...DEFAULT_THEME_SETTINGS });
    expect(loadThemeSettings('{not json')).toEqual({ ...DEFAULT_THEME_SETTINGS });
    expect(loadThemeSettings('"a string"')).toEqual({ ...DEFAULT_THEME_SETTINGS });
  });

  it('migrates the stored JSON string', () => {
    expect(loadThemeSettings(JSON.stringify({ designLanguage: 'none', appearance: 'light' })))
      .toMatchObject({ designLanguage: 'cyber', appearance: 'dark', designLanguageVersion: DESIGN_LANGUAGE_VERSION });
  });
});

describe('resolveThemeColor', () => {
  it('uses the Cyber canvas colours for the browser chrome', () => {
    expect(resolveThemeColor('cyber', 'dark')).toBe('#0a151a');
    expect(resolveThemeColor('cyber', 'light')).toBe('#edf2f4');
    expect(resolveThemeColor('unknown', 'light')).toBe('#134e9d');
  });
});

describe('RadixThemeProvider', () => {
  let container;
  let root;
  let handle;

  function Probe() {
    handle = useRadixTheme();
    return null;
  }

  const mount = () => {
    container = document.createElement('div');
    document.body.appendChild(container);
    root = createRoot(container);
    act(() => {
      root.render(<RadixThemeProvider><Probe /></RadixThemeProvider>);
    });
  };

  beforeEach(() => {
    localStorage.clear();
    document.documentElement.removeAttribute('data-design');
    document.documentElement.className = '';
    const meta = document.createElement('meta');
    meta.setAttribute('name', 'theme-color');
    meta.setAttribute('content', '#134e9d');
    document.head.appendChild(meta);
  });

  afterEach(() => {
    act(() => root.unmount());
    container.remove();
    document.head.querySelector('meta[name="theme-color"]')?.remove();
  });

  it('applies the migrated Cyber settings to <html> and persists them', () => {
    localStorage.setItem(STORAGE_KEY, JSON.stringify({ designLanguage: 'none', appearance: 'light' }));
    mount();

    expect(document.documentElement.getAttribute('data-design')).toBe('cyber');
    expect(document.documentElement.classList.contains('dark')).toBe(true);
    expect(document.head.querySelector('meta[name="theme-color"]').getAttribute('content')).toBe('#0a151a');
    expect(JSON.parse(localStorage.getItem(STORAGE_KEY))).toMatchObject({
      designLanguage: 'cyber',
      appearance: 'dark',
      designLanguageVersion: DESIGN_LANGUAGE_VERSION,
    });
  });

  it('lets Cyber switch to light and back without being migrated again', () => {
    mount();
    act(() => handle.toggleAppearance());

    expect(document.documentElement.classList.contains('light')).toBe(true);
    expect(document.documentElement.getAttribute('data-design')).toBe('cyber');
    expect(document.head.querySelector('meta[name="theme-color"]').getAttribute('content')).toBe('#edf2f4');
    expect(JSON.parse(localStorage.getItem(STORAGE_KEY))).toMatchObject({ appearance: 'light', designLanguage: 'cyber' });

    act(() => handle.resetSettings());
    expect(handle.settings).toMatchObject({ designLanguage: 'cyber', appearance: 'dark' });
  });
});

describe('Panel radius routing', () => {
  it('routes inline panel radii through the design token', () => {
    expect(resolvePanelStyle({ borderRadius: 16, padding: 12 })).toEqual({
      borderRadius: 'var(--dl-panel-radius, 16px)',
      padding: 12,
    });
    expect(resolvePanelStyle({ borderRadius: 'var(--radius-3)' })).toEqual({
      borderRadius: 'var(--dl-panel-radius, var(--radius-3))',
    });
    expect(resolvePanelStyle({ padding: 12 })).toEqual({ padding: 12 });
    expect(resolvePanelStyle(undefined)).toBeUndefined();
  });
});
