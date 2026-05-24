import React from 'react';
import { Card } from '@radix-ui/themes';

const GlassCard = ({ children, className, style, ...rest }) => {
  return (
    <Card style={style} className={className} {...rest}>
      {children}
    </Card>
  );
};

export default GlassCard;
