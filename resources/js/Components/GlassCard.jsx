import React from 'react';
import { Card } from '@radix-ui/themes';

const GlassCard = ({ children, className, style, ...rest }) => (
  <Card style={style} {...rest}>{children}</Card>
);

export default GlassCard;
