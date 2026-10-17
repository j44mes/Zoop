const path = require( 'path' );

module.exports = {
	mode: 'production',
	target: 'webworker',
	entry: path.resolve( __dirname, 'assets/src/analysis/worker.ts' ),
	output: {
		filename: 'worker.js',
		path: path.resolve( __dirname, 'assets/build' ),
		globalObject: 'self',
	},
	resolve: {
		extensions: [ '.ts', '.js' ],
		fallback: {
			url: require.resolve( 'url/' ),
		},
	},
	module: {
		rules: [
			{
				test: /\.ts$/,
				use: {
					loader: 'ts-loader',
					options: {
						transpileOnly: true,
						compilerOptions: {
							noEmit: false,
							module: 'esnext',
							target: 'ES2019',
						},
					},
				},
				exclude: /node_modules/,
			},
		],
	},
};
