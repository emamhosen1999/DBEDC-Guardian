import React from 'react';
import { Theme } from '@radix-ui/themes';
import { useRadixTheme } from '@/Contexts/RadixThemeContext';

export function RadixThemeShell({ children }) {
  const { settings } = useRadixTheme();

  return (
    <Theme
      accentColor={settings.accentColor}
      grayColor={settings.grayColor}
      radius={settings.radius}
      scaling={settings.scaling}
      appearance={settings.appearance}
      panelBackground={settings.panelBackground}
      className={settings.bgStyle === 'grid' ? 'app-bg-grid' : undefined}
      style={{ minHeight: '100%' }}
    >
      {children}
    </Theme>
  );
}
