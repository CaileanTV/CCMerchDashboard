#!/usr/bin/env node
/**
 * Runs the integration tests (tests/Integration) against a real MySQL/MariaDB.
 *
 * Two modes (see docs/TESTING.md):
 *
 * - External database: set MERCHBOARD_TEST_DB_HOST (plus _PORT, _NAME, _USER,
 *   _PASSWORD). Used by CI with the MySQL server that GitHub's runner image ships.
 *   The database is created if missing and its tables with the test prefix are
 *   dropped, so point it only at a database that exists for these tests.
 * - Otherwise (Windows): a portable MariaDB (pinned version, SHA-256 verified)
 *   is downloaded once into .tools/mariadb/ and started for this run only, on
 *   127.0.0.1 with a fresh, throwaway data directory in .tools/, then stopped.
 *   Nothing is installed as a service, and the run aborts if the port is in use
 *   instead of connecting to whatever listens there.
 *
 * Usage: node bin/integration.mjs [phpunit arguments]
 */
import { spawn, spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { existsSync, mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { createServer } from 'node:net';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve( dirname( fileURLToPath( import.meta.url ) ), '..' );
const phpunitArgs = process.argv.slice( 2 );

const MARIADB = {
	version: '10.11.19',
	sha256: '398ea30e5036010bbebe01d2b1804280424dcc2626e36d8e95155c04d25a0490',
};
const MARIADB_PORT = Number( process.env.MERCHBOARD_MARIADB_PORT || 3316 );

function runTests( env ) {
	const result = spawnSync(
		process.execPath,
		[
			join( root, 'bin', 'composer.mjs' ),
			'test:integration',
			'--',
			...phpunitArgs,
		],
		{ cwd: root, stdio: 'inherit', env: { ...process.env, ...env } }
	);
	return result.status ?? 1;
}

if ( process.env.MERCHBOARD_TEST_DB_HOST ) {
	process.exit( runTests( {} ) );
}

if ( process.platform !== 'win32' ) {
	console.error(
		'No portable MariaDB for this platform. Set MERCHBOARD_TEST_DB_HOST (and _PORT, _NAME, _USER, _PASSWORD) to a dedicated test database.'
	);
	process.exit( 1 );
}

const name = `mariadb-${ MARIADB.version }-winx64`;
const toolsDir = join( root, '.tools', 'mariadb' );
const baseDir = join( toolsDir, name );
const bin = ( exe ) => join( baseDir, 'bin', `${ exe }.exe` );

async function download() {
	const url = `https://archive.mariadb.org/mariadb-${ MARIADB.version }/winx64-packages/${ name }.zip`;
	console.log( `Downloading ${ url }` );
	const response = await fetch( url );
	if ( ! response.ok ) {
		throw new Error( `Download failed: HTTP ${ response.status }` );
	}
	const zip = Buffer.from( await response.arrayBuffer() );
	const hash = createHash( 'sha256' ).update( zip ).digest( 'hex' );
	if ( hash !== MARIADB.sha256 ) {
		throw new Error( `Checksum mismatch for ${ name }.zip (${ hash }).` );
	}
	mkdirSync( toolsDir, { recursive: true } );
	const zipPath = join( toolsDir, `${ name }.zip` );
	writeFileSync( zipPath, zip );
	// Windows' own bsdtar extracts zip files; Git Bash's GNU tar cannot.
	const tar = join(
		process.env.SystemRoot || 'C:\\Windows',
		'System32',
		'tar.exe'
	);
	const extracted = spawnSync( tar, [ '-xf', zipPath, '-C', toolsDir ], {
		stdio: 'inherit',
	} );
	rmSync( zipPath );
	if ( extracted.status !== 0 ) {
		throw new Error( 'Could not extract the MariaDB archive.' );
	}
}

function portIsFree( port ) {
	return new Promise( ( resolvePort ) => {
		const server = createServer();
		server.once( 'error', () => resolvePort( false ) );
		server.listen( port, '127.0.0.1', () =>
			server.close( () => resolvePort( true ) )
		);
	} );
}

function startServer( dataDir ) {
	return new Promise( ( resolveStart, rejectStart ) => {
		const server = spawn(
			bin( 'mariadbd' ),
			[
				'--no-defaults',
				`--basedir=${ baseDir }`,
				`--datadir=${ dataDir }`,
				`--port=${ MARIADB_PORT }`,
				'--bind-address=127.0.0.1',
				'--skip-name-resolve',
				'--console',
			],
			{ stdio: [ 'ignore', 'ignore', 'pipe' ] }
		);
		let output = '';
		const timer = setTimeout(
			() =>
				rejectStart(
					new Error( `MariaDB did not start:\n${ output }` )
				),
			60000
		);
		server.stderr.on( 'data', ( chunk ) => {
			output += chunk;
			if ( output.includes( 'ready for connections' ) ) {
				clearTimeout( timer );
				resolveStart( server );
			}
		} );
		server.once( 'exit', ( code ) => {
			clearTimeout( timer );
			rejectStart(
				new Error( `MariaDB exited with ${ code }:\n${ output }` )
			);
		} );
	} );
}

if ( ! existsSync( bin( 'mariadbd' ) ) ) {
	await download();
}

if ( ! ( await portIsFree( MARIADB_PORT ) ) ) {
	console.error(
		`Port ${ MARIADB_PORT } is in use. Set MERCHBOARD_MARIADB_PORT to a free port.`
	);
	process.exit( 1 );
}

// Throwaway data directory: every run starts from an empty server.
const dataDir = join( root, '.tools', 'mariadb-data' );
rmSync( dataDir, { recursive: true, force: true } );
const init = spawnSync(
	bin( 'mariadb-install-db' ),
	[ `--datadir=${ dataDir }` ],
	{ encoding: 'utf8' }
);
if ( init.status !== 0 ) {
	console.error( init.stdout, init.stderr );
	process.exit( 1 );
}

const server = await startServer( dataDir );
let status = 1;

try {
	status = runTests( {
		MERCHBOARD_TEST_DB_HOST: '127.0.0.1',
		MERCHBOARD_TEST_DB_PORT: String( MARIADB_PORT ),
		MERCHBOARD_TEST_DB_NAME: 'merchboard_tests',
		MERCHBOARD_TEST_DB_USER: 'root',
		MERCHBOARD_TEST_DB_PASSWORD: '',
	} );
} finally {
	spawnSync(
		bin( 'mariadb-admin' ),
		[ '-uroot', '-h127.0.0.1', `-P${ MARIADB_PORT }`, 'shutdown' ],
		{ stdio: 'ignore' }
	);
	await new Promise( ( done ) => {
		const timer = setTimeout( () => {
			server.kill();
			done();
		}, 15000 );
		server.once( 'exit', () => {
			clearTimeout( timer );
			done();
		} );
	} );
}

process.exit( status );
