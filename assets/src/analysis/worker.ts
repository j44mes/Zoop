import { scorePaper, type PaperInput } from './score-paper';

interface ScoreMessage {
	type: 'score';
	id: string;
	paper: PaperInput;
}

const scope = self as unknown as {
	onmessage: ( ( event: MessageEvent< ScoreMessage > ) => void ) | null;
	postMessage: ( message: unknown ) => void;
};

scope.onmessage = ( event ) => {
	if ( event.data?.type !== 'score' ) {
		return;
	}
	try {
		scope.postMessage( {
			type: 'result',
			id: event.data.id,
			result: scorePaper( event.data.paper ),
		} );
	} catch ( error ) {
		scope.postMessage( {
			type: 'error',
			id: event.data.id,
			message:
				error instanceof Error ? error.message : 'Analysis failed.',
		} );
	}
};
