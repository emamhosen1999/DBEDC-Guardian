import React from 'react';

const NebulaProgress = ({ 
  value = 0, 
  max = 100, 
  className = '', 
  ...props 
}) => {
  const percentage = Math.min(100, Math.max(0, (value / max) * 100));

  return (
    <div className={`ng-progress ${className}`} {...props}>
      <div 
        className="ng-progress-bar" 
        style={{ width: `${percentage}%` }}
      />
    </div>
  );
};

export default NebulaProgress;
