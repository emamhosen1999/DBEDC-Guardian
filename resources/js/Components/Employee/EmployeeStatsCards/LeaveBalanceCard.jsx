import React from 'react';
import { motion } from 'framer-motion';
import { Card, CardBody } from '@heroui/react';
import { 
    CalendarIcon,
    SparklesIcon
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

const LeaveBalanceCard = ({ leaveBalance = {} }) => {
    const { leaveTypes = [], userLeaveCounts = [] } = leaveBalance;

    // Calculate totals across all leave types
    const totalRemaining = userLeaveCounts.reduce(
        (sum, count) => sum + (count.remaining_days || 0),
        0
    );

    return (
        <motion.div
            initial={{ opacity: 0, scale: 0.9 }}
            animate={{ opacity: 1, scale: 1 }}
            transition={{ duration: 0.3, delay: 0.3 }}
        >
            <Card 
                radius={getThemeRadius()}
                style={getCardStyle()}
                className="h-full"
                onMouseEnter={(e) => {
                    e.currentTarget.style.border = `var(--borderWidth, 2px) solid color-mix(in srgb, var(--theme-warning) 50%, transparent)`;
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
                                style={{ background: `color-mix(in srgb, var(--theme-warning, #F59E0B) 10%, transparent)` }}
                            >
                                <CalendarIcon className="w-6 h-6" style={{ color: `var(--theme-warning, #F59E0B)` }} />
                            </div>
                            <div>
                                <h3 className="text-sm font-semibold" style={{ color: `var(--theme-foreground, #11181C)`, fontFamily: `var(--fontFamily, "Inter")` }}>
                                    Leave Balance
                                </h3>
                                <p className="text-xs" style={{ color: `var(--theme-foreground-400, #A1A1AA)`, fontFamily: `var(--fontFamily, "Inter")` }}>
                                    Remaining Days
                                </p>
                            </div>
                        </div>
                        <div className="text-right">
                            <span className="text-2xl font-bold" style={{ color: `var(--theme-warning, #F59E0B)`, fontFamily: `var(--fontFamily, "Inter")` }}>
                                {totalRemaining}
                            </span>
                            <p className="text-xs" style={{ color: `var(--theme-foreground-400, #A1A1AA)`, fontFamily: `var(--fontFamily, "Inter")` }}>
                                Total Days
                            </p>
                        </div>
                    </div>
                    
                    <div className="space-y-2">
                        {leaveTypes.length === 0 ? (
                            <p className="text-xs text-center" style={{ color: `var(--theme-foreground-400, #A1A1AA)`, fontFamily: `var(--fontFamily, "Inter")` }}>
                                No leave types available
                            </p>
                        ) : (
                            leaveTypes.map(({ type }) => {
                                const leaveCount = userLeaveCounts.find(c => c.leave_type === type) || {};
                                const remainingDays = leaveCount.remaining_days || 0;
                                return (
                                    <div key={type} className="flex items-center justify-between">
                                        <span className="text-xs" style={{ color: `var(--theme-foreground-400, #A1A1AA)`, fontFamily: `var(--fontFamily, "Inter")` }}>
                                            {type}
                                        </span>
                                        <span className="text-sm font-semibold" style={{ color: `var(--theme-foreground, #11181C)`, fontFamily: `var(--fontFamily, "Inter")` }}>
                                            {remainingDays} days
                                        </span>
                                    </div>
                                );
                            })
                        )}
                    </div>
                </CardBody>
            </Card>
        </motion.div>
    );
};

export default LeaveBalanceCard;
