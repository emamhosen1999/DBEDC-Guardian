import React, { useEffect, useMemo, useState, useCallback } from 'react';
import { Link, usePage, router } from "@inertiajs/react";
import { useMediaQuery } from '@/Hooks/useMediaQuery.js';
import { DropdownMenu } from '@radix-ui/themes';
import {
  MagnifyingGlassIcon,
  PersonIcon,
  DashboardIcon,
  ExitIcon,
} from '@radix-ui/react-icons';
import { isNavRouteActive } from '@/utils/navRoute.js';
import { buildNavSections, collectGroupPaths, filterNavPages, isGroupActive } from '@/Layouts/navSections.js';

/*
 * Cyber sidebar (seantheme.com/cyber, measured on the live site):
 * - every top-level group of Props/pages.jsx renders as a section header
 *   (.menu-header) with its children as top-level items; only a child that has
 *   children of its own is expandable (caret + submenu);
 * - one open item per depth (opening one closes the others), toggled
 *   instantly, submenu items slide in (appSidebarSubMenuSlideInRight .3s
 *   cubic-bezier(.7,0,.3,1), staggered 0/45/60/75… ms);
 * - items containing the active route are open on load without animation;
 *   the first click on such an item closes it.
 * Styles: resources/css/design/cyber/shell.css (.dl-nav*).
 */

const COMPACT_QUERY = '(max-width: 1199.98px)';

const highlightSearchMatch = (text, searchTerm) => {
  if (!searchTerm || !searchTerm.trim()) return text;
  const regex = new RegExp(`(${searchTerm.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})`, 'gi');
  const parts = text.split(regex);
  return parts.map((part, index) =>
    part.toLowerCase() === searchTerm.toLowerCase()
      ? <mark key={index} className="dl-nav-match">{part}</mark>
      : part
  );
};

const navIcon = (icon) => (icon ? React.cloneElement(icon, { 'aria-hidden': true, focusable: 'false' }) : null);

function NavItem({ page, path, depth, url, navState, onToggle, onNavigate, searchTerm, searching }) {
  const hasSub = Array.isArray(page.subMenu) && page.subMenu.length > 0;

  if (hasSub) {
    const active = isGroupActive(page, url);
    const state = navState[path];
    const open = searching || state === 'open' || (state !== 'closed' && active);
    const className = [
      'dl-nav-item',
      'dl-nav-item--has-sub',
      active ? 'dl-nav-item--active' : '',
      open ? 'dl-nav-item--open' : '',
      !searching && state === 'open' ? 'dl-nav-item--expand' : '',
    ].filter(Boolean).join(' ');
    return (
      <li className={className}>
        <button
          type="button"
          className="dl-nav-link"
          aria-expanded={open}
          onClick={() => onToggle(path, depth, open)}
        >
          {page.icon && <span className="dl-nav-icon">{navIcon(page.icon)}</span>}
          <span className="dl-nav-text">{highlightSearchMatch(page.name, searchTerm)}</span>
          <span className="dl-nav-caret" aria-hidden="true"><b className="dl-caret" /></span>
        </button>
        <ul className="dl-nav-sub">
          {page.subMenu.map((child) => (
            <NavItem
              key={child.name}
              page={child}
              path={`${path}/${child.name}`}
              depth={depth + 1}
              url={url}
              navState={navState}
              onToggle={onToggle}
              onNavigate={onNavigate}
              searchTerm={searchTerm}
              searching={searching}
            />
          ))}
        </ul>
      </li>
    );
  }

  if (!page.route) return null;
  const isActive = isNavRouteActive(url, page.route);
  return (
    <li className={`dl-nav-item${isActive ? ' dl-nav-item--active' : ''}`}>
      <Link
        href={route(page.route)}
        method={page.method}
        preserveState
        preserveScroll
        className="dl-nav-link"
        onClick={() => onNavigate(page.route)}
        aria-current={isActive ? 'page' : undefined}
      >
        {page.icon && <span className="dl-nav-icon">{navIcon(page.icon)}</span>}
        <span className="dl-nav-text">{highlightSearchMatch(page.name, searchTerm)}</span>
      </Link>
    </li>
  );
}

/* Cyber .menu-header: title, then a hairline over a HUD stripe and three blocks. */
const SectionHeader = ({ id, label }) => (
  <>
    <div className="dl-nav-header">
      <h2 className="dl-nav-header__title" id={id}>{label}</h2>
      <div className="dl-nav-header__deco" aria-hidden="true">
        <div className="dl-nav-header__line" />
        <div className="dl-nav-header__row">
          <div className="dl-hud-line dl-nav-header__hud" />
          <div className="dl-nav-header__block" />
          <div className="dl-nav-header__block" />
          <div className="dl-nav-header__block" />
        </div>
      </div>
    </div>
    <div className="dl-nav-rule" aria-hidden="true" />
  </>
);

