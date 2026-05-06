import React from 'react';

const NebulaCheckbox = ({ 
  label, 
  checked = false, 
  onChange, 
  disabled = false, 
  className = '',
  ...props 
}) => {
  return (
    <label className={`ng-checkbox-wrapper ${className}`}>
      <input
        type="checkbox"
        className="ng-check"
        checked={checked}
        onChange={onChange}
        disabled={disabled}
        {...props}
      />
      {label && <span className="ng-checkbox-label">{label}</span>}
    </label>
  );
};

export default NebulaCheckbox;
