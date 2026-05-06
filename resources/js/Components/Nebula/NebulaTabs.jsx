import React, { useState } from 'react';

const NebulaTabs = ({ 
  tabs = [], 
  defaultTab = 0, 
  onChange, 
  className = '', 
  ...props 
}) => {
  const [activeTab, setActiveTab] = useState(defaultTab);

  const handleTabChange = (index) => {
    setActiveTab(index);
    if (onChange) onChange(index);
  };

  return (
    <div className={`tabs-shell glass ${className}`} {...props}>
      {tabs.map((tab, index) => (
        <button
          key={index}
          className={`tab-btn ${activeTab === index ? 'active' : ''}`}
          onClick={() => handleTabChange(index)}
        >
          {tab.label}
        </button>
      ))}
      <div 
        className="tab-indicator"
        style={{ 
          left: `${(activeTab / tabs.length) * 100}%`,
          width: `${100 / tabs.length}%`
        }}
      />
    </div>
  );
};

export default NebulaTabs;
