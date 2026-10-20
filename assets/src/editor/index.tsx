import apiFetch from '@wordpress/api-fetch';
import { PluginSidebar } from '@wordpress/editor';
import { proposalRevisionHeaders } from './proposal-revision';
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

apiFetch.use( ( options, next ) => {
	const extra = proposalRevisionHeaders(
		String( options.path || '' ),
		String( options.method || 'GET' ).toUpperCase()
	);
	if ( extra ) {
		const headers = new Headers(
			options.headers as HeadersInit | undefined
		);
		Object.entries( extra ).forEach( ( [ name, value ] ) => {
			headers.set( name, value );
		} );
		const plain: Record< string, string > = {};
		headers.forEach( ( value, name ) => {
			plain[ name ] = value;
		} );
		options.headers = plain;
	}
	return next( options );
} );

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
