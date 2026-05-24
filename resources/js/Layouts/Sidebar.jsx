import React, { useEffect, useState, useCallback } from 'react';
import { Link, usePage } from "@inertiajs/react";
import { useMediaQuery } from '@/Hooks/useMediaQuery.js';
import { useRadixTheme } from '@/Contexts/RadixThemeContext';
import {
  Avatar,
  Badge,
  Box,
  Flex,
  IconButton,
  ScrollArea,
  Separator,
  Text,
  TextField,
  Tooltip,
} from '@radix-ui/themes';
import {
  ChevronRightIcon,
  MagnifyingGlassIcon,
  HomeIcon,
  GearIcon,
} from '@radix-ui/react-icons';
import logo from '../../../public/assets/images/logo.png';
import { isNavRouteActive } from '@/utils/navRoute.js';

const highlightSearchMatch = (text, searchTerm) => {
  if (!searchTerm || !searchTerm.trim()) return text;
  const regex = new RegExp(`(${searchTerm.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})`, 'gi');
  const parts = text.split(regex);
  return parts.map((part, index) =>
    part.toLowerCase() === searchTerm.toLowerCase()
      ? <Text key={index} as="span" weight="bold" color="accent" style={{ background: 'var(--accent-a4)', borderRadius: 'var(--radius-1)', padding: '0 2px' }}>{part}</Text>
      : part
  );
};

const useSidebarState = () => {
  const [openSubMenus, setOpenSubMenus] = useState(() => {
    try {
      const stored = localStorage.getItem('sidebar_open_submenus');
      return stored ? new Set(JSON.parse(stored)) : new Set();
    } catch { return new Set(); }
  });

  const updateOpenSubMenus = useCallback((newSet) => {
    const valid = newSet instanceof Set ? newSet : new Set();
    setOpenSubMenus(valid);
    try { localStorage.setItem('sidebar_open_submenus', JSON.stringify([...valid])); }
    catch (e) { console.warn('sidebar localStorage error', e); }
  }, []);

  return { openSubMenus, setOpenSubMenus: updateOpenSubMenus };
};

// Left accent bar that marks the active nav item
const ActiveBar = () => (
  <Box style={{
    position: 'absolute',
    left: 0,
    top: '50%',
    transform: 'translateY(-50%)',
    width: 3,
    height: 18,
    background: 'var(--accent-9)',
    borderRadius: '0 3px 3px 0',
    flexShrink: 0,
  }} />
);

