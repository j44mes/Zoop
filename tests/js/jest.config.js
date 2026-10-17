const path = require( 'path' );
const base = require( '@wordpress/scripts/config/jest-unit.config' );

module.exports = {
	...base,
	rootDir: path.resolve( __dirname, '../..' ),
	testMatch: [ '<rootDir>/tests/js/**/*.test.[jt]s?(x)' ],
	setupFiles: [ '<rootDir>/tests/js/setup.js' ],
	transformIgnorePatterns: [ '^(?!.*yoastseo/node_modules/).*node_modules' ],
};
