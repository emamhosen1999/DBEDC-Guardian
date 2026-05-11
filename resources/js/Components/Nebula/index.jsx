// Nebula → Radix UI compatibility shim
import React from 'react';
import { Box, Card } from '@radix-ui/themes';

export const GlassContainer = ({ children, className, style, ...rest }) => (
  <Box style={style} {...rest}>{children}</Box>
);

export const GlassCard = ({ children, className, style, ...rest }) => (
  <Card style={style} {...rest}>{children}</Card>
);

export const GlassPanel = ({ children, ...rest }) => <Box {...rest}>{children}</Box>;
export const GlassSection = ({ children, ...rest }) => <Box {...rest}>{children}</Box>;
export const GlassHeader = ({ children, ...rest }) => <Box {...rest}>{children}</Box>;
export const GlassBody = ({ children, ...rest }) => <Box p="3" {...rest}>{children}</Box>;
export const GlassFooter = ({ children, ...rest }) => <Box {...rest}>{children}</Box>;
