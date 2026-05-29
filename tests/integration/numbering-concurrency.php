<?php
/**
 * Concurrency test for the gap-free / duplicate-free invoice numbering.
 *
 * This is the legally-critical guarantee (art. 242 nonies A CGI: no gaps,
 * no duplicates in the numbering series). It cannot be covered by an
 * isolated PHPUnit test because the guarantee lives in MySQL's atomic
 * `UPDATE ... LAST_INSERT_ID()` under concurrent connections — so we
 * spawn real parallel OS processes that hammer the same counter row.
 *
 * It runs OUTSIDE WordPress, connecting straight to Local's MySQL
 * (127.0.0.1:10006, root/root/local), because wp-cli can't resolve the
 * DB host from an external shell on this setup. It uses a DEDICATED test
 * option key so the real invoice counter is never touched.
 *
 * IMPORTANT: the worker replicates the EXACT two SQL statements used by
 * InvoiceNumbering::get_next_invoice_number(). If that SQL ever changes,
 * mirror it here.
 *
 * Run:
 *   php tests/integration/numbering-concurrency.php
 *
 * @package Mathis\FacturX\WooCommerce
 */

declare(strict_types=1);

const DB_HOST = '127.0.0.1';
const DB_PORT = 10006;
const DB_USER = 'root';
const DB_PASS = 'root';
const DB_NAME = 'local';
const TABLE   = 'wp_options';
const KEY     = 'mathisfx_test_concurrency_counter';

const WORKERS         = 12;   // parallel processes
const NUMBERS_PER_JOB = 60;   // numbers each consumes
const EXPECTED        = WORKERS * NUMBERS_PER_JOB;

/**
 * Open a mysqli connection or die loudly.
 */
function mfx_connect(): mysqli {
	$mysqli = @new mysqli( DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT );
	if ( $mysqli->connect_errno ) {
		fwrite( STDERR, 'DB connect failed: ' . $mysqli->connect_error . "\n" );
		exit( 2 );
	}
	return $mysqli;
}

/**
 * Consume ONE number using the exact same atomic SQL as the plugin.
 */
function mfx_next_number( mysqli $mysqli ): int {
	// Mirror of InvoiceNumbering::get_next_invoice_number().
	$stmt = $mysqli->prepare(
		'UPDATE ' . TABLE . '
		 SET option_value = LAST_INSERT_ID(CAST(option_value AS UNSIGNED) + 1)
		 WHERE option_name = ?'
	);
	$key = KEY;
	$stmt->bind_param( 's', $key );
	$stmt->execute();
	$stmt->close();

	$res = $mysqli->query( 'SELECT LAST_INSERT_ID()' );
	$row = $res->fetch_row();
	return (int) $row[0];
}

/* ===================================================================== */
/* WORKER MODE: php numbering-concurrency.php --worker                    */
/* ===================================================================== */
if ( in_array( '--worker', $argv, true ) ) {
	$mysqli  = mfx_connect();
	$numbers = array();
	for ( $i = 0; $i < NUMBERS_PER_JOB; $i++ ) {
		$numbers[] = mfx_next_number( $mysqli );
	}
	$mysqli->close();
	// One number per line on stdout.
	echo implode( "\n", $numbers ) . "\n";
	exit( 0 );
}

/* ===================================================================== */
/* ORCHESTRATOR MODE (default)                                           */
/* ===================================================================== */
echo "=== Test de concurrence — numérotation ===\n";
echo 'Workers parallèles : ' . WORKERS . ' × ' . NUMBERS_PER_JOB . ' = ' . EXPECTED . " numéros\n\n";

// 1. Reset the dedicated test counter to 0.
$mysqli = mfx_connect();
$mysqli->query( "DELETE FROM " . TABLE . " WHERE option_name = '" . KEY . "'" );
$mysqli->query( "INSERT INTO " . TABLE . " (option_name, option_value, autoload) VALUES ('" . KEY . "', '0', 'no')" );
$mysqli->close();

// 2. Launch WORKERS subprocesses in parallel via proc_open.
$php       = PHP_BINARY;
$self      = __FILE__;
$descriptor = array(
	0 => array( 'pipe', 'r' ),
	1 => array( 'pipe', 'w' ),
	2 => array( 'pipe', 'w' ),
);

$procs = array();
$pipes = array();
for ( $w = 0; $w < WORKERS; $w++ ) {
	$p = proc_open(
		escapeshellarg( $php ) . ' ' . escapeshellarg( $self ) . ' --worker',
		$descriptor,
		$wpipes
	);
	if ( ! is_resource( $p ) ) {
		fwrite( STDERR, "Failed to spawn worker {$w}\n" );
		exit( 2 );
	}
	$procs[ $w ] = $p;
	$pipes[ $w ] = $wpipes;
}

// 3. Collect every worker's stdout (this also lets them run concurrently).
$all = array();
foreach ( $pipes as $w => $wpipes ) {
	$out = stream_get_contents( $wpipes[1] );
	fclose( $wpipes[0] );
	fclose( $wpipes[1] );
	fclose( $wpipes[2] );
	proc_close( $procs[ $w ] );

	foreach ( preg_split( '/\R/', trim( $out ) ) as $line ) {
		if ( $line !== '' && ctype_digit( $line ) ) {
			$all[] = (int) $line;
		}
	}
}

// 4. Cleanup the test key.
$mysqli = mfx_connect();
$mysqli->query( "DELETE FROM " . TABLE . " WHERE option_name = '" . KEY . "'" );
$mysqli->close();

// 5. Assertions.
$total  = count( $all );
$unique = count( array_unique( $all ) );
sort( $all );
$min          = $total ? $all[0] : 0;
$max          = $total ? $all[ $total - 1 ] : 0;
$contiguous   = ( $total > 0 ) && ( ( $max - $min + 1 ) === $total ) && ( $unique === $total );

echo 'Total numéros obtenus : ' . $total . ' (attendu ' . EXPECTED . ")\n";
echo 'Numéros uniques       : ' . $unique . "\n";
echo 'Plage                 : ' . $min . ' … ' . $max . "\n";
echo 'Doublons              : ' . ( $total - $unique ) . "\n";
echo 'Trous dans la plage   : ' . ( $total > 0 ? ( ( $max - $min + 1 ) - $unique ) : 0 ) . "\n\n";

if ( $total === EXPECTED && $unique === $total && $contiguous ) {
	echo "RESULTAT : ✅ PASS — aucun doublon, aucun trou, sous " . WORKERS . " processus parallèles.\n";
	exit( 0 );
}

echo "RESULTAT : ❌ FAIL — la garantie d'unicité/continuité est violée.\n";
exit( 1 );
