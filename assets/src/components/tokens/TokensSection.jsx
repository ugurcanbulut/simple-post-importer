import { useEffect, useState, useCallback } from '@wordpress/element';
import { Button, Modal, TextControl, Spinner, Icon } from '@wordpress/components';
import { copy, key as keyIcon, trash } from '@wordpress/icons';
import { __, sprintf } from '@wordpress/i18n';
import { api } from '../../hooks/useApi';

export default function TokensSection({ onError, onNotice }) {
	const [loading, setLoading] = useState(true);
	const [items, setItems] = useState([]);
	const [showCreate, setShowCreate] = useState(false);
	const [newName, setNewName] = useState('');
	const [generating, setGenerating] = useState(false);
	const [generated, setGenerated] = useState(null);
	const [confirmRevoke, setConfirmRevoke] = useState(null);

	const load = useCallback(async () => {
		try {
			const res = await api('/tokens');
			setItems(res.items || []);
		} catch (err) {
			onError?.(err?.message);
		} finally {
			setLoading(false);
		}
	}, [onError]);

	useEffect(() => { load(); }, [load]);

	const generate = useCallback(async () => {
		if (!newName.trim()) return;
		setGenerating(true);
		try {
			const token = await api('/tokens', {
				method: 'POST',
				data: { name: newName.trim() },
			});
			setGenerated(token);
			setNewName('');
			setShowCreate(false);
			load();
		} catch (err) {
			onError?.(err?.message);
		} finally {
			setGenerating(false);
		}
	}, [newName, load, onError]);

	const revoke = useCallback(async () => {
		const id = confirmRevoke;
		setConfirmRevoke(null);
		try {
			await api(`/tokens/${id}`, { method: 'PUT' });
			onNotice?.(__('Token revoked.', 'simple-post-importer'));
			load();
		} catch (err) {
			onError?.(err?.message);
		}
	}, [confirmRevoke, load, onError, onNotice]);

	const copyText = useCallback((text) => {
		if (navigator.clipboard) {
			navigator.clipboard.writeText(text).then(
				() => onNotice?.(__('Copied to clipboard.', 'simple-post-importer'))
			);
		}
	}, [onNotice]);

	if (loading) {
		return <div className="spi-card"><div className="spi-card__body"><Spinner /></div></div>;
	}

	return (
		<div className="spi-tokens">
			<section className="spi-card">
				<header className="spi-card__head">
					<div>
						<h2 className="spi-card__title">{__('API Tokens', 'simple-post-importer')}</h2>
						<p className="spi-card__sub">
							{__('Create a token and share it with another site running this plugin. That site can then push posts into this one.', 'simple-post-importer')}
						</p>
					</div>
					<div>
						<Button variant="primary" onClick={() => setShowCreate(true)} __next40pxDefaultSize>
							{__('+ Generate Token', 'simple-post-importer')}
						</Button>
					</div>
				</header>

				{items.length === 0 ? (
					<div className="spi-card__body" style={{ textAlign: 'center', padding: 48, color: '#666' }}>
						<Icon icon={keyIcon} size={48} />
						<p style={{ marginTop: 8 }}>
							{__('No tokens yet. Generate one to let another site push content here.', 'simple-post-importer')}
						</p>
					</div>
				) : (
					<ul className="spi-token-list">
						{items.map((t) => (
							<li key={t.id} className={`spi-token-item${t.revoked ? ' is-revoked' : ''}`}>
								<div className="spi-token-item__body">
									<div className="spi-token-item__name">
										{t.name}
										{t.revoked && (
											<span className="spi-chip spi-chip--muted">{__('Revoked', 'simple-post-importer')}</span>
										)}
									</div>
									<div className="spi-token-item__meta">
										<code className="spi-token-item__preview">{t.preview}…</code>
										<span>·</span>
										<span>{sprintf(__('Created %s', 'simple-post-importer'), formatDate(t.created_at))}</span>
										{t.last_used_at && (
											<>
												<span>·</span>
												<span>{sprintf(__('Last used %s', 'simple-post-importer'), formatDate(t.last_used_at))}</span>
											</>
										)}
									</div>
								</div>
								{!t.revoked && (
									<Button
										variant="tertiary"
										isDestructive
										size="compact"
										onClick={() => setConfirmRevoke(t.id)}
									>
										<Icon icon={trash} size={16} /> {__('Revoke', 'simple-post-importer')}
									</Button>
								)}
							</li>
						))}
					</ul>
				)}
			</section>

			{showCreate && (
				<Modal
					title={__('Generate new token', 'simple-post-importer')}
					onRequestClose={() => setShowCreate(false)}
				>
					<TextControl
						label={__('Token name', 'simple-post-importer')}
						help={__('A label to remember what this token is for (e.g., "Production site", "Staging").', 'simple-post-importer')}
						value={newName}
						onChange={setNewName}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<div style={{ marginTop: 24, display: 'flex', gap: 8, justifyContent: 'flex-end' }}>
						<Button variant="tertiary" onClick={() => setShowCreate(false)}>{__('Cancel', 'simple-post-importer')}</Button>
						<Button variant="primary" onClick={generate} disabled={!newName.trim() || generating}>
							{generating ? __('Generating…', 'simple-post-importer') : __('Generate', 'simple-post-importer')}
						</Button>
					</div>
				</Modal>
			)}

			{generated && (
				<Modal
					title={__('New token created', 'simple-post-importer')}
					onRequestClose={() => setGenerated(null)}
					size="medium"
				>
					<p>
						{__('Copy this token now — you won\'t be able to see it again.', 'simple-post-importer')}
					</p>
					<div className="spi-token-reveal">
						<code>{generated.plaintext}</code>
						<Button variant="secondary" onClick={() => copyText(generated.plaintext)}>
							<Icon icon={copy} size={16} /> {__('Copy', 'simple-post-importer')}
						</Button>
					</div>
					<p style={{ color: '#555', fontSize: 13, marginTop: 16 }}>
						{__('Paste it on the source site\'s Push Export screen under "API Token". The source site will connect to this one using this token.', 'simple-post-importer')}
					</p>
					<div style={{ marginTop: 16, display: 'flex', justifyContent: 'flex-end' }}>
						<Button variant="primary" onClick={() => setGenerated(null)}>{__('Done', 'simple-post-importer')}</Button>
					</div>
				</Modal>
			)}

			{confirmRevoke !== null && (
				<Modal
					title={__('Revoke token?', 'simple-post-importer')}
					onRequestClose={() => setConfirmRevoke(null)}
				>
					<p>{__('Any site currently using this token will lose access immediately. This cannot be undone.', 'simple-post-importer')}</p>
					<div style={{ marginTop: 16, display: 'flex', gap: 8, justifyContent: 'flex-end' }}>
						<Button variant="tertiary" onClick={() => setConfirmRevoke(null)}>{__('Cancel', 'simple-post-importer')}</Button>
						<Button variant="primary" isDestructive onClick={revoke}>{__('Revoke', 'simple-post-importer')}</Button>
					</div>
				</Modal>
			)}
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
