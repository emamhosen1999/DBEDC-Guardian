import React from 'react';

const NebulaSkeleton = ({ 
  width = '100%', 
  height = '1em', 
  className = '', 
  ...props 
}) => {
  return (
    <div 
      className={`ng-skeleton ${className}`}
      style={{ width, height }}
      {...props}
    />
  );
};

export default NebulaSkeleton;
