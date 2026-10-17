import { createRoot } from '@wordpress/element';
import { Panel, type MetadataOwner } from './panel';

interface EditorConfig {
	postId: number;
	blockEditor: boolean;
	workerUrl: string;
	permalink: string;
	section?: string;
	metadata: MetadataOwner;
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
			metadata={ config.metadata }
			section={ config.section || '' }
		/>
	);
}
