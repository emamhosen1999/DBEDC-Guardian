// Nebula → Radix UI compatibility shim
import React from 'react';
import { Box, Card } from '@radix-ui/themes';

export const GlassContainer = ({ children, className, style, ...rest }) => (
  <Box style={style} {...rest}>{children}</Box>
);

export const GlassCard = ({ children, className, style, ...rest }) => {
  const glassStyle = {
    backdropFilter: 'blur(24px)',
    WebkitBackdropFilter: 'blur(24px)',
    background: 'var(--color-panel-translucent)',
    border: '1px solid var(--gray-a4)',
    boxShadow: '0 24px 64px var(--black-a6), 0 4px 16px var(--black-a3)',
    ...style
  };

  return (
    <Card style={glassStyle} className={className} {...rest}>
      {children}
    </Card>
  );
};

export const GlassPanel = ({ children, ...rest }) => <Box {...rest}>{children}</Box>;
export const GlassSection = ({ children, ...rest }) => <Box {...rest}>{children}</Box>;
export const GlassHeader = ({ children, ...rest }) => <Box {...rest}>{children}</Box>;
export const GlassBody = ({ children, ...rest }) => <Box p="3" {...rest}>{children}</Box>;
export const GlassFooter = ({ children, ...rest }) => <Box {...rest}>{children}</Box>;
