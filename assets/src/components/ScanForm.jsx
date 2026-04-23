import { useState } from '@wordpress/element';
import { Button, TextControl, Card, CardBody, Spinner, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { api } from '../hooks/useApi';

export default function ScanForm({ onSessionCreated, onError }) {
	const [url, setUrl] = useState('');
	const [busy, setBusy] = useState(false);
	const [localError, setLocalError] = useState(null);

	const onSubmit = async (e) => {
		e?.preventDefault?.();
		setLocalError(null);
		if (!url) return;
		setBusy(true);
		try {
			const session = await api('/scans', {
				method: 'POST',
				data: { url },
			});
			setUrl('');
			onSessionCreated?.(session.id);
		} catch (err) {
			const message = err?.message || __('Failed to create scan session.', 'simple-post-importer');
			setLocalError(message);
			onError?.(message);
		} finally {
			setBusy(false);
		}
	};

	return (
		<Card className="spi-scan-form">
			<CardBody>
				<h2 style={{ marginTop: 0 }}>{__('Start a New Scan', 'simple-post-importer')}</h2>
				<p style={{ color: '#555' }}>
					{__('Enter the URL of a WordPress site (not the /wp-json endpoint — just the site URL). The scanner will discover posts via the public REST API.', 'simple-post-importer')}
				</p>

				<form onSubmit={onSubmit}>
					<TextControl
						label={__('Remote Site URL', 'simple-post-importer')}
						placeholder="https://example.com"
						value={url}
						onChange={setUrl}
						disabled={busy}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>

					{localError && (
						<Notice status="error" isDismissible={false}>
							{localError}
						</Notice>
					)}

					<div style={{ marginTop: 16, display: 'flex', gap: 8, alignItems: 'center' }}>
						<Button
							variant="primary"
							type="submit"
							disabled={busy || !url}
							__next40pxDefaultSize
						>
							{__('Start Scan', 'simple-post-importer')}
						</Button>
						{busy && <Spinner />}
					</div>
				</form>
			</CardBody>
		</Card>
	);
}
