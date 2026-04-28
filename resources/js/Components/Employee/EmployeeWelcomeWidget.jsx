import React from 'react';
import { motion } from 'framer-motion';
import { Card, CardHeader, CardBody, Chip } from '@heroui/react';
import { 
    SunIcon,
    MoonIcon,
    CalendarDaysIcon,
    ClockIcon
} from '@heroicons/react/24/outline';
import ProfileAvatar from '@/Components/ProfileAvatar';

// Helper function to get theme radius
const getThemeRadius = () => {
    if (typeof window === 'undefined') return 'lg';
    const rootStyles = getComputedStyle(document.documentElement);
    const borderRadius = rootStyles.getPropertyValue('--borderRadius')?.trim() || '12px';
    const radiusValue = parseInt(borderRadius);
    if (radiusValue === 0) return 'none';
    if (radiusValue <= 4) return 'sm';
    if (radiusValue <= 8) return 'md';
    if (radiusValue <= 16) return 'lg';
    return 'full';
};

// Get theme-aware card style
const getCardStyle = () => ({
    background: `linear-gradient(135deg, 
        var(--theme-content1, #FAFAFA) 20%, 
        var(--theme-content2, #F4F4F5) 10%, 
        var(--theme-content3, #F1F3F4) 20%)`,
    borderColor: `transparent`,
    borderWidth: `var(--borderWidth, 2px)`,
    borderRadius: `var(--borderRadius, 12px)`,
    fontFamily: `var(--fontFamily, "Inter")`,
    transform: `scale(var(--scale, 1))`,
    transition: 'all 0.2s ease-in-out',
    boxShadow: '0 2px 8px rgba(0, 0, 0, 0.1)',
});

const EmployeeWelcomeWidget = ({ auth, status = 'Present' }) => {
    const getGreeting = () => {
        const hour = new Date().getHours();
        if (hour < 12) return 'Good Morning';
        if (hour < 18) return 'Good Afternoon';
        return 'Good Evening';
    };

    const getCurrentDate = () => {
        const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
        return new Date().toLocaleDateString('en-US', options);
    };

    const getCurrentTime = () => {
        return new Date().toLocaleTimeString('en-US', { 
            hour: '2-digit', 
            minute: '2-digit',
            hour12: true 
        });
    };

    const isDayTime = () => {
        const hour = new Date().getHours();
        return hour >= 6 && hour < 18;
    };

    const getStatusColor = () => {
        switch (status.toLowerCase()) {
            case 'present':
                return 'success';
            case 'on leave':
                return 'warning';
            case 'absent':
                return 'danger';
            case 'remote':
                return 'primary';
            default:
                return 'default';
        }
    };

    return (
        <motion.div
            initial={{ opacity: 0, y: -20 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.5 }}
        >
            <Card 
                radius={getThemeRadius()}
                style={getCardStyle()}
                onMouseEnter={(e) => {
                    e.currentTarget.style.border = `var(--borderWidth, 2px) solid color-mix(in srgb, var(--theme-primary) 50%, transparent)`;
                    e.currentTarget.style.borderRadius = `var(--borderRadius, 12px)`;
                    e.currentTarget.style.transform = `scale(calc(var(--scale, 1) * 1.02))`;
                }}
                onMouseLeave={(e) => {
                    e.currentTarget.style.border = `var(--borderWidth, 2px) solid transparent`;
                    e.currentTarget.style.transform = `scale(var(--scale, 1))`;
                }}
            >
                <CardHeader className="pb-2" style={{ background: 'transparent' }}>
                    <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-4 w-full">
                        {/* Left Section - Greeting */}
                        <div className="flex items-center gap-4">
                            <motion.div
                                initial={{ scale: 0 }}
                                animate={{ scale: 1 }}
                                transition={{ delay: 0.2, type: "spring", stiffness: 200 }}
                                className="flex-shrink-0"
                            >
                                {isDayTime() ? (
                                    <SunIcon className="w-12 h-12" style={{ color: `var(--theme-primary, #006FEE)` }} />
                                ) : (
                                    <MoonIcon className="w-12 h-12" style={{ color: `var(--theme-secondary, #9353d3)` }} />
                                )}
                            </motion.div>
                            <div>
                                <h1 className="text-2xl font-bold" style={{ color: `var(--theme-foreground, #11181C)`, fontFamily: `var(--fontFamily, "Inter")` }}>
                                    {getGreeting()}, {auth.user?.name || 'Employee'}
                                </h1>
                                <p className="text-sm" style={{ color: `var(--theme-foreground-400, #A1A1AA)`, fontFamily: `var(--fontFamily, "Inter")` }}>
                                    Welcome to your employee dashboard
                                </p>
                            </div>
                        </div>

                        {/* Right Section - Date, Time, and Avatar */}
                        <div className="flex items-center gap-4">
                            <div className="text-right">
                                <div className="flex items-center justify-end gap-2 mb-1">
                                    <CalendarDaysIcon className="w-4 h-4" style={{ color: `var(--theme-foreground-400, #A1A1AA)` }} />
                                    <span className="text-sm font-medium" style={{ color: `var(--theme-foreground, #11181C)`, fontFamily: `var(--fontFamily, "Inter")` }}>
                                        {getCurrentDate()}
                                    </span>
                                </div>
                                <div className="flex items-center justify-end gap-2">
                                    <ClockIcon className="w-4 h-4" style={{ color: `var(--theme-foreground-400, #A1A1AA)` }} />
                                    <span className="text-sm font-medium" style={{ color: `var(--theme-foreground, #11181C)`, fontFamily: `var(--fontFamily, "Inter")` }}>
                                        {getCurrentTime()}
                                    </span>
                                </div>
                            </div>
                            <motion.div
                                initial={{ scale: 0, rotate: -180 }}
                                animate={{ scale: 1, rotate: 0 }}
                                transition={{ delay: 0.3, type: "spring", stiffness: 200 }}
                                className="flex-shrink-0"
                            >
                                <ProfileAvatar
                                    src={auth.user?.profile_image_url}
                                    name={auth.user?.name || 'Employee'}
                                    size="lg"
                                />
                            </motion.div>
                        </div>
                    </div>
                </CardHeader>
                <CardBody className="pt-0">
                    <motion.div
                        initial={{ opacity: 0, y: 20 }}
                        animate={{ opacity: 1, y: 0 }}
                        transition={{ delay: 0.4, duration: 0.5 }}
                    >
                        <div className="flex flex-wrap gap-2">
                            <Chip 
                                size="sm" 
                                variant="flat"
                                color="primary"
                                style={{ fontFamily: `var(--fontFamily, "Inter")` }}
                            >
                                {auth.user?.designation?.title || 'Employee'}
                            </Chip>
                            <Chip 
                                size="sm" 
                                variant="flat"
                                color={getStatusColor()}
                                style={{ fontFamily: `var(--fontFamily, "Inter")` }}
                            >
                                Status: {status}
                            </Chip>
                            <Chip 
                                size="sm" 
                                variant="flat"
                                color="default"
                                style={{ fontFamily: `var(--fontFamily, "Inter")` }}
                            >
                                {auth.user?.department?.name || 'Department'}
                            </Chip>
                        </div>
                    </motion.div>
                </CardBody>
            </Card>
        </motion.div>
    );
};

export default EmployeeWelcomeWidget;
