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
            style={{ 
                borderRadius: 'var(--radius-3)', 
                background: 'var(--gray-a2)',
                border: '1px solid var(--gray-a4)',
                transition: 'all 0.2s ease',
                cursor: 'pointer',
                height: '100%',
                ':hover': {
                    background: `var(--${color}-a3)`,
                    borderColor: `var(--${color}-a6)`
                }
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

export default function QuickLinksWidget({ permissions = [] }) {
    const has = (p) => permissions.includes(p);
    
    return (
        <Card style={{ height: '100%' }}>
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
                        <QuickLink href={route('attendance.employee')} icon={ClockIcon} label="My Attendance" color="blue" />
                    )}
                    {has('leave.own.view') && (
                        <QuickLink href={route('leaves.employee')} icon={FileTextIcon} label="My Leaves" color="amber" />
                    )}
                    {has('daily-works.own.view') && (
                        <QuickLink href={route('daily-works')} icon={PersonIcon} label="My Tasks" color="green" />
                    )}
                    {has('profile.view') && (
                        <QuickLink href={route('profile.edit')} icon={PersonIcon} label="My Profile" color="purple" />
                    )}
                </Grid>
            </Flex>
        </Card>
    );
}
