import React from 'react';
import { Card } from '@radix-ui/themes';

const GlassCard = ({ children, className, style, ...rest }) => {
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

export default GlassCard;
