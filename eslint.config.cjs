/**
 * ESLint flat config: WordPress defaults from @wordpress/scripts plus project overrides.
 */
const wordpressConfig = require( '@wordpress/scripts/config/eslint.config.cjs' );

module.exports = [
	{
		ignores: [ 'build/**', 'dist/**', 'vendor/**', '.tools/**' ],
	},
	...wordpressConfig,
	{
		// Node CLI helpers print to the terminal by design.
		files: [ 'bin/**/*.mjs' ],
		rules: {
			'no-console': 'off',
		},
	},
];
