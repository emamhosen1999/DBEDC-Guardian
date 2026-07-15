import { Panel } from '@/Components/ui/Panel';
import React, { useState } from 'react';
import { Head, usePage } from '@inertiajs/react';
import { Badge, Box, Flex, Heading, Separator, Tabs, Text, ScrollArea, Button } from '@radix-ui/themes';
import {
    PersonIcon, DownloadIcon, FileTextIcon, BackpackIcon, HeartIcon
} from '@radix-ui/react-icons';
import App from '@/Layouts/App.jsx';
import { useMediaQuery } from '@/Hooks/useMediaQuery.js';

import ProfileCard from '@/Components/Profile/ProfileCard.jsx';
import OverviewTab from '@/Components/Profile/OverviewTab.jsx';
import EmploymentAndBankTab from '@/Components/Profile/EmploymentAndBankTab.jsx';
import BackgroundTab from '@/Components/Profile/BackgroundTab.jsx';


const UserProfile = ({ title, allUsers, departments, designations }) => {
    const { auth, user: initialUser } = usePage().props;
    const isMobile = useMediaQuery('(max-width: 640px)');
    
    const [user, setUser] = useState(initialUser);
    const [activeTab, setActiveTab] = useState('personal');

    const canEditProfile = auth.permissions?.includes('profile.own.update') || 
                           auth.permissions?.includes('profile.update') || 
                           auth.user.id === user.id;

    // Calculate Completion
    const completionPercentage = 60; // TODO: Implement real calculation

    return (
        <>
            <Head title={title || `${user.name}'s Profile`} />

            <Flex justify="center" p="4">
                <Box style={{ width: '100%', maxWidth: 2000 }}>
                    <Panel>
                        {/* ── Page Header ── */}
                        <Box mb="4">
                            <Flex direction={{ initial: 'column', sm: 'row' }} align={{ initial: 'start', sm: 'center' }} justify="between" gap="4">
                                <Flex align="center" gap="3">
                                    <Box p="3" style={{ background: 'var(--accent-a3)', borderRadius: 'var(--radius-2)', border: '1px solid var(--accent-a6)', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                                        <PersonIcon style={{ width: 22, height: 22, color: 'var(--accent-9)' }} />
                                    </Box>
                                    <Box>
                                        <Heading size="5">Employee Profile</Heading>
                                        <Text size="2" color="gray">Comprehensive employee management</Text>
                                    </Box>
                                </Flex>

                                <Flex gap="2" align="center" wrap="wrap">
                                    <Button variant="soft" color="gray" size="2">
                                        <DownloadIcon /> {!isMobile && 'Export PDF'}
                                    </Button>
                                </Flex>
                            </Flex>
                        </Box>

                        <Separator size="4" mb="5" />

                        {/* ── Top Profile Card (Always Visible) ── */}
                        <ProfileCard 
                            user={user} 
                            setUser={setUser} 
                            canEdit={canEditProfile} 
                            completion={completionPercentage} 
                        />

                        <Box mt="5">
                            {/* ── Tabs ── */}
                            <Tabs.Root value={activeTab} onValueChange={setActiveTab}>
                                <ScrollArea type="auto" scrollbars="horizontal">
                                    <Tabs.List mb="4" style={{ whiteSpace: 'nowrap', width: 'max-content', minWidth: '100%' }}>
                                        <Tabs.Trigger value="personal">
                                            <Flex align="center" gap="2"><PersonIcon /> Personal Info</Flex>
                                        </Tabs.Trigger>
                                        <Tabs.Trigger value="employment">
                                            <Flex align="center" gap="2"><FileTextIcon /> Employment & Bank</Flex>
                                        </Tabs.Trigger>
                                        <Tabs.Trigger value="background">
                                            <Flex align="center" gap="2"><BackpackIcon /> Background</Flex>
                                        </Tabs.Trigger>
                                        <Tabs.Trigger value="dependents">
                                            <Flex align="center" gap="2"><HeartIcon /> Dependents</Flex>
                                        </Tabs.Trigger>
                                    </Tabs.List>
                                </ScrollArea>

                                {/* ── Tab Contents ── */}
                                <Tabs.Content value="personal">
                                    <OverviewTab user={user} setUser={setUser} canEdit={canEditProfile} />
                                </Tabs.Content>

                                <Tabs.Content value="employment">
                                    <EmploymentAndBankTab user={user} setUser={setUser} canEdit={canEditProfile} />
                                </Tabs.Content>

                                <Tabs.Content value="background">
                                    <BackgroundTab user={user} setUser={setUser} canEdit={canEditProfile} />
                                </Tabs.Content>

                                <Tabs.Content value="dependents">
                                    <Text>Dependents content goes here...</Text>
                                </Tabs.Content>
                            </Tabs.Root>
                        </Box>

                    </Panel>
                </Box>
            </Flex>
        </>
    );
};

UserProfile.layout = page => <App>{page}</App>;
export default UserProfile;