import React from 'react';
import { Box, Flex, Heading, Separator } from '@radix-ui/themes';

const TINT_STYLE = { background: 'var(--gray-2)', borderRadius: 'var(--radius-3)' };

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
 *   tinted  -> sits on a --gray-2 band + radius (emphasis blocks: KPIs, stats, alerts)
 *   divided -> hairline Separator between each child
 * All other props forward to the underlying Radix <Box>.
 */
export function Panel({ tinted = false, divided = false, p, children, style, ...props }) {
  const mergedStyle = tinted ? { ...TINT_STYLE, ...style } : style;
  const kids = divided ? intersperseSeparators(children) : children;
  return (
    <Box p={tinted ? (p ?? '4') : p} style={mergedStyle} {...props}>
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
