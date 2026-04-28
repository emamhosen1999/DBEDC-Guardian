import React from 'react';
import { motion } from 'framer-motion';
import { Card, CardHeader, CardBody, Button } from '@heroui/react';
import { 
    BoltIcon,
    CalendarIcon,
    UserIcon,
    DocumentTextIcon
} from '@heroicons/react/24/outline';
import { router } from '@inertiajs/react';

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

const QuickActionsCard = ({ onPunchAction = null }) => {
    const quickActions = [
        {
            label: 'Punch In/Out',
            icon: <BoltIcon className="w-5 h-5" />,
            color: 'primary',
            onPress: onPunchAction || (() => router.visit(route('attendance-employee'))),
            description: 'Record your attendance'
        },
        {
            label: 'Request Leave',
            icon: <CalendarIcon className="w-5 h-5" />,
            color: 'success',
            onPress: () => router.visit(route('leaves')),
            description: 'Apply for leave'
        },
        {
            label: 'My Profile',
            icon: <UserIcon className="w-5 h-5" />,
            color: 'secondary',
            onPress: () => router.visit(route('profile.edit')),
            description: 'View your profile'
        },
        {
            label: 'My Reports',
            icon: <DocumentTextIcon className="w-5 h-5" />,
            color: 'warning',
            onPress: () => router.visit(route('daily-works')),
            description: 'View daily works'
        },
    ];

    return (
        <motion.div
            initial={{ opacity: 0, y: 20 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.5, delay: 0.25 }}
        >
            <Card 
                radius={getThemeRadius()}
                style={getCardStyle()}
                className="h-full"
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
                    <div className="flex items-center gap-3">
                        <div 
                            className="p-2 rounded-lg"
                            style={{ background: `color-mix(in srgb, var(--theme-primary, #006FEE) 10%, transparent)` }}
                        >
                            <BoltIcon className="w-5 h-5" style={{ color: `var(--theme-primary, #006FEE)` }} />
                        </div>
                        <div>
                            <h3 className="text-lg font-semibold" style={{ color: `var(--theme-foreground, #11181C)`, fontFamily: `var(--fontFamily, "Inter")` }}>
                                Quick Actions
                            </h3>
                            <p className="text-xs" style={{ color: `var(--theme-foreground-400, #A1A1AA)`, fontFamily: `var(--fontFamily, "Inter")` }}>
                                Common tasks
                            </p>
                        </div>
                    </div>
                </CardHeader>
                <CardBody className="pt-0">
                    <div className="grid grid-cols-2 gap-3">
                        {quickActions.map((action, index) => (
                            <motion.div
                                key={index}
                                initial={{ opacity: 0, scale: 0.9 }}
                                animate={{ opacity: 1, scale: 1 }}
                                transition={{ duration: 0.3, delay: 0.3 + (index * 0.1) }}
                            >
                                <Button
                                    variant="flat"
                                    color={action.color}
                                    className="h-24 flex-col gap-2"
                                    onPress={action.onPress}
                                    style={{ 
                                        fontFamily: `var(--fontFamily, "Inter")`,
                                        background: `color-mix(in srgb, var(--theme-${action.color}, #006FEE) 10%, transparent)`
                                    }}
                                >
                                    <span className="p-2 rounded-full" style={{ background: `color-mix(in srgb, var(--theme-${action.color}, #006FEE) 20%, transparent)` }}>
                                        {action.icon}
                                    </span>
                                    <span className="text-xs font-semibold text-center">{action.label}</span>
                                </Button>
                            </motion.div>
                        ))}
                    </div>
                </CardBody>
            </Card>
        </motion.div>
    );
};

export default QuickActionsCard;
