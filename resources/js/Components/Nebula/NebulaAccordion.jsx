import React, { useState } from 'react';

const NebulaAccordion = ({ 
  items = [], 
  defaultOpen = null, 
  className = '', 
  ...props 
}) => {
  const [openIndex, setOpenIndex] = useState(defaultOpen);

  return (
    <div className={`ng-accordion ${className}`} {...props}>
      {items.map((item, index) => (
        <div key={index} className="accordion-item glass">
          <button
            className="accordion-head"
            onClick={() => setOpenIndex(openIndex === index ? null : index)}
          >
            {item.title}
            <span className={`accordion-chev ${openIndex === index ? 'open' : ''}`}>
              {openIndex === index ? '−' : '+'}
            </span>
          </button>
          {openIndex === index && (
            <div className="accordion-body">
              {item.content}
            </div>
          )}
        </div>
      ))}
    </div>
  );
};

export default NebulaAccordion;
