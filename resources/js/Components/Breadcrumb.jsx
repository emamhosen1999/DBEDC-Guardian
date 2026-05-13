import React from 'react';
import { Flex, Text } from '@radix-ui/themes';
import { HomeIcon } from '@radix-ui/react-icons';
import { Link, usePage } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { getPages } from '@/Props/pages.jsx';

const Breadcrumb = () => {
    const { props, url } = usePage();
    const { title, auth } = props;
    
    // Get permissions
    const permissions = auth?.permissions || [];
    const roles = auth?.roles || [];
    
    // Get the pages data
    const pages = getPages(roles, permissions, auth);
    
    // Function to find a page by route name in nested structure
    const findPageByRoute = (pages, routeName) => {
        for (const page of pages) {
            // Check if this page matches
            if (page.route === routeName) {
                return page;
            }
            // Check subMenu if it exists
            if (page.subMenu) {
                for (const subPage of page.subMenu) {
                    if (subPage.route === routeName) {
                        return { parent: page, page: subPage };
                    }
                    // Check nested subMenu
                    if (subPage.subMenu) {
                        for (const nestedPage of subPage.subMenu) {
                            if (nestedPage.route === routeName) {
                                return { parent: page, subParent: subPage, page: nestedPage };
                            }
                        }
                    }
                }
            }
        }
        return null;
    };
    
    // Generate breadcrumb items based on current route
    const generateBreadcrumbs = () => {
        const breadcrumbs = [];
        let currentRoute;
        
        try {
            currentRoute = route().current();
        } catch (error) {
            console.warn('Route function not available:', error);
            currentRoute = null;
        }
        
        // Always add Home breadcrumb first
        breadcrumbs.push({
            label: "Home",
            icon: <HomeIcon style={{ width: 14, height: 14 }} />,
            href: (() => {
                try {
                    return route('dashboard');
                } catch {
                    return '/';
                }
            })(),
            key: 'home'
        });
        
        if (!currentRoute) {
            // Fallback if no route found
            breadcrumbs.push({
                label: title || 'Current Page',
                icon: null,
                href: null,
                key: 'current'
            });
            return breadcrumbs;
        }
        
        // Find the current page in the pages data
        const pageData = findPageByRoute(pages, currentRoute);
        
        if (pageData) {
            if (pageData.parent && pageData.subParent) {
                // Three-level deep: Parent > SubParent > Current
                breadcrumbs.push({
                    label: pageData.parent.name,
                    icon: React.cloneElement(pageData.parent.icon, { style: { width: 14, height: 14 } }),
                    href: pageData.parent.route ? (() => {
                        try {
                            return route(pageData.parent.route);
                        } catch {
                            return null;
                        }
                    })() : null,
                    key: 'parent'
                });
                breadcrumbs.push({
                    label: pageData.subParent.name,
                    icon: React.cloneElement(pageData.subParent.icon, { style: { width: 14, height: 14 } }),
                    href: pageData.subParent.route ? (() => {
                        try {
                            return route(pageData.subParent.route);
                        } catch {
                            return null;
                        }
                    })() : null,
                    key: 'subparent'
                });
                breadcrumbs.push({
                    label: pageData.page.name,
                    icon: React.cloneElement(pageData.page.icon, { style: { width: 14, height: 14 } }),
                    href: null, // Current page
                    key: 'current'
                });
            } else if (pageData.parent) {
                // Two-level deep: Parent > Current
                breadcrumbs.push({
                    label: pageData.parent.name,
                    icon: React.cloneElement(pageData.parent.icon, { style: { width: 14, height: 14 } }),
                    href: pageData.parent.route ? (() => {
                        try {
                            return route(pageData.parent.route);
                        } catch {
                            return null;
                        }
                    })() : null,
                    key: 'parent'
                });
                breadcrumbs.push({
                    label: pageData.page.name,
                    icon: React.cloneElement(pageData.page.icon, { style: { width: 14, height: 14 } }),
                    href: null, // Current page
                    key: 'current'
                });
            } else {
                // Top-level page
                breadcrumbs.push({
                    label: pageData.name,
                    icon: React.cloneElement(pageData.icon, { style: { width: 14, height: 14 } }),
                    href: null, // Current page
                    key: 'current'
                });
            }
        } else {
            // Fallback if page not found in data
            breadcrumbs.push({
                label: title || 'Current Page',
                icon: null,
                href: null,
                key: 'current'
            });
        }
        
        return breadcrumbs;
    };

    const breadcrumbs = generateBreadcrumbs();

    return (
        <motion.nav
            initial={{ opacity: 0, y: -6 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.25 }}
            aria-label="Breadcrumb"
            style={{ padding: '6px 16px' }}
        >
            <Flex align="center" gap="1" wrap="wrap">
                {breadcrumbs.map((breadcrumb, idx) => (
                    <React.Fragment key={breadcrumb.key}>
                        {idx > 0 && (
                            <Text size="1" color="gray" style={{ userSelect: 'none' }}>/</Text>
                        )}
                        <Flex align="center" gap="1">
                            {breadcrumb.icon && (
                                <span style={{ display: 'flex', alignItems: 'center', color: breadcrumb.href ? 'var(--gray-10)' : 'var(--gray-12)', width: 14, height: 14 }}>
                                    {breadcrumb.icon}
                                </span>
                            )}
                            {breadcrumb.href ? (
                                <Link href={breadcrumb.href} style={{ textDecoration: 'none' }}>
                                    <Text size="2" color="gray" style={{ transition: 'color 120ms' }}
                                        onMouseEnter={e => e.target.style.color = 'var(--accent-11)'}
                                        onMouseLeave={e => e.target.style.color = ''}>
                                        {breadcrumb.label}
                                    </Text>
                                </Link>
                            ) : (
                                <Text size="2" weight="medium">{breadcrumb.label}</Text>
                            )}
                        </Flex>
                    </React.Fragment>
                ))}
            </Flex>
        </motion.nav>
    );
};

export default Breadcrumb;
