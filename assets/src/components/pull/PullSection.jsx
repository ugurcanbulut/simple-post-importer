import { useState, useEffect, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import ScanForm from '../ScanForm';
import SessionsList from '../SessionsList';
import SessionDetail from '../SessionDetail';

const TABS = [
	{ name: 'new-scan', title: __('New Scan', 'simple-post-importer') },
	{ name: 'sessions', title: __('Sessions', 'simple-post-importer') },
	{ name: 'details', title: __('Session Details', 'simple-post-importer') },
];

export default function PullSection({ onError, onNotice }) {
	const [activeTab, setActiveTab] = useState('new-scan');
	const [activeSessionId, setActiveSessionId] = useState(null);

	useEffect(() => {
		const [, sub] = (window.location.hash || '').replace('#', '').split('/');
		if (sub && sub.startsWith('session-')) {
			const id = parseInt(sub.replace('session-', ''), 10);
			if (!Number.isNaN(id)) {
				setActiveSessionId(id);
				setActiveTab('details');
			}
		} else if (TABS.some((t) => t.name === sub)) {
			setActiveTab(sub);
		}
	}, []);

	const changeTab = useCallback((tab) => {
		setActiveTab(tab);
		if (tab === 'details' && activeSessionId) {
			window.location.hash = `pull/session-${activeSessionId}`;
		} else {
			window.location.hash = `pull/${tab}`;
		}
	}, [activeSessionId]);

	const openSession = useCallback((id) => {
		setActiveSessionId(id);
		setActiveTab('details');
		window.location.hash = `pull/session-${id}`;
	}, []);

	const onScanCreated = useCallback((id) => {
		setActiveSessionId(id);
		setActiveTab('sessions');
		window.location.hash = 'pull/sessions';
		onNotice?.(__('Scan started. Progress updates automatically below.', 'simple-post-importer'));
	}, [onNotice]);

	return (
		<div className="spi-pull">
			<div className="spi-subtabs" role="tablist">
				{TABS.map((t) => (
					<button
						key={t.name}
						type="button"
						role="tab"
						aria-selected={activeTab === t.name}
						className={`spi-subtab${activeTab === t.name ? ' is-active' : ''}`}
						onClick={() => changeTab(t.name)}
					>
						{t.title}
					</button>
				))}
			</div>

			<div className="spi-subtab-content">
				{activeTab === 'new-scan' && (
					<ScanForm onSessionCreated={onScanCreated} onError={onError} />
				)}
				{activeTab === 'sessions' && (
					<SessionsList onOpen={openSession} onError={onError} onNotice={onNotice} />
				)}
				{activeTab === 'details' && (
					activeSessionId ? (
						<SessionDetail sessionId={activeSessionId} onError={onError} onNotice={onNotice} />
					) : (
						<EmptyDetails onGoToSessions={() => changeTab('sessions')} />
					)
				)}
			</div>
		</div>
	);
}

function EmptyDetails({ onGoToSessions }) {
	return (
		<div className="spi-card">
			<div className="spi-card__body" style={{ textAlign: 'center', padding: 48 }}>
				<p>{__('No session selected. Open one from the Sessions tab or start a new scan.', 'simple-post-importer')}</p>
				<button type="button" className="components-button is-secondary" onClick={onGoToSessions}>
					{__('Go to Sessions', 'simple-post-importer')}
				</button>
			</div>
		</div>
	);
}
