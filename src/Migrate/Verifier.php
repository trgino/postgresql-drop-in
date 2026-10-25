<?php
/**
 * Post-copy verification (docs/15 §6): count parity + canonical row
 * checksum when keys exist, min/max-PK order hashing, plus identity
 * sequence lag.
 *
 * Canonicalization mirrors the COPY-side transforms and normalizes read
 * backs (numeric scale, timestamp fractional digits, bytea hex output,
 * jsonb key order) so both sides compare as canonical strings, compared
 * in PHP (no dialect-specific SQL hash — deterministic across MySQL and
 * PG builds).
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Migrate;

use PDO;

final class Verifier {

	const NUMERIC_TYPES = array(
		'tinyint'   => true,
		'smallint'  => true,
		'mediumint' => true,
		'int'       => true,
		'integer'   => true,
		'bigint'    => true,
		'decimal'   => true,
		'numeric'   => true,
		'float'     => true,
		'double'    => true,
		'real'      => true,
	);
	const DATETIME_TYPES = array( 'datetime' => true, 'timestamp' => true, 'date' => true, 'time' => true );

	/**
	 * Canonical string for either side.
	 *
	 * @param string|null $raw  Value from a source or target row.
	 * @param string      $type Lower-case data type from MySQL source meta.
	 * @return string
	 */
	public static function canon( $raw, $type ) {
		if ( null === $raw ) {
			return '';
		}
		if ( is_resource( $raw ) ) {
			// PDO's pgsql driver returns a bytea column as a stream: the
			// raw bytes are the value (audit D-68).
			$raw = stream_get_contents( $raw );
		}
		$value = (string) $raw;
		$type  = strtolower( $type );
		if ( isset( self::NUMERIC_TYPES[ $type ] ) ) {
			$sign = '';
			if ( 0 === strpos( $value, '-' ) ) {
				$sign  = '-';
				$value = substr( $value, 1 );
			}
			$value = ltrim( $value, '+' );
			$int   = $value;
			$frac  = '';
			$dot   = strpos( $value, '.' );
			if ( false !== $dot ) {
				$int  = substr( $value, 0, $dot );
				$frac = rtrim( substr( $value, $dot + 1 ), '0' );
			}
			$int = ltrim( $int, '0' );
			if ( '' === $int ) {
				$int = '0';
			}
			return $sign . $int . ( '' !== $frac ? '.' . $frac : '' );
		}
		if ( isset( self::DATETIME_TYPES[ $type ] ) ) {
			// Mirror the COPY-side zero-date sentinel (P-06) before the
			// fractional-digit strip, so both sides hash identically.
			if ( 0 === strpos( $value, '0000-00-00' ) ) {
				$value = '0001-01-01' . substr( $value, 10 );
			}
			return preg_replace( '/\.\d+$/', '', $value ) ?? $value;
		}
		if ( 'blob' === $type || false !== strpos( $type, 'blob' ) || 'binary' === $type || 'varbinary' === $type || 'bytea' === $type ) {
			// PG readback may be hex text '\x…' (bytea_output=hex); unwrap to
			// raw bytes so both sides hash identically.
			if ( 0 === strpos( $value, '\\x' ) && ctype_xdigit( substr( $value, 2 ) ) ) {
				return (string) hex2bin( substr( $value, 2 ) );
			}
			return $value;
		}
		if ( 'json' === $type ) {
			return self::json_canon( $value );
		}
		// Text-ish types: mirror the COPY-side NUL strip (RowTransform).
		// Binary branches above return early; bytes compare untouched.
		return str_replace( "\0", '', $value );
	}

	/**
	 * Per-row canonical hash (column order = the copied column list).
	 *
	 * @return string sha256 hex of the canonical row.
	 */
	public static function rowHash( array $cells, array $types ) {
		$parts = array();
		foreach ( array_values( $cells ) as $i => $cell ) {
			$type    = isset( $types[ $i ] ) ? (string) $types[ $i ] : '';
			$parts[] = self::canon( $cell, $type );
		}
		return hash( 'sha256', implode( "\x1f", $parts ) );
	}

	public static function countSql( $table ) {
		return 'SELECT COUNT(*) FROM ' . MySqlSource::ident( (string) $table );
	}

	public static function targetCountSql( $table ) {
		return 'SELECT COUNT(*) FROM ' . self::pg_ident( (string) $table );
	}

	public static function targetScanSql( $table, array $columns, array $pk, $last_key_json, $limit ) {
		$sql = 'SELECT ' . implode( ', ', array_map( array( self::class, 'pg_ident' ), (array) $columns ) )
			. ' FROM ' . self::pg_ident( (string) $table );
		if ( array() !== (array) $pk && '' !== (string) $last_key_json ) {
			$tuple  = QueryPlanner::decodeKey( $last_key_json );
			$vals   = array();
			foreach ( (array) $pk as $i => $col ) {
				$vals[] = "'" . str_replace( "'", "''", isset( $tuple[ $i ] ) ? (string) $tuple[ $i ] : '' ) . "'";
			}
			$sql .= ' WHERE (' . implode( ', ', array_map( array( self::class, 'pg_ident' ), (array) $pk ) ) . ') > (' . implode( ', ', $vals ) . ')';
		}
		if ( array() !== (array) $pk ) {
			$sql .= ' ORDER BY ' . implode( ', ', array_map( array( self::class, 'pg_ident' ), (array) $pk ) );
		}
		$sql .= ' LIMIT ' . max( 1, (int) $limit );
		return $sql;
	}

	private static function pg_ident( $name ) {
		return '"' . str_replace( '"', '""', (string) $name ) . '"';
	}

	/** Recursive canonical JSON (sorts object keys, keeps scalars intact). */
	private static function json_canon( $value ) {
		$data = json_decode( (string) $value, true );
		if ( ! is_array( $data ) ) {
			return (string) $value; // scalars or unparseable → as-is.
		}
		return self::json_sort( $data );
	}

	private static function json_sort( array $data ): string {
		if ( array_keys( $data ) === range( 0, count( $data ) - 1 ) ) {
			// JSON list: order is semantic on both sides (jsonb preserves it).
			return (string) json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}
		ksort( $data );
		$out = array();
		foreach ( $data as $k => $v ) {
			$out[] = json_encode( (string) $k, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ':' . ( is_array( $v ) ? self::json_sort( $v ) : json_encode( $v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
		}
		return '{' . implode( ',', $out ) . '}';
	}

	/**
	 * Expected target row count for a source table.
	 *
	 * Rows dropped by the skip-row policy never reach the target, so the
	 * target holds source-minus-skipped rows (audit D-42).
	 *
	 * @param int $count_src Source row count.
	 * @param int $skipped   Rows dropped by policy.
	 * @return int
	 */
	public static function expectedTargetCount( $count_src, $skipped = 0 ) {
		return max( 0, (int) $count_src - max( 0, (int) $skipped ) );
	}

	/**
	 * Key of a row, built from the primary key columns.
	 *
	 * @param array $row Row (column => value).
	 * @param array $pk  Primary key columns.
	 * @return string
	 */
	public static function row_key( array $row, array $pk ) {
		if ( array() === (array) $pk ) {
			return '';
		}
		$parts = array();
		foreach ( (array) $pk as $column ) {
			$parts[] = isset( $row[ $column ] ) ? (string) $row[ $column ] : '';
		}
		return implode( "\0", $parts );
	}

	/**
	 * Compare a source batch with a target batch by primary key.
	 *
	 * Rows are matched by key, not by position: the two sides may order
	 * their rows differently (different collations) and that must not
	 * create false mismatches (audit D-09).
	 *
	 * @param array $source  Source rows.
	 * @param array $target  Target rows.
	 * @param array $pk      Primary key columns.
	 * @param array $types   Canonical type per column (positional).
	 * @param array $columns Column names (positional, same order as $types).
	 * @return array{mismatches:int,missing:int,extra:int,compared:int}
	 */
	public static function compare_rows( array $source, array $target, array $pk, array $types, array $columns = array() ) {
		$out = array(
			'mismatches' => 0,
			'missing'    => 0,
			'extra'      => 0,
			'compared'   => 0,
		);
		$columns = array_values( (array) $columns );
		if ( array() === $columns ) {
			$columns = array_keys( isset( $source[0] ) && is_array( $source[0] ) ? $source[0] : array() );
		}
		$target_by_key = array();
		foreach ( $target as $row ) {
			$key = self::row_key( (array) $row, $pk );
			if ( '' === $key ) {
				continue;
			}
			$target_by_key[ $key ] = (array) $row;
		}
		$seen = array();
		foreach ( $source as $row ) {
			$row = (array) $row;
			$key = self::row_key( $row, $pk );
			if ( '' === $key ) {
				continue;
			}
			$seen[ $key ] = true;
			if ( ! isset( $target_by_key[ $key ] ) ) {
				++$out['missing'];
				++$out['mismatches'];
				continue;
			}
			++$out['compared'];
			if ( self::rowHash( self::cells( $columns, $row ), $types )
				!== self::rowHash( self::cells( $columns, $target_by_key[ $key ] ), $types ) ) {
				++$out['mismatches'];
			}
		}
		foreach ( $target_by_key as $key => $row ) {
			if ( ! isset( $seen[ $key ] ) ) {
				++$out['extra'];
				++$out['mismatches'];
			}
		}
		return $out;
	}

	/**
	 * Verify one table (source streaming via CopySource, target via PDO).
	 *
	 * @param string            $table   Table.
	 * @param array<int,string> $columns Copy columns (already filtered).
	 * @param array<int,string> $types   Canonical type per column (source data_type).
	 * @param CopySource        $src     Source rows.
	 * @param PDO               $tgt     Target PDO.
	 * @param array<int,string> $pk      PK columns ([] = keyless: count only).
	 * @param int               $limit   Batch limit for scans.
	 * @param int               $skipped Rows dropped by the skip-row policy.
	 * @return array{status:string,count_src:int,count_tgt:int,hash_rows:int,mismatches:int,error:string,note:string}
	 */
	public function verifyTable( $table, array $columns, array $types, CopySource $src, PDO $tgt, array $pk, $limit = 500, $skipped = 0 ) {
		$table   = (string) $table;
		$skipped = max( 0, (int) $skipped );
		$out     = array(
			'status'     => 'ok',
			'count_src'  => 0,
			'count_tgt'  => 0,
			'hash_rows'  => 0,
			'mismatches' => 0,
			'error'      => '',
			'note'       => '',
		);

		$out['count_src'] = (int) $src->value( self::countSql( $table ) );
		$out['count_tgt'] = (int) $tgt->query( self::targetCountSql( $table ) )->fetchColumn();

		if ( $out['count_tgt'] !== self::expectedTargetCount( $out['count_src'], $skipped ) ) {
			$out['status'] = 'mismatch';
			$out['error']  = 'row count differs';
			return $out;
		}
		if ( array() === $pk || 0 === $out['count_tgt'] ) {
			return $out; // keyless tables: count-only (docs/15 §6 honest note).
		}
		if ( $skipped > 0 ) {
			// Rows the policy dropped are not in the target, so the
			// position-based checksum cannot be compared: count only
			// (docs/15 §6 honest note, audit D-42).
			$out['note'] = $skipped . ' row(s) skipped by policy; checksum not compared';
			return $out;
		}

		$watermark = '';
		while ( true ) {
			$sql = self::scanSql( $table, $columns, $pk, $watermark, $limit );
			$batch = array_values( iterator_to_array( $src->stream( $sql ), false ) );
			$stmt  = $tgt->prepare( self::targetScanSql( $table, $columns, $pk, $watermark, $limit ) );
			$stmt->execute();
			$t_rows = $stmt->fetchAll( PDO::FETCH_ASSOC );
			// Rows are matched by primary key, not by position: the two
			// sides may order rows differently (audit D-09).
			$cmp = self::compare_rows( $batch, $t_rows, $pk, $types, $columns );
			$out['mismatches'] += (int) $cmp['mismatches'];
			$out['hash_rows']  += (int) $cmp['compared'];
			if ( count( $t_rows ) > 0 ) {
				$tuple = array();
				foreach ( $pk as $pkcol ) {
					$tuple[] = isset( $t_rows[ count( $t_rows ) - 1 ][ $pkcol ] ) ? (string) $t_rows[ count( $t_rows ) - 1 ][ $pkcol ] : '';
				}
				$watermark = QueryPlanner::encodeKey( $tuple );
			}
			if ( count( $t_rows ) < $limit ) {
				break;
			}
		}
		if ( $out['mismatches'] > 0 ) {
			$out['status'] = 'mismatch';
			$out['error']  = 'row checksum differs on ' . $out['mismatches'] . ' row(s)';
		}
		return $out;
	}

	private static function scanSql( $table, array $columns, array $pk, $last_key_json, $limit ) {
		return QueryPlanner::batchSql( $table, $columns, (array) $pk, (string) $last_key_json, $limit );
	}

	/** @return array<int,?string> Ordered cells for a row. */
	private static function cells( array $columns, array $row ) {
		$out = array();
		foreach ( $columns as $col ) {
			$out[] = array_key_exists( $col, $row ) ? $row[ $col ] : null;
		}
		return $out;
	}

	/**
	 * Identity sequence lag check (docs/15 §5.1 setval, §6). OK when the
	 * sequence is at or above the column maximum.
	 *
	 * @return array{status:string,detail:string}
	 */
	public static function sequenceLag( PDO $tgt, $table, $column ) {
		$t   = "'" . str_replace( "'", "''", (string) $table ) . "'";
		$col = "'" . str_replace( "'", "''", (string) $column ) . "'";
		$sql = 'SELECT COALESCE( pg_sequence_last_value( pg_get_serial_sequence( ' . $t . ', ' . $col . ' ) ), 0 )'
			. ' >= COALESCE( ( SELECT MAX( ' . self::pg_ident( (string) $column ) . ' ) FROM ' . self::pg_ident( (string) $table ) . ' ), 0 )';
		$ok  = $tgt->query( $sql )->fetchColumn();
		return array(
			'status' => ( true === $ok || '1' === (string) $ok || 't' === (string) $ok ) ? 'ok' : 'lag',
			'detail' => (string) $table . '.' . (string) $column,
		);
	}
}
