import React from 'react';

const GlassCard = ({ 
  children, 
  variant = 'default', 
  tint = 'neutral', 
  className = '', 
  ...props 
}) => {
  const variantClasses = {
    default: 'glass',
    heavy: 'glass-heavy',
    frosted: 'glass-frosted',
  };

  const tintClasses = {
    neutral: '',
    plasma: 'glass-tint-plasma',
    aurora: 'glass-tint-aurora',
    electric: 'glass-tint-electric',
    crimson: 'glass-tint-crimson',
  };

  const classes = [
    variantClasses[variant] || variantClasses.default,
    tintClasses[tint] || tintClasses.neutral,
    className,
  ].filter(Boolean).join(' ');

  return (
    <div className={classes} {...props}>
      {children}
    </div>
  );
};

export default GlassCard;
