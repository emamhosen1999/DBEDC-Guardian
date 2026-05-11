import {
  GearIcon,
  PersonIcon,
  LockClosedIcon,
} from '@radix-ui/react-icons';
import React from 'react';

export const getSettingsPages = (permissions = [], auth = null) => [
  {
    name: 'Settings',
    icon: <GearIcon />,
    category: 'settings',
    subMenu: [
      { name: 'General', route: 'settings.general', icon: <GearIcon />, category: 'settings' },
      { name: 'Profile', route: 'profile.index', icon: <PersonIcon />, category: 'settings' },
    ],
  },
];

export default getSettingsPages;
