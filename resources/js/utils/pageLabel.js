/**
 * Human label for an Inertia page component, used when a page sends no
 * `title` prop: 'Notifications/Index' → 'Notifications', 'Errors/Forbidden'
 * → 'Forbidden', 'Settings/RequestLogs' → 'Request Logs'.
 */
export function pageLabelFromComponent(component) {
    if (!component || typeof component !== 'string') return '';
    const parts = component.split('/').filter(Boolean);
    let name = parts.pop() || '';
    if (name === 'Index' && parts.length) name = parts.pop();
    return name
        .replace(/([a-z0-9])([A-Z])/g, '$1 $2')
        .replace(/[-_]+/g, ' ')
        .trim();
}
