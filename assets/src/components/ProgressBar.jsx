export default function ProgressBar({ percent }) {
	const pct = Math.max(0, Math.min(100, Number(percent) || 0));
	return (
		<div className="spi-progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow={pct}>
			<div className="spi-progress-bar__fill" style={{ width: `${pct}%` }} />
		</div>
	);
}
