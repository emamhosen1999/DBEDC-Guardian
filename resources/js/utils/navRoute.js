/**
 * Resolve Ziggy route names to pathname for active nav highlighting.
 */
export function routePath(routeName) {
    if (!routeName || typeof route !== 'function') return null;
    try {
        const href = route(routeName);
        if (typeof href !== 'string') return null;
        if (href.startsWith('http')) {
            return new URL(href).pathname;
        }
        return href.startsWith('/') ? href : `/${href}`;
    } catch {
        return null;
    }
}

export function isNavRouteActive(currentPath, routeName) {
    const path = routePath(routeName);
    if (!path || !currentPath) return false;
    return currentPath === path || currentPath.startsWith(`${path}/`);
}
