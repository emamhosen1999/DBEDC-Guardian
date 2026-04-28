import React from 'react';
import { motion } from 'framer-motion';
import { Card, CardBody } from '@heroui/react';
import { 
    ClockIcon,
    MapPinIcon,
    CheckCircleIcon
} from '@heroicons/react/24/outline';

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

const AttendanceStatusCard = ({ punchData = {} }) => {
    const { punches = [], total_production_time = '00:00:00', isUserOnLeave = null } = punchData;
    
    const latestPunch = punches.length > 0 ? punches[punches.length - 1] : null;
    const isPunchedIn = latestPunch && !latestPunch.punchout_time;
    
    return (
        <motion.div
            initial={{ opacity: 0, scale: 0.9 }}
            animate={{ opacity: 1, scale: 1 }}
            transition={{ duration: 0.3, delay: 0.1 }}
        >
            <Card 
                radius={getThemeRadius()}
                style={getCardStyle()}
                className="h-full"
                onMouseEnter={(e) => {
                    e.currentTarget.style.border = `var(--borderWidth, 2px) solid color-mix(in srgb, var(--theme-success) 50%, transparent)`;
                    e.currentTarget.style.borderRadius = `var(--borderRadius, 12px)`;
                    e.currentTarget.style.transform = `scale(calc(var(--scale, 1) * 1.02))`;
                }}
                onMouseLeave={(e) => {
                    e.currentTarget.style.border = `var(--borderWidth, 2px) solid transparent`;
                    e.currentTarget.style.transform = `scale(var(--scale, 1))`;
                }}
            >
                <CardBody className="p-4">
                    <div className="flex items-start justify-between mb-3">
                        <div className="flex items-center gap-3">
                            <div 
                                className="p-2 rounded-lg"
                                style={{ background: `color-mix(in srgb, var(--theme-success, #22C55E) 10%, transparent)` }}
                            >
                                <ClockIcon className="w-6 h-6" style={{ color: `var(--theme-success, #22C55E)` }} />
                            </div>
                            <div>
                                <h3 className="text-sm font-semibold" style={{ color: `var(--theme-foreground, #11181C)`, fontFamily: `var(--fontFamily, "Inter")` }}>
                                    Attendance Status
                                </h3>
                                <p className="text-xs" style={{ color: `var(--theme-foreground-400, #A1A1AA)`, fontFamily: `var(--fontFamily, "Inter")` }}>
                                    Today
                                </p>
                            </div>
                        </div>
                        {isPunchedIn && (
                            <div className="flex items-center gap-1">
                                <div className="w-2 h-2 rounded-full bg-success-500 animate-pulse" style={{ background: `var(--theme-success, #22C55E)` }} />
                                <span className="text-xs font-medium" style={{ color: `var(--theme-success, #22C55E)`, fontFamily: `var(--fontFamily, "Inter")` }}>
                                    Active
                                </span>
                            </div>
                        )}
                    </div>
                    
                    <div className="space-y-2">
                        {isUserOnLeave ? (
                            <div className="flex items-center gap-2 p-2 rounded-lg" style={{ background: `color-mix(in srgb, var(--theme-warning, #F59E0B) 10%, transparent)` }}>
                                <span className="text-sm font-medium" style={{ color: `var(--theme-warning, #F59E0B)`, fontFamily: `var(--fontFamily, "Inter")` }}>
                                    On Leave ({isUserOnLeave.leave_type})
                                </span>
                            </div>
                        ) : (
                            <>
                                <div className="flex items-center justify-between">
                                    <span className="text-xs" style={{ color: `var(--theme-foreground-400, #A1A1AA)`, fontFamily: `var(--fontFamily, "Inter")` }}>
                                        Status
                                    </span>
                                    <span className="text-sm font-semibold" style={{ color: `var(--theme-foreground, #11181C)`, fontFamily: `var(--fontFamily, "Inter")` }}>
                                        {isPunchedIn ? 'Punched In' : 'Not Punched In'}
                                    </span>
                                </div>
                                {latestPunch && (
                                    <div className="flex items-center justify-between">
                                        <span className="text-xs" style={{ color: `var(--theme-foreground-400, #A1A1AA)`, fontFamily: `var(--fontFamily, "Inter")` }}>
                                            Last Punch
                                        </span>
                                        <span className="text-sm font-medium" style={{ color: `var(--theme-foreground, #11181C)`, fontFamily: `var(--fontFamily, "Inter")` }}>
                                            {latestPunch.punchin_time?.substring(0, 5) || '--:--'}
                                        </span>
                                    </div>
                                )}
                                <div className="flex items-center justify-between">
                                    <span className="text-xs" style={{ color: `var(--theme-foreground-400, #A1A1AA)`, fontFamily: `var(--fontFamily, "Inter")` }}>
                                        Work Time
                                    </span>
                                    <span className="text-sm font-semibold" style={{ color: `var(--theme-primary, #006FEE)`, fontFamily: `var(--fontFamily, "Inter")` }}>
                                        {total_production_time}
                                    </span>
                                </div>
                            </>
                        )}
                    </div>
                </CardBody>
            </Card>
        </motion.div>
    );
};

export default AttendanceStatusCard;
