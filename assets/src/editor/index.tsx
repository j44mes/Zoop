import { PluginSidebar } from '@wordpress/edit-post';
import { __ } from '@wordpress/i18n';
import { registerPlugin } from '@wordpress/plugins';
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

if ( config?.blockEditor ) {
	registerPlugin( 'seostudio', {
		render() {
			return (
				<PluginSidebar
					name="seostudio"
					title={ __( 'SEO', 'seo-studio' ) }
					icon="chart-bar"
				>
					<Panel
						postId={ config.postId }
						blockEditor
						workerUrl={ config.workerUrl }
						permalink={ config.permalink }
					/>
				</PluginSidebar>
			);
		},
	} );
}