const NavItem = React.memo(({ page, level, activePage, openSubMenus, onToggle, onNavigate, searchTerm, collapsed, onExpandSidebar }) => {
  const isActive = !!(page.route && isNavRouteActive(activePage, page.route));
  const hasActiveChild = !!(page.subMenu?.some(s =>
    s.route ? isNavRouteActive(activePage, s.route) : s.subMenu?.some(n => isNavRouteActive(activePage, n.route))
  ));
  const isExpanded = openSubMenus.has(page.name);
  const indent = level * 14;

  // ── Collapsed icon-only mode (top-level only) ──────────────────────────────
  if (collapsed && level === 0) {
    if (page.subMenu) {
      return (
        <Tooltip content={page.name} side="right" delayDuration={80}>
          <Box style={{ position: 'relative', display: 'flex', justifyContent: 'center', marginBottom: 2 }}>
            <IconButton
              variant={hasActiveChild ? 'soft' : 'ghost'}
              color={hasActiveChild ? 'accent' : 'gray'}
              size="3"
              onClick={onExpandSidebar}
              aria-label={page.name}
              style={{ width: 36, height: 36, cursor: 'pointer', borderRadius: 'var(--radius-2)' }}
            >
              {page.icon && React.cloneElement(page.icon, { style: { width: 16, height: 16 } })}
            </IconButton>
            {hasActiveChild && (
              <Box style={{
                position: 'absolute', top: 4, right: 4,
                width: 5, height: 5, borderRadius: '50%',
                background: 'var(--accent-9)',
                border: '1.5px solid var(--color-panel-solid)',
              }} />
            )}
          </Box>
        </Tooltip>
      );
    }
    if (page.route) {
      return (
        <Tooltip content={page.name} side="right" delayDuration={80}>
          <Box style={{ position: 'relative', display: 'flex', justifyContent: 'center', marginBottom: 2 }}>
            {isActive && <ActiveBar />}
            <IconButton
              variant={isActive ? 'soft' : 'ghost'}
              color={isActive ? 'accent' : 'gray'}
              size="3"
              asChild
              aria-label={page.name}
              style={{ width: 36, height: 36, cursor: 'pointer', borderRadius: 'var(--radius-2)' }}
            >
              <Link href={route(page.route)} onClick={() => onNavigate(page.route)}>
                {page.icon && React.cloneElement(page.icon, { style: { width: 16, height: 16 } })}
              </Link>
            </IconButton>
          </Box>
        </Tooltip>
      );
    }
    return null;
  }

  // ── Group item (expanded mode) ─────────────────────────────────────────────
  if (page.subMenu) {
    return (
      <Box style={{ position: 'relative', marginBottom: 1 }}>
        {hasActiveChild && <ActiveBar />}
        <Box
          role="button"
          tabIndex={0}
          onClick={() => onToggle(page.name)}
          onKeyDown={e => (e.key === 'Enter' || e.key === ' ') && onToggle(page.name)}
          style={{
            display: 'flex', alignItems: 'center', gap: 8,
            paddingLeft: 10 + indent, paddingRight: 10, height: 34,
            borderRadius: 'var(--radius-2)', cursor: 'pointer',
            background: hasActiveChild ? 'var(--accent-a3)' : 'transparent',
            color: hasActiveChild ? 'var(--accent-11)' : 'var(--gray-11)',
            userSelect: 'none', outline: 'none',
            transition: 'background 100ms',
          }}
          onMouseEnter={e => { if (!hasActiveChild) e.currentTarget.style.background = 'var(--gray-a2)'; }}
          onMouseLeave={e => { if (!hasActiveChild) e.currentTarget.style.background = 'transparent'; }}
          onFocus={e => { if (!hasActiveChild) e.currentTarget.style.background = 'var(--gray-a2)'; }}
          onBlur={e => { if (!hasActiveChild) e.currentTarget.style.background = 'transparent'; }}
        >
          {page.icon && (
            <Box style={{ flexShrink: 0, opacity: hasActiveChild ? 1 : 0.6, display: 'flex', alignItems: 'center' }}>
              {React.cloneElement(page.icon, { style: { width: 15, height: 15 } })}
            </Box>
          )}
          <Text size="2" style={{ flex: 1, color: 'inherit', lineHeight: 1 }}>
            {highlightSearchMatch(page.name, searchTerm)}
          </Text>
          <Badge size="1" variant="soft" color={hasActiveChild ? 'accent' : 'gray'} radius="full"
            style={{ fontSize: 10, minWidth: 16, height: 16, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: '0 4px' }}>
            {page.subMenu.length}
          </Badge>
          <ChevronRightIcon style={{
            width: 12, height: 12, flexShrink: 0, opacity: 0.5,
            transform: isExpanded ? 'rotate(90deg)' : 'none',
            transition: 'transform 150ms ease',
          }} />
        </Box>
        <Box style={{
          display: 'grid',
          gridTemplateRows: isExpanded ? '1fr' : '0fr',
          transition: 'grid-template-rows 200ms ease',
          marginLeft: 12 + indent,
        }}>
          <Box style={{ overflow: 'hidden' }}>
            <Box style={{ paddingTop: 2, paddingBottom: 2, borderLeft: '1px solid var(--gray-a4)', paddingLeft: 6 }}>
              {page.subMenu.map(sub => (
                <NavItem key={sub.name} page={sub} level={level + 1} activePage={activePage}
                  openSubMenus={openSubMenus} onToggle={onToggle} onNavigate={onNavigate}
                  searchTerm={searchTerm} collapsed={false} onExpandSidebar={onExpandSidebar} />
              ))}
            </Box>
          </Box>
        </Box>
      </Box>
    );
  }

  // ── Leaf nav item (expanded mode) ──────────────────────────────────────────
  if (page.route) {
    return (
      <Box style={{ position: 'relative', marginBottom: 1 }}>
        {isActive && <ActiveBar />}
        <Link
          href={route(page.route)}
          method={page.method}
          preserveState
          preserveScroll
          style={{ textDecoration: 'none', display: 'block' }}
          onClick={() => onNavigate(page.route)}
        >
          <Box
            style={{
              display: 'flex', alignItems: 'center', gap: 8,
              paddingLeft: 10 + indent, paddingRight: 10, height: 34,
              borderRadius: 'var(--radius-2)', cursor: 'pointer',
              background: isActive ? 'var(--accent-a3)' : 'transparent',
              color: isActive ? 'var(--accent-11)' : 'var(--gray-11)',
              transition: 'background 100ms',
            }}
            onMouseEnter={e => { if (!isActive) e.currentTarget.style.background = 'var(--gray-a2)'; }}
            onMouseLeave={e => { if (!isActive) e.currentTarget.style.background = 'transparent'; }}
          >
            {page.icon && (
              <Box style={{ flexShrink: 0, opacity: isActive ? 1 : 0.6, display: 'flex', alignItems: 'center' }}>
                {React.cloneElement(page.icon, { style: { width: 15, height: 15 } })}
              </Box>
            )}
            <Text size="2" weight={isActive ? 'medium' : 'regular'} style={{ color: 'inherit' }}>
              {highlightSearchMatch(page.name, searchTerm)}
            </Text>
          </Box>
        </Link>
      </Box>
    );
  }

  return null;
});
NavItem.displayName = 'NavItem';

