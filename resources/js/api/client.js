import axios from 'axios';

/**
 * Structured API error aligned with Laravel bootstrap/app.php JSON envelope.
 */
export class ApiError extends Error {
    constructor(message, { status = 0, errorCode = null, errors = null, response = null } = {}) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
        this.errorCode = errorCode;
        this.errors = errors;
        this.response = response;
    }
}

const getClient = () => {
    if (typeof window !== 'undefined' && window.axios) {
        return window.axios;
    }
    return axios;
};

/**
 * @param {'get'|'post'|'put'|'patch'|'delete'} method
 * @param {string} url
 * @param {object} [options]
 * @param {object} [options.params] - query string (GET)
 * @param {object} [options.data] - request body (mutations)
 * @param {boolean} [options.unwrap=true] - unwrap `{ success, data }` envelope
 * @param {string} [options.responseType] - axios responseType (e.g. 'blob')
 */
export async function requestJson(method, url, options = {}) {
    const normalizedMethod = String(method || 'get').toLowerCase();
    
    const isConfigObject = options && typeof options === 'object' && !(options instanceof FormData) && !(options instanceof Array) && (
        'data' in options || 
        'params' in options || 
        'headers' in options || 
        'responseType' in options || 
        'unwrap' in options
    );

    let clientConfig = {};
    let unwrap = true;

    if (isConfigObject) {
        const { params, data, unwrap: userUnwrap = true, responseType, headers, ...rest } = options;
        clientConfig = { params, data, responseType, headers, ...rest };
        unwrap = userUnwrap;
    } else {
        const isGetOrDelete = normalizedMethod === 'get' || normalizedMethod === 'delete';
        clientConfig = {
            params: isGetOrDelete ? options : undefined,
            data: !isGetOrDelete ? options : undefined,
        };
        unwrap = true;
    }

    const client = getClient();

    try {
        const response = await client.request({
            url,
            method: normalizedMethod,
            ...clientConfig,
        });

        if (clientConfig.responseType === 'blob') {
            return response.data;
        }

        const payload = response.data;

        if (!unwrap || payload == null || typeof payload !== 'object') {
            return payload;
        }

        if (Object.prototype.hasOwnProperty.call(payload, 'success')) {
            if (payload.success === false) {
                throw new ApiError(payload.message || 'Request failed', {
                    status: response.status,
                    errorCode: payload.error_code ?? null,
                    errors: payload.errors ?? null,
                    response,
                });
            }

            if (Object.prototype.hasOwnProperty.call(payload, 'pagination')) {
                return {
                    data: payload.data,
                    pagination: payload.pagination,
                    message: payload.message,
                };
            }

            return payload.data;
        }

        return payload;
    } catch (error) {
        if (error instanceof ApiError) {
            throw error;
        }

        const response = error.response;
        const payload = response?.data;

        if (payload && typeof payload === 'object') {
            throw new ApiError(
                payload.message || payload.error || error.message || 'Request failed',
                {
                    status: response?.status ?? 0,
                    errorCode: payload.error_code ?? null,
                    errors: payload.errors ?? payload.error ?? null,
                    response,
                }
            );
        }

        throw new ApiError(error.message || 'Network error', {
            status: response?.status ?? 0,
            response,
        });
    }
}

/**
 * Normalize axios responses that may use the API envelope or legacy shapes.
 */
export function unwrapAxiosPayload(payload) {
    if (payload && typeof payload === 'object' && Object.prototype.hasOwnProperty.call(payload, 'success')) {
        if (payload.success === false) {
            throw new ApiError(payload.message || 'Request failed', {
                status: 0,
                errorCode: payload.error_code ?? null,
                errors: payload.errors ?? null,
            });
        }
        return payload.data ?? payload;
    }
    return payload;
}

export function unwrapAxiosResponse(response) {
    return unwrapAxiosPayload(response?.data);
}

export default requestJson;
