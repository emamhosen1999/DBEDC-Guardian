// ProfileMenu is embedded directly in Header.jsx — this stub satisfies any remaining imports.
import React from 'react';
import { DropdownMenu, Flex, Text } from '@radix-ui/themes';
import { router } from '@inertiajs/react';

const ProfileMenu = ({ user, trigger, onThemeOpen, ...rest }) => (
  <DropdownMenu.Root>
    <DropdownMenu.Trigger>{trigger}</DropdownMenu.Trigger>
    <DropdownMenu.Content align="end" style={{ minWidth: 180 }}>
      {user && (
        <Flex direction="column" px="2" pt="2" pb="1">
          <Text size="2" weight="bold">{user.name}</Text>
          <Text size="1" color="gray">{user.email}</Text>
        </Flex>
      )}
      <DropdownMenu.Separator />
      <DropdownMenu.Item onClick={() => router.get(route('profile', { user: user?.id }))}>Profile</DropdownMenu.Item>
      {onThemeOpen && <DropdownMenu.Item onClick={onThemeOpen}>Theme Settings</DropdownMenu.Item>}
      <DropdownMenu.Separator />
      <DropdownMenu.Item color="red" onClick={() => router.post(route('logout'))}>Sign out</DropdownMenu.Item>
    </DropdownMenu.Content>
  </DropdownMenu.Root>
);

export default ProfileMenu;
