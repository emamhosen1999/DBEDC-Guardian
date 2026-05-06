import React, { useState, useEffect } from 'react';
import { NebulaButton, NebulaToggle, GlassCard } from './index';

const NebulaThemeDrawer = ({ isOpen, onClose }) => {
  const [theme, setTheme] = useState('dark');

  useEffect(() => {
    const stored = localStorage.getItem('nebula-theme') || 'dark';
    setTheme(stored);
    document.body.setAttribute('data-theme', stored);
  }, []);

  const handleThemeChange = (newTheme) => {
    setTheme(newTheme);
    localStorage.setItem('nebula-theme', newTheme);
    document.body.setAttribute('data-theme', newTheme);
  };

  if (!isOpen) return null;

  return (
    <div className="ng-theme-drawer-backdrop" onClick={onClose}>
      <GlassCard className="ng-theme-drawer" onClick={(e) => e.stopPropagation()}>
        <div className="ng-theme-drawer-header">
          <h2 className="ng-theme-drawer-title">
            <span className="holo-text">Nebula Glass</span> Theme
          </h2>
          <NebulaButton variant="ghost" size="sm" onClick={onClose}>
            ×
          </NebulaButton>
        </div>

        <div className="ng-theme-drawer-content">
          <div className="ng-theme-section">
            <div className="eyebrow">Theme Mode</div>
            <div className="ng-theme-options">
              <button
                className={`ng-theme-option ${theme === 'dark' ? 'active' : ''}`}
                onClick={() => handleThemeChange('dark')}
              >
                <div className="ng-theme-preview ng-theme-preview-dark"></div>
                <span>Dark</span>
              </button>
              <button
                className={`ng-theme-option ${theme === 'light' ? 'active' : ''}`}
                onClick={() => handleThemeChange('light')}
              >
                <div className="ng-theme-preview ng-theme-preview-light"></div>
                <span>Light</span>
              </button>
            </div>
          </div>

          <div className="ng-theme-section">
            <div className="eyebrow">About Nebula Glass</div>
            <p className="ng-theme-description">
              A pure CSS design system featuring glassmorphism, holographic gradients, 
              and 3D floating effects. Built with Space Grotesk typography and comprehensive 
              design tokens.
            </p>
          </div>
        </div>
      </GlassCard>
    </div>
  );
};

export default NebulaThemeDrawer;
