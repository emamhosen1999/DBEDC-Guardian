import React, { useState, useCallback, useEffect } from 'react';
import { Link, usePage, router } from '@inertiajs/react';
import { Box, Flex, IconButton, Text, Tooltip } from '@radix-ui/themes';
import {
  HomeIcon,
  PersonIcon,
  ClockIcon,
  FileTextIcon,
  GearIcon,
} from '@radix-ui/react-icons';

const BottomNav = ({ toggleThemeDrawer }) => {
  const { url, auth } = usePage().props;
  const [activeTab, setActiveTab] = useState('dashboard');

  useEffect(() => {
    if (url.includes('/attendance-employee') || url.includes('/attendance')) setActiveTab('attendance');
    else if (url.includes('/leaves-employee')) setActiveTab('leaves');
    else if (url.includes('/dashboard')) setActiveTab('dashboard');
    else if (url.includes('/profile/')) setActiveTab('profile');
    else setActiveTab('dashboard');
  }, [url, auth?.user?.id]);

  const navItems = [
    { id: 'dashboard', label: 'Dashboard', icon: HomeIcon, href: '/dashboard' },
    { id: 'attendance', label: 'Attendance', icon: ClockIcon, href: '/attendance' },
    { id: 'leaves', label: 'Leaves', icon: FileTextIcon, href: '/leaves-employee' },
    { id: 'profile', label: 'Profile', icon: PersonIcon, href: `/profile/${auth?.user?.id}` },
    { id: 'theme', label: 'Theme', icon: GearIcon, action: 'theme' },
  ];

  const handleNav = useCallback((item) => {
    if (item.action === 'theme') { toggleThemeDrawer?.(); return; }
    if (item.href) {
      setActiveTab(item.id);
      router.visit(item.href, { method: 'get', preserveState: false });
    }
  }, [toggleThemeDrawer]);

  return (
    <Box
      as="nav"
      style={{
        position: 'fixed',
        bottom: 0,
        left: 0,
        right: 0,
        height: 60,
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'space-around',
        background: 'var(--color-panel-solid)',
        borderTop: '1px solid var(--gray-a4)',
        zIndex: 200,
        paddingBottom: 'env(safe-area-inset-bottom)',
      }}
      aria-label="Bottom navigation"
    >
      {navItems.map(item => {
        const isActive = activeTab === item.id;
        const Icon = item.icon;
        return (
          <Tooltip key={item.id} content={item.label}>
            <Flex
              direction="column"
              align="center"
              gap="1"
              style={{
                cursor: 'pointer',
                padding: '6px 10px',
                borderRadius: 'var(--radius-2)',
                background: isActive ? 'var(--accent-a3)' : 'transparent',
                color: isActive ? 'var(--accent-11)' : 'var(--gray-10)',
                transition: 'background 120ms, color 120ms',
                minWidth: 52,
                position: 'relative',
              }}
              onClick={() => handleNav(item)}
              role="button"
              tabIndex={0}
              aria-label={item.label}
              aria-current={isActive ? 'page' : undefined}
              onKeyDown={e => { if (e.key === 'Enter' || e.key === ' ') handleNav(item); }}
            >
              <Icon style={{ width: 18, height: 18 }} />
              <Text
                size="1"
                weight={isActive ? 'bold' : 'regular'}
                style={{ fontSize: 10, lineHeight: 1 }}
              >
                {item.label}
              </Text>
              {isActive && (
                <Box style={{
                  position: 'absolute',
                  bottom: 0,
                  width: 24,
                  height: 2,
                  borderRadius: 'var(--radius-full)',
                  background: 'var(--accent-9)',
                }} />
              )}
            </Flex>
          </Tooltip>
        );
      })}
    </Box>
  );
};

export default BottomNav;
