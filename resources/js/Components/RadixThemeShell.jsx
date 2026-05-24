import React from 'react';
import { Theme } from '@radix-ui/themes';
import { useRadixTheme } from '@/Contexts/RadixThemeContext';

export function RadixThemeShell({ children }) {
  const { settings } = useRadixTheme();

  const bgClass = {
    grid: 'app-bg-grid',
    none: 'app-bg-none',
    gradient: 'app-bg-gradient',
    pattern: 'app-bg-pattern',
  }[settings.bgStyle];

  return (
    <Theme
      accentColor={settings.accentColor}
      grayColor={settings.grayColor}
      radius={settings.radius}
      scaling={settings.scaling}
      appearance={settings.appearance}
      panelBackground={settings.panelBackground}
      className={bgClass}
      style={{ minHeight: '100%' }}
    >
      {children}
    </Theme>
  );
}
