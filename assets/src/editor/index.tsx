import { PluginSidebar } from '@wordpress/editor';
import { __ } from '@wordpress/i18n';
import { registerPlugin } from '@wordpress/plugins';
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

if ( config?.blockEditor ) {
	registerPlugin( 'seostudio', {
		render() {
			return (
				<PluginSidebar
					name="seostudio"
					title={ __( 'Zoop', 'seo-studio' ) }
					icon="chart-bar"
				>
					<Panel
						postId={ config.postId }
						blockEditor
						workerUrl={ config.workerUrl }
						permalink={ config.permalink }
						metadata={ config.metadata }
						section={ config.section || '' }
					/>
				</PluginSidebar>
			);
		},
	} );
}
