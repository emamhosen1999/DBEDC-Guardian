import React from 'react';
import { motion } from 'framer-motion';
import { Card, CardBody } from '@heroui/react';
import { 
    ClipboardDocumentListIcon,
    CheckBadgeIcon,
    ClockIcon
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

const DailyWorksStatsCard = ({ stats = {} }) => {
    const { total = 0, completed = 0, pending = 0, rfi_submissions = 0 } = stats;
    
    const completionRate = total > 0 ? Math.round((completed / total) * 100) : 0;
    
    return (
        <motion.div
            initial={{ opacity: 0, scale: 0.9 }}
            animate={{ opacity: 1, scale: 1 }}
            transition={{ duration: 0.3, delay: 0.2 }}
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
                <CardBody className="p-4">
                    <div className="flex items-start justify-between mb-3">
                        <div className="flex items-center gap-3">
                            <div 
                                className="p-2 rounded-lg"
                                style={{ background: `color-mix(in srgb, var(--theme-primary, #006FEE) 10%, transparent)` }}
                            >
                                <ClipboardDocumentListIcon className="w-6 h-6" style={{ color: `var(--theme-primary, #006FEE)` }} />
                            </div>
                            <div>
                                <h3 className="text-sm font-semibold" style={{ color: `var(--theme-foreground, #11181C)`, fontFamily: `var(--fontFamily, "Inter")` }}>
                                    Daily Works
                                </h3>
                                <p className="text-xs" style={{ color: `var(--theme-foreground-400, #A1A1AA)`, fontFamily: `var(--fontFamily, "Inter")` }}>
                                    Task Overview
                                </p>
                            </div>
                        </div>
                        <div className="text-right">
                            <span className="text-2xl font-bold" style={{ color: `var(--theme-primary, #006FEE)`, fontFamily: `var(--fontFamily, "Inter")` }}>
                                {total}
                            </span>
                            <p className="text-xs" style={{ color: `var(--theme-foreground-400, #A1A1AA)`, fontFamily: `var(--fontFamily, "Inter")` }}>
                                Total Tasks
                            </p>
                        </div>
                    </div>
                    
                    <div className="space-y-2">
                        <div className="flex items-center justify-between">
                            <div className="flex items-center gap-2">
                                <CheckBadgeIcon className="w-4 h-4" style={{ color: `var(--theme-success, #22C55E)` }} />
                                <span className="text-xs" style={{ color: `var(--theme-foreground-400, #A1A1AA)`, fontFamily: `var(--fontFamily, "Inter")` }}>
                                    Completed
                                </span>
                            </div>
                            <span className="text-sm font-semibold" style={{ color: `var(--theme-foreground, #11181C)`, fontFamily: `var(--fontFamily, "Inter")` }}>
                                {completed}
                            </span>
                        </div>
                        <div className="flex items-center justify-between">
                            <div className="flex items-center gap-2">
                                <ClockIcon className="w-4 h-4" style={{ color: `var(--theme-warning, #F59E0B)` }} />
                                <span className="text-xs" style={{ color: `var(--theme-foreground-400, #A1A1AA)`, fontFamily: `var(--fontFamily, "Inter")` }}>
                                    Pending
                                </span>
                            </div>
                            <span className="text-sm font-semibold" style={{ color: `var(--theme-foreground, #11181C)`, fontFamily: `var(--fontFamily, "Inter")` }}>
                                {pending}
                            </span>
                        </div>
                        <div className="flex items-center justify-between">
                            <span className="text-xs" style={{ color: `var(--theme-foreground-400, #A1A1AA)`, fontFamily: `var(--fontFamily, "Inter")` }}>
                                Completion Rate
                            </span>
                            <span className="text-sm font-semibold" style={{ color: `var(--theme-success, #22C55E)`, fontFamily: `var(--fontFamily, "Inter")` }}>
                                {completionRate}%
                            </span>
                        </div>
                        {rfi_submissions > 0 && (
                            <div className="flex items-center justify-between pt-2" style={{ borderTop: `1px solid var(--theme-divider, #E4E4E7)` }}>
                                <span className="text-xs" style={{ color: `var(--theme-foreground-400, #A1A1AA)`, fontFamily: `var(--fontFamily, "Inter")` }}>
                                    RFI Submissions
                                </span>
                                <span className="text-sm font-semibold" style={{ color: `var(--theme-secondary, #9353d3)`, fontFamily: `var(--fontFamily, "Inter")` }}>
                                    {rfi_submissions}
                                </span>
                            </div>
                        )}
                    </div>
                </CardBody>
            </Card>
        </motion.div>
    );
};

export default DailyWorksStatsCard;
