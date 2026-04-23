import { useEffect } from '@wordpress/element';
import { Icon } from '@wordpress/components';
import { check, close, info } from '@wordpress/icons';

function Toast({ toast, onDismiss }) {
	useEffect(() => {
		const t = setTimeout(() => onDismiss(toast.id), toast.type === 'error' ? 8000 : 4500);
		return () => clearTimeout(t);
	}, [toast.id, toast.type, onDismiss]);

	const icon = toast.type === 'success' ? check : toast.type === 'error' ? close : info;

	return (
		<div className={`spi-toast spi-toast--${toast.type}`} role="status">
			<span className="spi-toast__icon"><Icon icon={icon} size={18} /></span>
			<span className="spi-toast__msg">{toast.message}</span>
			<button type="button" className="spi-toast__close" onClick={() => onDismiss(toast.id)}>
				<Icon icon={close} size={16} />
			</button>
		</div>
	);
}

export default function Toaster({ toasts, onDismiss }) {
	if (!toasts || toasts.length === 0) return null;
	return (
		<div className="spi-toaster" aria-live="polite">
			{toasts.map((t) => (
				<Toast key={t.id} toast={t} onDismiss={onDismiss} />
			))}
		</div>
	);
}
