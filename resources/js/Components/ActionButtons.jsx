import React, { useState, useEffect } from 'react';
import { Button, Flex } from '@radix-ui/themes';
import { DownloadIcon, PlusIcon, MixerHorizontalIcon, GearIcon, ReloadIcon } from '@radix-ui/react-icons';

/**
 * Standardized action buttons component matching TimeSheet table design
 * @param {Object} props
 * @param {Array} props.buttons - Array of button configurations
 * @param {boolean} props.loading - Loading state for buttons
 */
const ActionButtons = ({ buttons = [], loading = false }) => {
    // Custom responsive hook
    const useResponsive = () => {
        const [isMobile, setIsMobile] = useState(false);
        
        useEffect(() => {
            const checkDevice = () => {
                setIsMobile(window.innerWidth < 640);
            };
            
            checkDevice();
            window.addEventListener('resize', checkDevice);
            return () => window.removeEventListener('resize', checkDevice);
        }, []);
        
        return { isMobile };
    };
    
    const { isMobile } = useResponsive();

    // Predefined button styles matching timesheet table
    const colorMap = { primary: 'indigo', success: 'green', danger: 'red', warning: 'orange', secondary: 'gray', bordered: 'gray' };

    // Common button configurations
    const commonButtons = {
        add:          { color: 'primary',   icon: <PlusIcon />,             label: 'Add' },
        export_excel: { color: 'success',   icon: <DownloadIcon />,          label: 'Excel' },
        export_pdf:   { color: 'danger',    icon: <DownloadIcon />,          label: 'PDF' },
        refresh:      { color: 'primary',   icon: <ReloadIcon />,            label: 'Refresh' },
        filter:       { color: 'secondary', icon: <MixerHorizontalIcon />,   label: 'Filter' },
        settings:     { color: 'secondary', icon: <GearIcon />,              label: 'Settings' },
    };

    if (!buttons || buttons.length === 0) return null;

    return (
        <Flex gap="2" wrap="wrap">
            {buttons.map((button, index) => {
                const buttonConfig = typeof button === 'string'
                    ? { ...commonButtons[button], type: button }
                    : button;
                if (!buttonConfig) return null;
                const radixColor = colorMap[buttonConfig.color] || 'indigo';
                return (
                    <Button
                        key={index}
                        color={radixColor}
                        variant="soft"
                        disabled={buttonConfig.isDisabled || loading}
                        size={isMobile ? '1' : '2'}
                        onClick={buttonConfig.onPress || buttonConfig.onClick}
                    >
                        {buttonConfig.icon}
                        {buttonConfig.label || buttonConfig.type}
                    </Button>
                );
            })}
        </Flex>
    );
};

export default ActionButtons;
