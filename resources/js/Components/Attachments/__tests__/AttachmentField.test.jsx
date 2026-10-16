// @vitest-environment jsdom
import React from 'react';
import { createRoot } from 'react-dom/client';
import { act } from 'react-dom/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';

const stageFile = vi.fn();
const discardStaged = vi.fn(() => Promise.resolve({}));
const precheck = vi.fn();
vi.mock('../attachmentsApi', () => ({ stageFile: (...a) => stageFile(...a), discardStaged: (...a) => discardStaged(...a), scanPhoto: vi.fn(), adjustScan: vi.fn(), finalizeScan: vi.fn() }));
vi.mock('../documentPrecheck', () => ({ precheckImageFile: (...a) => precheck(...a) }));

const { default: AttachmentField } = await import('../AttachmentField.jsx');

let root; let host;
afterEach(() => { act(() => root?.unmount()); host?.remove(); vi.clearAllMocks(); });

function Harness({ initial = [], ...props }) {
    const [value, setValue] = React.useState(initial);
    return <AttachmentField value={value} onChange={setValue} {...props} />;
}
async function mount(ui) {
    host = document.createElement('div'); document.body.appendChild(host); root = createRoot(host);
    await act(async () => { root.render(ui); });
}
async function choose(file) {
    const input = host.querySelector('input[type=file]');
    Object.defineProperty(input, 'files', { value: [file], configurable: true });
    await act(async () => { input.dispatchEvent(new Event('change', { bubbles: true })); });
    await act(async () => { await new Promise((r) => setTimeout(r, 0)); });
}

describe('AttachmentField', () => {
    it('uploads a PDF straight away and lists it as clear with a Remove button', async () => {
        stageFile.mockResolvedValue({ token: 't1', name: 'cert.pdf', mime_type: 'application/pdf', preview_url: null, pages: 2, passed: true });
        await mount(<Harness required />);
        expect(host.textContent).toContain('A clear photo or PDF is required.');
        await choose(new File(['%PDF'], 'cert.pdf', { type: 'application/pdf' }));
        expect(stageFile).toHaveBeenCalledTimes(1);
        expect(host.textContent).toContain('cert.pdf');
        expect(host.textContent).toContain('Clear');
        const remove = [...host.querySelectorAll('button')].find((b) => b.textContent === 'Remove');
        await act(async () => { remove.click(); });
        expect(discardStaged).toHaveBeenCalledWith('t1');
        expect(host.querySelector('.cy-docs')).toBeNull();
    });

    it('shows the server-style guidance BEFORE upload for an unclear photo and blocks "Use this photo"', async () => {
        URL.createObjectURL = vi.fn(() => 'blob:x'); URL.revokeObjectURL = vi.fn();
        precheck.mockResolvedValue({ passed: false, code: 'blurry', message: 'This scan is too blurry to read. Hold the phone steady, use good light and fill the frame with the document, then upload it again.' });
        await mount(<Harness required />);
        await choose(new File(['x'], 'photo.jpg', { type: 'image/jpeg' }));
        expect(host.textContent).toContain('too blurry to read');
        const use = [...host.querySelectorAll('button')].find((b) => b.textContent === 'Use this photo');
        expect(use.disabled).toBe(true);
        expect(stageFile).not.toHaveBeenCalled();
        expect([...host.querySelectorAll('button')].map((b) => b.textContent)).toContain('Straighten and clean');
    });

    it('uploads a photo that passes the pre-check when the user accepts it, and hides the drop zone at the file limit', async () => {
        URL.createObjectURL = vi.fn(() => 'blob:y'); URL.revokeObjectURL = vi.fn();
        precheck.mockResolvedValue({ passed: true, code: 'ok', message: '' });
        stageFile.mockResolvedValue({ token: 't2', name: 'photo.jpg', mime_type: 'image/jpeg', preview_url: '/p', pages: 1, passed: true });
        await mount(<Harness max={1} />);
        await choose(new File(['x'], 'photo.jpg', { type: 'image/jpeg' }));
        const use = [...host.querySelectorAll('button')].find((b) => b.textContent === 'Use this photo');
        expect(use.disabled).toBe(false);
        await act(async () => { use.click(); });
        await act(async () => { await new Promise((r) => setTimeout(r, 0)); });
        expect(host.querySelectorAll('.cy-docs__row')).toHaveLength(1);
        expect(host.querySelector('input[type=file]')).toBeNull();
    });

    it('shows the server message when the upload is rejected', async () => {
        stageFile.mockRejectedValue(new Error('The page looks blank. Make sure the document is facing the camera.'));
        await mount(<Harness />);
        await choose(new File(['%PDF'], 'a.pdf', { type: 'application/pdf' }));
        expect(host.textContent).toContain('The page looks blank');
        expect(host.querySelector('.cy-docs')).toBeNull();
    });
});
