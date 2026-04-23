import { useEffect, useState, useCallback } from '@wordpress/element';
import { Button, Spinner, Icon } from '@wordpress/components';
import { upload } from '@wordpress/icons';
import { __, sprintf } from '@wordpress/i18n';
import { api } from '../../hooks/useApi';
import StatusBadge from '../StatusBadge';

const POLL_INTERVAL_MS = 3000;

export default function PushSessionsList({ onNew, onOpen, onError }) {
	const [loading, setLoading] = useState(true);
	const [items, setItems] = useState([]);

	const load = useCallback(async () => {
		try {
			const res = await api('/push-sessions?per_page=50');
			setItems(res.items || []);
		} catch (err) {
			onError?.(err?.message);
		} finally {
			setLoading(false);
		}
	}, [onError]);

	useEffect(() => { load(); }, [load]);

	useEffect(() => {
		const active = items.some((s) => s.status === 'running' || s.status === 'pending');
		if (!active) return undefined;
		const t = setInterval(load, POLL_INTERVAL_MS);
		return () => clearInterval(t);
	}, [items, load]);

	if (loading) {
		return <div className="spi-card"><div className="spi-card__body"><Spinner /></div></div>;
	}

	return (
		<div className="spi-push">
			<section className="spi-card">
				<header className="spi-card__head">
					<div>
						<h2 className="spi-card__title">{__('Push Export', 'simple-post-importer')}</h2>
						<p className="spi-card__sub">
							{__('Send local posts to another WordPress site that has this plugin installed. Works over HTTPS using a token you generate on that site.', 'simple-post-importer')}
						</p>
					</div>
					<Button variant="primary" onClick={onNew} __next40pxDefaultSize>
						{__('+ New Push', 'simple-post-importer')}
					</Button>
				</header>

				{items.length === 0 ? (
					<div className="spi-card__body" style={{ textAlign: 'center', padding: 56, color: '#666' }}>
						<Icon icon={upload} size={56} />
						<p style={{ marginTop: 16, fontSize: 15 }}>
							{__('No pushes yet.', 'simple-post-importer')}
						</p>
						<p style={{ color: '#888' }}>
							{__('Click “New Push” to send posts to another site.', 'simple-post-importer')}
						</p>
					</div>
				) : (
					<ul className="spi-push-list">
						{items.map((s) => {
							const pct = s.total > 0 ? Math.round((s.done / s.total) * 100) : 0;
							return (
								<li key={s.id} className="spi-push-list__item">
									<div className="spi-push-list__main">
										<div className="spi-push-list__title">
											{s.target_site_name || s.target_url}
											{' '}
											<StatusBadge status={s.status} />
										</div>
										<div className="spi-push-list__meta">
											<span>{s.target_url}</span>
											<span>·</span>
											<span>
												{sprintf(
													__('%1$d / %2$d posts', 'simple-post-importer'),
													s.done,
													s.total
												)}
											</span>
											<span>·</span>
											<span>{formatDate(s.created_at)}</span>
										</div>
										{s.status === 'running' && (
											<div className="spi-progress-bar" style={{ marginTop: 8 }}>
												<div className="spi-progress-bar__fill" style={{ width: `${pct}%` }} />
											</div>
										)}
										{s.error && (
											<div className="spi-push-list__error">{s.error}</div>
										)}
									</div>
									<div className="spi-push-list__actions">
										<Button variant="secondary" size="compact" onClick={() => onOpen(s.id)}>
											{__('Open', 'simple-post-importer')}
										</Button>
									</div>
								</li>
							);
						})}
					</ul>
				)}
			</section>
		</div>
	);
}

function formatDate(s) {
	if (!s) return '—';
	try {
		return new Date(s.replace(' ', 'T') + 'Z').toLocaleString();
	} catch (e) {
		return s;
	}
}
