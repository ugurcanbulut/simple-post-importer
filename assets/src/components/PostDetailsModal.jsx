import { useEffect, useState } from '@wordpress/element';
import { Modal, Spinner } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { api } from '../hooks/useApi';

export default function PostDetailsModal({ sessionId, postId, onClose, onError }) {
	const [loading, setLoading] = useState(true);
	const [data, setData] = useState(null);

	useEffect(() => {
		let cancelled = false;
		(async () => {
			try {
				const res = await api(`/sessions/${sessionId}/posts/${postId}`);
				if (!cancelled) setData(res);
			} catch (err) {
				onError?.(err.message);
				onClose?.();
			} finally {
				if (!cancelled) setLoading(false);
			}
		})();
		return () => { cancelled = true; };
	}, [sessionId, postId, onError, onClose]);

	return (
		<Modal
			title={loading ? __('Loading…', 'simple-post-importer') : (data?.title || __('Post details', 'simple-post-importer'))}
			onRequestClose={onClose}
			size="large"
			className="spi-modal-content"
		>
			{loading ? (
				<div style={{ padding: 24 }}><Spinner /></div>
			) : data ? (
				<>
					<div className="spi-modal-meta">
						<div><strong>{__('Remote ID:', 'simple-post-importer')}</strong> {data.remote_id}</div>
						{data.published_date && (
							<div><strong>{__('Published:', 'simple-post-importer')}</strong> {formatDate(data.published_date)}</div>
						)}
						{data.author_data?.name && (
							<div><strong>{__('Author:', 'simple-post-importer')}</strong> {data.author_data.name}</div>
						)}
						{data.post_status && (
							<div><strong>{__('Status:', 'simple-post-importer')}</strong> {data.post_status}</div>
						)}
					</div>

					{data.featured_image_url && (
						<img
							src={data.featured_image_url}
							alt=""
							style={{ maxWidth: '100%', borderRadius: 4, marginBottom: 16 }}
						/>
					)}

					{data.terms_data && (
						<div style={{ marginBottom: 16, fontSize: 13, color: '#555' }}>
							{data.terms_data.categories?.length > 0 && (
								<div>
									<strong>{__('Categories:', 'simple-post-importer')}</strong>{' '}
									{data.terms_data.categories.map((c) => c.name).join(', ')}
								</div>
							)}
							{data.terms_data.tags?.length > 0 && (
								<div>
									<strong>{__('Tags:', 'simple-post-importer')}</strong>{' '}
									{data.terms_data.tags.map((t) => t.name).join(', ')}
								</div>
							)}
						</div>
					)}

					<div
						className="spi-modal-content__body"
						/* eslint-disable-next-line react/no-danger */
						dangerouslySetInnerHTML={{ __html: data.content_safe || '' }}
					/>
				</>
			) : (
				<p>{__('Post not found.', 'simple-post-importer')}</p>
			)}
		</Modal>
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
