import { useEffect, useState, useCallback } from '@wordpress/element';
import { Button, Spinner, CheckboxControl, SelectControl, TextControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { api, buildQuery } from '../../hooks/useApi';

const PER_PAGE = 25;

export default function PushPostsPicker({ sessionId, session, onChanged, onError, onNotice }) {
	const [loadingCandidates, setLoadingCandidates] = useState(true);
	const [candidates, setCandidates] = useState([]);
	const [total, setTotal] = useState(0);
	const [pages, setPages] = useState(1);
	const [page, setPage] = useState(1);
	const [postType, setPostType] = useState('post');
	const [search, setSearch] = useState('');
	const [postTypes, setPostTypes] = useState([{ slug: 'post', label: 'Posts' }]);
	const [selected, setSelected] = useState(new Set());
	const [queuedIds, setQueuedIds] = useState(new Set());
	const [adding, setAdding] = useState(false);
	const [addingAll, setAddingAll] = useState(false);
	const [items, setItems] = useState([]);
	const [loadingItems, setLoadingItems] = useState(true);

	const loadCandidates = useCallback(async (p = 1) => {
		setLoadingCandidates(true);
		try {
			const res = await api('/push-candidates' + buildQuery({
				page: p,
				per_page: PER_PAGE,
				post_type: postType,
				search,
			}));
			setCandidates(res.items || []);
			setTotal(res.total || 0);
			setPages(res.pages || 1);
			setPostTypes(res.post_types || [{ slug: 'post', label: 'Posts' }]);
			setPage(p);
		} catch (err) {
			onError?.(err?.message);
		} finally {
			setLoadingCandidates(false);
		}
	}, [postType, search, onError]);

	const loadItems = useCallback(async () => {
		try {
			const res = await api(`/push-sessions/${sessionId}/items?per_page=100`);
			const list = res.items || [];
			setItems(list);
			setQueuedIds(new Set(list.map((i) => i.local_post_id)));
		} catch (err) {
			onError?.(err?.message);
		} finally {
			setLoadingItems(false);
		}
	}, [sessionId, onError]);

	useEffect(() => { loadCandidates(1); }, [loadCandidates]);
	useEffect(() => { loadItems(); }, [loadItems]);
	useEffect(() => {
		if (session?.status === 'running' || session?.status === 'pending') {
			const t = setInterval(loadItems, 3000);
			return () => clearInterval(t);
		}
	}, [session?.status, loadItems]);

	const toggle = (id) => {
		setSelected((s) => {
			const next = new Set(s);
			if (next.has(id)) next.delete(id); else next.add(id);
			return next;
		});
	};

	const addSelected = useCallback(async () => {
		if (selected.size === 0) return;
		setAdding(true);
		try {
			const ids = Array.from(selected);
			const res = await api(`/push-sessions/${sessionId}/items`, {
				method: 'POST',
				data: { post_ids: ids },
			});
			onNotice?.(sprintf(__('%d post(s) queued.', 'simple-post-importer'), res.added || 0));
			setSelected(new Set());
			await loadItems();
			onChanged?.();
		} catch (err) {
			onError?.(err?.message);
		} finally {
			setAdding(false);
		}
	}, [selected, sessionId, loadItems, onChanged, onError, onNotice]);

	const addAll = useCallback(async () => {
		setAddingAll(true);
		try {
			const res = await api(`/push-sessions/${sessionId}/items`, {
				method: 'POST',
				data: { all_posts: true, post_type: postType, post_status: 'publish' },
			});
			onNotice?.(sprintf(__('%d post(s) queued.', 'simple-post-importer'), res.added || 0));
			await loadItems();
			onChanged?.();
		} catch (err) {
			onError?.(err?.message);
		} finally {
			setAddingAll(false);
		}
	}, [sessionId, postType, loadItems, onChanged, onError, onNotice]);

	return (
		<section className="spi-card">
			<header className="spi-card__head">
				<div>
					<h3 className="spi-card__title">{__('Select posts to push', 'simple-post-importer')}</h3>
					<p className="spi-card__sub">
						{sprintf(
							__('%1$d queued · %2$d pushed · %3$d pending', 'simple-post-importer'),
							session?.total ?? 0,
							session?.done ?? 0,
							session?.pending ?? 0
						)}
					</p>
				</div>
			</header>

			<div className="spi-card__body">
				<div className="spi-filter-bar">
					<SelectControl
						label={__('Post type', 'simple-post-importer')}
						hideLabelFromVision
						value={postType}
						options={postTypes.map((p) => ({ value: p.slug, label: p.label }))}
						onChange={(v) => setPostType(v)}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<TextControl
						label={__('Search', 'simple-post-importer')}
						hideLabelFromVision
						placeholder={__('Search posts…', 'simple-post-importer')}
						value={search}
						onChange={setSearch}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<Button
						variant="tertiary"
						onClick={() => loadCandidates(1)}
						disabled={loadingCandidates}
					>
						{__('Apply', 'simple-post-importer')}
					</Button>
					<div style={{ flex: 1 }} />
					<Button
						variant="secondary"
						onClick={addAll}
						disabled={addingAll || loadingCandidates}
					>
						{addingAll
							? __('Queuing…', 'simple-post-importer')
							: sprintf(__('Queue all (%d)', 'simple-post-importer'), total)}
					</Button>
					<Button
						variant="primary"
						onClick={addSelected}
						disabled={adding || selected.size === 0}
					>
						{adding
							? __('Queuing…', 'simple-post-importer')
							: sprintf(__('Queue selected (%d)', 'simple-post-importer'), selected.size)}
					</Button>
				</div>

				{loadingCandidates ? (
					<div style={{ padding: 24 }}><Spinner /></div>
				) : candidates.length === 0 ? (
					<div style={{ padding: 24, color: '#666', textAlign: 'center' }}>
						{__('No posts found.', 'simple-post-importer')}
					</div>
				) : (
					<ul className="spi-candidate-list">
						{candidates.map((c) => {
							const isQueued = queuedIds.has(c.id);
							const isChecked = selected.has(c.id);
							return (
								<li key={c.id} className={`spi-candidate${isQueued ? ' is-queued' : ''}`}>
									<div className="spi-candidate__check">
										<CheckboxControl
											__nextHasNoMarginBottom
											checked={isChecked || isQueued}
											disabled={isQueued}
											onChange={() => toggle(c.id)}
										/>
									</div>
									<div className="spi-candidate__thumb">
										{c.featured_image_url ? (
											<img src={c.featured_image_url} alt="" loading="lazy" />
										) : (
											<div className="spi-candidate__no-thumb" />
										)}
									</div>
									<div className="spi-candidate__body">
										<div className="spi-candidate__title">{c.title || __('(untitled)', 'simple-post-importer')}</div>
										<div className="spi-candidate__meta">
											<span>{c.author}</span>
											<span>·</span>
											<span>{formatDate(c.date_gmt)}</span>
											<span>·</span>
											<span>{c.post_type}</span>
											{isQueued && (
												<>
													<span>·</span>
													<span className="spi-chip spi-chip--ok">{__('Queued', 'simple-post-importer')}</span>
												</>
											)}
										</div>
									</div>
								</li>
							);
						})}
					</ul>
				)}

				<div className="spi-card__footer-row">
					<span style={{ color: '#666', fontSize: 13 }}>
						{sprintf(__('Page %1$d of %2$d', 'simple-post-importer'), page, pages)}
					</span>
					<div className="spi-pagination">
						<Button variant="tertiary" size="compact" disabled={page <= 1} onClick={() => loadCandidates(page - 1)}>
							‹ {__('Prev', 'simple-post-importer')}
						</Button>
						<Button variant="tertiary" size="compact" disabled={page >= pages} onClick={() => loadCandidates(page + 1)}>
							{__('Next', 'simple-post-importer')} ›
						</Button>
					</div>
				</div>
			</div>

			{items.length > 0 && (
				<>
					<div className="spi-card__divider" />
					<div className="spi-card__body">
						<h4 style={{ margin: '0 0 12px 0', fontSize: 14 }}>
							{__('Queued for push', 'simple-post-importer')}
						</h4>
						{loadingItems ? (
							<Spinner />
						) : (
							<ul className="spi-queue-list">
								{items.map((it) => (
									<li key={it.id} className={`spi-queue-item${it.pushed ? ' is-pushed' : ''}`}>
										<span className="spi-queue-item__title">{it.title || `#${it.local_post_id}`}</span>
										{it.pushed ? (
											<span className="spi-chip spi-chip--ok">
												{it.target_post_url ? (
													<a href={it.target_post_url} target="_blank" rel="noopener noreferrer">
														{__('Pushed ↗', 'simple-post-importer')}
													</a>
												) : __('Pushed', 'simple-post-importer')}
											</span>
										) : it.error ? (
											<span className="spi-chip spi-chip--error">{it.error}</span>
										) : (
											<span className="spi-chip spi-chip--muted">{__('Pending', 'simple-post-importer')}</span>
										)}
									</li>
								))}
							</ul>
						)}
					</div>
				</>
			)}
		</section>
	);
}

function formatDate(s) {
	if (!s) return '—';
	try {
		return new Date(s.replace(' ', 'T') + 'Z').toLocaleDateString();
	} catch (e) { return s; }
}
