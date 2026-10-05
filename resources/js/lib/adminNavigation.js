import { usePage } from '@inertiajs/vue3';

export function useAdminUrls() {
    return usePage().props.admin_urls ?? {};
}

export function adminIndexUrl(key, fallback) {
    const urls = usePage().props.admin_urls;

    if (urls && urls[key]) {
        return urls[key];
    }

    return fallback;
}

export function appendQuery(baseUrl, params) {
    if (!params || Object.keys(params).length === 0) {
        return baseUrl;
    }

    const query = new URLSearchParams();

    Object.entries(params).forEach(([key, value]) => {
        if (value !== null && value !== undefined && value !== '') {
            query.set(key, String(value));
        }
    });

    const queryString = query.toString();

    return queryString ? `${baseUrl}?${queryString}` : baseUrl;
}
