import React, { useEffect, useState } from 'react';
import { motion } from 'framer-motion';
import { Card, CardHeader, CardBody, Button, Chip, Avatar } from '@heroui/react';
import { 
    CheckCircleIcon,
    XCircleIcon,
    CalendarDaysIcon,
    ExclamationTriangleIcon
} from '@heroicons/react/24/outline';
import ProfileAvatar from '@/Components/ProfileAvatar';
import axios from 'axios';
import { showToast } from '@/utils/toastUtils';

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

const PendingApprovalsCard = () => {
    const [approvals, setApprovals] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);

    useEffect(() => {
        let isMounted = true;
        const controller = new AbortController();
        
        const fetchApprovals = async () => {
            try {
                setLoading(true);
                setError(null);
                
                const response = await axios.get(route('admin.dashboard.pending-approvals'), {
                    signal: controller.signal,
                    timeout: 10000
                });
                
                if (isMounted && response.data?.approvals) {
                    setApprovals(response.data.approvals);
                }
            } catch (err) {
                if (isMounted && !controller.signal.aborted) {
                    console.error('Failed to fetch pending approvals:', err);
                    setError(err.message);
                    setApprovals([]);
                }
            } finally {
                if (isMounted) {
                    setLoading(false);
                }
            }
        };

        fetchApprovals();

        return () => {
            isMounted = false;
            controller.abort();
        };
    }, []);

    const handleApprove = async (approvalId) => {
        try {
            await axios.post(route('admin.dashboard.approve-leave', approvalId));
            
            showToast.success('Leave request approved successfully');
            setApprovals(prev => prev.filter(a => a.id !== approvalId));
        } catch (error) {
            console.error('Failed to approve leave:', error);
            showToast.error('Failed to approve leave request');
        }
    };

    const handleReject = async (approvalId) => {
        try {
            await axios.post(route('admin.dashboard.reject-leave', approvalId));
            
            showToast.success('Leave request rejected');
            setApprovals(prev => prev.filter(a => a.id !== approvalId));
        } catch (error) {
            console.error('Failed to reject leave:', error);
            showToast.error('Failed to reject leave request');
        }
    };

    const getUrgencyColor = (urgency) => {
        switch (urgency) {
            case 'high':
                return 'danger';
            case 'medium':
                return 'warning';
            case 'low':
                return 'success';
            default:
                return 'default';
        }
    };

    const formatDate = (dateString) => {
        const date = new Date(dateString);
        return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
    };

    if (loading) {
        return (
            <Card 
                className="h-full"
                radius={getThemeRadius()}
                style={getCardStyle()}
            >
                <CardHeader className="pb-2 p-4" style={{ background: 'transparent' }}>
                    <h2 className="text-lg font-bold text-foreground" style={{ fontFamily: `var(--fontFamily, "Inter")` }}>
                        Pending Approvals
                    </h2>
                </CardHeader>
                <CardBody className="pt-0 p-4">
                    {[1, 2, 3].map((_, idx) => (
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
                        Failed to load approvals: {error}
                    </p>
                </CardBody>
            </Card>
        );
    }

    if (approvals.length === 0) {
        return (
            <Card 
                className="h-full"
                radius={getThemeRadius()}
                style={getCardStyle()}
            >
                <CardHeader className="pb-2" style={{ background: 'transparent' }}>
                    <h2 className="text-lg font-bold text-foreground" style={{ fontFamily: `var(--fontFamily, "Inter")` }}>
                        Pending Approvals
                    </h2>
                </CardHeader>
                <CardBody className="pt-0">
                    <div className="flex items-center justify-center h-32">
                        <div className="text-center">
                            <CheckCircleIcon className="w-12 h-12 mx-auto mb-2" style={{ color: `var(--theme-success, #17C964)` }} />
                            <p className="text-sm" style={{ color: `var(--theme-foreground-400, #A1A1AA)` }}>
                                No pending approvals
                            </p>
                        </div>
                    </div>
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
                <div className="flex items-center justify-between w-full">
                    <h2 className="text-lg font-bold text-foreground" style={{ fontFamily: `var(--fontFamily, "Inter")` }}>
                        Pending Approvals
                    </h2>
                    <Chip size="sm" color="warning" variant="flat">
                        {approvals.length}
                    </Chip>
                </div>
            </CardHeader>
            <CardBody className="pt-0">
                <div className="space-y-3">
                    {approvals.map((approval, index) => (
                        <motion.div
                            key={approval.id}
                            initial={{ opacity: 0, x: -20 }}
                            animate={{ opacity: 1, x: 0 }}
                            transition={{ delay: index * 0.1, duration: 0.3 }}
                            className="p-3 rounded-lg border"
                            style={{
                                backgroundColor: `var(--theme-background, #FFFFFF)`,
                                borderColor: `var(--theme-divider, #E4E4E7)`
                            }}
                        >
                            <div className="flex items-start gap-3">
                                <ProfileAvatar
                                    src={approval.user.profile_image_url}
                                    name={approval.user.name}
                                    size="sm"
                                    className="flex-shrink-0"
                                    fallbackIcon={<ExclamationTriangleIcon className="w-4 h-4" />}
                                />
                                <div className="flex-1 min-w-0">
                                    <div className="flex items-center gap-2 mb-1">
                                        <p 
                                            className="text-sm font-medium text-foreground truncate"
                                            style={{ fontFamily: `var(--fontFamily, "Inter")` }}
                                        >
                                            {approval.user.name}
                                        </p>
                                        <Chip 
                                            size="sm" 
                                            color={getUrgencyColor(approval.urgency)}
                                            variant="flat"
                                        >
                                            {approval.urgency}
                                        </Chip>
                                    </div>
                                    <p 
                                        className="text-xs text-default-500 mb-1"
                                        style={{ fontFamily: `var(--fontFamily, "Inter")` }}
                                    >
                                        {approval.leaveType} • {approval.duration}
                                    </p>
                                    <div className="flex items-center gap-1 text-xs text-default-400">
                                        <CalendarDaysIcon className="w-3 h-3" />
                                        <span style={{ fontFamily: `var(--fontFamily, "Inter")` }}>
                                            {formatDate(approval.from_date)} - {formatDate(approval.to_date)}
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <div className="flex gap-2 mt-3">
                                <Button
                                    size="sm"
                                    color="success"
                                    variant="flat"
                                    startContent={<CheckCircleIcon className="w-4 h-4" />}
                                    onPress={() => handleApprove(approval.id)}
                                    className="flex-1"
                                >
                                    Approve
                                </Button>
                                <Button
                                    size="sm"
                                    color="danger"
                                    variant="flat"
                                    startContent={<XCircleIcon className="w-4 h-4" />}
                                    onPress={() => handleReject(approval.id)}
                                    className="flex-1"
                                >
                                    Reject
                                </Button>
                            </div>
                        </motion.div>
                    ))}
                </div>
            </CardBody>
        </Card>
    );
};

export default PendingApprovalsCard;
