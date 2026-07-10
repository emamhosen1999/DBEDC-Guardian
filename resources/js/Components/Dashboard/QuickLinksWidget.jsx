import React from 'react';
import { Card, Flex, Grid, Heading, Box, Text } from '@radix-ui/themes';
import { 
    ClockIcon, 
    FileTextIcon, 
    Link2Icon, 
    PersonIcon 
} from '@radix-ui/react-icons';
import { Link } from '@inertiajs/react';

const QuickLink = ({ href, icon: Icon, label, color }) => (
    <Link href={href} style={{ textDecoration: 'none', display: 'block', height: '100%' }}>
        <Box 
            p="3" 
            className={`quick-link-item hover-${color}`}
            style={{ 
                borderRadius: 'var(--radius-3)', 
                background: 'var(--gray-a2)',
                border: '1px solid var(--gray-a4)',
                transition: 'all 0.22s cubic-bezier(0.4, 0, 0.2, 1)',
                cursor: 'pointer',
                height: '100%',
            }}
        >
            <Flex direction="column" align="center" gap="2">
                <Box style={{
                    padding: 8, borderRadius: 'var(--radius-full)',
                    background: `var(--${color}-a3)`,
                }}>
                    <Icon style={{ color: `var(--${color}-9)`, width: 20, height: 20 }} />
                </Box>
                <Text size="2" weight="medium" color="gray" align="center">{label}</Text>
            </Flex>
        </Box>
    </Link>
);

import { usePage } from '@inertiajs/react';

export default function QuickLinksWidget({ permissions = [] }) {
    const has = (p) => permissions.includes(p);
    const { auth } = usePage().props;
    
    return (
        <Card style={{ height: '100%' }}>
            <style dangerouslySetInnerHTML={{__html: `
                .quick-link-item:hover {
                    transform: translateY(-2px);
                    box-shadow: var(--shadow-2);
                }
                .quick-link-item.hover-blue:hover {
                    background: var(--blue-a3) !important;
                    border-color: var(--blue-a6) !important;
                }
                .quick-link-item.hover-amber:hover {
                    background: var(--amber-a3) !important;
                    border-color: var(--amber-a6) !important;
                }
                .quick-link-item.hover-green:hover {
                    background: var(--green-a3) !important;
                    border-color: var(--green-a6) !important;
                }
                .quick-link-item.hover-purple:hover {
                    background: var(--purple-a3) !important;
                    border-color: var(--purple-a6) !important;
                }
            `}} />
            <Flex direction="column" gap="3" style={{ height: '100%' }}>
                <Flex align="center" gap="2">
                    <Box style={{
                        padding: 8, borderRadius: 'var(--radius-3)',
                        background: 'var(--accent-a3)', flexShrink: 0,
                    }}>
                        <Link2Icon style={{ color: 'var(--accent-9)' }} />
                    </Box>
                    <Heading size="3">Quick Links</Heading>
                </Flex>

                <Grid columns="2" gap="3" style={{ flex: 1 }}>
                    {has('attendance.own.view') && (
                        <QuickLink href={route('attendance-employee')} icon={ClockIcon} label="My Attendance" color="blue" />
                    )}
                    {has('leave.own.view') && (
                        <QuickLink href={route('leaves-employee')} icon={FileTextIcon} label="My Leaves" color="amber" />
                    )}
                    {has('daily-works.own.view') && (
                        <QuickLink href={route('daily-works-unified')} icon={PersonIcon} label="My Tasks" color="green" />
                    )}
                    {has('profile.own.view') && auth?.user?.id && (
                        <QuickLink href={route('profile', { user: auth.user.id })} icon={PersonIcon} label="My Profile" color="purple" />
                    )}
                </Grid>
            </Flex>
        </Card>
    );
}
