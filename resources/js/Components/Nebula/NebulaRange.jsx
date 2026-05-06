import React from 'react';

const NebulaRange = ({ 
  value = 0, 
  min = 0, 
  max = 100, 
  onChange, 
  disabled = false, 
  className = '',
  label = '',
  ...props 
}) => {
  return (
    <div className={`ng-range-wrapper ${className}`}>
      {label && <label className="ng-label">{label}</label>}
      <input
        type="range"
        className="ng-range"
        value={value}
        min={min}
        max={max}
        onChange={onChange}
        disabled={disabled}
        {...props}
      />
    </div>
  );
};

export default NebulaRange;