const Sidebar = React.memo(({ toggleSideBar, pages, url }) => {
  const isCompact = useMediaQuery(COMPACT_QUERY);
  const { auth, app } = usePage().props;

  const [activePage, setActivePage] = useState(url);
  const [searchTerm, setSearchTerm] = useState('');
  // path -> 'open' | 'closed'; unset items follow the active route (Cyber reload semantics).
  const [navState, setNavState] = useState({});

  const searching = searchTerm.trim().length > 0;
  const visiblePages = useMemo(
    () => (searching ? filterNavPages(pages, searchTerm) : pages),
    [pages, searchTerm, searching],
  );
  const sections = useMemo(() => buildNavSections(visiblePages), [visiblePages]);
  const groupsByDepth = useMemo(() => collectGroupPaths(sections), [sections]);

  // A new route behaves like Cyber's page load: only groups on the active route stay open.
  useEffect(() => {
    setActivePage(url);
    setNavState({});
  }, [url]);

  const handleToggle = useCallback((path, depth, isOpen) => {
    setNavState((prev) => {
      const next = { ...prev };
      (groupsByDepth[depth] || []).forEach((p) => { if (p !== path) next[p] = 'closed'; });
      next[path] = isOpen ? 'closed' : 'open';
      return next;
    });
  }, [groupsByDepth]);

  const handlePageClick = useCallback((pageRoute) => {
    setActivePage('/' + pageRoute);
    setSearchTerm('');
    if (isCompact) toggleSideBar();
  }, [isCompact, toggleSideBar]);

  const handleLogout = useCallback(() => router.post(route('logout'), { preserveState: true, preserveScroll: true }), []);

  const userName = auth?.user?.name || auth?.user?.first_name || 'Employee';
  const userDesignation = auth?.user?.designation?.title || 'Team Member';
  const avatarSrc = auth?.user?.profile_image_url || auth?.user?.profile_image;

  return (
    <aside id="app-sidebar" className="dl-sidebar" aria-label="Main navigation">
      <div className="dl-sidebar__scroll">
        <div className="dl-sidebar__inner">
          <nav className="dl-nav" aria-label="Primary">

            {/* ── Profile (Cyber .menu-profile) ─────────────────────────────── */}
            <div className="dl-sidebar__profile-wrap">
              <DropdownMenu.Root>
                <DropdownMenu.Trigger>
                  <button type="button" className="dl-sidebar__profile" aria-label={`Account menu for ${userName}`}>
                    <span className="dl-sidebar__profile-image">
                      {avatarSrc ? <img src={avatarSrc} alt="" /> : <PersonIcon aria-hidden="true" />}
                    </span>
                    <span className="dl-sidebar__profile-info">
                      <span className="dl-sidebar__profile-row">
                        <span className="dl-sidebar__profile-name">Welcome back, {userName}</span>
                        <span className="dl-sidebar__profile-caret" aria-hidden="true"><b className="dl-caret" /></span>
                      </span>
                      <small className="dl-sidebar__profile-role">{userDesignation}</small>
                    </span>
                  </button>
                </DropdownMenu.Trigger>
                <DropdownMenu.Content align="start" style={{ minWidth: 200 }}>
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
                  <DropdownMenu.Separator />
                  <DropdownMenu.Item color="red" onClick={handleLogout}>
                    <ExitIcon style={{ marginRight: 8 }} /> Sign out
                  </DropdownMenu.Item>
                </DropdownMenu.Content>
              </DropdownMenu.Root>
            </div>

            {/* ── Search (Guardian addition, Cyber .menu-search look) ───────── */}
            <div className="dl-sidebar__search">
              <MagnifyingGlassIcon aria-hidden="true" />
              <input
                type="search"
                placeholder="Search navigation…"
                aria-label="Search navigation"
                value={searchTerm}
                onChange={e => setSearchTerm(e.target.value)}
              />
            </div>

            {/* ── Sections (Cyber .menu-header + .menu-item) ────────────────── */}
            {sections.map((section, i) => (
              <React.Fragment key={section.key}>
                <SectionHeader id={`dl-nav-section-${i}`} label={section.label} />
                <ul className="dl-nav-list" aria-labelledby={`dl-nav-section-${i}`}>
                  {section.items.map((page) => (
                    <NavItem
                      key={page.name}
                      page={page}
                      path={`${section.key}/${page.name}`}
                      depth={0}
                      url={activePage}
                      navState={navState}
                      onToggle={handleToggle}
                      onNavigate={handlePageClick}
                      searchTerm={searchTerm}
                      searching={searching}
                    />
                  ))}
                </ul>
              </React.Fragment>
            ))}

            {searching && sections.length === 0 && (
              <p className="dl-nav-empty" role="status">No results for "{searchTerm}"</p>
            )}
          </nav>

          {/* ── Status block (Cyber sidebar widgets) ───────────────────────── */}
          <div className="dl-sidebar__foot">
            <div className="dl-sidebar__stat">
              <span className="dl-sidebar__stat-label">Session</span>
              <span className="dl-sidebar__stat-value">online</span>
            </div>
            <div className="dl-sidebar__stat">
              <span className="dl-sidebar__stat-label">Version</span>
              <span className="dl-sidebar__stat-value">{app?.version || 'v4'}</span>
            </div>
          </div>
        </div>
      </div>
    </aside>
  );
});

Sidebar.displayName = 'Sidebar';
export default Sidebar;
