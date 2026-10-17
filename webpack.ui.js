const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	entry: {
		dashboard: path.resolve( __dirname, 'assets/src/dashboard/index.tsx' ),
		editor: path.resolve( __dirname, 'assets/src/editor/index.tsx' ),
		metabox: path.resolve( __dirname, 'assets/src/editor/metabox.tsx' ),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'assets/build' ),
	},
};
