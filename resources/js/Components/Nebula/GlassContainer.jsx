import React from 'react';

const GlassContainer = ({ 
  children, 
  perspective = 'mid', 
  className = '', 
  ...props 
}) => {
  const perspectiveClasses = {
    near: 'scene-near',
    mid: 'scene',
    far: 'scene-far',
  };

  const classes = [
    perspectiveClasses[perspective] || perspectiveClasses.mid,
    className,
  ].filter(Boolean).join(' ');

  return (
    <div className={classes} {...props}>
      {children}
    </div>
  );
};

export default GlassContainer;
