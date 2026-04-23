import { useState, useEffect, useCallback } from '@wordpress/element';
import { Icon } from '@wordpress/components';
import { download, upload, key, cog } from '@wordpress/icons';
import { __ } from '@wordpress/i18n';
import PullSection from './components/pull/PullSection';
import PushSection from './components/push/PushSection';
import TokensSection from './components/tokens/TokensSection';
import SettingsSection from './components/settings/SettingsSection';
import Toaster from './components/Toaster';

const SECTIONS = [
	{ id: 'pull', label: __('Pull Import', 'simple-post-importer'), icon: download, hint: __('Scan a remote site, pick posts, import.', 'simple-post-importer') },
	{ id: 'push', label: __('Push Export', 'simple-post-importer'), icon: upload, hint: __('Send local posts to another site.', 'simple-post-importer') },
	{ id: 'tokens', label: __('Tokens', 'simple-post-importer'), icon: key, hint: __('Tokens for sites that push into here.', 'simple-post-importer') },
	{ id: 'settings', label: __('Settings', 'simple-post-importer'), icon: cog, hint: __('Defaults and fallbacks.', 'simple-post-importer') },
];

export default function App() {
	const [section, setSection] = useState('pull');
	const [toasts, setToasts] = useState([]);

	const pushToast = useCallback((type, message) => {
		const id = Date.now() + Math.random();
		setToasts((t) => [...t, { id, type, message }]);
	}, []);

	const dismissToast = useCallback((id) => {
		setToasts((t) => t.filter((x) => x.id !== id));
	}, []);

	const onError = useCallback((err) => {
		const msg = typeof err === 'string' ? err : (err?.message || 'Error');
		pushToast('error', msg);
	}, [pushToast]);

	const onNotice = useCallback((msg) => {
		pushToast('success', msg);
	}, [pushToast]);

	useEffect(() => {
		const hash = window.location.hash.replace('#', '');
		const segment = hash.split('/')[0];
		if (SECTIONS.some((s) => s.id === segment)) {
			setSection(segment);
		}
	}, []);

	const switchSection = useCallback((id) => {
		setSection(id);
		window.location.hash = id;
	}, []);

	const current = SECTIONS.find((s) => s.id === section) || SECTIONS[0];

	return (
		<div className="spi-app">
			<header className="spi-app__header">
				<div className="spi-app__title">
					<h1>{__('Simple Post Importer', 'simple-post-importer')}</h1>
					<p className="spi-app__tagline">{current.hint}</p>
				</div>
			</header>

			<nav className="spi-nav" role="tablist">
				{SECTIONS.map((s) => (
					<button
						key={s.id}
						type="button"
						role="tab"
						aria-selected={section === s.id}
						className={`spi-nav__item${section === s.id ? ' is-active' : ''}`}
						onClick={() => switchSection(s.id)}
					>
						<span className="spi-nav__icon">
							<Icon icon={s.icon} size={20} />
						</span>
						<span className="spi-nav__label">{s.label}</span>
					</button>
				))}
			</nav>

			<main className="spi-main">
				{section === 'pull' && (
					<PullSection onError={onError} onNotice={onNotice} />
				)}
				{section === 'push' && (
					<PushSection onError={onError} onNotice={onNotice} />
				)}
				{section === 'tokens' && (
					<TokensSection onError={onError} onNotice={onNotice} />
				)}
				{section === 'settings' && (
					<SettingsSection onError={onError} onNotice={onNotice} />
				)}
			</main>

			<Toaster toasts={toasts} onDismiss={dismissToast} />
		</div>
	);
}
