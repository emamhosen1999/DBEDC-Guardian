import React, { useEffect, useState } from 'react';
import { motion } from 'framer-motion';
import { Card, CardHeader, CardBody, Chip, Button, Avatar } from '@heroui/react';
import { 
    UserIcon,
    BuildingOfficeIcon,
    CalendarIcon,
    EyeIcon
} from '@heroicons/react/24/outline';
import ProfileAvatar from '@/Components/ProfileAvatar';
import axios from 'axios';
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

const RecentMembersTable = () => {
    const [members, setMembers] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);

    useEffect(() => {
        let isMounted = true;
        const controller = new AbortController();
        
        const fetchMembers = async () => {
            try {
                setLoading(true);
                setError(null);
                
                const response = await axios.get(route('admin.dashboard.recent-members'), {
                    signal: controller.signal,
                    timeout: 10000
                });
                
                if (isMounted && response.data?.members) {
                    setMembers(response.data.members);
                }
            } catch (err) {
                if (isMounted && !controller.signal.aborted) {
                    console.error('Failed to fetch recent members:', err);
                    setError(err.message);
                    setMembers([]);
                }
            } finally {
                if (isMounted) {
                    setLoading(false);
                }
            }
        };

        fetchMembers();

        return () => {
            isMounted = false;
            controller.abort();
        };
    }, []);

    const getStatusColor = (status) => {
        switch (status) {
            case 'Active':
                return 'success';
            case 'Inactive':
                return 'default';
            case 'On Leave':
                return 'warning';
            default:
                return 'default';
        }
    };

    const formatDate = (dateString) => {
        const date = new Date(dateString);
        return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    };

    const handleViewProfile = (memberId) => {
        router.visit(route('employees.show', memberId));
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
                        Recent Members
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
                        Failed to load recent members: {error}
                    </p>
                </CardBody>
            </Card>
        );
    }

    if (members.length === 0) {
        return (
            <Card 
                className="h-full"
                radius={getThemeRadius()}
                style={getCardStyle()}
            >
                <CardHeader className="pb-2" style={{ background: 'transparent' }}>
                    <h2 className="text-lg font-bold text-foreground" style={{ fontFamily: `var(--fontFamily, "Inter")` }}>
                        Recent Members
                    </h2>
                </CardHeader>
                <CardBody className="pt-0">
                    <div className="flex items-center justify-center h-32">
                        <div className="text-center">
                            <UserIcon className="w-12 h-12 mx-auto mb-2" style={{ color: `var(--theme-foreground-400, #A1A1AA)` }} />
                            <p className="text-sm" style={{ color: `var(--theme-foreground-400, #A1A1AA)` }}>
                                No recent members
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
                <div className="flex items-center justify-between w-full">
                    <h2 className="text-lg font-bold text-foreground" style={{ fontFamily: `var(--fontFamily, "Inter")` }}>
                        Recent Members
                    </h2>
                    <Chip size="sm" color="primary" variant="flat">
                        {members.length}
                    </Chip>
                </div>
            </CardHeader>
            <CardBody className="pt-0">
                <div className="overflow-x-auto">
                    <table className="w-full" style={{ fontFamily: `var(--fontFamily, "Inter")` }}>
                        <thead>
                            <tr className="text-left">
                                <th className="pb-3 text-xs font-medium text-default-500 uppercase tracking-wider">
                                    Member
                                </th>
                                <th className="pb-3 text-xs font-medium text-default-500 uppercase tracking-wider">
                                    Department
                                </th>
                                <th className="pb-3 text-xs font-medium text-default-500 uppercase tracking-wider">
                                    Join Date
                                </th>
                                <th className="pb-3 text-xs font-medium text-default-500 uppercase tracking-wider">
                                    Status
                                </th>
                                <th className="pb-3 text-xs font-medium text-default-500 uppercase tracking-wider">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {members.map((member, index) => (
                                <motion.tr
                                    key={member.id}
                                    initial={{ opacity: 0, y: 20 }}
                                    animate={{ opacity: 1, y: 0 }}
                                    transition={{ delay: index * 0.1, duration: 0.3 }}
                                    className="border-b"
                                    style={{ borderColor: `var(--theme-divider, #E4E4E7)` }}
                                >
                                    <td className="py-3">
                                        <div className="flex items-center gap-3">
                                            <ProfileAvatar
                                                src={member.profile_image_url}
                                                name={member.name}
                                                size="sm"
                                                className="flex-shrink-0"
                                                fallbackIcon={<UserIcon className="w-4 h-4" />}
                                            />
                                            <p className="text-sm font-medium text-foreground">
                                                {member.name}
                                            </p>
                                        </div>
                                    </td>
                                    <td className="py-3">
                                        <div className="flex items-center gap-2">
                                            <BuildingOfficeIcon className="w-4 h-4 text-default-400" />
                                            <p className="text-sm text-default-600">
                                                {member.department}
                                            </p>
                                        </div>
                                    </td>
                                    <td className="py-3">
                                        <div className="flex items-center gap-2">
                                            <CalendarIcon className="w-4 h-4 text-default-400" />
                                            <p className="text-sm text-default-600">
                                                {formatDate(member.joinDate)}
                                            </p>
                                        </div>
                                    </td>
                                    <td className="py-3">
                                        <Chip 
                                            size="sm" 
                                            color={getStatusColor(member.status)}
                                            variant="flat"
                                        >
                                            {member.status}
                                        </Chip>
                                    </td>
                                    <td className="py-3">
                                        <Button
                                            size="sm"
                                            variant="flat"
                                            isIconOnly
                                            onPress={() => handleViewProfile(member.id)}
                                        >
                                            <EyeIcon className="w-4 h-4" />
                                        </Button>
                                    </td>
                                </motion.tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </CardBody>
        </Card>
    );
};

export default RecentMembersTable;
