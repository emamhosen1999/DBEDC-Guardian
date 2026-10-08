import React from 'react';
import { Box, Flex, Heading, Separator } from '@radix-ui/themes';

/* Mobile-app-aligned: tinted uses --aero-surface (#0E0E14 dark / #F4F4F7 light) */
const TINT_STYLE = { background: 'var(--aero-surface, var(--gray-2))', borderRadius: 12 };
// Cardless "surface" — a defining hairline edge, no fill, no shadow.
// Matches mobile app's $surfaceBorder (rgba(255,255,255,0.10) dark / rgba(0,0,0,0.07) light)
const SURFACE_STYLE = { border: '1px solid var(--aero-surface-border, var(--gray-a4))', borderRadius: 12 };

/**
 * Inline radii win over any stylesheet, so a panel's radius is routed through
 * the design-language token instead: Cyber sets --dl-panel-radius to 0, every
 * other language falls back to the value the caller asked for.
 */
export function resolvePanelStyle(style) {
  if (!style || style.borderRadius == null) return style;
  const requested = typeof style.borderRadius === 'number' ? `${style.borderRadius}px` : style.borderRadius;
  return { ...style, borderRadius: `var(--dl-panel-radius, ${requested})` };
}

function intersperseSeparators(children) {
  const arr = React.Children.toArray(children);
  return arr.flatMap((child, i) =>
    i === 0 ? [child] : [<Separator key={`panel-sep-${i}`} size="4" my="4" />, child]
  );
}

/**
 * Panel — the single flat/cardless surface for the app.
 * Default: transparent, NO border, NO shadow. Structure comes from whitespace,
 * hairline Separators (Panel.Header / Panel.Section), and the Radix type scale.
 *   tinted            -> sits on a --gray-2 band + radius (emphasis blocks: KPIs, stats, alerts)
 *   variant="surface" -> hairline --gray-a4 edge + radius + padding (intentional surface cards)
 *   divided           -> hairline Separator between each child
 * `variant`/`size` are absorbed (Card-era leftovers) and never leak to the DOM.
 * All other props forward to the underlying Radix <Box>.
 */
export function Panel({ tinted = false, divided = false, variant, size, p, children, style, className, ...props }) {
  const isSurface = variant === 'surface';
  const base = tinted ? TINT_STYLE : isSurface ? SURFACE_STYLE : null;
  const mergedStyle = resolvePanelStyle(base ? { ...base, ...style } : style);
  const kids = divided ? intersperseSeparators(children) : children;
  return (
    <Box
      p={tinted || isSurface ? (p ?? '4') : p}
      style={mergedStyle}
      className={className ? `dl-panel ${className}` : 'dl-panel'}
      data-panel={tinted ? 'tinted' : isSurface ? 'surface' : undefined}
      {...props}
    >
      {kids}
    </Box>
  );
}

function PanelHeader({ title, actions, children }) {
  return (
    <Box>
      <Flex align="center" justify="between" gap="3" mb="3">
        {title ? <Heading size="4" weight="medium">{title}</Heading> : children}
        {actions ? <Flex align="center" gap="2">{actions}</Flex> : null}
      </Flex>
      <Separator size="4" mb="4" />
    </Box>
  );
}

function PanelBody({ children, ...props }) {
  return <Box {...props}>{children}</Box>;
}

function PanelSection({ title, first = false, children }) {
  return (
    <Box>
      {!first ? <Separator size="4" my="4" /> : null}
      {title ? <Heading size="3" weight="medium" mb="2">{title}</Heading> : null}
      {children}
    </Box>
  );
}

Panel.Header = PanelHeader;
Panel.Body = PanelBody;
Panel.Section = PanelSection;

export default Panel;
