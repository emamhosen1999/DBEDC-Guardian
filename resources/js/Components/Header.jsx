import React from 'react';
import { Button, DropdownMenu, Flex, Text, Avatar, Separator } from '@radix-ui/themes';
import { BellIcon, GearIcon, PersonIcon, ExitIcon, HamburgerMenuIcon } from '@radix-ui/react-icons';
import { Link, usePage, router } from '@inertiajs/react';

const Header = ({
    title = 'DBEDC Guardian',
    showUserMenu = true,
    showNotifications = true,
    customActions = null,
    toggleSideBar,
}) => {
    const { props } = usePage();
    const { auth } = props;

    const handleLogout = () => router.post(route('logout'));
    const handleProfile = () => {
        if (auth?.user?.id) router.visit(route('profile', { user: auth.user.id }));
    };

    return (
        <Flex
            align="center"
            justify="between"
            px="4"
            style={{ height: 56, borderBottom: '1px solid var(--gray-a6)', background: 'var(--color-panel-solid)', position: 'sticky', top: 0, zIndex: 50 }}
        >
            <Flex align="center" gap="3">
                {toggleSideBar && (
                    <Button variant="ghost" size="2" onClick={toggleSideBar}>
                        <HamburgerMenuIcon />
                    </Button>
                )}
                <Text size="4" weight="bold" color="accent">{title}</Text>
            </Flex>

            <Flex align="center" gap="2">
                {customActions}
                {showNotifications && (
                    <Button variant="ghost" size="2">
                        <BellIcon />
                    </Button>
                )}
                {showUserMenu && auth?.user && (
                    <DropdownMenu.Root>
                        <DropdownMenu.Trigger>
                            <Button variant="ghost" size="2">
                                <Avatar
                                    size="1"
                                    src={auth.user.profile_photo_url}
                                    fallback={auth.user.name?.[0] || 'U'}
                                    radius="full"
                                />
                                <Text size="2">{auth.user.name}</Text>
                            </Button>
                        </DropdownMenu.Trigger>
                        <DropdownMenu.Content>
                            <DropdownMenu.Item onClick={handleProfile}>
                                <PersonIcon /> Profile
                            </DropdownMenu.Item>
                            <DropdownMenu.Item>
                                <GearIcon /> Settings
                            </DropdownMenu.Item>
                            <DropdownMenu.Separator />
                            <DropdownMenu.Item color="red" onClick={handleLogout}>
                                <ExitIcon /> Logout
                            </DropdownMenu.Item>
                        </DropdownMenu.Content>
                    </DropdownMenu.Root>
                )}
            </Flex>
        </Flex>
    );
};

export default Header;
