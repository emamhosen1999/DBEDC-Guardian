import { requestJson } from '@/api/client';

/** Calls to the shared attachment pipeline (the same endpoints the mobile app uses, under the web session). */
const form = (name, file, extra = {}) => {
    const body = new FormData();
    body.append(name, file);
    Object.entries(extra).forEach(([k, v]) => v != null && body.append(k, v));
    return body;
};

export const stageFile = (file, onProgress) => requestJson('post', '/attachments/stage', {
    data: form('file', file),
    onUploadProgress: (e) => e.total && onProgress?.(Math.round((e.loaded / e.total) * 100)),
});

export const scanPhoto = (file, mode = 'color', onProgress) => requestJson('post', '/attachments/scan', {
    data: form('photo', file, { mode }),
    onUploadProgress: (e) => e.total && onProgress?.(Math.round((e.loaded / e.total) * 100)),
});

export const adjustScan = (token, { corners, mode } = {}) => requestJson('post', `/attachments/scan/${token}/adjust`, { data: { corners, mode } });
export const finalizeScan = (pages) => requestJson('post', '/attachments/scan/finalize', { data: { pages } });
export const discardStaged = (token) => requestJson('delete', `/attachments/staged/${token}`);
export const requestReupload = (type, id, reason) => requestJson('post', `/attachments/${type}/${id}/request-reupload`, { data: { reason } });
export const submitReupload = (type, id, tokens) => requestJson('post', `/attachments/${type}/${id}/reupload`, { data: { attachment_tokens: tokens } });
