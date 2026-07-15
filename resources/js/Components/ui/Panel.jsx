import React from 'react';
import { Box, Flex, Heading, Separator } from '@radix-ui/themes';

const TINT_STYLE = { background: 'var(--gray-2)', borderRadius: 'var(--radius-3)' };
// Cardless "surface" — a defining hairline edge, no fill, no shadow (ladder rung L3).
// Applied to panels that were real Radix surface Cards (variant="surface"): they read as
// intentional surfaces without reintroducing heavy card chrome, and regain the inner
// padding the Card used to provide.
const SURFACE_STYLE = { border: '1px solid var(--gray-a4)', borderRadius: 'var(--radius-3)' };

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
export function Panel({ tinted = false, divided = false, variant, size, p, children, style, ...props }) {
  const isSurface = variant === 'surface';
  const base = tinted ? TINT_STYLE : isSurface ? SURFACE_STYLE : null;
  const mergedStyle = base ? { ...base, ...style } : style;
  const kids = divided ? intersperseSeparators(children) : children;
  return (
    <Box p={tinted || isSurface ? (p ?? '4') : p} style={mergedStyle} {...props}>
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
