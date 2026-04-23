import { useEffect, useState, useCallback, useRef } from '@wordpress/element';
import { Button, Spinner, Icon, Modal, Notice } from '@wordpress/components';
import { arrowLeft, check } from '@wordpress/icons';
import { __, sprintf } from '@wordpress/i18n';
import { api } from '../../hooks/useApi';
import StatusBadge from '../StatusBadge';
import ProgressBar from '../ProgressBar';
import PushPostsPicker from './PushPostsPicker';

const POLL_INTERVAL_MS = 2000;

export default function PushDetail({ sessionId, onBack, onError, onNotice }) {
	const [session, setSession] = useState(null);
	const [loading, setLoading] = useState(true);
	const [confirmDelete, setConfirmDelete] = useState(false);
	const mountedRef = useRef(true);
	const prevStatusRef = useRef(null);

	const refresh = useCallback(async () => {
		try {
			const s = await api(`/push-sessions/${sessionId}`);
			if (mountedRef.current) setSession(s);
			return s;
		} catch (err) {
			onError?.(err?.message);
			return null;
		} finally {
			if (mountedRef.current) setLoading(false);
		}
	}, [sessionId, onError]);

	useEffect(() => {
		mountedRef.current = true;
		setLoading(true);
		refresh();
		return () => { mountedRef.current = false; };
	}, [refresh]);

	useEffect(() => {
		if (!session) return undefined;
		if (session.status !== 'running' && session.status !== 'pending') return undefined;
		const t = setInterval(refresh, POLL_INTERVAL_MS);
		return () => clearInterval(t);
	}, [session?.status, refresh]); // eslint-disable-line

	useEffect(() => {
		if (!session) return;
		if (prevStatusRef.current === 'running' && session.status === 'complete') {
			onNotice?.(__('Push complete.', 'simple-post-importer'));
		}
		prevStatusRef.current = session.status;
	}, [session?.status, onNotice]); // eslint-disable-line

	const startPush = useCallback(async () => {
		try {
			await api(`/push-sessions/${sessionId}/start`, { method: 'POST' });
			await refresh();
		} catch (err) {
			onError?.(err?.message);
		}
	}, [sessionId, refresh, onError]);

	const remove = useCallback(async () => {
		setConfirmDelete(false);
		try {
			await api(`/push-sessions/${sessionId}`, { method: 'DELETE' });
			onNotice?.(__('Push session deleted.', 'simple-post-importer'));
			onBack?.();
		} catch (err) {
			onError?.(err?.message);
		}
	}, [sessionId, onNotice, onError, onBack]);

	if (loading) {
		return <div className="spi-card"><div className="spi-card__body"><Spinner /></div></div>;
	}

	if (!session) {
		return (
			<div className="spi-card"><div className="spi-card__body">
				<p>{__('Session not found.', 'simple-post-importer')}</p>
			</div></div>
		);
	}

	const pct = session.total > 0 ? Math.round((session.done / session.total) * 100) : 0;
	const isRunning = session.status === 'running';
	const canStart = (session.pending ?? 0) > 0 && !isRunning;

	return (
		<div className="spi-push-detail">
			<div className="spi-back-link">
				<Button variant="link" onClick={onBack}>
					<Icon icon={arrowLeft} size={16} /> {__('Back to pushes', 'simple-post-importer')}
				</Button>
			</div>

			<section className="spi-card">
				<header className="spi-card__head">
					<div>
						<h2 className="spi-card__title">
							{session.target_site_name || __('Target', 'simple-post-importer')}
							{' '}
							<StatusBadge status={session.status} />
						</h2>
						<p className="spi-card__sub">
							{session.target_url}
							{' · '}
							<code style={{ fontSize: 12 }}>{session.token_preview}…</code>
						</p>
					</div>
					<div className="spi-inline-actions">
						<Button
							variant="primary"
							onClick={startPush}
							disabled={!canStart}
							__next40pxDefaultSize
						>
							{isRunning
								? __('Running…', 'simple-post-importer')
								: sprintf(__('Start Push (%d)', 'simple-post-importer'), session.pending ?? 0)}
						</Button>
						<Button
							variant="tertiary"
							isDestructive
							onClick={() => setConfirmDelete(true)}
						>
							{__('Delete session', 'simple-post-importer')}
						</Button>
					</div>
				</header>

				{(isRunning || session.status === 'complete') && session.total > 0 && (
					<div className="spi-card__body" style={{ paddingTop: 0 }}>
						<ProgressBar percent={pct} />
						<small style={{ color: '#555' }}>
							{session.status === 'complete' ? (
								<>
									<Icon icon={check} size={14} />{' '}
									{sprintf(__('All %d posts pushed.', 'simple-post-importer'), session.done)}
								</>
							) : (
								sprintf(__('%1$d of %2$d posts pushed', 'simple-post-importer'), session.done, session.total)
							)}
						</small>
					</div>
				)}

				{session.error && (
					<div className="spi-card__body" style={{ paddingTop: 0 }}>
						<Notice status="error" isDismissible={false}>
							{session.error}
						</Notice>
					</div>
				)}
			</section>

			<PushPostsPicker
				sessionId={sessionId}
				session={session}
				onChanged={refresh}
				onError={onError}
				onNotice={onNotice}
			/>

			{confirmDelete && (
				<Modal
					title={__('Delete push session?', 'simple-post-importer')}
					onRequestClose={() => setConfirmDelete(false)}
				>
					<p>{__('This removes the record of the push from this site. Posts already sent to the target will not be deleted there.', 'simple-post-importer')}</p>
					<div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 16 }}>
						<Button variant="tertiary" onClick={() => setConfirmDelete(false)}>{__('Cancel', 'simple-post-importer')}</Button>
						<Button variant="primary" isDestructive onClick={remove}>{__('Delete', 'simple-post-importer')}</Button>
					</div>
				</Modal>
			)}
		</div>
	);
}
