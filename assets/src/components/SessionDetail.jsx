import { useEffect, useState, useCallback, useRef } from '@wordpress/element';
import { Button, Spinner, Modal, Notice } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { api } from '../hooks/useApi';
import StatusBadge from './StatusBadge';
import ProgressBar from './ProgressBar';
import PostsTable from './PostsTable';

const POLL_INTERVAL_MS = 2000;

export default function SessionDetail({ sessionId, onError, onNotice }) {
	const [session, setSession] = useState(null);
	const [loading, setLoading] = useState(true);
	const [confirmClear, setConfirmClear] = useState(false);
	const [tableKey, setTableKey] = useState(0);
	const mountedRef = useRef(true);
	const prevScanStatusRef = useRef(null);
	const prevImportStatusRef = useRef(null);

	const refresh = useCallback(async () => {
		try {
			const s = await api(`/sessions/${sessionId}`);
			if (mountedRef.current) {
				setSession(s);
			}
			return s;
		} catch (err) {
			onError?.(err.message);
			return null;
		} finally {
			if (mountedRef.current) {
				setLoading(false);
			}
		}
	}, [sessionId, onError]);

	useEffect(() => {
		mountedRef.current = true;
		setLoading(true);
		refresh();
		return () => {
			mountedRef.current = false;
		};
	}, [refresh]);

	useEffect(() => {
		if (!session) return undefined;
		const active =
			session.scan_status === 'running' ||
			session.scan_status === 'pending' ||
			session.import_status === 'running';
		if (!active) return undefined;
		const id = setInterval(refresh, POLL_INTERVAL_MS);
		return () => clearInterval(id);
	}, [session?.scan_status, session?.import_status, refresh]); // eslint-disable-line react-hooks/exhaustive-deps

	useEffect(() => {
		if (!session) return;
		if (prevScanStatusRef.current === 'running' && session.scan_status === 'complete') {
			onNotice?.(__('Scan complete.', 'simple-post-importer'));
			setTableKey((k) => k + 1);
		}
		if (prevImportStatusRef.current === 'running' && session.import_status === 'complete') {
			onNotice?.(__('Import finished.', 'simple-post-importer'));
			setTableKey((k) => k + 1);
		}
		prevScanStatusRef.current = session.scan_status;
		prevImportStatusRef.current = session.import_status;
	}, [session?.scan_status, session?.import_status, onNotice]); // eslint-disable-line react-hooks/exhaustive-deps

	const startImport = useCallback(async () => {
		try {
			await api(`/sessions/${sessionId}/import`, { method: 'POST' });
			await refresh();
		} catch (err) {
			onError?.(err.message);
		}
	}, [sessionId, refresh, onError]);

	const doClear = useCallback(async () => {
		setConfirmClear(false);
		try {
			const res = await api(`/sessions/${sessionId}/imports`, { method: 'DELETE' });
			onNotice?.(sprintf(
				__('Cleared %1$d posts and %2$d attachments.', 'simple-post-importer'),
				res.posts_deleted || 0,
				res.attachments_deleted || 0
			));
			await refresh();
			setTableKey((k) => k + 1);
		} catch (err) {
			onError?.(err.message);
		}
	}, [sessionId, refresh, onError, onNotice]);

	if (loading) {
		return <div style={{ padding: 24 }}><Spinner /></div>;
	}

	if (!session) {
		return (
			<div className="spi-empty-state">
				<p>{__('Session not found.', 'simple-post-importer')}</p>
			</div>
		);
	}

	const scanTotal = Number(session.scan_total_pages) || 0;
	const scanCurrent = Number(session.scan_current_page) || 0;
	const scanPct = scanTotal > 0 ? Math.round((scanCurrent / scanTotal) * 100) : 0;
	const importPct = session.import_total > 0 ? Math.round((session.import_done / session.import_total) * 100) : 0;

	const scanActive = session.scan_status === 'running' || session.scan_status === 'pending';
	const importActive = session.import_status === 'running';
	const posts_selected_pending = session.posts_selected_pending ?? 0;
	const posts_imported = session.posts_imported ?? 0;

	return (
		<div className="spi-session-detail">
			<div className="spi-session-detail__header">
				<h2>
					{sprintf(__('Session #%d', 'simple-post-importer'), session.id)}{' '}
					<span style={{ fontWeight: 'normal', color: '#555' }}>— {session.source_url}</span>
				</h2>

				<div className="spi-session-detail__meta">
					<div>
						<strong>{__('Scan:', 'simple-post-importer')}</strong>{' '}
						<StatusBadge status={session.scan_status} />
					</div>
					<div>
						<strong>{__('Import:', 'simple-post-importer')}</strong>{' '}
						<StatusBadge status={session.import_status} />
					</div>
					<div>
						<strong>{__('Posts:', 'simple-post-importer')}</strong>{' '}
						{session.posts_total ?? session.scan_total_posts}
					</div>
					<div>
						<strong>{__('Imported:', 'simple-post-importer')}</strong>{' '}
						{posts_imported}
					</div>
				</div>

				{scanActive && (
					<div className="spi-session-detail__progress">
						<ProgressBar percent={scanPct} />
						<small>
							{sprintf(
								__('Scanning page %1$d of %2$d (%3$d posts found)', 'simple-post-importer'),
								scanCurrent,
								scanTotal,
								session.scan_total_posts
							)}
						</small>
					</div>
				)}

				{session.scan_status === 'failed' && session.scan_error && (
					<Notice status="error" isDismissible={false}>
						{session.scan_error}
					</Notice>
				)}

				{importActive && (
					<div className="spi-session-detail__progress">
						<ProgressBar percent={importPct} />
						<small>
							{sprintf(
								__('Importing %1$d of %2$d posts', 'simple-post-importer'),
								session.import_done,
								session.import_total
							)}
						</small>
					</div>
				)}

				{session.import_status === 'failed' && session.import_error && (
					<Notice status="error" isDismissible={false}>
						{session.import_error}
					</Notice>
				)}

				<div className="spi-session-detail__toolbar">
					<Button
						variant="primary"
						onClick={startImport}
						disabled={importActive || session.scan_status !== 'complete' || posts_selected_pending === 0}
					>
						{sprintf(
							__('Import Selected (%d)', 'simple-post-importer'),
							posts_selected_pending
						)}
					</Button>
					<Button
						variant="secondary"
						isDestructive
						onClick={() => setConfirmClear(true)}
						disabled={posts_imported === 0 || importActive}
					>
						{__('Clear Imported', 'simple-post-importer')}
					</Button>
					<Button variant="tertiary" onClick={refresh}>
						{__('Refresh', 'simple-post-importer')}
					</Button>
				</div>
			</div>

			{session.scan_status === 'complete' ? (
				<PostsTable
					key={tableKey}
					sessionId={sessionId}
					onError={onError}
					onChange={refresh}
				/>
			) : (
				<div className="spi-empty-state">
					<p>
						{scanActive
							? __('Scanning remote site… this page will update automatically.', 'simple-post-importer')
							: __('Waiting for scan to start…', 'simple-post-importer')}
					</p>
				</div>
			)}

			{confirmClear && (
				<Modal
					title={__('Clear imported posts?', 'simple-post-importer')}
					onRequestClose={() => setConfirmClear(false)}
				>
					<p>
						{__('This will permanently delete all local posts imported by this session and their sideloaded attachments. Scanned data and selections are preserved, so you can re-import later.', 'simple-post-importer')}
					</p>
					<div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 16 }}>
						<Button variant="tertiary" onClick={() => setConfirmClear(false)}>
							{__('Cancel', 'simple-post-importer')}
						</Button>
						<Button variant="primary" isDestructive onClick={doClear}>
							{__('Clear', 'simple-post-importer')}
						</Button>
					</div>
				</Modal>
			)}
		</div>
	);
}
