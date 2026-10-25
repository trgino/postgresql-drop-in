<?php
/**
 * Known compatible list v1 (docs/10 §5, K-19; K-33 packaged default).
 *
 * Files: packaged plugin compat list + site-local writable copies. Missing
 * or malformed files degrade to "unverified" lookups (never an error).
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Compat;

final class KnownList {

	const RESULTS = array( 'compatible', 'warning', 'incompatible' );

	/**
	 * Load one file; tolerant: malformed json/schema → empty entries.
	 *
	 * @param string $file Path to json.
	 * @return array{schema:int, entries:array<int, array<string, mixed>>}
	 */
	public static function loadFile( $file ) {
		$base = array(
			'schema'  => 1,
			'entries' => array(),
		);
		if ( ! is_file( (string) $file ) ) {
			return $base;
		}
		try {
			$data = json_decode( (string) file_get_contents( (string) $file ), true );
			if ( ! is_array( $data ) || ! isset( $data['schema'] ) || 1 !== (int) $data['schema'] || ! is_array( $data['entries'] ?? null ) ) {
				return $base;
			}
			$entries = array();
			foreach ( $data['entries'] as $entry ) {
				if ( ! is_array( $entry ) || ! isset( $entry['slug'], $entry['version'], $entry['tree_hash'], $entry['result'] ) ) {
					continue;
				}
				if ( ! in_array( (string) $entry['result'], self::RESULTS, true ) ) {
					continue;
				}
				$entries[] = array(
					'slug'       => (string) $entry['slug'],
					'version'    => (string) $entry['version'],
					'tree_hash'  => (string) $entry['tree_hash'],
					'result'     => (string) $entry['result'],
					'deviations' => isset( $entry['deviations'] ) && is_array( $entry['deviations'] ) ? array_values( array_map( 'strval', $entry['deviations'] ) ) : array(),
					'tested_with' => isset( $entry['tested_with'] ) && is_array( $entry['tested_with'] ) ? $entry['tested_with'] : array(),
					'notes'      => isset( $entry['notes'] ) ? (string) $entry['notes'] : '',
				);
			}
			return array(
				'schema'  => 1,
				'entries' => $entries,
			);
		} catch ( \Throwable $e ) {
			return $base;
		}
	}

	/**
	 * Exact slug+version+tree_hash lookup over a list of files
	 * (packaged defaults merged with site copies; first hit wins).
	 *
	 * @param array<int,string> $files  JSON paths.
	 * @param string            $slug       Target slug.
	 * @param string            $version    Target version.
	 * @param string            $tree_hash  Target tree hash (sha256:…).
	 * @return ?string result value or null when unknown ("doğrulanmadı").
	 */
	public static function lookup( $files, $slug, $version, $tree_hash ) {
		foreach ( (array) $files as $file ) {
			foreach ( self::loadFile( (string) $file )['entries'] as $entry ) {
				if (
					(string) $entry['slug'] === (string) $slug
					&& (string) $entry['version'] === (string) $version
					&& (string) $entry['tree_hash'] === (string) $tree_hash
				) {
					return (string) $entry['result'];
				}
			}
		}
		return null;
	}

	/**
	 * Write (upsert) one entry into a site-local list file.
	 *
	 * @param string               $file  Target file (must be .json).
	 * @param array<string, mixed> $entry {slug,version,tree_hash,result}.
	 * @return int 0 ok, 2 no dir, 3 invalid input.
	 */
	public static function writeFile( $file, $entry ) {
		if ( ! is_dir( dirname( (string) $file ) ) ) {
			return 2;
		}
		if ( ! in_array( (string) ( $entry['result'] ?? '' ), self::RESULTS, true ) ) {
			return 3;
		}
		$list    = self::loadFile( $file );
		$entries = $list['entries'];
		$kept    = array();
		foreach ( $entries as $row ) {
			if (
				$row['slug'] === (string) $entry['slug']
				&& $row['version'] === (string) $entry['version']
				&& $row['tree_hash'] === (string) $entry['tree_hash']
			) {
				continue;
			}
			$kept[] = $row;
		}
		$kept[] = array(
			'slug'       => (string) $entry['slug'],
			'version'    => (string) $entry['version'],
			'tree_hash'  => (string) $entry['tree_hash'],
			'result'     => (string) $entry['result'],
			'deviations' => array(),
			'notes'      => (string) ( $entry['notes'] ?? '' ),
		);
		$json = json_encode( array( 'schema' => 1, 'entries' => $kept ), JSON_PRETTY_PRINT );
		// Atomic upsert: write a temp sibling then rename (parity with CompatLog).
		$tmp = (string) $file . '.tmp';
		if ( false === @file_put_contents( $tmp, (string) $json . "\n" ) ) {
			return 2;
		}
		if ( ! @rename( $tmp, (string) $file ) ) {
			@unlink( $tmp );
			return 2;
		}
		return 0;
	}
}
