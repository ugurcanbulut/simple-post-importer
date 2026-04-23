import { useState, useCallback } from '@wordpress/element';
import { Button, TextControl, Spinner, Icon, Notice } from '@wordpress/components';
import { check, arrowLeft } from '@wordpress/icons';
import { __ } from '@wordpress/i18n';
import { api } from '../../hooks/useApi';

/**
 * Step 1 of a push flow: collect target URL + token, handshake, and create a session.
 * After success, we push the user over to PushDetail to pick which posts to send.
 */
export default function PushCreate({ onCancel, onCreated, onError }) {
	const [targetUrl, setTargetUrl] = useState('');
	const [token, setToken] = useState('');
	const [connecting, setConnecting] = useState(false);
	const [handshake, setHandshake] = useState(null);

	const connect = useCallback(async (e) => {
		e?.preventDefault?.();
		if (!targetUrl.trim() || !token.trim()) return;
		setConnecting(true);
		setHandshake(null);
		try {
			const res = await api('/push-sessions', {
				method: 'POST',
				data: { target_url: targetUrl.trim(), token: token.trim() },
			});
			setHandshake(res.handshake);
			onCreated?.(res.session.id);
		} catch (err) {
			onError?.(err?.message || __('Connection failed', 'simple-post-importer'));
		} finally {
			setConnecting(false);
		}
	}, [targetUrl, token, onCreated, onError]);

	return (
		<div className="spi-push-create">
			<div className="spi-back-link">
				<Button variant="link" onClick={onCancel}>
					<Icon icon={arrowLeft} size={16} /> {__('Back to pushes', 'simple-post-importer')}
				</Button>
			</div>

			<section className="spi-card spi-card--narrow">
				<header className="spi-card__head">
					<h2 className="spi-card__title">{__('Connect to target site', 'simple-post-importer')}</h2>
					<p className="spi-card__sub">
						{__('Enter the URL of the site you want to push posts to, and a token generated there (Tokens tab).', 'simple-post-importer')}
					</p>
				</header>
				<form className="spi-card__body" onSubmit={connect}>
					<TextControl
						label={__('Target site URL', 'simple-post-importer')}
						placeholder="https://target.example.com"
						value={targetUrl}
						onChange={setTargetUrl}
						disabled={connecting}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<div style={{ height: 12 }} />
					<TextControl
						label={__('API token', 'simple-post-importer')}
						placeholder="spi_…"
						type="password"
						value={token}
						onChange={setToken}
						disabled={connecting}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>

					{handshake && (
						<Notice status="success" isDismissible={false}>
							<Icon icon={check} size={16} />{' '}
							{__('Connected to', 'simple-post-importer')}{' '}
							<strong>{handshake.site_name || handshake.site_url}</strong>
							{' '}({handshake.wp_version ? `WP ${handshake.wp_version}` : ''})
						</Notice>
					)}
				</form>
				<footer className="spi-card__foot">
					<Button variant="tertiary" onClick={onCancel} disabled={connecting}>
						{__('Cancel', 'simple-post-importer')}
					</Button>
					<Button
						variant="primary"
						onClick={connect}
						disabled={connecting || !targetUrl.trim() || !token.trim()}
						__next40pxDefaultSize
					>
						{connecting && <Spinner />}
						{connecting ? __('Connecting…', 'simple-post-importer') : __('Connect', 'simple-post-importer')}
					</Button>
				</footer>
			</section>
		</div>
	);
}
