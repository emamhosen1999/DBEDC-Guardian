import React, { useEffect, useState } from 'react';
import { motion } from 'framer-motion';
import { Card, CardHeader, CardBody, Progress, Chip } from '@heroui/react';
import { 
    ServerIcon,
    CircleStackIcon,
    ArchiveBoxIcon,
    ClockIcon,
    CheckCircleIcon,
    XCircleIcon
} from '@heroicons/react/24/outline';
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

const SystemHealthCard = () => {
    const [healthData, setHealthData] = useState({
        server: { status: 'healthy', responseTime: 45 },
        database: { status: 'connected', connections: 12 },
        storage: { used: 45, total: 100 },
        lastBackup: '2 hours ago'
    });
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);

    useEffect(() => {
        let isMounted = true;
        const controller = new AbortController();
        
        const fetchHealthData = async () => {
            try {
                setLoading(true);
                setError(null);
                
                const response = await axios.get(route('admin.dashboard.system-health'), {
                    signal: controller.signal,
                    timeout: 10000
                });
                
                if (isMounted && response.data) {
                    setHealthData({
                        server: response.data.server || { status: 'unknown', responseTime: 0 },
                        database: response.data.database || { status: 'unknown', connections: 0 },
                        storage: response.data.storage || { used: 0, total: 100 },
                        lastBackup: response.data.lastBackup || 'Unknown'
                    });
                }
            } catch (err) {
                if (isMounted && !controller.signal.aborted) {
                    console.error('Failed to fetch system health:', err);
                    setError(err.message);
                    // Set default values on error
                    setHealthData({
                        server: { status: 'unknown', responseTime: 0 },
                        database: { status: 'unknown', connections: 0 },
                        storage: { used: 0, total: 100 },
                        lastBackup: 'Unknown'
                    });
                }
            } finally {
                if (isMounted) {
                    setLoading(false);
                }
            }
        };

        fetchHealthData();

        return () => {
            isMounted = false;
            controller.abort();
        };
    }, []);

    const getStatusColor = (status) => {
        switch (status) {
            case 'healthy':
            case 'connected':
                return 'success';
            case 'error':
            case 'disconnected':
                return 'danger';
            case 'warning':
                return 'warning';
            default:
                return 'default';
        }
    };

    const getStatusIcon = (status) => {
        switch (status) {
            case 'healthy':
            case 'connected':
                return CheckCircleIcon;
            case 'error':
            case 'disconnected':
                return XCircleIcon;
            default:
                return ServerIcon;
        }
    };

    const getStorageColor = (percentage) => {
        if (percentage >= 90) return 'danger';
        if (percentage >= 70) return 'warning';
        return 'success';
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
                        System Health
                    </h2>
                </CardHeader>
                <CardBody className="pt-0">
                    {[1, 2, 3, 4].map((_, idx) => (
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
                        Failed to load system health: {error}
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
                e.currentTarget.style.border = `var(--borderWidth, 2px) solid color-mix(in srgb, var(--theme-success) 50%, transparent)`;
                e.currentTarget.style.borderRadius = `var(--borderRadius, 12px)`;
                e.currentTarget.style.transform = `scale(calc(var(--scale, 1) * 1.02))`;
            }}
            onMouseLeave={(e) => {
                e.currentTarget.style.border = `var(--borderWidth, 2px) solid transparent`;
                e.currentTarget.style.transform = `scale(var(--scale, 1))`;
            }}
        >
            <CardHeader className="pb-2" style={{ background: 'transparent' }}>
                <h2 className="text-lg font-bold text-foreground" style={{ fontFamily: `var(--fontFamily, "Inter")` }}>
                    System Health
                </h2>
            </CardHeader>
            <CardBody className="pt-0">
                <div className="space-y-4">
                    {/* Server Status */}
                    <motion.div
                        initial={{ opacity: 0, x: -20 }}
                        animate={{ opacity: 1, x: 0 }}
                        transition={{ delay: 0.1, duration: 0.3 }}
                        className="flex items-center justify-between p-3 rounded-lg"
                        style={{ backgroundColor: `var(--theme-background, #FFFFFF)`, borderColor: `var(--theme-divider, #E4E4E7)` }}
                    >
                        <div className="flex items-center gap-3">
                            <div
                                className="flex items-center justify-center min-w-[40px] min-h-[40px]"
                                style={{
                                    backgroundColor: `color-mix(in srgb, var(--theme-success) 15%, transparent)`,
                                    borderRadius: `var(--borderRadius, 12px)`,
                                    border: `var(--borderWidth, 1px) solid color-mix(in srgb, var(--theme-success) 25%, transparent)`
                                }}
                            >
                                <ServerIcon 
                                    className="w-5 h-5 stroke-2"
                                    style={{ color: 'var(--theme-success, #17C964)' }}
                                />
                            </div>
                            <div>
                                <p 
                                    className="text-sm font-medium text-foreground"
                                    style={{ fontFamily: `var(--fontFamily, "Inter")` }}
                                >
                                    Server Status
                                </p>
                                <p 
                                    className="text-xs text-default-500"
                                    style={{ fontFamily: `var(--fontFamily, "Inter")` }}
                                >
                                    Response: {healthData.server.responseTime}ms
                                </p>
                            </div>
                        </div>
                        <Chip 
                            size="sm" 
                            color={getStatusColor(healthData.server.status)}
                            variant="flat"
                            startContent={<CheckCircleIcon className="w-3 h-3" />}
                        >
                            {healthData.server.status}
                        </Chip>
                    </motion.div>

                    {/* Database Status */}
                    <motion.div
                        initial={{ opacity: 0, x: -20 }}
                        animate={{ opacity: 1, x: 0 }}
                        transition={{ delay: 0.2, duration: 0.3 }}
                        className="flex items-center justify-between p-3 rounded-lg"
                        style={{ backgroundColor: `var(--theme-background, #FFFFFF)`, borderColor: `var(--theme-divider, #E4E4E7)` }}
                    >
                        <div className="flex items-center gap-3">
                            <div
                                className="flex items-center justify-center min-w-[40px] min-h-[40px]"
                                style={{
                                    backgroundColor: `color-mix(in srgb, var(--theme-success) 15%, transparent)`,
                                    borderRadius: `var(--borderRadius, 12px)`,
                                    border: `var(--borderWidth, 1px) solid color-mix(in srgb, var(--theme-success) 25%, transparent)`
                                }}
                            >
                                <CircleStackIcon 
                                    className="w-5 h-5 stroke-2"
                                    style={{ color: 'var(--theme-success, #17C964)' }}
                                />
                            </div>
                            <div>
                                <p 
                                    className="text-sm font-medium text-foreground"
                                    style={{ fontFamily: `var(--fontFamily, "Inter")` }}
                                >
                                    Database
                                </p>
                                <p 
                                    className="text-xs text-default-500"
                                    style={{ fontFamily: `var(--fontFamily, "Inter")` }}
                                >
                                    {healthData.database.connections} connections
                                </p>
                            </div>
                        </div>
                        <Chip 
                            size="sm" 
                            color={getStatusColor(healthData.database.status)}
                            variant="flat"
                            startContent={<CheckCircleIcon className="w-3 h-3" />}
                        >
                            {healthData.database.status}
                        </Chip>
                    </motion.div>

                    {/* Storage Usage */}
                    <motion.div
                        initial={{ opacity: 0, x: -20 }}
                        animate={{ opacity: 1, x: 0 }}
                        transition={{ delay: 0.3, duration: 0.3 }}
                        className="p-3 rounded-lg"
                        style={{ backgroundColor: `var(--theme-background, #FFFFFF)`, borderColor: `var(--theme-divider, #E4E4E7)` }}
                    >
                        <div className="flex items-center justify-between mb-2">
                            <div className="flex items-center gap-3">
                                <div
                                    className="flex items-center justify-center min-w-[40px] min-h-[40px]"
                                    style={{
                                        backgroundColor: `color-mix(in srgb, var(--theme-primary) 15%, transparent)`,
                                        borderRadius: `var(--borderRadius, 12px)`,
                                        border: `var(--borderWidth, 1px) solid color-mix(in srgb, var(--theme-primary) 25%, transparent)`
                                    }}
                                >
                                    <ArchiveBoxIcon 
                                        className="w-5 h-5 stroke-2"
                                        style={{ color: 'var(--theme-primary, #006FEE)' }}
                                    />
                                </div>
                                <div>
                                    <p 
                                        className="text-sm font-medium text-foreground"
                                        style={{ fontFamily: `var(--fontFamily, "Inter")` }}
                                    >
                                        Storage
                                    </p>
                                    <p 
                                        className="text-xs text-default-500"
                                        style={{ fontFamily: `var(--fontFamily, "Inter")` }}
                                    >
                                        {healthData.storage.used}% used
                                    </p>
                                </div>
                            </div>
                        </div>
                        <Progress 
                            value={healthData.storage.used} 
                            color={getStorageColor(healthData.storage.used)}
                            size="sm"
                            className="w-full"
                        />
                    </motion.div>

                    {/* Last Backup */}
                    <motion.div
                        initial={{ opacity: 0, x: -20 }}
                        animate={{ opacity: 1, x: 0 }}
                        transition={{ delay: 0.4, duration: 0.3 }}
                        className="flex items-center justify-between p-3 rounded-lg"
                        style={{ backgroundColor: `var(--theme-background, #FFFFFF)`, borderColor: `var(--theme-divider, #E4E4E7)` }}
                    >
                        <div className="flex items-center gap-3">
                            <div
                                className="flex items-center justify-center min-w-[40px] min-h-[40px]"
                                style={{
                                    backgroundColor: `color-mix(in srgb, var(--theme-secondary) 15%, transparent)`,
                                    borderRadius: `var(--borderRadius, 12px)`,
                                    border: `var(--borderWidth, 1px) solid color-mix(in srgb, var(--theme-secondary) 25%, transparent)`
                                }}
                            >
                                <ClockIcon 
                                    className="w-5 h-5 stroke-2"
                                    style={{ color: 'var(--theme-secondary, #7C3AED)' }}
                                />
                            </div>
                            <div>
                                <p 
                                    className="text-sm font-medium text-foreground"
                                    style={{ fontFamily: `var(--fontFamily, "Inter")` }}
                                >
                                    Last Backup
                                </p>
                                <p 
                                    className="text-xs text-default-500"
                                    style={{ fontFamily: `var(--fontFamily, "Inter")` }}
                                >
                                    {healthData.lastBackup}
                                </p>
                            </div>
                        </div>
                    </motion.div>
                </div>
            </CardBody>
        </Card>
    );
};

export default SystemHealthCard;
