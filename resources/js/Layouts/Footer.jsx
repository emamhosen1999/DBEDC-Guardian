import { Panel } from '@/Components/ui/Panel';
import React from 'react';
import { Box, Flex, Separator, Text } from '@radix-ui/themes';
import { HeartFilledIcon } from '@radix-ui/react-icons';
import { Link, usePage } from '@inertiajs/react';

const Footer = () => {
    const currentYear = new Date().getFullYear();
    const { url } = usePage();
    const activePage = url;

    const quickLinks = [
        { label: 'Dashboard', href: '/dashboard' },
        { label: 'Employees', href: '/employees' },
        { label: 'Attendance', href: '/attendances' },
        { label: 'Leaves', href: '/leaves' },
        { label: 'Timesheet', href: '/timesheet' },
    ];

    return (
        <Box as="footer" role="contentinfo" pt="8" mt="auto" style={{ borderTop: '1px solid var(--gray-a6)' }}>
            <Box px="6" style={{ maxWidth: 1280, margin: '0 auto' }}>
                <Panel>
                    <Box p="8">
                        <Flex gap="8" wrap="wrap">
                            {/* Brand */}
                            <Box style={{ flex: '1 1 220px', minWidth: 200 }}>
                                <Flex align="center" gap="3" mb="3">
                                    <Box style={{ width: 44, height: 44, borderRadius: 'var(--radius-3)', background: 'var(--accent-9)', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                                        <Text size="5" weight="bold" style={{ color: 'white' }}>A</Text>
                                    </Box>
                                    <Box>
                                        <Text size="4" weight="bold" as="p">DBEDC Guardian</Text>
                                        <Text size="1" color="gray" as="p">Enterprise Solution</Text>
                                    </Box>
                                </Flex>
                                <Text size="2" color="gray" as="p" mb="3">
                                    Connected workforce, project, quality, and operations management.
                                </Text>
                                <Flex align="center" gap="1">
                                    <Text size="2" color="gray">Crafted with</Text>
                                    <HeartFilledIcon style={{ color: 'var(--red-9)', width: 14, height: 14 }} />
                                    <Text size="2" color="gray">by the Aero Team</Text>
                                </Flex>
                            </Box>

                            {/* Quick Links */}
                            <Box style={{ flex: '1 1 200px', minWidth: 160 }}>
                                <Text size="3" weight="bold" as="p" mb="3">Quick Links</Text>
                                <Flex direction="column" gap="1">
                                    {quickLinks.map((link, i) => (
                                        <Link key={i} href={link.href} style={{ fontSize: 13, color: activePage === link.href ? 'var(--accent-9)' : 'var(--gray-11)', textDecoration: 'none', padding: '3px 0' }}>
                                            {link.label}
                                        </Link>
                                    ))}
                                </Flex>
                            </Box>

                        </Flex>

                        <Separator size="4" my="5" />

                        <Flex justify="between" align="center" wrap="wrap" gap="3">
                            <Text size="2" color="gray">&copy; {currentYear} DBEDC Guardian. All rights reserved.</Text>
                        </Flex>
                    </Box>
                </Panel>
            </Box>
        </Box>
    );
};

export default Footer;
