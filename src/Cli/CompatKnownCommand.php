<?php
/**
 * `wp slonik known` — known-compatible list v1 (docs/10 §5, K-19).
 *
 * `list|add|check`; adds write to the SITE-local file
 * (`wp-content/wpslonik/known-compat.json` or --file). Packaged list is
 * read-only. Remote fetch is out of scope (K-33: opt-in feature later).
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Cli;

use Slonik\Compat\KnownList;

final class CompatKnownCommand {

	/**
	 * Default files in lookup order: packaged first (upstream truth), then
	 * the site-local writable copy.
	 *
	 * @return array<int,string>
	 */
	public static function defaultFiles() {
		$packaged = self::pluginDir() . '/compat/known-compat.json';
		$site     = ( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : sys_get_temp_dir() ) . '/wpslonik/known-compat.json';
		return array( $packaged, $site );
	}

	private static function pluginDir() {
		return defined( 'WPSLONIK_PLUGIN_DIR' ) ? (string) WPSLONIK_PLUGIN_DIR : dirname( __DIR__, 2 );
	}

	/**
	 * Command entry.
	 *
	 * @param array<int,string>   $args  Positional args.
	 * @param array<string,mixed> $assoc Assoc args.
	 * @return int
	 */
	public static function run( $args, $assoc = array() ) {
		if ( ! class_exists( 'WP_CLI', false ) ) {
			return 3;
		}
		$sub = isset( $args[0] ) ? (string) $args[0] : '';
		if ( 'list' === $sub ) {
			\WP_CLI::line( self::renderList() );
			return 0;
		}
		if ( 'add' === $sub ) {
			return self::runAdd( $assoc );
		}
		$slug      = (string) ( $assoc['slug'] ?? '' );
		$version   = (string) ( $assoc['version'] ?? '' );
		$tree_hash = (string) ( $assoc['tree-hash'] ?? '' );
		$exit      = self::exitFor( 'check', array( 'slug' => $slug, 'version' => $version, 'tree_hash' => $tree_hash ) );
		if ( 3 === $exit && class_exists( 'WP_CLI', false ) ) {
			\WP_CLI::error( 'Usage: wp slonik known check --slug=<slug> --version=<x.y.z> --tree-hash=sha256:<64hex>' );
		}
		list( $label, $message ) = self::checkOutcome( self::defaultFiles(), $slug, $version, $tree_hash );
		\WP_CLI::line( $label . ': ' . $message );
		return 0;
	}

	/**
	 * Pure: subcommand arg validation → exit code (3 = usage error).
	 *
	 * @param string               $sub list|add|check|bogus.
	 * @param array<string, mixed> $args Args under check.
	 * @return int
	 */
	public static function exitFor( $sub, $args ) {
		if ( ! in_array( (string) $sub, array( 'list', 'add', 'check' ), true ) ) {
			return 3;
		}
		if ( 'add' === $sub && ! isset( $args['result'] ) ) {
			return 3;
		}
		if ( ! ( isset( $args['slug'] ) && '' !== (string) $args['slug'] ) ) {
			return 3;
		}
		if ( ! ( isset( $args['version'] ) && '' !== (string) $args['version'] ) ) {
			return 3;
		}
		$hash = (string) ( $args['tree_hash'] ?? '' );
		if ( 1 !== preg_match( '/^sha256:[0-9a-f]{64}$/', $hash ) ) {
			return 3;
		}
		if ( 'add' === $sub ) {
			return in_array( (string) $args['result'], KnownList::RESULTS, true ) ? 0 : 3;
		}
		return 0;
	}

	/**
	 * Pure: known check outcome.
	 *
	 * @param array<int,string> $files  Lookup files.
	 * @param string            $slug      Slug.
	 * @param string            $version   Version.
	 * @param string            $tree_hash Tree hash.
	 * @return array{0:string,1:string} [label, message].
	 */
	public static function checkOutcome( $files, $slug, $version, $tree_hash ) {
		$result = KnownList::lookup( $files, $slug, $version, $tree_hash );
		if ( null === $result ) {
			return array( 'unverified', 'unknown slug: ' . (string) $slug );
		}
		return array( $result, (string) $slug . ' ' . (string) $version );
	}

	/**
	 * Pure-ish save (unit seam): upsert entry to a file, exit-code shaped.
	 *
	 * @param string               $file  Path.
	 * @param array<string, mixed> $entry Entry fields.
	 * @return int 0 ok else code.
	 */
	public static function saveEntry( $file, $entry ) {
		return KnownList::writeFile( $file, $entry );
	}

	private static function runAdd( $assoc ) {
		$args = array(
			'slug'      => (string) ( $assoc['slug'] ?? '' ),
			'version'   => (string) ( $assoc['version'] ?? '' ),
			'tree_hash' => (string) ( $assoc['tree-hash'] ?? '' ),
			'result'    => (string) ( $assoc['result'] ?? '' ),
			'notes'     => (string) ( $assoc['notes'] ?? '' ),
		);
		$exit = self::exitFor( 'add', $args );
		if ( 3 === $exit ) {
			\WP_CLI::error( 'Usage: wp slonik known add --slug=<slug> --version=<x.y.z> --tree-hash=sha256:<64hex> --result=<compatible|warning|incompatible> [--notes="…"]' );
			return 3;
		}
		$file = isset( $assoc['file'] ) ? (string) $assoc['file'] : '';
		if ( '' === $file ) {
			$dir = ( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : sys_get_temp_dir() ) . '/wpslonik';
			if ( ! is_dir( $dir ) ) {
				\WP_CLI::error( 'site wpslonik dir missing; pass --file=/path/known-compat.json' );
				return 2;
			}
			$file = $dir . '/known-compat.json';
		}
		if ( ! preg_match( '/\.json$/', $file ) || false !== strpos( $file, '..' ) ) {
			\WP_CLI::error( '--file must be a local .json path (no traversal)' );
			return 3;
		}
		$code = KnownList::writeFile( $file, $args );
		if ( 0 !== $code ) {
			\WP_CLI::error( 'cannot write ' . $file );
			return $code;
		}
		\WP_CLI::success( 'entry upserted: ' . $args['slug'] . ' ' . $args['version'] );
		return 0;
	}

	private static function renderList() {
		return self::renderListFor( self::defaultFiles() );
	}

	public static function renderListFor( $files ) {
		$rows = array();
		foreach ( (array) $files as $file ) {
			$list = KnownList::loadFile( (string) $file );
			foreach ( $list['entries'] as $entry ) {
				$rows[] = $entry['slug'] . '@' . $entry['version'] . ' => ' . $entry['result'];
			}
		}
		if ( count( $rows ) === 0 ) {
			return 'known list: (empty)' . "\n";
		}
		return 'known list (' . count( $rows ) . "):\n" . implode( "\n", $rows ) . "\n";
	}
}
