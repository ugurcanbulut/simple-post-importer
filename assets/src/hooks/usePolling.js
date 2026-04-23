import { useEffect, useRef, useState, useCallback } from '@wordpress/element';

/**
 * Poll an async function on an interval until isDone returns true.
 */
export function usePolling({ fn, intervalMs = 800, isDone, onError }) {
	const [running, setRunning] = useState(false);
	const [lastResult, setLastResult] = useState(null);
	const stopRef = useRef(false);
	const fnRef = useRef(fn);
	const doneRef = useRef(isDone);
	const errRef = useRef(onError);

	useEffect(() => {
		fnRef.current = fn;
	}, [fn]);
	useEffect(() => {
		doneRef.current = isDone;
	}, [isDone]);
	useEffect(() => {
		errRef.current = onError;
	}, [onError]);

	const stop = useCallback(() => {
		stopRef.current = true;
		setRunning(false);
	}, []);

	const start = useCallback(async () => {
		if (running) return;
		stopRef.current = false;
		setRunning(true);
		/* eslint-disable no-await-in-loop */
		while (!stopRef.current) {
			try {
				const result = await fnRef.current();
				setLastResult(result);
				if (doneRef.current && doneRef.current(result)) {
					stopRef.current = true;
					setRunning(false);
					return result;
				}
			} catch (e) {
				if (errRef.current) errRef.current(e);
				stopRef.current = true;
				setRunning(false);
				throw e;
			}
			await new Promise((r) => setTimeout(r, intervalMs));
		}
		setRunning(false);
		return null;
		/* eslint-enable no-await-in-loop */
	}, [intervalMs, running]);

	useEffect(() => () => { stopRef.current = true; }, []);

	return { start, stop, running, lastResult };
}
