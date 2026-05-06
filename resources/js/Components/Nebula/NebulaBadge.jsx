import React from 'react';

const NebulaBadge = ({ 
  children, 
  tone = 'neutral', 
  className = '', 
  ...props 
}) => {
  const toneClasses = {
    neutral: '',
    plasma: 'ng-badge-plasma',
    aurora: 'ng-badge-aurora',
    electric: 'ng-badge-electric',
    crimson: 'ng-badge-crimson',
  };

  const classes = [
    'ng-badge',
    toneClasses[tone] || toneClasses.neutral,
    className,
  ].filter(Boolean).join(' ');

  return (
    <span className={classes} {...props}>
      {children}
    </span>
  );
};

const NebulaChip = ({ 
  children, 
  onClose, 
  className = '', 
  ...props 
}) => {
  return (
    <div className={`ng-chip ${className}`} {...props}>
      {children}
      {onClose && (
        <button 
          type="button"
          className="ng-chip-close" 
          onClick={onClose}
          aria-label="Remove"
        >
          ×
        </button>
      )}
    </div>
  );
};

export { NebulaBadge, NebulaChip };
