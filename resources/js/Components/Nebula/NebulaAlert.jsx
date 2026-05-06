import React from 'react';

const NebulaAlert = ({ 
  children, 
  variant = 'info', 
  icon = null, 
  className = '', 
  ...props 
}) => {
  const variantClasses = {
    info: 'ng-alert-info',
    success: 'ng-alert-success',
    warning: 'ng-alert-warning',
    danger: 'ng-alert-danger',
  };

  const defaultIcons = {
    info: 'ℹ',
    success: '✓',
    warning: '⚠',
    danger: '✕',
  };

  const classes = [
    'ng-alert',
    variantClasses[variant] || variantClasses.info,
    className,
  ].filter(Boolean).join(' ');

  return (
    <div className={classes} {...props}>
      <div className="ng-alert-icon">
        {icon || defaultIcons[variant]}
      </div>
      <div className="ng-alert-content">
        {children}
      </div>
    </div>
  );
};

export default NebulaAlert;
