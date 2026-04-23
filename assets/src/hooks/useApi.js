import apiFetch from '@wordpress/api-fetch';

const NS = '/simple-post-importer/v1';

export function api(path, options = {}) {
	return apiFetch({ path: `${NS}${path}`, ...options });
}

export function buildQuery(params = {}) {
	const qs = new URLSearchParams();
	Object.entries(params).forEach(([k, v]) => {
		if (v === undefined || v === null || v === '') return;
		qs.append(k, String(v));
	});
	const s = qs.toString();
	return s ? `?${s}` : '';
}
