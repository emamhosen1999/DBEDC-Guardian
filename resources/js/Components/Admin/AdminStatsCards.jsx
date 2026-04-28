import React, { useEffect, useState } from 'react';
import { motion } from 'framer-motion';
import { 
    UsersIcon,
    UserGroupIcon,
    ClockIcon,
    ShieldCheckIcon
} from '@heroicons/react/24/outline';
import axios from 'axios';
import { Skeleton, Card } from '@heroui/react';

const AdminStatCard = ({ title, value, icon: IconComponent, color, isLoaded, testId }) => (
    <motion.div
        initial={{ opacity: 0, scale: 0.9 }}
        animate={{ opacity: 1, scale: 1 }}
        transition={{ duration: 0.3 }}
        className="h-full w-full"
    >
        <Card 
            className="h-full w-full transition-all duration-200 cursor-pointer shadow-lg"
            style={{
                border: `var(--borderWidth, 2px) solid transparent`,
                borderRadius: `var(--borderRadius, 12px)`,
                fontFamily: `var(--fontFamily, "Inter")`,
                transform: `scale(var(--scale, 1))`,
                background: `linear-gradient(135deg, 
                    var(--theme-content1, #FAFAFA) 20%, 
                    var(--theme-content2, #F4F4F5) 10%, 
                    var(--theme-content3, #F1F3F4) 20%)`,
            }}
            onMouseEnter={(e) => {
                e.currentTarget.style.border = `var(--borderWidth, 2px) solid color-mix(in srgb, ${color} 50%, transparent)`;
                e.currentTarget.style.borderRadius = `var(--borderRadius, 12px)`;
                e.currentTarget.style.transform = `scale(calc(var(--scale, 1) * 1.02))`;
            }}
            onMouseLeave={(e) => {
                e.currentTarget.style.border = `var(--borderWidth, 2px) solid transparent`;
                e.currentTarget.style.transform = `scale(var(--scale, 1))`;
            }}
        >
            <div className="p-4 h-full w-full flex flex-col">
                <Skeleton 
                    className="rounded-lg" 
                    isLoaded={isLoaded}
                    data-testid={testId}
                    style={{
                        borderRadius: `var(--borderRadius, 8px)`
                    }}
                >
                    <div 
                        className="flex flex-col gap-2 h-full"
                        role="region"
                        aria-label={`${title} statistics`}
                        style={{
                            fontFamily: `var(--fontFamily, "Inter")`,
                            transform: `scale(var(--scale, 1))`
                        }}
                    >
                        {/* Header */}
                        <div className="flex justify-between items-start">
                            <h3 
                                className="text-xs font-medium text-default-500 leading-tight flex-1 mr-1"
                                style={{ 
                                    fontFamily: `var(--fontFamily, "Inter")`
                                }}
                            >
                                {title}
                            </h3>
                            <div
                                className="flex items-center justify-center min-w-[40px] sm:min-w-[48px] min-h-[40px] sm:min-h-[48px] flex-shrink-0"
                                style={{ 
                                    backgroundColor: `color-mix(in srgb, ${color} 15%, transparent)`,
                                    borderRadius: `var(--borderRadius, 12px)`,
                                    border: `var(--borderWidth, 1px) solid color-mix(in srgb, ${color} 25%, transparent)`
                                }}
                            >
                                <IconComponent 
                                    className="w-6 h-6 stroke-2"
                                    style={{ color: color }}
                                    aria-hidden="true"
                                />
                            </div>
                        </div>

                        {/* Value */}
                        <div className="mt-auto">
                            <div 
                                className="text-2xl font-bold text-foreground leading-none"
                                aria-live="polite"
                                style={{ 
                                    fontFamily: `var(--fontFamily, "Inter")`
                                }}
                            >
                                {typeof value === 'number' ? value.toLocaleString() : value}
                            </div>
                        </div>
                    </div>
                </Skeleton>
            </div>
        </Card>
    </motion.div>
);

