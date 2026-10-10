// @vitest-environment jsdom
import React from 'react';
import { createRoot } from 'react-dom/client';
import { act } from 'react-dom/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    usePage: () => ({ props: { app: { name: 'DBEDC', version: '4.0.9' } } }),
}));

const { default: InstallApp, formatApkSize, formatReleased } = await import('../InstallApp.jsx');

let root;
let host;
afterEach(() => { act(() => root?.unmount()); host?.remove(); });
function mount(ui) {
    host = document.createElement('div');
    document.body.appendChild(host);
    root = createRoot(host);
    act(() => root.render(ui));
}

describe('InstallApp facts', () => {
    it('formats the served APK size and date, and invents nothing when unknown', () => {
        expect(formatApkSize(92668185)).toBe('88.4 MB');
        expect(formatApkSize(null)).toBeNull();
        expect(formatApkSize(0)).toBeNull();
        expect(formatReleased('2026-04-11')).toContain('2026');
        expect(formatReleased(null)).toBeNull();
        expect(formatReleased('garbage')).toBeNull();
    });

    it('shows no size / release line without an APK on disk', () => {
        mount(<InstallApp apk={null} />);
        expect(host.querySelector('.cy-soon__note')).toBeNull();
    });

    it('shows the real size and release date, the Android-only gate and the four steps', () => {
        mount(<InstallApp apk={{ size_bytes: 92668185, released_at: '2026-04-11' }} />);
        expect(host.querySelector('.cy-soon__note').textContent).toContain('88.4 MB');
        // jsdom is not Android: the download is disabled and the requirement is announced
        expect(host.querySelector('[role="note"]').textContent).toContain('Android device required');
        expect(host.querySelector('.cy-soon__actions button').disabled).toBe(true);
        expect(host.querySelectorAll('.cy-accordion__item')).toHaveLength(4);
        expect(host.querySelector('footer.dl-footer')).not.toBeNull();
    });
});
