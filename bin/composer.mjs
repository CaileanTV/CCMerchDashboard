#!/usr/bin/env node
/**
 * Cross-platform Composer launcher for this project.
 *
 * Uses a global `composer` when available, otherwise the project-local
 * `.tools/composer.phar` (see docs/DEVELOPMENT.md). When the PHP CLI has no
 * php.ini (common with WinGet installs on Windows), the extensions Composer
 * needs are enabled per invocation instead of changing the global PHP setup.
 *
 * Usage: node bin/composer.mjs <composer arguments>
 */
import { spawnSync } from 'node:child_process';
import {
	existsSync,
	mkdirSync,
	readdirSync,
	rmSync,
	writeFileSync,
} from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve( dirname( fileURLToPath( import.meta.url ) ), '..' );
const args = process.argv.slice( 2 );
const REQUIRED_EXTENSIONS = [
	'openssl',
	'mbstring',
	'curl',
	'zip',
	'fileinfo',
];

function run( command, commandArgs ) {
	const result = spawnSync( command, commandArgs, {
		cwd: root,
		stdio: 'inherit',
		shell: false,
	} );
	if ( result.error ) {
		throw result.error;
	}
	process.exit( result.status ?? 1 );
}

const phar = join( root, '.tools', 'composer.phar' );

if ( ! existsSync( phar ) ) {
	const probe = spawnSync( 'composer', [ '--version' ], {
		stdio: 'ignore',
		shell: process.platform === 'win32',
	} );
	if ( probe.status === 0 ) {
		const result = spawnSync( 'composer', args, {
			cwd: root,
			stdio: 'inherit',
			shell: process.platform === 'win32',
		} );
		process.exit( result.status ?? 1 );
	}
	console.error(
		'Composer not found. Install it globally or place composer.phar in .tools/ (see docs/DEVELOPMENT.md).'
	);
	process.exit( 1 );
}

const modules = spawnSync( 'php', [ '-m' ], { encoding: 'utf8' } );
if ( modules.status !== 0 ) {
	console.error( 'PHP CLI not found on PATH.' );
	process.exit( 1 );
}

const loaded = modules.stdout.toLowerCase().split( /\r?\n/ );
const missing = REQUIRED_EXTENSIONS.filter(
	( ext ) => ! loaded.includes( ext )
);
if ( missing.length > 0 ) {
	// Composer scripts (phpunit, phpcs, phpstan) start new PHP processes, which
	// would not inherit `-d` flags. A project-local ini picked up through
	// PHP_INI_SCAN_DIR is inherited by every child process instead.
	const binary = spawnSync( 'php', [ '-r', 'echo PHP_BINARY;' ], {
		encoding: 'utf8',
	} ).stdout;
	const iniDir = join( root, '.tools', 'php-ini' );
	const ini = [
		`extension_dir="${ join( dirname( binary ), 'ext' ) }"`,
		...missing.map( ( ext ) => `extension=${ ext }` ),
		'',
	].join( '\n' );

	mkdirSync( iniDir, { recursive: true } );
	// The directory is owned by this script: drop any other ini file so no
	// extension is loaded twice (PHP warns "Module already loaded").
	for ( const file of readdirSync( iniDir ) ) {
		if ( file.endsWith( '.ini' ) && file !== 'merchboard-dev.ini' ) {
			rmSync( join( iniDir, file ) );
		}
	}
	writeFileSync( join( iniDir, 'merchboard-dev.ini' ), ini );
	process.env.PHP_INI_SCAN_DIR = iniDir;
}

run( 'php', [ phar, ...args ] );
