/**
 * ESLint flat config: WordPress defaults from @wordpress/scripts plus project overrides.
 */
const wordpressConfig = require( '@wordpress/scripts/config/eslint.config.cjs' );

// The WordPress unit-test preset, which @wordpress/scripts applies to .js/.jsx tests only.
const testUnitConfig = wordpressConfig.filter(
	( config ) =>
		Array.isArray( config.files ) &&
		config.files.includes( '**/@(test|__tests__)/**/*.{js,jsx}' )
);

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
	// The dashboard tests are TypeScript.
	...testUnitConfig.map( ( config ) => ( {
		...config,
		files: [ 'assets/src/**/test/*.{ts,tsx}' ],
	} ) ),
	{
		// Creator dashboard (docs/FRONTEND.md, docs/SECURITY.md).
		files: [ 'assets/src/**/*.{ts,tsx}' ],
		rules: {
			// Never render HTML strings: server values are always text.
			'react/no-danger': 'error',
			// No escape hatch around strict typing; untrusted data is `unknown`.
			'@typescript-eslint/no-explicit-any': 'error',
			// Credentials and state never go to browser storage.
			'no-restricted-globals': [
				'error',
				'localStorage',
				'sessionStorage',
				'indexedDB',
			],
			'no-restricted-properties': [
				'error',
				{ object: 'window', property: 'localStorage' },
				{ object: 'window', property: 'sessionStorage' },
				{ object: 'document', property: 'cookie' },
			],
			// Parameters are documented by their TypeScript types.
			'jsdoc/require-param': 'off',
			// Types come from `react`; runtime React comes from WordPress (@wordpress/element).
			'import/no-extraneous-dependencies': 'off',
		},
	},
	{
		// Tests may assert that storage and cookies stay empty.
		files: [ 'assets/src/**/test/*.{ts,tsx}' ],
		rules: {
			'no-restricted-properties': 'off',
		},
	},
];
