import React from 'react';

const NebulaSpinner = ({ 
  size = 'md', 
  className = '', 
  ...props 
}) => {
  const sizeClasses = {
    sm: 'ng-spinner-sm',
    md: 'ng-spinner-md',
    lg: 'ng-spinner-lg',
  };

  const classes = [
    'ng-spinner',
    sizeClasses[size] || sizeClasses.md,
    className,
  ].filter(Boolean).join(' ');

  return (
    <div className={classes} {...props} />
  );
};

export default NebulaSpinner;
