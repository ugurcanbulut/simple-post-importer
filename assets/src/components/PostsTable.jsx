import { useEffect, useState, useCallback } from '@wordpress/element';
import { Button, CheckboxControl, Spinner } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { api, buildQuery } from '../hooks/useApi';
import StatusBadge from './StatusBadge';
import PostDetailsModal from './PostDetailsModal';

const PER_PAGE = 25;

export default function PostsTable({ sessionId, onError, onChange }) {
	const [loading, setLoading] = useState(true);
	const [items, setItems] = useState([]);
	const [total, setTotal] = useState(0);
	const [page, setPage] = useState(1);
	const [detailId, setDetailId] = useState(null);
	const [busyIds, setBusyIds] = useState(new Set());

	const load = useCallback(async (p = page) => {
		setLoading(true);
		try {
			const res = await api(`/sessions/${sessionId}/posts${buildQuery({ page: p, per_page: PER_PAGE })}`);
			setItems(res.items || []);
			setTotal(res.total || 0);
			setPage(p);
		} catch (err) {
			onError?.(err.message);
		} finally {
			setLoading(false);
		}
	}, [sessionId, page, onError]);

	useEffect(() => {
		load(1);
	}, [sessionId]); // eslint-disable-line react-hooks/exhaustive-deps

	const toggleSelect = useCallback(async (id, selected) => {
		setBusyIds((s) => new Set([...s, id]));
		try {
			await api(`/sessions/${sessionId}/posts/${id}`, {
				method: 'PATCH',
				data: { selected },
			});
			setItems((list) => list.map((it) => it.id === id ? { ...it, selected } : it));
			onChange?.();
		} catch (err) {
			onError?.(err.message);
		} finally {
			setBusyIds((s) => {
				const next = new Set(s);
				next.delete(id);
				return next;
			});
		}
	}, [sessionId, onChange, onError]);

	const bulkSelectAll = useCallback(async (selected) => {
		try {
			await api(`/sessions/${sessionId}/posts/bulk-select`, {
				method: 'POST',
				data: { selected, all: true },
			});
			await load(page);
			onChange?.();
		} catch (err) {
			onError?.(err.message);
		}
	}, [sessionId, page, load, onChange, onError]);

	const bulkSelectPage = useCallback(async (selected) => {
		const ids = items.map((i) => i.id);
		try {
			await api(`/sessions/${sessionId}/posts/bulk-select`, {
				method: 'POST',
				data: { selected, ids },
			});
			setItems((list) => list.map((it) => ({ ...it, selected })));
			onChange?.();
		} catch (err) {
			onError?.(err.message);
		}
	}, [sessionId, items, onChange, onError]);

	if (loading) {
		return <div style={{ padding: 24 }}><Spinner /></div>;
	}

	if (items.length === 0) {
		return (
			<div className="spi-empty-state">
				<p>{__('No posts found in this session.', 'simple-post-importer')}</p>
			</div>
		);
	}

	const pages = Math.max(1, Math.ceil(total / PER_PAGE));
	const allOnPageSelected = items.every((i) => i.selected);

	return (
		<>
			<div className="spi-posts-table">
				<div style={{ padding: '12px 16px', borderBottom: '1px solid #f0f0f0', display: 'flex', gap: 8 }}>
					<Button variant="tertiary" size="compact" onClick={() => bulkSelectAll(true)}>
						{__('Select all', 'simple-post-importer')}
					</Button>
					<Button variant="tertiary" size="compact" onClick={() => bulkSelectAll(false)}>
						{__('Select none', 'simple-post-importer')}
					</Button>
					<span style={{ flex: 1 }} />
					<span style={{ color: '#555', fontSize: 13 }}>
						{sprintf(__('%d posts total', 'simple-post-importer'), total)}
					</span>
				</div>

				<table>
					<thead>
						<tr>
							<th className="spi-posts-table__col-check">
								<CheckboxControl
									__nextHasNoMarginBottom
									checked={allOnPageSelected}
									onChange={(v) => bulkSelectPage(v)}
								/>
							</th>
							<th className="spi-posts-table__col-thumb"></th>
							<th>{__('Title & Excerpt', 'simple-post-importer')}</th>
							<th className="spi-posts-table__col-date">{__('Published', 'simple-post-importer')}</th>
							<th className="spi-posts-table__col-status">{__('Status', 'simple-post-importer')}</th>
							<th className="spi-posts-table__col-actions"></th>
						</tr>
					</thead>
					<tbody>
						{items.map((p) => (
							<tr key={p.id} className={p.imported ? 'is-imported' : ''}>
								<td>
									<CheckboxControl
										__nextHasNoMarginBottom
										checked={!!p.selected}
										disabled={busyIds.has(p.id) || p.imported}
										onChange={(v) => toggleSelect(p.id, v)}
									/>
								</td>
								<td>
									{p.featured_image_url ? (
										<img
											className="spi-posts-table__thumb"
											src={p.featured_image_url}
											alt=""
											loading="lazy"
											onError={(e) => { e.target.style.display = 'none'; }}
										/>
									) : (
										<div className="spi-posts-table__thumb-placeholder">
											{__('No image', 'simple-post-importer')}
										</div>
									)}
								</td>
								<td>
									<div className="spi-posts-table__title">{p.title || __('(untitled)', 'simple-post-importer')}</div>
									{p.excerpt && <div className="spi-posts-table__excerpt">{p.excerpt}</div>}
									<MetaRows post={p} />
									{p.imported && p.local_post_id && (
										<div style={{ fontSize: 12, color: '#155724', marginTop: 4 }}>
											{sprintf(__('Imported → local post #%d', 'simple-post-importer'), p.local_post_id)}
										</div>
									)}
								</td>
								<td className="spi-posts-table__col-date">{formatDate(p.published_date)}</td>
								<td><StatusBadge status={p.post_status} /></td>
								<td className="spi-posts-table__col-actions">
									<Button variant="secondary" size="compact" onClick={() => setDetailId(p.id)}>
										{__('View Details', 'simple-post-importer')}
									</Button>
								</td>
							</tr>
						))}
					</tbody>
				</table>

				<div className="spi-posts-table__footer">
					<div>{sprintf(__('Page %1$d of %2$d', 'simple-post-importer'), page, pages)}</div>
					<div className="spi-pagination">
						<Button
							variant="tertiary"
							size="compact"
							disabled={page <= 1}
							onClick={() => load(page - 1)}
						>
							‹ {__('Prev', 'simple-post-importer')}
						</Button>
						<Button
							variant="tertiary"
							size="compact"
							disabled={page >= pages}
							onClick={() => load(page + 1)}
						>
							{__('Next', 'simple-post-importer')} ›
						</Button>
					</div>
				</div>
			</div>

			{detailId !== null && (
				<PostDetailsModal
					sessionId={sessionId}
					postId={detailId}
					onClose={() => setDetailId(null)}
					onError={onError}
				/>
			)}
		</>
	);
}

