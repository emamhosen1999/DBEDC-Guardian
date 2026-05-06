import React from 'react';

const NebulaButton = ({ 
  children, 
  variant = 'primary', 
  size = 'md', 
  className = '', 
  disabled = false,
  onClick,
  type = 'button',
  ...props 
}) => {
  const baseClasses = 'ng-btn';
  const variantClasses = {
    primary: 'ng-btn-primary',
    secondary: 'ng-btn-secondary',
    ghost: 'ng-btn-ghost',
    glass: 'ng-btn-glass',
    danger: 'ng-btn-danger',
    aurora: 'ng-btn-aurora',
  };
  const sizeClasses = {
    sm: 'ng-btn-sm',
    md: '',
    lg: 'ng-btn-lg',
  };

  const classes = [
    baseClasses,
    variantClasses[variant] || variantClasses.primary,
    sizeClasses[size] || sizeClasses.md,
    className,
  ].filter(Boolean).join(' ');

  return (
    <button
      type={type}
      className={classes}
      disabled={disabled}
      onClick={onClick}
      {...props}
    >
      {children}
    </button>
  );
};

export default NebulaButton;
