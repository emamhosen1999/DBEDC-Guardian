import React from 'react';

const NebulaModal = ({ 
  isOpen = false, 
  onClose, 
  children, 
  className = '', 
  size = 'md',
  ...props 
}) => {
  if (!isOpen) return null;

  const sizeClasses = {
    sm: 'ng-modal-sm',
    md: 'ng-modal-md',
    lg: 'ng-modal-lg',
    xl: 'ng-modal-xl',
  };

  return (
    <div className="ng-modal-backdrop" onClick={onClose}>
      <div 
        className={`ng-modal-shell glass ${sizeClasses[size] || sizeClasses.md} ${className}`}
        onClick={(e) => e.stopPropagation()}
        {...props}
      >
        {children}
      </div>
    </div>
  );
};

export default NebulaModal;
