import React, { useState } from 'react';

const NebulaTooltip = ({ 
  children, 
  content, 
  position = 'top', 
  className = '', 
  ...props 
}) => {
  const [isVisible, setIsVisible] = useState(false);

  const positionClasses = {
    top: 'tooltip-top',
    bottom: 'tooltip-bottom',
    left: 'tooltip-left',
    right: 'tooltip-right',
  };

  return (
    <div 
      className={`tooltip-wrap ${positionClasses[position] || positionClasses.top} ${className}`}
      onMouseEnter={() => setIsVisible(true)}
      onMouseLeave={() => setIsVisible(false)}
      {...props}
    >
      {children}
      {isVisible && (
        <div className="ng-tooltip">
          {content}
        </div>
      )}
    </div>
  );
};

export default NebulaTooltip;
