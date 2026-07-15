import { Panel } from '@/Components/ui/Panel';
import React from 'react';

const GlassCard = ({ children, className, style, ...rest }) => {
  return (
    <Panel style={style} className={className} {...rest}>
      {children}
    </Panel>
  );
};

export default GlassCard;
