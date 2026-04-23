import { useEffect, useState, useCallback } from '@wordpress/element';
import { Button, Spinner, SelectControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { api } from '../../hooks/useApi';

export default function SettingsSection({ onError, onNotice }) {
	const [loading, setLoading] = useState(true);
	const [saving, setSaving] = useState(false);
	const [settings, setSettings] = useState(null);
	const [resolvedId, setResolvedId] = useState(0);
	const [users, setUsers] = useState([]);
	const [defaultAuthorId, setDefaultAuthorId] = useState('0');

	useEffect(() => {
		let cancelled = false;
		(async () => {
			try {
				const [s, u] = await Promise.all([
					api('/settings'),
					apiFetch({ path: '/wp/v2/users?per_page=100&context=edit' }),
				]);
				if (cancelled) return;
				setSettings(s.settings);
				setResolvedId(s.resolved_default_author_id);
				setDefaultAuthorId(String(s.settings?.default_author_id ?? 0));
				setUsers(Array.isArray(u) ? u : []);
			} catch (err) {
				onError?.(err?.message || 'Failed to load settings');
			} finally {
				if (!cancelled) setLoading(false);
			}
		})();
		return () => { cancelled = true; };
	}, [onError]);

	const save = useCallback(async () => {
		setSaving(true);
		try {
			const res = await api('/settings', {
				method: 'POST',
				data: {
					default_author_id: parseInt(defaultAuthorId, 10) || 0,
				},
			});
			setSettings(res.settings);
			setResolvedId(res.resolved_default_author_id);
			onNotice?.(__('Settings saved.', 'simple-post-importer'));
		} catch (err) {
			onError?.(err?.message || 'Save failed');
		} finally {
			setSaving(false);
		}
	}, [defaultAuthorId, onError, onNotice]);

	if (loading) {
		return <div className="spi-card"><div className="spi-card__body"><Spinner /></div></div>;
	}

	const userOptions = [
		{ value: '0', label: __('Auto (first administrator)', 'simple-post-importer') },
		...users.map((u) => ({
			value: String(u.id),
			label: `${u.name || u.slug} (${u.slug})`,
		})),
	];

	const resolvedUser = users.find((u) => u.id === resolvedId);

	return (
		<div className="spi-settings">
			<section className="spi-card">
				<header className="spi-card__head">
					<h2 className="spi-card__title">{__('Default author fallback', 'simple-post-importer')}</h2>
					<p className="spi-card__sub">
						{__("When a remote author can't be resolved to a username, imported posts are assigned to this local user instead.", 'simple-post-importer')}
					</p>
				</header>
				<div className="spi-card__body">
					<SelectControl
						label={__('Default author', 'simple-post-importer')}
						value={defaultAuthorId}
						options={userOptions}
						onChange={setDefaultAuthorId}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					{resolvedUser && defaultAuthorId === '0' && (
						<p style={{ marginTop: 8, fontSize: 13, color: '#555' }}>
							{sprintf(
								__('Currently resolves to: %s', 'simple-post-importer'),
								`${resolvedUser.name || resolvedUser.slug} (${resolvedUser.slug})`
							)}
						</p>
					)}
				</div>
				<footer className="spi-card__foot">
					<Button variant="primary" onClick={save} disabled={saving} __next40pxDefaultSize>
						{saving ? __('Saving…', 'simple-post-importer') : __('Save', 'simple-post-importer')}
					</Button>
				</footer>
			</section>
		</div>
	);
}
