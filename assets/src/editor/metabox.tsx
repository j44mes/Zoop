import { createRoot } from '@wordpress/element';
import { Panel } from './panel';

interface EditorConfig {
	postId: number;
	blockEditor: boolean;
	workerUrl: string;
	permalink: string;
}

declare global {
	interface Window {
		seostudioEditor?: EditorConfig;
	}
}

const config = window.seostudioEditor;
const mount = document.getElementById( 'seostudio-metabox' );

if ( config && mount ) {
	createRoot( mount ).render(
		<Panel
			postId={ config.postId }
			blockEditor={ false }
			workerUrl={ config.workerUrl }
			permalink={ config.permalink }
		/>
	);
}
