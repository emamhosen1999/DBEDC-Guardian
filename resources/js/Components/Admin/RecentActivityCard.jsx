import React, { useEffect, useState } from 'react';
import { motion } from 'framer-motion';
import { Card, CardHeader, CardBody, Avatar, Chip, Divider } from '@heroui/react';
import { 
    UserIcon,
    UserPlusIcon,
    CalendarDaysIcon,
    DocumentTextIcon,
    ShieldCheckIcon,
    ClockIcon
} from '@heroicons/react/24/outline';
import ProfileAvatar from '@/Components/ProfileAvatar';
import axios from 'axios';

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

const RecentActivityCard = () => {
    const [activities, setActivities] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);

    useEffect(() => {
        let isMounted = true;
        const controller = new AbortController();
        
        const fetchActivities = async () => {
            try {
                setLoading(true);
                setError(null);
                
                const response = await axios.get(route('admin.dashboard.recent-activity'), {
                    signal: controller.signal,
                    timeout: 10000
                });
                
                if (isMounted && response.data?.activities) {
                    setActivities(response.data.activities);
                }
            } catch (err) {
                if (isMounted && !controller.signal.aborted) {
                    console.error('Failed to fetch recent activity:', err);
                    setError(err.message);
                    setActivities([]);
                }
            } finally {
                if (isMounted) {
                    setLoading(false);
                }
            }
        };

        fetchActivities();

        return () => {
            isMounted = false;
            controller.abort();
        };
    }, []);

    const getActivityIcon = (type) => {
        switch (type) {
            case 'user_joined':
                return UserPlusIcon;
            case 'leave_requested':
                return CalendarDaysIcon;
            case 'attendance_anomaly':
                return ClockIcon;
            case 'role_updated':
                return ShieldCheckIcon;
            case 'document_uploaded':
                return DocumentTextIcon;
            default:
                return UserIcon;
        }
    };

    const getActivityColor = (type) => {
        switch (type) {
            case 'user_joined':
                return 'var(--theme-success, #17C964)';
            case 'leave_requested':
                return 'var(--theme-warning, #F5A524)';
            case 'attendance_anomaly':
                return 'var(--theme-danger, #F31260)';
            case 'role_updated':
                return 'var(--theme-primary, #006FEE)';
            case 'document_uploaded':
                return 'var(--theme-secondary, #7C3AED)';
            default:
                return 'var(--theme-foreground, #11181C)';
        }
    };

    const formatTimestamp = (timestamp) => {
        const date = new Date(timestamp);
        const now = new Date();
        const diffMs = now - date;
        const diffMins = Math.floor(diffMs / 60000);
        const diffHours = Math.floor(diffMs / (60 * 60000));
        const diffDays = Math.floor(diffMs / (24 * 60 * 60000));

        if (diffMins < 60) {
            return `${diffMins} minute${diffMins !== 1 ? 's' : ''} ago`;
        } else if (diffHours < 24) {
            return `${diffHours} hour${diffHours !== 1 ? 's' : ''} ago`;
        } else {
            return `${diffDays} day${diffDays !== 1 ? 's' : ''} ago`;
        }
    };

    if (loading) {
        return (
            <Card 
                className="h-full"
                radius={getThemeRadius()}
                style={getCardStyle()}
            >
                <CardHeader className="pb-2" style={{ background: 'transparent' }}>
                    <h2 className="text-lg font-bold text-foreground" style={{ fontFamily: `var(--fontFamily, "Inter")` }}>
                        Recent Activity
                    </h2>
                </CardHeader>
                <CardBody className="pt-0">
                    {[1, 2, 3, 4, 5].map((_, idx) => (
                        <div key={idx} className="flex items-center gap-3 py-2">
                            <div className="w-10 h-10 rounded-full bg-default-200 animate-pulse" />
                            <div className="flex-1">
                                <div className="h-4 w-3/4 bg-default-200 rounded animate-pulse mb-1" />
                                <div className="h-3 w-1/2 bg-default-200 rounded animate-pulse" />
                            </div>
                        </div>
                    ))}
                </CardBody>
            </Card>
        );
    }

    if (error) {
        return (
            <Card 
                className="h-full"
                radius={getThemeRadius()}
                style={{
                    ...getCardStyle(),
                    borderColor: `color-mix(in srgb, var(--theme-danger) 50%, transparent)`,
                    background: `linear-gradient(135deg, 
                        color-mix(in srgb, var(--theme-danger) 5%, var(--theme-content1)) 20%, 
                        color-mix(in srgb, var(--theme-danger) 3%, var(--theme-content2)) 10%, 
                        color-mix(in srgb, var(--theme-danger) 2%, var(--theme-content3)) 20%)`,
                }}
            >
                <CardBody>
                    <p style={{ color: 'var(--theme-danger)' }}>
                        Failed to load activity: {error}
                    </p>
                </CardBody>
            </Card>
        );
    }

    if (activities.length === 0) {
        return (
            <Card 
                className="h-full"
                radius={getThemeRadius()}
                style={getCardStyle()}
            >
                <CardHeader className="pb-2" style={{ background: 'transparent' }}>
                    <h2 className="text-lg font-bold text-foreground" style={{ fontFamily: `var(--fontFamily, "Inter")` }}>
                        Recent Activity
                    </h2>
                </CardHeader>
                <CardBody className="pt-0">
                    <p className="text-sm text-default-500" style={{ fontFamily: `var(--fontFamily, "Inter")` }}>
                        No recent activity
                    </p>
                </CardBody>
            </Card>
        );
    }

    return (
        <Card 
            className="h-full"
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
            <CardHeader className="pb-2 p-4" style={{ background: 'transparent' }}>
                <h2 className="text-lg font-bold text-foreground" style={{ fontFamily: `var(--fontFamily, "Inter")` }}>
                    Recent Activity
                </h2>
            </CardHeader>
            <CardBody className="pt-0 p-4">
                <div className="space-y-1">
                    {activities.map((activity, index) => {
                        const IconComponent = getActivityIcon(activity.type);
                        const color = getActivityColor(activity.type);
                        
                        return (
                            <React.Fragment key={activity.id}>
                                <motion.div
                                    initial={{ opacity: 0, x: -20 }}
                                    animate={{ opacity: 1, x: 0 }}
                                    transition={{ delay: index * 0.1, duration: 0.3 }}
                                    className="flex items-start gap-3 py-2"
                                >
                                    <div
                                        className="flex items-center justify-center min-w-[40px] min-h-[40px] flex-shrink-0"
                                        style={{
                                            backgroundColor: `color-mix(in srgb, ${color} 15%, transparent)`,
                                            borderRadius: `var(--borderRadius, 12px)`,
                                            border: `var(--borderWidth, 1px) solid color-mix(in srgb, ${color} 25%, transparent)`
                                        }}
                                    >
                                        <IconComponent 
                                            className="w-5 h-5 stroke-2"
                                            style={{ color: color }}
                                            aria-hidden="true"
                                        />
                                    </div>
                                    <div className="flex-1 min-w-0">
                                        <div className="flex items-center gap-2 mb-1">
                                            <ProfileAvatar
                                                src={activity.user.profile_image_url}
                                                name={activity.user.name}
                                                size="sm"
                                                className="flex-shrink-0"
                                                fallbackIcon={<UserIcon className="w-4 h-4" />}
                                            />
                                            <p 
                                                className="text-sm font-medium text-foreground truncate"
                                                style={{ fontFamily: `var(--fontFamily, "Inter")` }}
                                            >
                                                {activity.user.name}
                                            </p>
                                        </div>
                                        <p 
                                            className="text-xs text-default-500"
                                            style={{ fontFamily: `var(--fontFamily, "Inter")` }}
                                        >
                                            {activity.description}
                                        </p>
                                    </div>
                                    <span 
                                        className="text-xs text-default-400 flex-shrink-0"
                                        style={{ fontFamily: `var(--fontFamily, "Inter")` }}
                                    >
                                        {formatTimestamp(activity.timestamp)}
                                    </span>
                                </motion.div>
                                {index < activities.length - 1 && (
                                    <Divider style={{ borderColor: `var(--theme-divider, #E4E4E7)` }} />
                                )}
                            </React.Fragment>
                        );
                    })}
                </div>
            </CardBody>
        </Card>
    );
};

export default RecentActivityCard;
