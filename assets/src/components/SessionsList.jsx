import { useEffect, useState, useCallback, useRef } from '@wordpress/element';
import { Button, Spinner, Modal } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { api } from '../hooks/useApi';
import StatusBadge from './StatusBadge';

const POLL_INTERVAL_MS = 2500;

export default function SessionsList({ onOpen, onError, onNotice }) {
	const [loading, setLoading] = useState(true);
	const [items, setItems] = useState([]);
	const [confirmDelete, setConfirmDelete] = useState(null);
	const mountedRef = useRef(true);

	const load = useCallback(async () => {
		try {
			const res = await api('/sessions?per_page=50');
			if (mountedRef.current) {
				setItems(res.items || []);
			}
		} catch (err) {
			onError?.(err.message);
		} finally {
			if (mountedRef.current) {
				setLoading(false);
			}
		}
	}, [onError]);

	useEffect(() => {
		mountedRef.current = true;
		load();
		return () => {
			mountedRef.current = false;
		};
	}, [load]);

	useEffect(() => {
		const hasActive = items.some((s) =>
			s.scan_status === 'running' ||
			s.scan_status === 'pending' ||
			s.import_status === 'running'
		);
		if (!hasActive) return undefined;
		const id = setInterval(load, POLL_INTERVAL_MS);
		return () => clearInterval(id);
	}, [items, load]);

	const doDelete = async () => {
		const id = confirmDelete;
		setConfirmDelete(null);
		try {
			await api(`/sessions/${id}`, { method: 'DELETE' });
			onNotice?.(__('Session deleted.', 'simple-post-importer'));
			load();
		} catch (err) {
			onError?.(err.message);
		}
	};

	if (loading) {
		return <div style={{ padding: 24 }}><Spinner /></div>;
	}

	if (items.length === 0) {
		return (
			<div className="spi-empty-state">
				<p>{__('No sessions yet. Start a new scan to begin.', 'simple-post-importer')}</p>
			</div>
		);
	}

	return (
		<>
			<div className="spi-sessions-list">
				<table>
					<thead>
						<tr>
							<th>{__('ID', 'simple-post-importer')}</th>
							<th>{__('Source', 'simple-post-importer')}</th>
							<th>{__('Scan', 'simple-post-importer')}</th>
							<th>{__('Posts', 'simple-post-importer')}</th>
							<th>{__('Import', 'simple-post-importer')}</th>
							<th>{__('Created', 'simple-post-importer')}</th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						{items.map((s) => (
							<tr key={s.id}>
								<td>#{s.id}</td>
								<td>{s.source_url}</td>
								<td>
									<StatusBadge status={s.scan_status} />
									{(s.scan_status === 'running' || s.scan_status === 'pending') && s.scan_total_pages > 0 && (
										<div style={{ fontSize: 12, color: '#555', marginTop: 4 }}>
											{sprintf(__('Page %1$d / %2$d', 'simple-post-importer'), s.scan_current_page, s.scan_total_pages)}
										</div>
									)}
								</td>
								<td>{s.scan_total_posts}</td>
								<td>
									<StatusBadge status={s.import_status} />
									{s.import_status === 'running' && s.import_total > 0 && (
										<div style={{ fontSize: 12, color: '#555', marginTop: 4 }}>
											{sprintf(__('%1$d / %2$d', 'simple-post-importer'), s.import_done, s.import_total)}
										</div>
									)}
								</td>
								<td style={{ fontSize: 13, color: '#555' }}>{formatDate(s.created_at)}</td>
								<td>
									<div className="spi-inline-actions">
										<Button variant="secondary" size="compact" onClick={() => onOpen(s.id)}>
											{__('Open', 'simple-post-importer')}
										</Button>
										<Button variant="tertiary" size="compact" isDestructive onClick={() => setConfirmDelete(s.id)}>
											{__('Delete', 'simple-post-importer')}
										</Button>
									</div>
								</td>
							</tr>
						))}
					</tbody>
				</table>
			</div>

			{confirmDelete !== null && (
				<Modal
					title={__('Delete session?', 'simple-post-importer')}
					onRequestClose={() => setConfirmDelete(null)}
				>
					<p>
						{__('This will remove the scan record and all scanned posts metadata. Any posts already imported will remain in WordPress. To remove imported posts first, open the session and click "Clear Imported".', 'simple-post-importer')}
					</p>
					<div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 16 }}>
						<Button variant="tertiary" onClick={() => setConfirmDelete(null)}>
							{__('Cancel', 'simple-post-importer')}
						</Button>
						<Button variant="primary" isDestructive onClick={doDelete}>
							{__('Delete', 'simple-post-importer')}
						</Button>
					</div>
				</Modal>
			)}
		</>
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
