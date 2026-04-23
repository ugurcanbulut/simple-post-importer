import { createRoot } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import App from './App';
import './style.scss';

const config = window.SPI_CONFIG || {};

if (config.rootURL) {
	apiFetch.use(apiFetch.createRootURLMiddleware(config.rootURL));
}
if (config.nonce) {
	apiFetch.use(apiFetch.createNonceMiddleware(config.nonce));
}

const mount = () => {
	const el = document.getElementById('spi-admin-root');
	if (!el) return;
	const root = createRoot(el);
	root.render(<App config={config} />);
};

if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', mount);
} else {
	mount();
}
