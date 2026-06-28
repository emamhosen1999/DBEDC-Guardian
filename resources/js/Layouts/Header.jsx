import React, { useState, useCallback, useEffect, useRef } from 'react';
import { Link, usePage, router } from "@inertiajs/react";
import { useMediaQuery } from '@/Hooks/useMediaQuery.js';
import {
  Avatar,
  Badge,
  Box,
  Card,
  DropdownMenu,
  Flex,
  IconButton,
  Kbd,
  Separator,
  Text,
  TextField,
  Tooltip,
} from '@radix-ui/themes';
import {
  HamburgerMenuIcon,
  MagnifyingGlassIcon,
  BellIcon,
  PersonIcon,
  ExitIcon,
  SunIcon,
  MoonIcon,
  DashboardIcon,
  MixerHorizontalIcon,
  Cross1Icon,
} from '@radix-ui/react-icons';
import LanguageSwitcher from '@/Components/LanguageSwitcher';
import { useRadixTheme } from '@/Contexts/RadixThemeContext';
import {
  useUnreadCount,
  useNotificationsList,
  useMarkRead,
  useMarkAllRead,
} from '@/api/queries/useNotificationsQuery';
import { useRealtimeNotifications } from '@/Hooks/useRealtimeNotifications';

const getGreeting = () => {
  const h = new Date().getHours();
  if (h < 12) return 'Good morning';
  if (h < 17) return 'Good afternoon';
  return 'Good evening';
};

const isMac = typeof navigator !== 'undefined' && /mac/i.test(navigator.platform);

// Thin vertical divider between icon groups
const VDivider = () => (
  <Box style={{ width: 1, height: 20, background: 'var(--gray-a5)', flexShrink: 0, marginInline: 4 }} />
);

