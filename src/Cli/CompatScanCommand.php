<?php
/**
 * `wp slonik compat` — static compatibility scan (docs/10 §2, K-16).
 *
 * Static analysis is advisory only (K-17): never blocks activation.
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Cli;

use Slonik\Compat\DirSource;
use Slonik\Compat\HostParserFactory;
use Slonik\Compat\ScanReport;
use Slonik\Compat\Scanner;
use Slonik\Sql\Unsupported;

final class CompatScanCommand {

	/**
	 * Command entry (WP-CLI when available).
	 *
	 * @param array<int,string>    $args  Positional args.
	 * @param array<string,mixed>  $assoc Assoc args.
	 * @return int Exit code.
	 */
	public static function run( $args, $assoc = array() ) {
		if ( ! class_exists( 'WP_CLI', false ) ) {
			return 3;
		}
		$target = isset( $args[0] ) ? (string) $args[0] : '';
		if ( '' === $target ) {
			\WP_CLI::error( 'Usage: wp slonik compat <path> [--format=json|text]' );
			return 3;
		}
		if ( ! is_dir( $target ) ) {
			\WP_CLI::error( 'Scan target is not a directory: ' . $target );
			return 2;
		}
		self::ensure_parser();
		if ( ! self::parserAvailable() ) {
			\WP_CLI::error( 'static scan needs nikic/php-parser: run from a composer context or ship the plugin package intact (third-party/php-parser)' );
			return 3;
		}

		$scanner = new Scanner(
			new DirSource( $target ),
			array(
				'type' => 'path',
				'slug' => basename( rtrim( (string) str_replace( '\\', '/', $target ), '/' ) ),
			)
		);
		$report = $scanner->scan();
		$format = isset( $assoc['format'] ) ? (string) $assoc['format'] : 'text';
		if ( 'json' === $format ) {
			\WP_CLI::line( $report->toJson() );
		} else {
			\WP_CLI::line( self::renderText( $report ) );
			$exit = self::exitCode( $report->summary() );
			if ( 1 === $exit ) {
				\WP_CLI::line( 'compat: incompatible findings present (exit 1)' );
			}
			return $exit;
		}
		return self::exitCode( $report->summary() );
	}

	/**
	 * Vendored php-parser bootstrap (third-party copy for release packaging;
	 * composer autoload also works in dev trees).
	 */
	private static function ensure_parser() {
		try {
			( new HostParserFactory() )->create();
			return;
		} catch ( \Throwable $e ) {
			if ( defined( 'WPSLONIK_PLUGIN_DIR' ) ) {
				$boot = WPSLONIK_PLUGIN_DIR . '/third-party/php-parser/bootstrap.php';
			} else {
				$boot = dirname( __DIR__, 2 ) . '/third-party/php-parser/bootstrap.php';
			}
			if ( is_file( $boot ) ) {
				require_once $boot;
			}
		}
	}

	/**
	 * @return bool True when a parser instance can be created.
	 */
	public static function parserAvailable() {
		try {
			( new HostParserFactory() )->create();
			return true;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Advisory footer included (-- K-17 honesty).
	 *
	 * @param ScanReport $report Report.
	 * @return string
	 */
	public static function renderText( $report ) {
		$s    = $report->summary();
		$out  = self::summaryLine( $s ) . "\n";
		$gap  = self::unsupportedLine( $report );
		if ( '' !== $gap ) {
			$out .= $gap . "\n";
		}
		foreach ( $report->findings() as $f ) {
			$out .= $f['file'] . ':' . $f['line'] . ' ' . $f['status'];
			if ( count( $f['rules'] ?? array() ) > 0 ) {
				$out .= ' [' . implode( ',', $f['rules'] ) . ']';
			}
			$template = $f['sql_template'];
			if ( '' !== $template ) {
				$out .= ' ' . preg_replace( '/\s+/', ' ', $template );
			}
			if ( '' !== ( $f['message'] ?? '' ) ) {
				$out .= ' — ' . $f['message'];
			}
			$out .= "\n";
		}
		$out .= "static scan is advisory only — not a gate (K-17); verify live with wp slonik schema:check\n";
		return $out;
	}

	/**
	 * The constructs without a PostgreSQL equivalent, with their counts.
	 *
	 * @param ScanReport $report Report.
	 * @return string The line, or '' when there is none.
	 */
	public static function unsupportedLine( $report ) {
		$counts = array();
		foreach ( $report->findings() as $finding ) {
			$construct = isset( $finding['construct'] ) ? (string) $finding['construct'] : '';
			if ( '' === $construct ) {
				continue;
			}
			$label = Unsupported::label( $construct );
			$counts[ $label ] = isset( $counts[ $label ] ) ? $counts[ $label ] + 1 : 1;
		}
		if ( array() === $counts ) {
			return '';
		}
		arsort( $counts );
		$parts = array();
		foreach ( $counts as $label => $count ) {
			$parts[] = $label . ' ×' . $count;
		}
		return 'PostgreSQL karşılığı olmayan yapılar (' . Unsupported::RULE . '): ' . implode( ', ', $parts );
	}

	/**
	 * @param array<string,int> $summary Summary counts.
	 * @return string
	 */
	public static function summaryLine( $summary ) {
		$pairs = array();
		foreach ( array( 'compatible', 'warning', 'incompatible', 'unverified', 'info', 'skipped' ) as $k ) {
			$pairs[] = $k . ': ' . (int) ( $summary[ $k ] ?? 0 );
		}
		return 'Target compat summary — ' . implode( ', ', $pairs );
	}

	/**
	 * @param array<string,int> $summary Summary counts.
	 * @return int 0 when no incompatible, 1 otherwise.
	 */
	public static function exitCode( $summary ) {
		return ( $summary['incompatible'] ?? 0 ) > 0 ? 1 : 0;
	}
}
