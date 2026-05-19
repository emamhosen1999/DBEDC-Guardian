import React, { useCallback, useState } from 'react';
import {
  Avatar, Badge, Box, Button, Card, Dialog, Flex,
  Heading, ScrollArea, Select, Separator, Text, TextField, Tooltip,
} from '@radix-ui/themes';
import {
  useRadixTheme,
  ACCENT_COLORS,
  GRAY_COLORS,
  RADIUS_OPTIONS,
  SCALING_OPTIONS,
  FONT_FAMILIES,
} from '@/Contexts/RadixThemeContext';
import { SunIcon, MoonIcon, ResetIcon, CopyIcon, CheckIcon } from '@radix-ui/react-icons';

/* ── radius preview corner values per option ──────────────────────────── */
const RADIUS_CORNERS = { none: 0, small: '4px', medium: '8px', large: '16px', full: '80%' };

/* ── gray color preview bg per option ──────────────────────────────────── */
const GRAY_BG = {
  auto: 'var(--slate-9)',
  gray: 'var(--gray-9)',
  mauve: 'var(--mauve-9)',
  slate: 'var(--slate-9)',
  sage: 'var(--sage-9)',
  olive: 'var(--olive-9)',
  sand: 'var(--sand-9)',
};


export default function RadixThemeDrawer({ open, onClose }) {
  const { settings, updateSettings, resetSettings, toggleAppearance } = useRadixTheme();
  const [copied, setCopied] = useState(false);

  const copyTheme = useCallback(() => {
    const code = `<Theme
  accentColor="${settings.accentColor}"
  grayColor="${settings.grayColor === 'auto' ? undefined : settings.grayColor}"
  radius="${settings.radius}"
  scaling="${settings.scaling}"
  appearance="${settings.appearance}"
  panelBackground="${settings.panelBackground}"
/>`;
    navigator.clipboard.writeText(code).then(() => {
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    });
  }, [settings]);

  return (
    <Dialog.Root open={open} onOpenChange={(o) => { if (!o) onClose(); }}>
      <Dialog.Content style={{ maxWidth: 440, width: '100%', padding: 0, overflow: 'hidden' }}>

        {/* ── HEADER ────────────────────────────────────────────────── */}
        <Box p="5" style={{ position: 'relative', overflowX: 'hidden' }}>
          <Dialog.Close style={{ position: 'absolute', top: 8, right: 8 }}>
            <Box as="button" className="rt-reset rt-Kbd" style={{ fontSize: 11, cursor: 'pointer' }}>T</Box>
          </Dialog.Close>
          <Dialog.Title asChild>
            <Heading size="5" mb="5">Theme</Heading>
          </Dialog.Title>

          <ScrollArea scrollbars="vertical" style={{ maxHeight: '80vh', overflowX: 'hidden' }}>
            <Flex direction="column" pb="2" style={{ minWidth: 0, width: '100%' }}>

           

              {/* ── Accent color ─────────────────────────────────────── */}
              <Text id="accent-title" size="2" weight="medium" mt="1" mb="3">Accent color</Text>
              <div role="group" aria-labelledby="accent-title"
                style={{ display: 'grid', gridTemplateColumns: 'repeat(9, minmax(0,1fr))', gap: 8, marginBottom: 12 }}>
                {ACCENT_COLORS.map((c) => (
                  <Tooltip key={c} content={c.charAt(0).toUpperCase() + c.slice(1)}>
                    <label className="rt-ThemePanelSwatch" style={{ backgroundColor: `var(--${c}-9)`, cursor: 'pointer' }}>
                      <input
                        className="rt-ThemePanelSwatchInput"
                        type="radio" name="accentColor" value={c}
                        checked={settings.accentColor === c}
                        onChange={() => updateSettings({ accentColor: c, customAccentHex: '' })}
                      />
                    </label>
                  </Tooltip>
                ))}
              </div>
              {/* Custom hex */}
              <Flex gap="2" align="center" mb="1">
                <Text size="1" color="gray" style={{ whiteSpace: 'nowrap' }}>Custom hex</Text>
                <TextField.Root
                  size="1" placeholder="#6e56cf"
                  value={settings.customAccentHex}
                  onChange={e => updateSettings({ customAccentHex: e.target.value })}
                  maxLength={7}
                  style={{ fontFamily: 'monospace', width: 88 }}
                />
                {settings.customAccentHex && (
                  <Box style={{ width: 16, height: 16, borderRadius: 'var(--radius-1)', background: settings.customAccentHex, border: '1px solid var(--gray-6)', flexShrink: 0 }} />
                )}
              </Flex>

              <Separator size="4" my="4" />

              {/* ── Gray color ───────────────────────────────────────── */}
              <Text id="gray-title" size="2" weight="medium" mb="3">Gray color</Text>
              <div role="group" aria-labelledby="gray-title"
                style={{ display: 'grid', gridTemplateColumns: 'repeat(7, minmax(0,1fr))', gap: 8, marginBottom: 4 }}>
                {GRAY_COLORS.map((c) => (
                  <Tooltip key={c} content={c === 'auto' ? 'Auto (match accent)' : c.charAt(0).toUpperCase() + c.slice(1)}>
                    <label className="rt-ThemePanelSwatch rt-Flex rt-r-ai-center rt-r-jc-center"
                      style={{ backgroundColor: GRAY_BG[c] ?? 'var(--gray-9)', cursor: 'pointer', filter: c === 'gray' ? 'saturate(0)' : undefined }}>
                      <input
                        className="rt-ThemePanelSwatchInput"
                        type="radio" name="grayColor" value={c}
                        checked={settings.grayColor === c}
                        onChange={() => updateSettings({ grayColor: c })}
                      />
                    </label>
                  </Tooltip>
                ))}
              </div>

              <Separator size="4" my="4" />

              {/* ── Appearance ───────────────────────────────────────── */}
              <Text id="appearance-title" size="2" weight="medium" mb="3">Appearance</Text>
              <div role="group" aria-labelledby="appearance-title"
                style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 8, marginBottom: 4 }}>
                {[{val:'light', icon: <SunIcon />}, {val:'dark', icon: <MoonIcon />}].map(({ val, icon }) => (
                  <label key={val} className="rt-ThemePanelRadioCard">
                    <input className="rt-ThemePanelRadioCardInput" type="radio" name="appearance" value={val}
                      checked={settings.appearance === val}
                      onChange={() => updateSettings({ appearance: val })} />
                    <Flex align="center" justify="center" gap="2" style={{ height: 32 }}>
                      {icon}
                      <Text size="1" weight="medium" style={{ textTransform: 'capitalize' }}>{val}</Text>
                    </Flex>
                  </label>
                ))}
              </div>

              <Separator size="4" my="4" />

              {/* ── Radius ───────────────────────────────────────────── */}
              <Text id="radius-title" size="2" weight="medium" mb="3">Radius</Text>
              <div role="group" aria-labelledby="radius-title"
                style={{ display: 'grid', gridTemplateColumns: 'repeat(5, minmax(0,1fr))', gap: 8, marginBottom: 4 }}>
                {RADIUS_OPTIONS.map((r) => (
                  <Flex key={r} direction="column" align="center">
                    <label className="rt-ThemePanelRadioCard">
                      <input className="rt-ThemePanelRadioCardInput" type="radio" name="radius" value={r}
                        checked={settings.radius === r}
                        onChange={() => updateSettings({ radius: r })} />
                      <Box m="3" style={{
                        width: 32, height: 32,
                        borderTopLeftRadius: RADIUS_CORNERS[r],
                        backgroundImage: 'linear-gradient(to bottom right, var(--accent-3), var(--accent-4))',
                        borderTop: '2px solid var(--accent-a8)',
                        borderLeft: '2px solid var(--accent-a8)',
                      }} />
                    </label>
                    <Text size="1" mt="2" style={{ textTransform: 'capitalize', color: 'var(--gray-10)' }}>{r}</Text>
                  </Flex>
                ))}
              </div>

              <Separator size="4" my="4" />

              {/* ── Scaling ──────────────────────────────────────────── */}
              <Text id="scaling-title" size="2" weight="medium" mb="3">Scaling</Text>
              <div role="group" aria-labelledby="scaling-title"
                style={{ display: 'grid', gridTemplateColumns: 'repeat(5, minmax(0,1fr))', gap: 8, marginBottom: 4 }}>
                {SCALING_OPTIONS.map((s) => (
                  <label key={s} className="rt-ThemePanelRadioCard">
                    <input className="rt-ThemePanelRadioCardInput" type="radio" name="scaling" value={s}
                      checked={settings.scaling === s}
                      onChange={() => updateSettings({ scaling: s })} />
                    <Flex align="center" justify="center" style={{ height: 32 }}>
                      <Text size="1" weight="medium">{s}</Text>
                    </Flex>
                  </label>
                ))}
              </div>

              <Separator size="4" my="4" />

              {/* ── Font family (OUR ADDITION) ────────────────────────── */}
              <Text id="font-title" size="2" weight="medium" mb="3">Font family</Text>
              <Select.Root value={settings.fontFamily} onValueChange={(v) => updateSettings({ fontFamily: v })}>
                <Select.Trigger style={{ width: '100%' }} />
                <Select.Content>
                  {FONT_FAMILIES.map((f) => (
                    <Select.Item key={f.value} value={f.value}>{f.label}</Select.Item>
                  ))}
                </Select.Content>
              </Select.Root>

              <Separator size="4" my="4" />

              {/* ── Page background (OUR ADDITION) ───────────────────── */}
              <Text id="page-bg-title" size="2" weight="medium" mb="3">Page background</Text>
              <div role="group" aria-labelledby="page-bg-title"
                style={{ display: 'grid', gridTemplateColumns: 'repeat(2, minmax(0,1fr))', gap: 8, marginBottom: 4 }}>
                {[
                  {val:'grid', label:'Grid + Glow'},
                  {val:'none', label:'None'},
                  {val:'gradient', label:'Gradient'},
                  {val:'pattern', label:'Pattern'},
                ].map(({ val, label }) => (
                  <label key={val} className="rt-ThemePanelRadioCard">
                    <input className="rt-ThemePanelRadioCardInput" type="radio" name="bgStyle" value={val}
                      checked={settings.bgStyle === val}
                      onChange={() => updateSettings({ bgStyle: val })} />
                    <Flex direction="column" align="center" justify="center" gap="1" style={{ padding: '8px 4px' }}>
                      <Box style={{
                        width: 40, height: 26, borderRadius: 4,
                        border: '1px solid var(--gray-a5)',
                        backgroundImage: val === 'grid'
                          ? 'radial-gradient(circle, var(--accent-a7) 1px, transparent 1px)'
                          : val === 'gradient'
                          ? 'linear-gradient(135deg, var(--accent-a3), var(--accent-a5))'
                          : val === 'pattern'
                          ? 'repeating-linear-gradient(45deg, var(--gray-a4) 0px, var(--gray-a4) 2px, transparent 2px, transparent 8px)'
                          : 'none',
                        background: val === 'none' ? 'var(--gray-a3)' : undefined,
                        backgroundSize: val === 'grid' ? '8px 8px' : val === 'pattern' ? '8px 8px' : undefined,
                      }} />
                      <Text size="1" weight="medium">{label}</Text>
                    </Flex>
                  </label>
                ))}
              </div>

              {/* ── Copy theme button ────────────────────────────────── */}
              <Button mt="5" size="2" style={{ width: '100%' }} onClick={copyTheme}>
                {copied ? <><CheckIcon /> Copied!</> : <><CopyIcon /> Copy Theme</>}
              </Button>

              {/* Reset */}
              <Button mt="2" size="2" variant="ghost" color="gray" style={{ width: '100%' }} onClick={resetSettings}>
                <ResetIcon /> Reset to defaults
              </Button>

            </Flex>
          </ScrollArea>
        </Box>
      </Dialog.Content>
    </Dialog.Root>
  );
}
