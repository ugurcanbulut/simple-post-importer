import { useState, useEffect, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import PushSessionsList from './PushSessionsList';
import PushCreate from './PushCreate';
import PushDetail from './PushDetail';

export default function PushSection({ onError, onNotice }) {
	const [view, setView] = useState({ name: 'list' });

	useEffect(() => {
		const hash = window.location.hash.replace('#', '');
		const parts = hash.split('/');
		if (parts[0] === 'push' && parts[1]) {
			if (parts[1] === 'new') setView({ name: 'create' });
			else if (parts[1].startsWith('session-')) {
				const id = parseInt(parts[1].replace('session-', ''), 10);
				if (!Number.isNaN(id)) setView({ name: 'detail', id });
			}
		}
	}, []);

	const gotoList = useCallback(() => {
		setView({ name: 'list' });
		window.location.hash = 'push';
	}, []);

	const gotoCreate = useCallback(() => {
		setView({ name: 'create' });
		window.location.hash = 'push/new';
	}, []);

	const gotoDetail = useCallback((id) => {
		setView({ name: 'detail', id });
		window.location.hash = `push/session-${id}`;
	}, []);

	if (view.name === 'create') {
		return (
			<PushCreate
				onCancel={gotoList}
				onCreated={gotoDetail}
				onError={onError}
				onNotice={onNotice}
			/>
		);
	}

	if (view.name === 'detail') {
		return (
			<PushDetail
				sessionId={view.id}
				onBack={gotoList}
				onError={onError}
				onNotice={onNotice}
			/>
		);
	}

	return (
		<PushSessionsList
			onNew={gotoCreate}
			onOpen={gotoDetail}
			onError={onError}
			onNotice={onNotice}
		/>
	);
}
