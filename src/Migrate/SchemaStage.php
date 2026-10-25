<?php
/**
 * Schema staging for the migration (docs/15 §5.3, §5.1).
 *
 * SHOW CREATE TABLE output → sanitized → MinDdl (the single translation
 * source of truth). Tables are created with indexes EXCLUDED from the data
 * phase (faster load, docs/15 §5.1); those become `extra` statements to
 * run after the copy, together with sequence sync + ANALYZE.
 *
 * Unsupported MySQL objects (FULLTEXT/SPATIAL keys, FK constraints, CHECK,
 * other named constraints) are line-stripped WITH a notice (docs/15 §5.3:
 * "tablo oluşturulur, bu indeksler/kolonlar atlanır + uyarı"). This is
 * metadata-line preprocessing of MySQL DDL text, not SQL rewriting: the
 * translation itself is still emitted from the parsed AST (MinDdl) only.
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Migrate;

use Slonik\Schema\MinDdl;
use Slonik\Sql\TranslationException;

final class SchemaStage {

	/** @var array<string,true> Spatial column DATA_TYPEs — skipped (NG-8, docs/15 §5.3). */
	const SPATIAL_TYPES = array(
		'point'             => true,
		'geometry'        => true,
		'linestring'      => true,
		'polygon'         => true,
		'multipoint'      => true,
		'multilinestring' => true,
		'multipolygon'    => true,
		'geomcollection'  => true,
		'geometrycollection' => true,
	);

	/**
	 * @param string $ddl MySQL SHOW CREATE TABLE body (or a CREATE TABLE stmt).
	 * @return array{table:string,extra:array<int,string>,comments:array<int,string>,notices:array<int,string>,skip_columns:array<int,string>}
	 * @throws TranslationException When MinDdl cannot translate the remainder.
	 */
	public static function stage( $ddl, array $options = array() ) {
		$cleaned = self::sanitize( (string) $ddl );
		$output  = MinDdl::translate( implode( "\n", $cleaned['lines'] ), $options );
		$table   = (string) array_shift( $output );
		$extra   = array();
		$comments = array();
		foreach ( $output as $stmt ) {
			$head = substr( (string) $stmt, 0, 32 );
			if ( 0 === strpos( $head, 'CREATE INDEX' )
				|| 0 === strpos( $head, 'CREATE OR REPLACE FUNCTION' )
				|| 0 === strpos( $head, 'CREATE OR REPLACE TRIGGER' )
				|| 0 === strpos( $head, 'CREATE TRIGGER' ) ) {
				// Indexes and the ON UPDATE triggers belong to the final
				// phase (after the copy), not to the column comments.
				$extra[] = (string) $stmt;
				continue;
			}
			$comments[] = (string) $stmt;
		}
		return array(
			'table'        => $table,
			'extra'        => $extra,
			'comments'     => $comments,
			'notices'      => $cleaned['notices'],
			'skip_columns' => $cleaned['skip_columns'],
		);
	}

	/**
	 * Line-level sanitizer for SHOW CREATE output.
	 *
	 * @return array{lines:array<int,string>,notices:array<int,string>,skip_columns:array<int,string>}
	 */
	public static function sanitize( $ddl ) {
		$lines        = array();
		$notices      = array();
		$skip_columns = array();
		foreach ( explode( "\n", str_replace( "\r\n", "\n", (string) $ddl ) ) as $line ) {
			$line = rtrim( $line, "\r" );
			$t    = ltrim( $line );
			if ( 0 === strpos( $t, 'FULLTEXT KEY ' ) ) {
				$notices[] = preg_replace( '/^[[:space:]]+/', '', $t );
				continue;
			}
			if ( 0 === strpos( $t, 'SPATIAL KEY ' ) ) {
				$notices[] = preg_replace( '/^[[:space:]]+/', '', $t );
				continue;
			}
			if ( 0 === strpos( $t, 'CONSTRAINT ' ) ) {
				$u = strtoupper( $t );
				if ( false !== strpos( $u, ' FOREIGN KEY' ) ) {
					$notices[] = $t;
					continue;
				}
				if ( false !== strpos( $u, ' UNIQUE' ) ) {
					// Keep the uniqueness: citext collisions matter (docs/15 §4).
					$pos = strpos( $t, 'UNIQUE' );
					$lines[] = substr( $t, (int) $pos );
					continue;
				}
				$notices[] = $t;
				continue;
			}
			$spatial = self::spatial_column( $t );
			if ( '' !== $spatial ) {
				$notices[]       = $t;
				$skip_columns[]  = $spatial;
				continue;
			}
			if ( 0 === strpos( $t, '`' ) && 1 === preg_match( '/^`([^\`]+)`[[:space:]].*(GENERATED[[:space:]]+(ALWAYS[[:space:]]+)?AS[[:space:]]|VIRTUAL[[:space:]]|STORED[[:space:]])/', $t ) ) {
				// Generated-expression columns are skipped consistently on
				// both sides (docs/15 runtime parity note; v1).
				$notices[]      = $t;
				$skip_columns[] = (string) preg_replace( '/^`([^\`]+)`.*$/', '$1', $t );
				continue;
			}
			$lines[] = $line;
		}
		for ( $i = count( $lines ) - 1; $i >= 0; --$i ) {
			$t = ltrim( $lines[ $i ] );
			if ( 0 === strpos( $t, ')' ) ) {
				$prev = $i - 1;
				if ( isset( $lines[ $prev ] ) ) {
					$body = rtrim( $lines[ $prev ] );
					if ( ',' === substr( $body, -1 ) ) {
						$lines[ $prev ] = substr( $body, 0, -1 ) . substr( $lines[ $prev ], strlen( $body ) );
					}
				}
				break;
			}
		}
		return array( 'lines' => $lines, 'notices' => $notices, 'skip_columns' => $skip_columns );
	}

	/**
	 * @param string $line Def line.
	 * @return string Column name when the line declares a spatial column, else ''.
	 */
	private static function spatial_column( $line ) {
		if ( 1 !== preg_match( '/^`([^\`]+)`[[:space:]]+([a-z]+)/i', $line, $m ) ) {
			return '';
		}
		return isset( self::SPATIAL_TYPES[ strtolower( $m[2] ) ] ) ? (string) $m[1] : '';
	}
}
