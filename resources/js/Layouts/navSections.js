import { isNavRouteActive } from '@/utils/navRoute.js';

/*
 * Presentation helpers for the Cyber sidebar. Props/pages.jsx stays the nav
 * model (items, permission gating, routes); these only decide how it is laid
 * out: top-level leaves under "Navigation", every top-level group as its own
 * section header with its children as items.
 */

export const NAVIGATION_SECTION = 'Navigation';
export const SETTINGS_SECTION = 'Settings';

const hasChildren = (page) => Array.isArray(page?.subMenu) && page.subMenu.length > 0;

export function buildNavSections(pages = []) {
    const navigation = [];
    const settings = [];
    const groups = [];
    pages.forEach((page) => {
        if (hasChildren(page)) {
            groups.push({ key: page.name, label: page.name, items: page.subMenu });
        } else if (page.category === 'settings') {
            settings.push(page);
        } else if (page.route) {
            navigation.push(page);
        }
    });
    const sections = [];
    if (navigation.length) sections.push({ key: NAVIGATION_SECTION, label: NAVIGATION_SECTION, items: navigation });
    sections.push(...groups);
    if (settings.length) sections.push({ key: SETTINGS_SECTION, label: SETTINGS_SECTION, items: settings });
    return sections.filter((section) => section.items.length > 0);
}

/* Keep items whose name matches; a matching group keeps all its children. */
export function filterNavPages(pages = [], term = '') {
    const needle = term.trim().toLowerCase();
    if (!needle) return pages;
    return pages.reduce((acc, page) => {
        const matches = page.name.toLowerCase().includes(needle);
        if (hasChildren(page)) {
            const children = matches ? page.subMenu : filterNavPages(page.subMenu, term);
            if (children.length > 0) acc.push({ ...page, subMenu: children });
        } else if (matches) {
            acc.push(page);
        }
        return acc;
    }, []);
}

/* Expandable item paths per depth, matching the paths NavItem builds. */
export function collectGroupPaths(sections = []) {
    const byDepth = {};
    const walk = (items, prefix, depth) => {
        items.forEach((item) => {
            if (!hasChildren(item)) return;
            const path = `${prefix}/${item.name}`;
            (byDepth[depth] ||= []).push(path);
            walk(item.subMenu, path, depth + 1);
        });
    };
    sections.forEach((section) => walk(section.items, section.key, 0));
    return byDepth;
}

export function isGroupActive(page, url) {
    if (!page) return false;
    if (page.route && isNavRouteActive(url, page.route)) return true;
    return hasChildren(page) && page.subMenu.some((child) => isGroupActive(child, url));
}
