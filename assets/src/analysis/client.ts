import type { ContentAnalysis, PaperInput } from './score-paper';

interface WorkerResponse {
	type: string;
	result?: ContentAnalysis;
	message?: string;
}

export function scoreInWorker(
	workerUrl: string,
	paper: PaperInput
): Promise< ContentAnalysis > {
	return new Promise( ( resolve, reject ) => {
		let worker: Worker;
		try {
			worker = new Worker( workerUrl );
		} catch ( error ) {
			reject( error );
			return;
		}
		const timer = window.setTimeout( () => {
			worker.terminate();
			reject( new Error( 'The analysis worker took too long.' ) );
		}, 20000 );
		worker.onmessage = ( event: MessageEvent< WorkerResponse > ) => {
			window.clearTimeout( timer );
			worker.terminate();
			if ( event.data?.type === 'result' && event.data.result ) {
				resolve( event.data.result );
				return;
			}
			reject(
				new Error(
					event.data?.message || 'The analysis worker failed.'
				)
			);
		};
		worker.onerror = () => {
			window.clearTimeout( timer );
			worker.terminate();
			reject( new Error( 'The analysis worker failed to load.' ) );
		};
		worker.postMessage( { type: 'score', id: 'panel', paper } );
	} );
}
