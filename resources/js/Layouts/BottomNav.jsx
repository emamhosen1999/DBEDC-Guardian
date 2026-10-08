import React, { useState, useCallback, useEffect } from 'react';
import { usePage, router } from '@inertiajs/react';
import { Tooltip } from '@radix-ui/themes';
import {
  HomeIcon,
  PersonIcon,
  ClockIcon,
  FileTextIcon,
  GearIcon,
  DotsHorizontalIcon,
} from '@radix-ui/react-icons';

const BottomNav = ({ toggleThemeDrawer }) => {
  const { url, auth } = usePage().props;
  const [activeTab, setActiveTab] = useState('dashboard');

  useEffect(() => {
    if (url.includes('/attendance-employee') || url.includes('/attendance')) setActiveTab('attendance');
    else if (url.includes('/leaves-employee')) setActiveTab('leaves');
    else if (url.includes('/petty-cash')) setActiveTab('petty-cash');
    else if (url.includes('/dashboard')) setActiveTab('dashboard');
    else if (url.includes('/profile/')) setActiveTab('profile');
    else setActiveTab('dashboard');
  }, [url, auth?.user?.id]);

  const navItems = [
    { id: 'dashboard', label: 'Dashboard', icon: HomeIcon, href: '/dashboard' },
    { id: 'attendance', label: 'Attendance', icon: ClockIcon, href: '/attendance' },
    { id: 'leaves', label: 'Leaves', icon: FileTextIcon, href: '/leaves-employee' },
    { id: 'petty-cash', label: 'Petty Cash', icon: DotsHorizontalIcon, href: '/petty-cash' },
    { id: 'profile', label: 'Profile', icon: PersonIcon, href: `/profile/${auth?.user?.id}` },
    { id: 'theme', label: 'Theme', icon: GearIcon, action: 'theme' },
  ];

  const handleNav = useCallback((item) => {
    if (item.action === 'theme') { toggleThemeDrawer?.(); return; }
    if (item.href) {
      setActiveTab(item.id);
      router.visit(item.href, { method: 'get', preserveState: true, preserveScroll: true });
    }
  }, [toggleThemeDrawer]);

  return (
    <nav className="dl-bottomnav" aria-label="Bottom navigation">
      {navItems.map(item => {
        const isActive = activeTab === item.id;
        const Icon = item.icon;
        return (
          <Tooltip key={item.id} content={item.label}>
            <button
              type="button"
              className="dl-bottomnav__item"
              onClick={() => handleNav(item)}
              aria-current={isActive ? 'page' : undefined}
            >
              <Icon aria-hidden="true" />
              <span className="dl-bottomnav__label">{item.label}</span>
            </button>
          </Tooltip>
        );
      })}
    </nav>
  );
};

export default BottomNav;
