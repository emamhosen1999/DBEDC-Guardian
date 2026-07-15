import { Panel } from '@/Components/ui/Panel';
import React from 'react';
import { Flex, Heading, Box, Text } from '@radix-ui/themes';
import { 
    ClockIcon, 
    FileTextIcon, 
    Link2Icon, 
    PersonIcon,
    ChevronRightIcon
} from '@radix-ui/react-icons';
import { Link, usePage } from '@inertiajs/react';

const QuickLink = ({ href, icon: Icon, label, color }) => (
    <Link href={href} style={{ textDecoration: 'none', display: 'block' }}>
        <Box 
            p="3" 
            className={`quick-link-item hover-${color}`}
            style={{ 
                borderRadius: 'var(--radius-3)', 
                background: 'var(--gray-a2)',
                border: '1px solid var(--gray-a4)',
                transition: 'all 0.22s cubic-bezier(0.4, 0, 0.2, 1)',
                cursor: 'pointer',
            }}
        >
            <Flex align="center" justify="between" gap="3">
                <Flex align="center" gap="3">
                    <Box style={{
                        padding: 8, borderRadius: 'var(--radius-3)',
                        background: `var(--${color}-a3)`,
                        display: 'flex', alignItems: 'center', justifyContent: 'center',
                    }}>
                        <Icon style={{ color: `var(--${color}-9)`, width: 16, height: 16 }} />
                    </Box>
                    <Text size="2" weight="bold" style={{ color: 'var(--gray-12)' }}>{label}</Text>
                </Flex>
                <ChevronRightIcon style={{ color: 'var(--gray-8)', width: 16, height: 16 }} />
            </Flex>
        </Box>
    </Link>
);

export default function QuickLinksWidget({ permissions = [] }) {
    const has = (p) => permissions.includes(p);
    const { auth } = usePage().props;
    
    return (
        <Panel style={{ height: '100%' }}>
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
                <Flex align="center" gap="2" mb="1">
                    <Box style={{
                        padding: 8, borderRadius: 'var(--radius-3)',
                        background: 'var(--accent-a3)', flexShrink: 0,
                    }}>
                        <Link2Icon style={{ color: 'var(--accent-9)' }} />
                    </Box>
                    <Heading size="3">Quick Links</Heading>
                </Flex>

                <Flex direction="column" gap="2" style={{ flex: 1, justifyContent: 'center' }}>
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
                </Flex>
            </Flex>
        </Panel>
    );
}
