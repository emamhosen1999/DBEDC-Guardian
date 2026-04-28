import React from 'react';
import { motion } from 'framer-motion';
import { Card, CardBody } from '@heroui/react';
import { 
    SparklesIcon,
    CalendarDaysIcon
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

const UpcomingHolidayCard = ({ upcomingHoliday = null }) => {
    const { name = 'No upcoming holidays', date = null, type = 'holiday' } = upcomingHoliday || {};
    
    const formatDate = (dateString) => {
        if (!dateString) return 'TBD';
        const date = new Date(dateString);
        return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    };
    
    const getDaysUntil = (dateString) => {
        if (!dateString) return null;
        const holidayDate = new Date(dateString);
        const today = new Date();
        const diffTime = holidayDate - today;
        const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
        return diffDays;
    };
    
    const daysUntil = getDaysUntil(date);
    
    return (
        <motion.div
            initial={{ opacity: 0, scale: 0.9 }}
            animate={{ opacity: 1, scale: 1 }}
            transition={{ duration: 0.3, delay: 0.4 }}
        >
            <Card 
                radius={getThemeRadius()}
                style={getCardStyle()}
                className="h-full"
                onMouseEnter={(e) => {
                    e.currentTarget.style.border = `var(--borderWidth, 2px) solid color-mix(in srgb, var(--theme-secondary) 50%, transparent)`;
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
                                style={{ background: `color-mix(in srgb, var(--theme-secondary, #9353d3) 10%, transparent)` }}
                            >
                                <SparklesIcon className="w-6 h-6" style={{ color: `var(--theme-secondary, #9353d3)` }} />
                            </div>
                            <div>
                                <h3 className="text-sm font-semibold" style={{ color: `var(--theme-foreground, #11181C)`, fontFamily: `var(--fontFamily, "Inter")` }}>
                                    Upcoming Holiday
                                </h3>
                                <p className="text-xs" style={{ color: `var(--theme-foreground-400, #A1A1AA)`, fontFamily: `var(--fontFamily, "Inter")` }}>
                                    Next holiday
                                </p>
                            </div>
                        </div>
                    </div>
                    
                    <div className="space-y-2">
                        <div>
                            <p className="text-sm font-semibold mb-1" style={{ color: `var(--theme-foreground, #11181C)`, fontFamily: `var(--fontFamily, "Inter")` }}>
                                {name}
                            </p>
                        </div>
                        <div className="flex items-center gap-2">
                            <CalendarDaysIcon className="w-4 h-4" style={{ color: `var(--theme-foreground-400, #A1A1AA)` }} />
                            <span className="text-sm" style={{ color: `var(--theme-foreground-400, #A1A1AA)`, fontFamily: `var(--fontFamily, "Inter")` }}>
                                {formatDate(date)}
                            </span>
                        </div>
                        {daysUntil !== null && (
                            <div className="flex items-center justify-between pt-2" style={{ borderTop: `1px solid var(--theme-divider, #E4E4E7)` }}>
                                <span className="text-xs" style={{ color: `var(--theme-foreground-400, #A1A1AA)`, fontFamily: `var(--fontFamily, "Inter")` }}>
                                    {daysUntil === 0 ? 'Today' : daysUntil === 1 ? 'Tomorrow' : `In ${daysUntil} days`}
                                </span>
                            </div>
                        )}
                    </div>
                </CardBody>
            </Card>
        </motion.div>
    );
};

export default UpcomingHolidayCard;