function formatDate(s) {
	if (!s) return '—';
	try {
		const d = new Date(s.replace(' ', 'T') + 'Z');
		return d.toLocaleDateString();
	} catch (e) {
		return s;
	}
}

function MetaRows({ post }) {
	const author = post.author_data?.name;
	const categories = (post.terms_data?.categories || []).map((c) => c.name).filter(Boolean);
	const tags = (post.terms_data?.tags || []).map((t) => t.name).filter(Boolean);

	if (!author && categories.length === 0 && tags.length === 0) return null;

	return (
		<div className="spi-posts-table__meta">
			{author && (
				<div className="spi-posts-table__meta-row">
					<span className="spi-posts-table__meta-label">{__('Author:', 'simple-post-importer')}</span>
					<span>{author}</span>
				</div>
			)}
			{categories.length > 0 && (
				<div className="spi-posts-table__meta-row">
					<span className="spi-posts-table__meta-label">{__('Categories:', 'simple-post-importer')}</span>
					<span>{categories.join(', ')}</span>
				</div>
			)}
			{tags.length > 0 && (
				<div className="spi-posts-table__meta-row">
					<span className="spi-posts-table__meta-label">{__('Tags:', 'simple-post-importer')}</span>
					<span>{tags.join(', ')}</span>
				</div>
			)}
		</div>
	);
}
