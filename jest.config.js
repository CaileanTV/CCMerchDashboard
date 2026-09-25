/**
 * Jest config for the creator dashboard (phase 13): the @wordpress/scripts
 * defaults (jsdom, Babel with TypeScript), limited to the frontend sources so
 * vendor/, .tools/ and the PHP tests are never scanned. Jest merges the
 * preset's setup files with ours.
 */
const defaultConfig = require( '@wordpress/scripts/config/jest-unit.config.js' );

module.exports = {
	...defaultConfig,
	roots: [ '<rootDir>/assets/src' ],
	setupFiles: [ '<rootDir>/assets/src/dashboard/testing/setup.ts' ],
};
