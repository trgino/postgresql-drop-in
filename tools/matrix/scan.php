<?php
/**
 * The static half of the compatibility matrix: every plugin is scanned
 * for the MySQL constructs without a PostgreSQL equivalent (R-062) and
 * for the rules that translate with a deviation.
 *
 * The scan needs no WordPress and no database: it reads the plugin
 * sources (docs/11 §8).
 *
 * Usage:
 *   php tools/matrix/scan.php [--out=tools/matrix/out/scan.json]
 *
 * Exit code: 0 when every plugin was scanned, 1 otherwise.
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

use Slonik\Compat\DirSource;
use Slonik\Compat\HostParserFactory;
use Slonik\Compat\Scanner;

// The big plugins need more than the default 128M for the parser.
if ( function_exists( 'ini_set' ) ) {
	@ini_set( 'memory_limit', '1G' );
}

$root = dirname( __DIR__, 2 );
$out  = $root . '/tools/matrix/out/scan.json';
foreach ( array_slice( $argv, 1 ) as $arg ) {
	if ( 0 === strpos( $arg, '--out=' ) ) {
		$out = $root . '/' . ltrim( substr( $arg, 6 ), '/' );
	}
}
$plugins = $root . '/tools/matrix/out/plugins';

// The parser: the vendored copy first, the composer one otherwise.
try {
	( new HostParserFactory() )->create();
} catch ( \Throwable $e ) {
	$boot = $root . '/third-party/php-parser/bootstrap.php';
	if ( is_file( $boot ) ) {
		require_once $boot;
	} else {
		fwrite( STDERR, "the static scan needs nikic/php-parser (composer install or the vendored copy)\n" );
		exit( 1 );
	}
}

$manifest = json_decode( (string) file_get_contents( $root . '/tools/matrix/plugins.json' ), true );
$tiers    = array();
foreach ( array( 'tier1', 'popular' ) as $group ) {
	foreach ( (array) ( $manifest[ $group ] ?? array() ) as $slug ) {
		$tiers[ $slug ] = $group;
	}
}

/**
 * The plugin version from its main file header.
 *
 * @param string $dir Plugin directory.
 * @return string Version ('' when unknown).
 */
function slonik_plugin_version( $dir ) {
	$files = glob( rtrim( $dir, '/' ) . '/*.php' );
	if ( ! is_array( $files ) ) {
		return '';
	}
	$entries = array();
	foreach ( $files as $file ) {
		$entries[] = array(
			'path'     => basename( $file ),
			'contents' => (string) file_get_contents( $file ),
		);
	}
	return Scanner::detectVersion( $entries );
}

$report = array(
	'generated_by' => array( 'tool' => 'postgresql-drop-in', 'stage' => 'static' ),
	'plugins'      => array(),
);

$failed = 0;
foreach ( $tiers as $slug => $group ) {
	$dir = $plugins . '/' . $slug;
	if ( ! is_dir( $dir ) ) {
		echo "skip  {$slug} (not fetched)\n";
		++$failed;
		continue;
	}
	$scanner = new Scanner(
		new DirSource( $dir ),
		array(
			'type'    => 'plugin',
			'slug'    => $slug,
			'version' => slonik_plugin_version( $dir ),
			'tier'    => $group,
		)
	);
	$result = $scanner->scan();
	$summary = $result->summary();

	// The fail-closed constructs (R-062) decide the verdict.
	$gaps = array();
	foreach ( $result->findings() as $finding ) {
		if ( ! in_array( 'R-062', (array) ( $finding['rules'] ?? array() ), true ) ) {
			continue;
		}
		$construct = isset( $finding['construct'] ) ? (string) $finding['construct'] : '';
		$label     = '' !== $construct ? $construct : 'unknown';
		$gaps[ $label ] = isset( $gaps[ $label ] ) ? $gaps[ $label ] + 1 : 1;
	}
	$verdict = array() === $gaps ? 'compatible' : 'incompatible';
	if ( 'incompatible' !== $verdict && (int) ( $summary['warning'] ?? 0 ) > 0 ) {
		$verdict = 'warning';
	}

	echo str_pad( $slug, 30 ) . ' ' . str_pad( $verdict, 14 ) . ' findings=' . $summary['incompatible'] . '/' . $summary['warning'] . '/' . $summary['unverified'];
	if ( array() !== $gaps ) {
		echo '  gaps: ' . implode( ', ', array_map( static fn( $k, $v ) => $k . '×' . $v, array_keys( $gaps ), $gaps ) );
	}
	echo "\n";

	$report['plugins'][ $slug ] = array(
		'tier'     => $group,
		'verdict'  => $verdict,
		'summary'  => $summary,
		'gaps'     => $gaps,
		'target'   => $result->target(),
		'findings' => $result->findings(),
	);
}

if ( ! is_dir( dirname( $out ) ) ) {
	mkdir( dirname( $out ), 0777, true );
}
file_put_contents( $out, json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
echo "\nreport: " . str_replace( $root . '/', '', $out ) . "\n";
exit( $failed > 0 ? 1 : 0 );