const AdminStatsCards = () => {
    const [stats, setStats] = useState({
        totalUsers: 0,
        activeMembers: 0,
        pendingRequests: 0,
        systemStatus: 'online'
    });
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);

    useEffect(() => {
        let isMounted = true;
        const controller = new AbortController();
        
        const fetchStats = async () => {
            try {
                setLoading(true);
                setError(null);
                
                // For now, use placeholder data until API is implemented
                const response = await axios.get(route('admin.dashboard.stats'), {
                    signal: controller.signal,
                    timeout: 10000
                });
                
                if (isMounted && response.data) {
                    setStats({
                        totalUsers: response.data.totalUsers || 0,
                        activeMembers: response.data.activeMembers || 0,
                        pendingRequests: response.data.pendingRequests || 0,
                        systemStatus: response.data.systemStatus || 'online'
                    });
                }
            } catch (err) {
                if (isMounted && !controller.signal.aborted) {
                    console.error('Failed to fetch admin stats:', err);
                    setError(err.message);
                    // Set default values on error
                    setStats({
                        totalUsers: 0,
                        activeMembers: 0,
                        pendingRequests: 0,
                        systemStatus: 'offline'
                    });
                }
            } finally {
                if (isMounted) {
                    setLoading(false);
                }
            }
        };

        fetchStats();

        return () => {
            isMounted = false;
            controller.abort();
        };
    }, []);

    const statsConfig = [
        {
            id: 'total-users',
            title: 'Total Users',
            value: stats.totalUsers,
            icon: UsersIcon,
            color: 'var(--theme-primary, #006FEE)',
            testId: 'stat-total-users'
        },
        {
            id: 'active-members',
            title: 'Active Members',
            value: stats.activeMembers,
            icon: UserGroupIcon,
            color: 'var(--theme-success, #17C964)',
            testId: 'stat-active-members'
        },
        {
            id: 'pending-requests',
            title: 'Pending Requests',
            value: stats.pendingRequests,
            icon: ClockIcon,
            color: 'var(--theme-warning, #F5A524)',
            testId: 'stat-pending-requests'
        },
        {
            id: 'system-status',
            title: 'System Status',
            value: stats.systemStatus === 'online' ? 'Online' : 'Offline',
            icon: ShieldCheckIcon,
            color: stats.systemStatus === 'online' ? 'var(--theme-success, #17C964)' : 'var(--theme-danger, #F31260)',
            testId: 'stat-system-status'
        }
    ];

    if (error) {
        return (
            <div className="flex flex-col w-full h-full">
                <div className="flex-grow h-full flex items-center justify-center">
                    <Card 
                        className="transition-all duration-200"
                        style={{
                            background: `color-mix(in srgb, var(--theme-danger, #F31260) 10%, transparent)`,
                            borderColor: `color-mix(in srgb, var(--theme-danger, #F31260) 50%, transparent)`,
                            borderRadius: `var(--borderRadius, 12px)`,
                            fontFamily: `var(--fontFamily, "Inter")`,
                        }}
                    >
                        <div className="p-4 text-center">
                            <p style={{ color: 'var(--theme-danger, #F31260)' }}>
                                Failed to load statistics
                            </p>
                        </div>
                    </Card>
                </div>
            </div>
        );
    }

    return (
        <div className="flex flex-col w-full h-full">
            <section 
                className="h-full w-full"
                aria-label="Admin Dashboard Statistics"
            >
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 h-full max-w-full">
                    {statsConfig.map((stat, index) => (
                        <div key={stat.id} className="w-full">
                            <AdminStatCard
                                title={stat.title}
                                value={stat.value}
                                icon={stat.icon}
                                color={stat.color}
                                isLoaded={!loading}
                                testId={stat.testId}
                            />
                        </div>
                    ))}
                </div>
            </section>
        </div>
    );
};

export default AdminStatsCards;
