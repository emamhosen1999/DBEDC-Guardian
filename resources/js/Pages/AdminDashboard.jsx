import { Head } from '@inertiajs/react';
import React from 'react';
import { motion } from 'framer-motion';
import { Card, CardHeader, CardBody } from "@heroui/react";
import App from "@/Layouts/App.jsx";
import AdminStatsCards from '@/Components/Admin/AdminStatsCards.jsx';
import RecentActivityCard from '@/Components/Admin/RecentActivityCard.jsx';
import AttendanceChart from '@/Components/Admin/AttendanceChart.jsx';
import PendingApprovalsCard from '@/Components/Admin/PendingApprovalsCard.jsx';
import SystemHealthCard from '@/Components/Admin/SystemHealthCard.jsx';
import RecentMembersTable from '@/Components/Admin/RecentMembersTable.jsx';
import WelcomeWidget from '@/Components/Admin/WelcomeWidget.jsx';

export default function AdminDashboard({ auth }) {
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

    const containerVariants = {
        hidden: { opacity: 0 },
        visible: {
            opacity: 1,
            transition: {
                duration: 0.6,
                staggerChildren: 0.1,
                ease: "easeOut"
            }
        }
    };

    const itemVariants = {
        hidden: { 
            opacity: 0, 
            y: 20, 
            scale: 0.95 
        },
        visible: { 
            opacity: 1, 
            y: 0, 
            scale: 1,
            transition: {
                duration: 0.5,
                ease: "easeOut"
            }
        }
    };

    return ( 
        <>
            <Head title="Admin Dashboard" />
            <motion.div
                variants={containerVariants}
                initial="hidden"
                animate="visible"
                className="w-full p-8"
            >
                {/* Welcome Widget */}
                <motion.div key="welcome" variants={itemVariants}>
                    <WelcomeWidget auth={auth} />
                </motion.div>

                {/* Statistics Cards */}
                <motion.div key="stats" variants={itemVariants} className="mb-8 mt-8">
                    <AdminStatsCards />
                </motion.div>

                {/* Main Content Grid */}
                <div className="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
                    {/* Left Column (60%) */}
                    <div className="lg:col-span-2 space-y-6">
                        <motion.div key="recent-activity" variants={itemVariants}>
                            <RecentActivityCard />
                        </motion.div>

                        <motion.div key="attendance-chart" variants={itemVariants}>
                            <AttendanceChart />
                        </motion.div>
                    </div>

                    {/* Right Column (40%) */}
                    <div className="space-y-6">
                        <motion.div key="quick-actions" variants={itemVariants}>
                            <Card 
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
                                        Quick Actions
                                    </h2>
                                </CardHeader>
                                <CardBody className="pt-0 p-4">
                                    <div className="grid grid-cols-1 gap-3">
                                        <motion.div 
                                            className="p-3 rounded-lg border cursor-pointer hover:border-primary transition-all"
                                            style={{ 
                                                backgroundColor: `var(--theme-background, #FFFFFF)`,
                                                borderColor: `var(--theme-divider, #E4E4E7)`
                                            }}
                                            whileHover={{ scale: 1.02 }}
                                            whileTap={{ scale: 0.98 }}
                                        >
                                            <div className="font-medium" style={{ color: `var(--theme-foreground, #11181C)`, fontFamily: `var(--fontFamily, "Inter")` }}>Manage Users</div>
                                            <div className="text-sm mt-1" style={{ color: `var(--theme-foreground-400, #A1A1AA)`, fontFamily: `var(--fontFamily, "Inter")` }}>Add, edit, or remove users</div>
                                        </motion.div>
                                        <motion.div 
                                            className="p-3 rounded-lg border cursor-pointer hover:border-primary transition-all"
                                            style={{ 
                                                backgroundColor: `var(--theme-background, #FFFFFF)`,
                                                borderColor: `var(--theme-divider, #E4E4E7)`
                                            }}
                                            whileHover={{ scale: 1.02 }}
                                            whileTap={{ scale: 0.98 }}
                                        >
                                            <div className="font-medium" style={{ color: `var(--theme-foreground, #11181C)`, fontFamily: `var(--fontFamily, "Inter")` }}>Manage Roles</div>
                                            <div className="text-sm mt-1" style={{ color: `var(--theme-foreground-400, #A1A1AA)`, fontFamily: `var(--fontFamily, "Inter")` }}>Configure user roles and permissions</div>
                                        </motion.div>
                                        <motion.div 
                                            className="p-3 rounded-lg border cursor-pointer hover:border-primary transition-all"
                                            style={{ 
                                                backgroundColor: `var(--theme-background, #FFFFFF)`,
                                                borderColor: `var(--theme-divider, #E4E4E7)`
                                            }}
                                            whileHover={{ scale: 1.02 }}
                                            whileTap={{ scale: 0.98 }}
                                        >
                                            <div className="font-medium" style={{ color: `var(--theme-foreground, #11181C)`, fontFamily: `var(--fontFamily, "Inter")` }}>Organization Settings</div>
                                            <div className="text-sm mt-1" style={{ color: `var(--theme-foreground-400, #A1A1AA)`, fontFamily: `var(--fontFamily, "Inter")` }}>Update company information</div>
                                        </motion.div>
                                        <motion.div 
                                            className="p-3 rounded-lg border cursor-pointer hover:border-primary transition-all"
                                            style={{ 
                                                backgroundColor: `var(--theme-background, #FFFFFF)`,
                                                borderColor: `var(--theme-divider, #E4E4E7)`
                                            }}
                                            whileHover={{ scale: 1.02 }}
                                            whileTap={{ scale: 0.98 }}
                                        >
                                            <div className="font-medium" style={{ color: `var(--theme-foreground, #11181C)`, fontFamily: `var(--fontFamily, "Inter")` }}>System Monitoring</div>
                                            <div className="text-sm mt-1" style={{ color: `var(--theme-foreground-400, #A1A1AA)`, fontFamily: `var(--fontFamily, "Inter")` }}>View system performance metrics</div>
                                        </motion.div>
                                    </div>
                                </CardBody>
                            </Card>
                        </motion.div>

                        <motion.div key="pending-approvals" variants={itemVariants}>
                            <PendingApprovalsCard />
                        </motion.div>

                        <motion.div key="system-health" variants={itemVariants}>
                            <SystemHealthCard />
                        </motion.div>
                    </div>
                </div>

                {/* Recent Members Table */}
                <motion.div key="recent-members" variants={itemVariants}>
                    <RecentMembersTable />
                </motion.div>
            </motion.div>
        </>
    );
}

AdminDashboard.layout = (page) => <App>{page}</App>;
