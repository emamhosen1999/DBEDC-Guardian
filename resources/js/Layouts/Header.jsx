import React, { useState, useCallback, useEffect, useRef } from 'react';
import { Link, usePage, router } from "@inertiajs/react";
import { useMediaQuery } from '@/Hooks/useMediaQuery.js';
import { Avatar, Badge, Box, DropdownMenu, Flex, Text, Tooltip } from '@radix-ui/themes';
import {
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
import logo from '../../../public/assets/images/logo.png';
import { pageLabelFromComponent } from '@/utils/pageLabel.js';

const isMac = typeof navigator !== 'undefined' && /mac/i.test(navigator.platform);

/* Brand: first word at full strength, the rest at half opacity (Cyber "CYBER ADMIN"). */
function BrandName({ name }) {
  const [first, ...rest] = String(name || 'DBEDC Guardian').split(' ');
  return (
    <span className="dl-header__logo-text">
      {first}
      {rest.length > 0 && <> <span className="dl-header__logo-muted">{rest.join(' ')}</span></>}
    </span>
  );
}

function MenuToggler({ sideBarOpen, onClick }) {
  return (
    <button
      type="button"
      className="dl-header__toggler"
      onClick={onClick}
      aria-label={sideBarOpen ? 'Collapse navigation' : 'Expand navigation'}
      aria-expanded={!!sideBarOpen}
      aria-controls="app-sidebar"
    >
      <span className="dl-header__toggler-bar" />
      <span className="dl-header__toggler-bar" />
      <span className="dl-header__toggler-bar" />
    </button>
  );
}

const Header = React.memo(({ toggleSideBar, sideBarOpen, toggleThemeDrawer }) => {
  const { props: { auth, app, title }, component } = usePage();
  const { settings, toggleAppearance } = useRadixTheme();
  const isMobile  = useMediaQuery('(max-width: 640px)');
  const isTablet  = useMediaQuery('(max-width: 1024px)');
  const [searchOpen, setSearchOpen] = useState(false);
  const [searchQuery, setSearchQuery] = useState('');
  const searchInputRef = useRef(null);

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
      searchInputRef.current?.blur();
    }
  }, [searchQuery]);

  const openSearch = useCallback(() => {
    setSearchOpen(true);
    requestAnimationFrame(() => searchInputRef.current?.focus());
  }, []);

  const closeSearch = useCallback(() => {
    setSearchOpen(false);
    setSearchQuery('');
    searchInputRef.current?.blur();
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

  const userName        = auth?.user?.name || auth?.user?.first_name || 'Employee';
  const userDesignation = auth?.user?.designation?.title || 'Team Member';
  const avatarSrc       = auth?.user?.profile_image_url || auth?.user?.profile_image;
  const pageTitle       = title || pageLabelFromComponent(component) || app?.name || 'Dashboard';
  const shortcut        = isMac ? '⌘K' : 'Ctrl K';

  // ── Mobile full-width search row ─────────────────────────────────────────
  if (isMobile && searchOpen) {
    return (
      <header className="dl-header dl-header--search">
        <form onSubmit={handleSearchSubmit} role="search">
          <div className="dl-header__search">
            <MagnifyingGlassIcon className="dl-header__search-icon" aria-hidden="true" />
            <input
              ref={searchInputRef}
              className="dl-header__search-input"
              type="search"
              placeholder="Search anything…"
              aria-label="Search Guardian"
              value={searchQuery}
              onChange={e => setSearchQuery(e.target.value)}
              autoFocus
            />
          </div>
          <button type="button" className="dl-header__icon-btn" onClick={closeSearch} aria-label="Close search">
            <Cross1Icon />
          </button>
        </form>
      </header>
    );
  }

  return (
    <header className="dl-header">
      {/* ── Brand: sidebar toggle + logo ─────────────────────────────────── */}
      <div className="dl-header__brand" style={{ WebkitAppRegion: 'no-drag' }}>
        <MenuToggler sideBarOpen={sideBarOpen} onClick={toggleSideBar} />
        <Link href={route('dashboard')} className="dl-header__logo" aria-label={`${app?.name || 'DBEDC Guardian'} dashboard`}>
          <img src={logo} alt="" onError={e => { e.currentTarget.style.display = 'none'; }} />
          <BrandName name={app?.name} />
        </Link>
      </div>

      <div className="dl-header__menu">
        {/* ── Page title (Cyber's header menu slot, ≥1200px) ────────────── */}
        {!isMobile && (
          <div className="dl-header__item dl-header__item--title">
            <span className="dl-header__title">{pageTitle}</span>
          </div>
        )}

        {/* ── HUD filler ─────────────────────────────────────────────────── */}
        <div className="dl-header__item dl-header__item--grow" aria-hidden="true">
          <div className="dl-hud-line--lg" style={{ width: '100%', height: '100%' }} />
        </div>

        {/* ── Action icons ───────────────────────────────────────────────── */}
        <div className="dl-header__item dl-header__icons" style={{ WebkitAppRegion: 'no-drag' }}>

          {/* Mobile: search icon */}
          {isMobile && (
            <button type="button" className="dl-header__icon-btn" onClick={openSearch} aria-label="Search">
              <MagnifyingGlassIcon />
            </button>
          )}

          {/* Appearance toggle — hidden on mobile */}
          {!isMobile && (
            <Tooltip content={settings.appearance === 'dark' ? 'Light mode' : 'Dark mode'} delayDuration={600}>
              <button type="button" className="dl-header__icon-btn" onClick={toggleAppearance} aria-label="Toggle appearance">
                {settings.appearance === 'dark' ? <SunIcon /> : <MoonIcon />}
              </button>
            </Tooltip>
          )}

          {/* Language switcher — hidden on small tablet */}
          {!isTablet && <LanguageSwitcher />}

          {/* Notifications */}
          <DropdownMenu.Root>
            <DropdownMenu.Trigger>
              <button
                type="button"
                className="dl-header__icon-btn"
                aria-label={unreadCount > 0 ? `Notifications, ${unreadCount} unread` : 'Notifications'}
              >
                <BellIcon />
                {unreadCount > 0 && (
                  <span className="dl-header__count" aria-hidden="true">
                    {unreadCount > 9 ? '9+' : unreadCount}
                  </span>
                )}
              </button>
            </DropdownMenu.Trigger>
            <DropdownMenu.Content align="end" sideOffset={6} style={{ minWidth: 300, maxWidth: 'min(340px, calc(100vw - 16px))' }}>
              <Flex align="center" justify="between" px="3" py="2">
                <Text size="2" weight="bold" style={{ textTransform: 'uppercase' }}>Notifications</Text>
                {unreadCount > 0 && <Badge color="red" variant="soft" size="1">{unreadCount} unread</Badge>}
              </Flex>
              <DropdownMenu.Separator />
              {Array.isArray(notifications) && notifications.length > 0 ? (
                <>
                  {notifications.slice(0, 6).map(n => (
                    <DropdownMenu.Item
                      key={n.id}
                      style={{ opacity: n.read_at ? 0.62 : 1, height: 'auto', paddingBlock: 6 }}
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
                  <DropdownMenu.Separator />
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
                  <BellIcon style={{ width: 20, height: 20, color: 'var(--gray-a9)' }} />
                  <Text size="2" color="gray">All caught up</Text>
                </Flex>
              )}
              <DropdownMenu.Separator />
              <DropdownMenu.Item asChild>
                <Link href={route('settings.notifications')} style={{ justifyContent: 'center' }}>
                  <Text size="2" color="gray">Notification settings</Text>
                </Link>
              </DropdownMenu.Item>
            </DropdownMenu.Content>
          </DropdownMenu.Root>

        </div>

        {/* ── Search (desktop: inline field; Ctrl/⌘K focuses it) ─────────── */}
        {!isMobile && (
          <form className="dl-header__item dl-header__search" onSubmit={handleSearchSubmit} role="search" style={{ WebkitAppRegion: 'no-drag' }}>
            <MagnifyingGlassIcon className="dl-header__search-icon" aria-hidden="true" />
            <input
              ref={searchInputRef}
              className="dl-header__search-input"
              type="search"
              placeholder="Search"
              aria-label={`Search Guardian (${shortcut})`}
              aria-keyshortcuts={isMac ? 'Meta+K' : 'Control+K'}
              value={searchQuery}
              onFocus={() => setSearchOpen(true)}
              onBlur={() => { if (!searchQuery) setSearchOpen(false); }}
              onChange={e => setSearchQuery(e.target.value)}
            />
            {!isTablet && <kbd className="dl-header__search-kbd" aria-hidden="true">{shortcut}</kbd>}
          </form>
        )}

        <div className="dl-header__item dl-header__icons" style={{ WebkitAppRegion: 'no-drag' }}>
          {/* User menu */}
          <DropdownMenu.Root>
            <DropdownMenu.Trigger>
              <button type="button" className="dl-header__icon-btn" aria-label={`Account menu for ${userName}`}>
                {avatarSrc
                  ? <img className="dl-header__avatar" src={avatarSrc} alt="" />
                  : <PersonIcon />}
                {!isTablet && <span>{userName}</span>}
              </button>
            </DropdownMenu.Trigger>
            <DropdownMenu.Content align="end" sideOffset={6} style={{ minWidth: 220 }}>
              {/* Employee info */}
              <Box style={{ margin: '2px 2px 4px', padding: 0, background: 'var(--aero-surface, var(--gray-a2))' }}>
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
                    <Text size="2" weight="bold" style={{ display: 'block', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', textTransform: 'uppercase' }}>{userName}</Text>
                    <Text size="1" color="gray" style={{ display: 'block', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{userDesignation}</Text>
                    {auth?.user?.email && (
                      <Text size="1" color="gray" style={{ display: 'block', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', maxWidth: 160 }}>{auth.user.email}</Text>
                    )}
                  </Box>
                </Flex>
              </Box>
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
              <DropdownMenu.Separator />
              <DropdownMenu.Item color="red" onClick={handleLogout}>
                <ExitIcon style={{ marginRight: 8 }} /> Sign out
              </DropdownMenu.Item>
            </DropdownMenu.Content>
          </DropdownMenu.Root>

          {/* Theme drawer (Cyber's gear, last) — hidden on mobile */}
          {!isMobile && (
            <Tooltip content="Customize theme" delayDuration={600}>
              <button type="button" className="dl-header__icon-btn" onClick={toggleThemeDrawer} aria-label="Theme settings">
                <MixerHorizontalIcon />
              </button>
            </Tooltip>
          )}
        </div>
      </div>
    </header>
  );
});

Header.displayName = 'Header';
export default Header;
