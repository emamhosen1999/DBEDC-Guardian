import { describe, expect, it } from 'vitest';
import { pageLabelFromComponent } from './pageLabel';

describe('pageLabelFromComponent', () => {
    it('uses the folder for Index pages and splits camel case', () => {
        expect(pageLabelFromComponent('Notifications/Index')).toBe('Notifications');
        expect(pageLabelFromComponent('Search/Index')).toBe('Search');
        expect(pageLabelFromComponent('Errors/Forbidden')).toBe('Forbidden');
        expect(pageLabelFromComponent('Settings/RequestLogs')).toBe('Request Logs');
        expect(pageLabelFromComponent('Dashboard')).toBe('Dashboard');
    });

    it('returns an empty string for missing input', () => {
        expect(pageLabelFromComponent(undefined)).toBe('');
        expect(pageLabelFromComponent('')).toBe('');
    });
});
