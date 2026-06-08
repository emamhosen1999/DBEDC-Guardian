import React from 'react';
import { Card, Flex, Grid, Heading, Box, Text } from '@radix-ui/themes';
import { PersonIcon, HomeIcon, LayersIcon, SewingPinIcon } from '@radix-ui/react-icons';

const StatCard = ({ title, value, icon: Icon, color }) => (
    <Card style={{ height: '100%' }}>
        <Flex direction="column" gap="3">
            <Flex align="center" gap="3">
                <Box style={{
                    padding: 8, 
                    borderRadius: 'var(--radius-3)',
                    background: `var(--${color}-a3)`,
                    border: `1px solid var(--${color}-a5)`,
                    display: 'flex', alignItems: 'center', justifyContent: 'center'
                }}>
                    <Icon style={{ color: `var(--${color}-9)`, width: 20, height: 20 }} />
                </Box>
                <Heading size="2" color="gray" style={{ textTransform: 'uppercase', letterSpacing: '0.05em' }}>
                    {title}
                </Heading>
            </Flex>
            <Text size="7" weight="bold" style={{ color: `var(--${color}-11)` }}>
                {value ?? 0}
            </Text>
        </Flex>
    </Card>
);

export default function OrganizationOverview({ stats }) {
    if (!stats) return null;

    return (
        <Box mb="5">
            <Grid columns={{ initial: '1', sm: '2', md: '4' }} gap="4">
                <StatCard 
                    title="Total Employees" 
                    value={stats.total_employees} 
                    icon={PersonIcon} 
                    color="blue" 
                />
                <StatCard 
                    title="Departments" 
                    value={stats.total_departments} 
                    icon={HomeIcon} 
                    color="indigo" 
                />
                <StatCard 
                    title="Designations" 
                    value={stats.total_designations} 
                    icon={LayersIcon} 
                    color="violet" 
                />
                <StatCard 
                    title="Work Locations" 
                    value={stats.total_locations} 
                    icon={SewingPinIcon} 
                    color="plum" 
                />
            </Grid>
        </Box>
    );
}
