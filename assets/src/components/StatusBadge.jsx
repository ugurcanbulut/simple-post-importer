import { __ } from '@wordpress/i18n';

const LABELS = {
	pending: 'Pending',
	running: 'Running',
	complete: 'Complete',
	failed: 'Failed',
	idle: 'Idle',
	publish: 'Published',
	draft: 'Draft',
	private: 'Private',
	pending_review: 'Pending',
};

export default function StatusBadge({ status }) {
	if (!status) return <span>—</span>;
	const key = String(status).toLowerCase();
	const label = LABELS[key] || status;
	return <span className={`spi-status spi-status--${key}`}>{label}</span>;
}