const SectionLabel = ({ icon, label, color = 'accent' }) => (
  <Flex align="center" gap="1" px="1" pt="2" pb="1" style={{ flexShrink: 0 }}>
    {React.cloneElement(icon, { style: { width: 10, height: 10, color: `var(--${color}-9)`, flexShrink: 0 } })}
    <Text size="1" weight="bold" style={{ textTransform: 'uppercase', letterSpacing: '0.08em', fontSize: 10, color: `var(--${color}-11)` }}>
      {label}
    </Text>
    <Box style={{ flex: 1, height: '1px', background: `var(--${color}-a5)`, marginLeft: 2 }} />
  </Flex>
);

const Sidebar = React.memo(({ toggleSideBar, pages, url, sideBarOpen }) => {
  const isMobile = useMediaQuery('(max-width: 768px)');
  const { auth, app } = usePage().props;
  const { settings } = useRadixTheme();
  const collapsed = !isMobile && !sideBarOpen;
  const panelBgVar = settings.panelBackground === 'translucent' ? 'var(--color-panel-translucent)' : 'var(--color-panel-solid)';

  const { openSubMenus, setOpenSubMenus: updateOpenSubMenus } = useSidebarState();
  const [activePage, setActivePage] = useState(url);
  const [searchTerm, setSearchTerm] = useState('');

  const filterPagesRecursively = useCallback((pagesList, term) => {
    const lower = term.toLowerCase();
    return pagesList.reduce((acc, page) => {
      const matches = page.name.toLowerCase().includes(lower);
      if (page.subMenu) {
        const filteredSub = filterPagesRecursively(page.subMenu, term);
        if (matches || filteredSub.length > 0) acc.push({ ...page, subMenu: filteredSub });
      } else if (matches) {
        acc.push(page);
      }
      return acc;
    }, []);
  }, []);

  const groupedPages = (() => {
    const allPages = searchTerm.trim() ? filterPagesRecursively(pages, searchTerm) : pages;
    return {
      mainPages: allPages.filter(p => !p.category || p.category === 'main'),
      settingsPages: allPages.filter(p => p.category === 'settings'),
    };
  })();

  useEffect(() => {
    if (!searchTerm.trim()) return;
    const expand = (list, set = new Set()) => {
      list.forEach(p => {
        if (p.subMenu) {
          const has = p.subMenu.some(s => s.name.toLowerCase().includes(searchTerm.toLowerCase()) || s.subMenu);
          if (has) { set.add(p.name); expand(p.subMenu, set); }
        }
      });
      return set;
    };
    updateOpenSubMenus(expand(pages));
  }, [searchTerm, pages]);

  useEffect(() => {
    setActivePage(url);
    const expandParents = (items, target, parents = []) => {
      for (const p of items) {
        const crumbs = [...parents, p.name];
        if (p.route && '/' + p.route === target) {
          updateOpenSubMenus(new Set([...openSubMenus, ...crumbs.slice(0, -1)]));
          return true;
        }
        if (p.subMenu && expandParents(p.subMenu, target, crumbs)) return true;
      }
      return false;
    };
    expandParents(pages, url);
  }, [url, pages]);

  const handleSubMenuToggle = useCallback((name) => {
    const next = new Set(openSubMenus);
    next.has(name) ? next.delete(name) : next.add(name);
    updateOpenSubMenus(next);
  }, [openSubMenus, updateOpenSubMenus]);

  const handlePageClick = useCallback((pageRoute) => {
    setActivePage('/' + pageRoute);
    setSearchTerm('');
    if (isMobile) toggleSideBar();
  }, [isMobile, toggleSideBar]);

  const userName = auth?.user?.name || auth?.user?.first_name || 'User';
  const userDesignation = auth?.user?.designation?.title || 'Team Member';
  const avatarSrc = auth?.user?.profile_image_url || auth?.user?.profile_image;

  const renderNavList = (list, isCollapsed) =>
    list.map(page => (
      <NavItem
        key={page.name}
        page={page}
        level={0}
        activePage={activePage}
        openSubMenus={openSubMenus}
        onToggle={handleSubMenuToggle}
        onNavigate={handlePageClick}
        searchTerm={isCollapsed ? '' : searchTerm}
        collapsed={isCollapsed}
        onExpandSidebar={toggleSideBar}
      />
    ));

  return (
    <Box
      style={{
        width: isMobile ? 260 : (collapsed ? 56 : 240),
        height: '100vh',
        display: 'flex',
        flexDirection: 'column',
        background: panelBgVar,
        borderRight: '1px solid var(--gray-a4)',
        flexShrink: 0,
        overflow: 'hidden',
      }}>

      {/* ── Brand ───────────────────────────────────────────────────────────── */}
      <Flex
        align="center"
        justify={collapsed ? 'center' : 'start'}
        gap="3"
        px={collapsed ? '0' : '3'}
        style={{ height: 56, borderBottom: '1px solid var(--gray-a4)', flexShrink: 0, paddingInline: collapsed ? 0 : undefined }}
      >
        <Box style={{ width: 30, height: 30, flexShrink: 0, overflow: 'hidden', borderRadius: 'var(--radius-2)', border: '1px solid var(--gray-a5)' }}>
          <img src={logo} alt={app?.name || 'Logo'} style={{ width: '100%', height: '100%', objectFit: 'contain' }} />
        </Box>
        {!collapsed && (
          <Box style={{ minWidth: 0, flex: 1 }}>
            <Text size="2" weight="bold" truncate style={{ display: 'block', color: 'var(--accent-11)' }}>{app?.name || 'Enterprise'}</Text>
            <Text size="1" color="gray" style={{ display: 'block' }}>DBEDC Guardian</Text>
          </Box>
        )}
      </Flex>

      {/* ── Search (expanded only) ───────────────────────────────────────────── */}
      {!collapsed && (
        <Box px="2" py="2" style={{ flexShrink: 0 }}>
          <TextField.Root
            size="1"
            placeholder="Search navigation…"
            value={searchTerm}
            onChange={e => setSearchTerm(e.target.value)}
          >
            <TextField.Slot>
              <MagnifyingGlassIcon style={{ width: 12, height: 12 }} />
            </TextField.Slot>
          </TextField.Root>
        </Box>
      )}

      {/* ── Navigation ──────────────────────────────────────────────────────── */}
      <ScrollArea style={{ flex: 1 }}>
        <Flex direction="column" px={collapsed ? '1' : '2'} pb="3" pt="1" style={{ gap: 0 }}>

          {collapsed ? (
            /* Icon-only layout */
            <>
              {renderNavList(groupedPages.mainPages, true)}
              {groupedPages.settingsPages.length > 0 && (
                <>
                  <Separator size="4" my="1" />
                  {renderNavList(groupedPages.settingsPages, true)}
                </>
              )}
              {groupedPages.mainPages.length === 0 && groupedPages.settingsPages.length === 0 &&
                renderNavList(pages, true)}
            </>
          ) : (
            /* Full expanded layout */
            <>
              {groupedPages.mainPages.length > 0 && (
                <>
                  <SectionLabel icon={<HomeIcon />} label="Main" color="accent" />
                  {renderNavList(groupedPages.mainPages, false)}
                </>
              )}
              {groupedPages.settingsPages.length > 0 && (
                <>
                  <Separator size="4" my="2" />
                  <SectionLabel icon={<GearIcon />} label="Admin" color="amber" />
                  {renderNavList(groupedPages.settingsPages, false)}
                </>
              )}
              {groupedPages.mainPages.length === 0 && groupedPages.settingsPages.length === 0 && !searchTerm.trim() &&
                renderNavList(pages, false)}
              {searchTerm.trim() && groupedPages.mainPages.length === 0 && groupedPages.settingsPages.length === 0 && (
                <Flex direction="column" align="center" gap="1" py="6">
                  <MagnifyingGlassIcon style={{ width: 20, height: 20, color: 'var(--gray-7)' }} />
                  <Text size="2" color="gray">No results for "{searchTerm}"</Text>
                </Flex>
              )}
            </>
          )}
        </Flex>
      </ScrollArea>

      {/* ── User footer ─────────────────────────────────────────────────────── */}
      <Box style={{ borderTop: '1px solid var(--gray-a4)', flexShrink: 0, padding: collapsed ? '8px 0' : '8px 12px' }}>
        {collapsed ? (
          <Tooltip content={`${userName} · ${userDesignation}`} side="right" delayDuration={80}>
            <Flex justify="center" style={{ cursor: 'default' }}>
              <Box style={{ position: 'relative' }}>
                <Avatar src={avatarSrc} fallback={userName.charAt(0).toUpperCase()} size="2" radius="full" />
                <Box style={{
                  position: 'absolute', bottom: 0, right: 0,
                  width: 8, height: 8, borderRadius: '50%',
                  background: 'var(--green-9)', border: '2px solid var(--color-panel-solid)',
                }} />
              </Box>
            </Flex>
          </Tooltip>
        ) : (
          <Flex align="center" gap="2">
            <Box style={{ position: 'relative', flexShrink: 0 }}>
              <Avatar src={avatarSrc} fallback={userName.charAt(0).toUpperCase()} size="2" radius="full" />
              <Box style={{
                position: 'absolute', bottom: 0, right: 0,
                width: 8, height: 8, borderRadius: '50%',
                background: 'var(--green-9)', border: '2px solid var(--color-panel-solid)',
              }} />
            </Box>
            <Box style={{ minWidth: 0, flex: 1 }}>
              <Text size="2" weight="medium" truncate style={{ display: 'block' }}>{userName}</Text>
              <Text size="1" color="gray" truncate style={{ display: 'block' }}>{userDesignation}</Text>
            </Box>
            <Text size="1" color="gray" style={{ flexShrink: 0 }}>{app?.version || 'v4'}</Text>
          </Flex>
        )}
      </Box>

    </Box>
  );
});

Sidebar.displayName = 'Sidebar';
export default Sidebar;
