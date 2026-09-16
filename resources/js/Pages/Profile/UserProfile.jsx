import { Panel } from '@/Components/ui/Panel';
import React, { useState } from 'react';
import { Head, usePage } from '@inertiajs/react';
import { Badge, Box, Flex, Heading, Separator, Tabs, Text, ScrollArea, Button } from '@radix-ui/themes';
import {
    PersonIcon, DownloadIcon, FileTextIcon, BackpackIcon, HeartIcon
} from '@radix-ui/react-icons';
import App from '@/Layouts/App.jsx';
import { useQueryFilters } from '@/Hooks/useQueryFilters';
import { useMediaQuery } from '@/Hooks/useMediaQuery.js';

import ProfileCard from '@/Components/Profile/ProfileCard.jsx';
import OverviewTab from '@/Components/Profile/OverviewTab.jsx';
import EmploymentAndBankTab from '@/Components/Profile/EmploymentAndBankTab.jsx';
import BackgroundTab from '@/Components/Profile/BackgroundTab.jsx';

const UserProfile = ({ title, allUsers = [], departments = [], designations = [], can = {} }) => {
    const { auth, user: initialUser } = usePage().props;
    const isMobile = useMediaQuery('(max-width: 640px)');
    
    const [user, setUser] = useState(initialUser);
    /* Each tab is a distinct section of the profile, so it belongs in the URL:
       a colleague can be sent straight to ?tab=employment. */
    const f = useQueryFilters({ mode: 'client', defaults: { tab: 'personal' }, debounceKeys: [] });
    const PROFILE_TABS = ['personal', 'employment', 'background', 'dependents'];
    const activeTab = PROFILE_TABS.includes(f.values.tab) ? f.values.tab : 'personal';
    const setActiveTab = (value) => f.set('tab', value);

    const canEditProfile = Boolean(can.edit);
    const canManageEmployment = Boolean(can.manageEmployment);

    const completionPercentage = 60;

    return (
        <App>
            <Head title={title || `${user?.name || 'User'}'s Profile`} />

            <Flex justify="center" p="4">
                <Box style={{ width: '100%', maxWidth: 2000 }}>
                    <Panel>
                        {/* ── Page Header ── */}
                        <Box mb="4">
                            <Flex direction={{ initial: 'column', sm: 'row' }} align={{ initial: 'start', sm: 'center' }} justify="between" gap="4">
                                <Flex align="center" gap="3">
                                    <Box p="3" style={{ background: 'var(--blue-a3)', borderRadius: 12, border: '1px solid var(--blue-a5)', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                                        <PersonIcon style={{ width: 22, height: 22, color: 'var(--blue-9)' }} />
                                    </Box>
                                    <Box>
                                        <Heading size="5" style={{ fontFamily: `'Space Grotesk', system-ui, sans-serif`, fontWeight: 800, letterSpacing: '-0.02em' }}>Employee Profile</Heading>
                                        <Text size="2" style={{ color: 'var(--aero-color-subtle, var(--gray-9))' }}>Comprehensive employee record and personal details</Text>
                                    </Box>
                                </Flex>

                                <Flex gap="2" align="center" wrap="wrap">
                                    <Button variant="soft" color="gray" size="2" style={{ borderRadius: 10 }}>
                                        <DownloadIcon /> {!isMobile && 'Export PDF'}
                                    </Button>
                                </Flex>
                            </Flex>
                        </Box>

                        <Separator size="4" mb="5" style={{ background: 'var(--dl-border-color, rgba(0,0,0,0.06))' }} />

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
                                    <EmploymentAndBankTab
                                        user={user}
                                        setUser={setUser}
                                        departments={departments}
                                        designations={designations}
                                        allUsers={allUsers}
                                        canEdit={canManageEmployment}
                                    />
                                </Tabs.Content>

                                <Tabs.Content value="background">
                                    <BackgroundTab user={user} setUser={setUser} canEdit={canEditProfile} />
                                </Tabs.Content>

                                <Tabs.Content value="dependents">
                                    <Text size="2" color="gray">Dependents information and emergency contacts.</Text>
                                </Tabs.Content>
                            </Tabs.Root>
                        </Box>

                    </Panel>
                </Box>
            </Flex>
        </App>
    );
};

export default UserProfile;
