#!/usr/bin/env node
/* eslint-disable no-bitwise -- The ZIP format packs DOS timestamps and file attributes into bit fields. */
/**
 * Builds the Twitch Extension panel's ZIP for upload in Twitch's developer
 * console (ADR-112): dist/merchboard-twitch-extension-<version>-<host>.zip
 * with panel.html, panel.js and panel.css at the root.
 *
 *   npm run twitch-extension -- --site https://ccmerch.dk
 *
 * The site's REST URL of the panel is written into panel.html, so each site
 * (live, stg) gets its own ZIP. Pass --api <url> when the site does not use
 * /wp-json/ (plain permalinks). The ZIP never holds the extension's secret
 * or anything but the three files.
 *
 * No dependencies: the panel is built with @wordpress/scripts and the ZIP is
 * written with node:zlib (deflate + crc32).
 */
import { execFileSync } from 'node:child_process';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { crc32, deflateRawSync } from 'node:zlib';

const root = join( dirname( fileURLToPath( import.meta.url ) ), '..' );
const out = join( root, 'dist', 'twitch-extension' );

/**
 * The value after a flag, or null.
 * @param {string} flag The flag, e.g. --site.
 */
function argument( flag ) {
	const index = process.argv.indexOf( flag );

	return index > 0 && index + 1 < process.argv.length
		? process.argv[ index + 1 ]
		: null;
}

/**
 * Stops with a message.
 * @param {string} message Message.
 */
function fail( message ) {
	process.stderr.write( `twitch-extension: ${ message }\n` );
	process.exit( 1 );
}

const SAFE_URL = /^https:\/\/[a-z0-9.-]+(?::\d+)?(?:\/[A-Za-z0-9._~\/-]*)?$/;
const site = ( argument( '--site' ) ?? '' ).replace( /\/+$/, '' );
const api =
	argument( '--api' ) ??
	`${ site }/wp-json/merchboard/v1/twitch-extension/panel`;

if ( ! SAFE_URL.test( site ) ) {
	fail( 'pass --site https://<the shop> (https only).' );
}

if ( ! /^https:\/\/[A-Za-z0-9.:/_~?=&%-]+$/.test( api ) ) {
	fail( 'the panel URL must be an https URL without quotes or spaces.' );
}

const version = JSON.parse(
	readFileSync( join( root, 'package.json' ), 'utf8' )
).version;
const host = new URL( site ).host.replace( /[^a-z0-9.-]/g, '-' );

// 1. Build the panel.
execFileSync(
	process.execPath,
	[
		join(
			root,
			'node_modules',
			'@wordpress',
			'scripts',
			'bin',
			'wp-scripts.js'
		),
		'build',
		'panel=./assets/src/twitch-extension/panel.ts',
		`--output-path=${ join( out, 'build' ) }`,
	],
	{ cwd: root, stdio: 'inherit' }
);

// 2. The three files.
const html = readFileSync(
	join( root, 'assets', 'src', 'twitch-extension', 'panel.html' ),
	'utf8'
)
	.replace( '{{API}}', api.replace( /&/g, '&amp;' ) )
	.replace( '{{VERSION}}', version );
const files = [
	{
		name: 'panel.css',
		data: readFileSync( join( out, 'build', 'panel.css' ) ),
	},
	{ name: 'panel.html', data: Buffer.from( html, 'utf8' ) },
	{
		name: 'panel.js',
		data: readFileSync( join( out, 'build', 'panel.js' ) ),
	},
];

if ( /\{\{|\}\}/.test( html ) ) {
	fail( 'panel.html still has a placeholder.' );
}

/**
 * A ZIP (no ZIP64) of files with a fixed timestamp.
 * @param {Array<{name: string, data: Buffer}>} entries Files.
 */
function zip( entries ) {
	const time = 0;
	const date = ( ( 2026 - 1980 ) << 9 ) | ( 1 << 5 ) | 1;
	const locals = [];
	const centrals = [];
	let offset = 0;

	for ( const { name, data } of entries ) {
		const nameBuf = Buffer.from( name, 'utf8' );
		const compressed = deflateRawSync( data, { level: 9 } );
		const crc = crc32( data );

		const local = Buffer.alloc( 30 );
		local.writeUInt32LE( 0x04034b50, 0 );
		local.writeUInt16LE( 20, 4 );
		local.writeUInt16LE( 0x0800, 6 );
		local.writeUInt16LE( 8, 8 );
		local.writeUInt16LE( time, 10 );
		local.writeUInt16LE( date, 12 );
		local.writeUInt32LE( crc, 14 );
		local.writeUInt32LE( compressed.length, 18 );
		local.writeUInt32LE( data.length, 22 );
		local.writeUInt16LE( nameBuf.length, 26 );
		locals.push( local, nameBuf, compressed );

		const central = Buffer.alloc( 46 );
		central.writeUInt32LE( 0x02014b50, 0 );
		central.writeUInt16LE( ( 3 << 8 ) | 20, 4 );
		central.writeUInt16LE( 20, 6 );
		central.writeUInt16LE( 0x0800, 8 );
		central.writeUInt16LE( 8, 10 );
		central.writeUInt16LE( time, 12 );
		central.writeUInt16LE( date, 14 );
		central.writeUInt32LE( crc, 16 );
		central.writeUInt32LE( compressed.length, 20 );
		central.writeUInt32LE( data.length, 24 );
		central.writeUInt16LE( nameBuf.length, 28 );
		central.writeUInt32LE( ( 0o100644 << 16 ) >>> 0, 38 );
		central.writeUInt32LE( offset, 42 );
		centrals.push( central, nameBuf );

		offset += local.length + nameBuf.length + compressed.length;
	}

	const directory = Buffer.concat( centrals );
	const end = Buffer.alloc( 22 );
	end.writeUInt32LE( 0x06054b50, 0 );
	end.writeUInt16LE( entries.length, 8 );
	end.writeUInt16LE( entries.length, 10 );
	end.writeUInt32LE( directory.length, 12 );
	end.writeUInt32LE( offset, 16 );

	return Buffer.concat( [ ...locals, directory, end ] );
}

// 3. The ZIP.
mkdirSync( join( root, 'dist' ), { recursive: true } );
const target = join(
	root,
	'dist',
	`merchboard-twitch-extension-${ version }-${ host }.zip`
);
writeFileSync( target, zip( files ) );
process.stdout.write(
	`twitch-extension: ${ target }\n  panel reads ${ api }\n  allowlist ${ new URL( api ).host } for URL fetching and image domains in the developer console.\n`
);