const Header = React.memo(({ toggleSideBar, sideBarOpen, toggleThemeDrawer }) => {
  const { auth, app, title } = usePage().props;
  const { settings, toggleAppearance } = useRadixTheme();
  const isMobile  = useMediaQuery('(max-width: 640px)');
  const isTablet  = useMediaQuery('(max-width: 1024px)');
  const [searchOpen, setSearchOpen] = useState(false);
  const [searchQuery, setSearchQuery] = useState('');
  const searchWrapRef = useRef(null);

  // Live in-app notifications (Task 13): React Query + RTDB-driven refresh.
  const { data: unreadCount = 0 } = useUnreadCount();
  const { data: notificationsPage } = useNotificationsList();
  const notifications = notificationsPage?.data ?? [];
  const markReadMutation = useMarkRead();
  const markAllReadMutation = useMarkAllRead();
  useRealtimeNotifications(auth?.user?.id);

  const handleNotificationClick = useCallback((n) => {
    if (!n.read_at) {
      markReadMutation.mutate(n.id);
    }
    const url = n.data?.url;
    if (url) {
      router.visit(url);
    }
  }, [markReadMutation]);

  const handleMarkAllRead = useCallback(() => {
    markAllReadMutation.mutate();
  }, [markAllReadMutation]);

  const handleLogout = useCallback(() => router.post(route('logout'), { preserveState: true, preserveScroll: true }), []);

  const handleSearchSubmit = useCallback((e) => {
    e.preventDefault();
    if (searchQuery.trim()) {
      router.get(route('search'), { q: searchQuery }, { preserveState: true, preserveScroll: true });
      setSearchOpen(false);
      setSearchQuery('');
    }
  }, [searchQuery]);

  const openSearch = useCallback(() => {
    setSearchOpen(true);
    requestAnimationFrame(() => {
      searchWrapRef.current?.querySelector('input')?.focus();
    });
  }, []);

  const closeSearch = useCallback(() => {
    setSearchOpen(false);
    setSearchQuery('');
  }, []);

  // Cmd/Ctrl+K global shortcut
  useEffect(() => {
    const handler = (e) => {
      if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
        e.preventDefault();
        searchOpen ? closeSearch() : openSearch();
      }
      if (e.key === 'Escape' && searchOpen) closeSearch();
    };
    document.addEventListener('keydown', handler);
    return () => document.removeEventListener('keydown', handler);
  }, [searchOpen, openSearch, closeSearch]);

  const userName        = auth?.user?.name || auth?.user?.first_name || 'User';
  const userDesignation = auth?.user?.designation?.title || 'Team Member';
  const avatarSrc       = auth?.user?.profile_image_url || auth?.user?.profile_image;
  const pageTitle       = title || app?.name || 'Dashboard';

  // ── Mobile full-width search overlay ──────────────────────────────────────
  if (isMobile && searchOpen) {
    return (
      <Card
        as="header"
        style={{
          height: 56, display: 'flex', alignItems: 'center',
          paddingInline: 10, gap: 6,
          borderBottom: '1px solid var(--gray-a4)',
          position: 'sticky', top: 0, zIndex: 100, flexShrink: 0,
          borderRadius: 0,
        }}
      >
        <form
          onSubmit={handleSearchSubmit}
          style={{ display: 'flex', alignItems: 'center', gap: 6, flex: 1 }}
        >
          <Box ref={searchWrapRef} style={{ flex: 1 }}>
            <TextField.Root
              size="2"
              placeholder="Search anything…"
              value={searchQuery}
              onChange={e => setSearchQuery(e.target.value)}
              autoFocus
            >
              <TextField.Slot>
                <MagnifyingGlassIcon style={{ width: 14, height: 14 }} />
              </TextField.Slot>
            </TextField.Root>
          </Box>
          <IconButton type="button" variant="ghost" color="gray" size="2" onClick={closeSearch} aria-label="Close search">
            <Cross1Icon />
          </IconButton>
        </form>
      </Card>
    );
  }

  return (
    <Card
      as="header"
      style={{
        height: 56,
        display: 'flex',
        alignItems: 'center',
        paddingInline: 12,
        gap: 4,
        borderBottom: '1px solid var(--gray-a4)',
        position: 'sticky',
        top: 0,
        zIndex: 100,
        flexShrink: 0,
        borderRadius: 0,
      }}
    >
      {/* ── Sidebar toggle ──────────────────────────────────────────────────── */}
      <Tooltip content={sideBarOpen ? 'Collapse sidebar' : 'Expand sidebar'} delayDuration={600}>
        <IconButton variant="ghost" color="gray" size="2" onClick={toggleSideBar} aria-label="Toggle sidebar">
          <HamburgerMenuIcon />
        </IconButton>
      </Tooltip>

      {/* ── Page title — always flex:1, always visible on ≥tablet ────────────── */}
      {!isMobile && (
        <Box style={{ flex: 1, minWidth: 0, paddingLeft: 6 }}>
          <Text
            size="2"
            weight="medium"
            style={{ display: 'block', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', color: 'var(--gray-12)' }}
          >
            {pageTitle}
          </Text>
        </Box>
      )}

      {/* ── Mobile spacer ───────────────────────────────────────────────────── */}
      {isMobile && <Box style={{ flex: 1 }} />}

      {/* ── Search — anchored on RIGHT, never shifts left ────────────────────── */}
      {!isMobile && (
        <Box ref={searchWrapRef} style={{ flexShrink: 0 }}>
          {searchOpen ? (
            <form
              onSubmit={handleSearchSubmit}
              style={{ display: 'flex', alignItems: 'center', gap: 4 }}
            >
              <TextField.Root
                size="2"
                placeholder="Search anything…"
                value={searchQuery}
                onChange={e => setSearchQuery(e.target.value)}
                style={{ width: isTablet ? 200 : 280 }}
                autoFocus
              >
                <TextField.Slot>
                  <MagnifyingGlassIcon style={{ width: 14, height: 14 }} />
                </TextField.Slot>
              </TextField.Root>
              <Tooltip content="Close  Esc" delayDuration={600}>
                <IconButton type="button" variant="ghost" color="gray" size="2" onClick={closeSearch} aria-label="Close search">
                  <Cross1Icon />
                </IconButton>
              </Tooltip>
            </form>
          ) : (
            <Box
              role="button"
              tabIndex={0}
              onClick={openSearch}
              onKeyDown={e => e.key === 'Enter' && openSearch()}
              aria-label="Open search"
              style={{
                display: 'flex', alignItems: 'center', gap: 6,
                height: 32, paddingInline: 10,
                width: isTablet ? 160 : 210,
                border: '1px solid var(--gray-a5)',
                borderRadius: 'var(--radius-2)',
                cursor: 'text', color: 'var(--gray-9)',
                background: 'var(--gray-a2)',
                transition: 'border-color 120ms, background 120ms',
                outline: 'none',
              }}
              onMouseEnter={e => { e.currentTarget.style.borderColor = 'var(--gray-a7)'; e.currentTarget.style.background = 'var(--gray-a3)'; }}
              onMouseLeave={e => { e.currentTarget.style.borderColor = 'var(--gray-a5)'; e.currentTarget.style.background = 'var(--gray-a2)'; }}
              onFocus={e => { e.currentTarget.style.borderColor = 'var(--accent-8)'; }}
              onBlur={e => { e.currentTarget.style.borderColor = 'var(--gray-a5)'; }}
            >
              <MagnifyingGlassIcon style={{ width: 13, height: 13, flexShrink: 0 }} />
              <Text size="2" color="gray" style={{ flex: 1, userSelect: 'none', whiteSpace: 'nowrap' }}>Search…</Text>
              {!isTablet && (
                <Kbd size="1" style={{ opacity: 0.65, fontSize: 10, flexShrink: 0 }}>{isMac ? '⌘K' : 'Ctrl+K'}</Kbd>
              )}
            </Box>
          )}
        </Box>
      )}

      {/* ── Right action bar ─────────────────────────────────────────────────── */}
      <Flex align="center" gap="2" style={{ flexShrink: 0, paddingLeft: 6 }}>

        {/* Mobile: search icon */}
        {isMobile && (
          <IconButton variant="ghost" color="gray" size="2" onClick={openSearch} aria-label="Search">
            <MagnifyingGlassIcon />
          </IconButton>
        )}

        {/* Appearance toggle — hidden on mobile */}
        {!isMobile && (
          <Tooltip content={settings.appearance === 'dark' ? 'Light mode' : 'Dark mode'} delayDuration={600}>
            <IconButton variant="ghost" color="gray" size="2" onClick={toggleAppearance} aria-label="Toggle appearance">
              {settings.appearance === 'dark' ? <SunIcon /> : <MoonIcon />}
            </IconButton>
          </Tooltip>
        )}

        {/* Theme drawer — hidden on mobile */}
        {!isMobile && (
          <Tooltip content="Customize theme" delayDuration={600}>
            <IconButton variant="ghost" color="gray" size="2" onClick={toggleThemeDrawer} aria-label="Theme settings">
              <MixerHorizontalIcon />
            </IconButton>
          </Tooltip>
        )}

        {/* Language switcher — hidden on small tablet */}
        {!isTablet && <LanguageSwitcher />}

        <VDivider />

        {/* Notifications */}
        <DropdownMenu.Root>
          <DropdownMenu.Trigger>
            <Box style={{ position: 'relative', display: 'inline-flex' }}>
              <IconButton variant="ghost" color="gray" size="2" aria-label="Notifications">
                <BellIcon />
              </IconButton>
              {unreadCount > 0 && (
                <Box style={{
                  position: 'absolute', top: 2, right: 2,
                  minWidth: 14, height: 14,
                  background: 'var(--red-9)', color: '#fff',
                  borderRadius: '50%', fontSize: 9, fontWeight: 700,
                  display: 'flex', alignItems: 'center', justifyContent: 'center',
                  border: '1.5px solid var(--color-panel-solid)',
                  pointerEvents: 'none', lineHeight: 1,
                }}>
                  {unreadCount > 9 ? '9+' : unreadCount}
                </Box>
              )}
            </Box>
          </DropdownMenu.Trigger>
          <DropdownMenu.Content align="end" style={{ minWidth: 300, maxWidth: 340 }}>
            <Flex align="center" justify="between" px="3" py="2">
              <Text size="2" weight="bold">Notifications</Text>
              {unreadCount > 0 && <Badge color="red" variant="soft" size="1">{unreadCount} unread</Badge>}
            </Flex>
            <Separator size="4" />
            {Array.isArray(notifications) && notifications.length > 0 ? (
              <>
                {notifications.slice(0, 6).map(n => (
                  <DropdownMenu.Item
                    key={n.id}
                    style={{ opacity: n.read_at ? 0.55 : 1 }}
                    onSelect={() => handleNotificationClick(n)}
                  >
                    <Flex direction="column" style={{ maxWidth: 260 }}>
                      <Text size="2" weight={n.read_at ? 'regular' : 'medium'} style={{ lineHeight: 1.4 }}>
                        {n.data?.title || n.data?.message || 'Notification'}
                      </Text>
                      {n.created_at && (
                        <Text size="1" color="gray">{new Date(n.created_at).toLocaleDateString()}</Text>
                      )}
                    </Flex>
                  </DropdownMenu.Item>
                ))}
                <Separator size="4" />
                {unreadCount > 0 && (
                  <DropdownMenu.Item onSelect={handleMarkAllRead} disabled={markAllReadMutation.isPending}>
                    <Text size="2" style={{ justifyContent: 'center', width: '100%', textAlign: 'center' }}>Mark all read</Text>
                  </DropdownMenu.Item>
                )}
                <DropdownMenu.Item asChild>
                  <Link href={route('notifications.index')} style={{ justifyContent: 'center' }}>
                    <Text size="2" color="accent">View all notifications</Text>
                  </Link>
                </DropdownMenu.Item>
              </>
            ) : (
              <Flex align="center" justify="center" direction="column" gap="1" py="5">
                <BellIcon style={{ width: 20, height: 20, color: 'var(--gray-7)' }} />
                <Text size="2" color="gray">All caught up</Text>
              </Flex>
            )}
            <Separator size="4" />
            <DropdownMenu.Item asChild>
              <Link href={route('settings.notifications')} style={{ justifyContent: 'center' }}>
                <Text size="2" color="gray">Notification settings</Text>
              </Link>
            </DropdownMenu.Item>
          </DropdownMenu.Content>
        </DropdownMenu.Root>

        {/* User menu */}
        <DropdownMenu.Root>
          <DropdownMenu.Trigger>
            <Box
              role="button"
              tabIndex={0}
              aria-label="User menu"
              style={{
                display: 'flex', alignItems: 'center', gap: 8,
                padding: '4px 6px 4px 4px',
                borderRadius: 'var(--radius-2)',
                cursor: 'pointer', outline: 'none',
                transition: 'background 120ms',
              }}
              onMouseEnter={e => { e.currentTarget.style.background = 'var(--gray-a3)'; }}
              onMouseLeave={e => { e.currentTarget.style.background = 'transparent'; }}
            >
              <Box style={{ position: 'relative', flexShrink: 0 }}>
                <Avatar src={avatarSrc} fallback={userName.charAt(0).toUpperCase()} size="2" radius="full" />
                <Box style={{
                  position: 'absolute', bottom: 0, right: 0,
                  width: 8, height: 8, borderRadius: '50%',
                  background: 'var(--green-9)',
                  border: '1.5px solid var(--color-panel-solid)',
                }} />
              </Box>
              {!isTablet && (
                <Box>
                  <Text size="1" color="gray" style={{ display: 'block', lineHeight: 1.2 }}>{getGreeting()}</Text>
                  <Text size="2" weight="medium" style={{ display: 'block', lineHeight: 1.2, maxWidth: 110, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                    {userName}
                  </Text>
                </Box>
              )}
            </Box>
          </DropdownMenu.Trigger>
          <DropdownMenu.Content align="end" style={{ minWidth: 220 }}>
            {/* User info card */}
            <Card style={{ margin: '2px 2px 4px', padding: 0 }}>
              <Flex align="center" gap="3" px="3" py="3">
                <Box style={{ position: 'relative', flexShrink: 0 }}>
                  <Avatar src={avatarSrc} fallback={userName.charAt(0).toUpperCase()} size="3" radius="full" />
                  <Box style={{
                    position: 'absolute', bottom: 1, right: 1,
                    width: 9, height: 9, borderRadius: '50%',
                    background: 'var(--green-9)', border: '2px solid var(--color-panel-solid)',
                  }} />
                </Box>
                <Box style={{ minWidth: 0 }}>
                  <Text size="2" weight="bold" style={{ display: 'block', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{userName}</Text>
                  <Text size="1" color="gray" style={{ display: 'block', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{userDesignation}</Text>
                  {auth?.user?.email && (
                    <Text size="1" color="gray" style={{ display: 'block', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', maxWidth: 160 }}>{auth.user.email}</Text>
                  )}
                </Box>
              </Flex>
            </Card>
            <DropdownMenu.Item asChild>
              <Link href={auth?.user?.id ? route('profile', { user: auth.user.id }) : '#'}>
                <PersonIcon style={{ marginRight: 8 }} /> Profile
              </Link>
            </DropdownMenu.Item>
            <DropdownMenu.Item asChild>
              <Link href={route('dashboard')}>
                <DashboardIcon style={{ marginRight: 8 }} /> Dashboard
              </Link>
            </DropdownMenu.Item>
            <DropdownMenu.Item onClick={toggleThemeDrawer}>
              <MixerHorizontalIcon style={{ marginRight: 8 }} /> Theme Settings
            </DropdownMenu.Item>
            <Separator size="4" my="1" />
            <DropdownMenu.Item color="red" onClick={handleLogout}>
              <ExitIcon style={{ marginRight: 8 }} /> Sign out
            </DropdownMenu.Item>
          </DropdownMenu.Content>
        </DropdownMenu.Root>

      </Flex>
    </Card>
  );
});

Header.displayName = 'Header';
export default Header;
