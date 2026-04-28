import React from 'react';
import { motion } from 'framer-motion';
import { Card, CardHeader, CardBody, Chip } from '@heroui/react';
import { 
    CalendarDaysIcon,
    CheckCircleIcon,
    ClockIcon,
    XCircleIcon
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

const UpcomingLeavesCard = ({ leaves = [] }) => {
    const getStatusIcon = (status) => {
        switch (status?.toLowerCase()) {
            case 'approved':
                return <CheckCircleIcon className="w-4 h-4" style={{ color: `var(--theme-success, #22C55E)` }} />;
            case 'pending':
                return <ClockIcon className="w-4 h-4" style={{ color: `var(--theme-warning, #F59E0B)` }} />;
            case 'rejected':
                return <XCircleIcon className="w-4 h-4" style={{ color: `var(--theme-danger, #EF4444)` }} />;
            default:
                return <ClockIcon className="w-4 h-4" style={{ color: `var(--theme-foreground-400, #A1A1AA)` }} />;
        }
    };

    const getStatusColor = (status) => {
        switch (status?.toLowerCase()) {
            case 'approved':
                return 'success';
            case 'pending':
                return 'warning';
            case 'rejected':
                return 'danger';
            default:
                return 'default';
        }
    };

    const formatDate = (dateString) => {
        if (!dateString) return 'N/A';
        const date = new Date(dateString);
        return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    };

    const calculateDuration = (fromDate, toDate) => {
        if (!fromDate || !toDate) return 'N/A';
        const from = new Date(fromDate);
        const to = new Date(toDate);
        const diffTime = Math.abs(to - from);
        const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1;
        return `${diffDays} day${diffDays > 1 ? 's' : ''}`;
    };

    return (
        <motion.div
            initial={{ opacity: 0, y: 20 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.5, delay: 0.35 }}
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
                <CardHeader className="pb-2" style={{ background: 'transparent' }}>
                    <div className="flex items-center gap-3">
                        <div 
                            className="p-2 rounded-lg"
                            style={{ background: `color-mix(in srgb, var(--theme-warning, #F59E0B) 10%, transparent)` }}
                        >
                            <CalendarDaysIcon className="w-5 h-5" style={{ color: `var(--theme-warning, #F59E0B)` }} />
                        </div>
                        <div>
                            <h3 className="text-lg font-semibold" style={{ color: `var(--theme-foreground, #11181C)`, fontFamily: `var(--fontFamily, "Inter")` }}>
                                Upcoming Leaves
                            </h3>
                            <p className="text-xs" style={{ color: `var(--theme-foreground-400, #A1A1AA)`, fontFamily: `var(--fontFamily, "Inter")` }}>
                                Your leave requests
                            </p>
                        </div>
                    </div>
                </CardHeader>
                <CardBody className="pt-0">
                    <div className="space-y-3">
                        {leaves.length === 0 ? (
                            <div className="text-center py-8">
                                <CalendarDaysIcon className="w-12 h-12 mx-auto mb-2" style={{ color: `var(--theme-foreground-400, #A1A1AA)` }} />
                                <p className="text-sm" style={{ color: `var(--theme-foreground-400, #A1A1AA)`, fontFamily: `var(--fontFamily, "Inter")` }}>
                                    No upcoming leaves
                                </p>
                            </div>
                        ) : (
                            leaves.slice(0, 4).map((leave, index) => (
                                <motion.div
                                    key={leave.id || index}
                                    initial={{ opacity: 0, x: -20 }}
                                    animate={{ opacity: 1, x: 0 }}
                                    transition={{ duration: 0.3, delay: 0.4 + (index * 0.1) }}
                                    className="p-3 rounded-lg"
                                    style={{ background: `var(--theme-content1, #FAFAFA)` }}
                                >
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="flex-1 min-w-0">
                                            <p className="text-sm font-medium mb-1" style={{ color: `var(--theme-foreground, #11181C)`, fontFamily: `var(--fontFamily, "Inter")` }}>
                                                {leave.leave_type || 'Leave'}
                                            </p>
                                            <div className="flex items-center gap-2 text-xs" style={{ color: `var(--theme-foreground-400, #A1A1AA)`, fontFamily: `var(--fontFamily, "Inter")` }}>
                                                <span>{formatDate(leave.from_date)}</span>
                                                <span>→</span>
                                                <span>{formatDate(leave.to_date)}</span>
                                                <span>•</span>
                                                <span>{calculateDuration(leave.from_date, leave.to_date)}</span>
                                            </div>
                                        </div>
                                        <div className="flex-shrink-0">
                                            <Chip
                                                size="sm"
                                                variant="flat"
                                                color={getStatusColor(leave.status)}
                                                startContent={getStatusIcon(leave.status)}
                                                className="text-xs"
                                                style={{ fontFamily: `var(--fontFamily, "Inter")` }}
                                            >
                                                {leave.status || 'Unknown'}
                                            </Chip>
                                        </div>
                                    </div>
                                </motion.div>
                            ))
                        )}
                    </div>
                </CardBody>
            </Card>
        </motion.div>
    );
};

export default UpcomingLeavesCard;
