import React from 'react';

const NebulaToggle = ({ 
  checked = false, 
  onChange, 
  disabled = false, 
  className = '',
  label = '',
  ...props 
}) => {
  return (
    <label className={`ng-toggle-wrapper ${className}`}>
      <input
        type="checkbox"
        className="ng-toggle"
        checked={checked}
        onChange={onChange}
        disabled={disabled}
        {...props}
      />
      {label && <span className="ng-toggle-label">{label}</span>}
    </label>
  );
};

export default NebulaToggle;
