/**
 * Resolve Ziggy route names to pathname for sidebar active-state matching.
 */
export function pathForRoute(routeName) {
    if (!routeName || typeof route !== 'function') {
        return null;
    }
    try {
        const href = route(routeName);
        if (typeof href !== 'string') {
            return null;
        }
        const path = href.startsWith('http') ? new URL(href).pathname : href;
        return path.split('?')[0];
    } catch {
        return null;
    }
}

export function isNavRouteActive(currentPath, routeName) {
    const path = pathForRoute(routeName);
    if (!path || !currentPath) {
        return false;
    }
    return currentPath === path || currentPath.startsWith(`${path}/`);
}
