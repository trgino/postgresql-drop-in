<?php
/**
 * Schema coherence checks (H-C13 catalog markers, H-C14 sequences).
 *
 * Pure logic over the Probe interface (ArrayProbe in tests, PdoProbe
 * live). Repair executes ONLY SELECT setval(...) statements it generated
 * itself; anything else throws instead of executing.
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Schema;

use Slonik\Health\Probe;

final class SchemaChecker {

	/** @var Probe */
	private $probe;

	/** @var string */
	private $schema;

	/** @var array */
	private $tables;

	/**
	 * @param Probe    $probe  Database probe.
	 * @param string   $schema Schema name.
	 * @param array    $tables Array of array(table, columns, identity|null).
	 */
	public function __construct( Probe $probe, $schema, $tables ) {
		$this->probe  = $probe;
		$this->schema = (string) $schema;
		$this->tables = (array) $tables;
	}

	/**
	 * @return array Array of array(id, status, message, table).
	 */
	public function check() {
		$out = array();
		foreach ( $this->tables as $spec ) {
			$spec    = (array) $spec;
			$table   = (string) $spec['table'];
			$columns = isset( $spec['columns'] ) ? array_values( (array) $spec['columns'] ) : array();
			$total   = (int) $this->probe->queryValue(
				"SELECT count(*) FROM information_schema.columns WHERE table_schema = '" . $this->lit( $this->schema )
				. "' AND table_name = '" . $this->lit( $table ) . "'"
			);
			$marked = (int) $this->probe->queryValue(
				"SELECT count(*) FROM pg_attribute a JOIN pg_class c ON c.oid = a.attrelid"
				. " JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = '" . $this->lit( $this->schema )
				. "' AND c.relname = '" . $this->lit( $table )
				. "' AND a.attnum > 0 AND NOT a.attisdropped AND col_description(a.attrelid, a.attnum) LIKE '{\"mysql\":%}'"
			);
			if ( $marked < $total || $total !== count( $columns ) ) {
				$out[] = array(
					'id'      => 'H-C13',
					'status'  => 'warn',
					'message' => $table . ': ' . $marked . '/' . $total . ' columns carry catalog markers (expected ' . count( $columns ) . ')',
					'table'   => $table,
				);
			} else {
				$out[] = array( 'id' => 'H-C13', 'status' => 'ok', 'message' => $table . ': markers coherent', 'table' => $table );
			}
			if ( isset( $spec['identity'] ) && '' !== (string) $spec['identity'] ) {
				$out[] = $this->sequenceStatus( $table, (string) $spec['identity'] );
			}
		}
		return $out;
	}

	/**
	 * @return array Repair SQL statements (setval only).
	 */
	public function repairs() {
		$out = array();
		foreach ( $this->tables as $spec ) {
			$spec = (array) $spec;
			if ( ! isset( $spec['identity'] ) || '' === (string) $spec['identity'] ) {
				continue;
			}
			$table = (string) $spec['table'];
			$col   = (string) $spec['identity'];
			$max   = (int) $this->probe->queryValue(
				'SELECT COALESCE(MAX("' . $this->ident( $col ) . '"), 0) FROM "' . $this->ident( $table ) . '"'
			);
			$seq = $this->probe->queryValue(
				'SELECT pg_get_serial_sequence(\'"' . $this->ident( $table ) . '"\', \'' . $this->lit( $col ) . '\')'
			);
			if ( ! is_string( $seq ) || '' === $seq ) {
				continue;
			}
			$last = (int) $this->probe->queryValue( 'SELECT last_value FROM ' . $this->qname( $seq ) );
			if ( $max > $last ) {
				$out[] = SequenceSync::sql( $table, $col );
			}
		}
		return $out;
	}

	/**
	 * Execute one repair statement (allowlisted).
	 *
	 * @param string $sql Repair SQL.
	 * @return bool
	 */
	public function repairStatement( $sql ) {
		$sql = ltrim( (string) $sql );
		if ( 0 !== stripos( $sql, 'SELECT setval(' ) || false !== strpos( $sql, ';' ) ) {
			throw new \RuntimeException( 'repair refused: not a single setval statement' );
		}
		return $this->probe->execOk( $sql );
	}

	/**
	 * @return array Single H-C14 status row.
	 */
	private function sequenceStatus( $table, $col ) {
		$max = (int) $this->probe->queryValue(
			'SELECT COALESCE(MAX("' . $this->ident( $col ) . '"), 0) FROM "' . $this->ident( $table ) . '"'
		);
		$seq = $this->probe->queryValue(
			'SELECT pg_get_serial_sequence(\'"' . $this->ident( $table ) . '"\', \'' . $this->lit( $col ) . '\')'
		);
		if ( ! is_string( $seq ) || '' === $seq ) {
			return array( 'id' => 'H-C14', 'status' => 'ok', 'message' => $table . ': no sequence', 'table' => $table );
		}
		$last = (int) $this->probe->queryValue( 'SELECT last_value FROM ' . $this->qname( $seq ) );
		if ( $max > $last ) {
			return array(
				'id'      => 'H-C14',
				'status'  => 'warn',
				'message' => $table . '.' . $col . ': max ' . $max . ' ahead of sequence ' . $last,
				'table'   => $table,
			);
		}
		return array( 'id' => 'H-C14', 'status' => 'ok', 'message' => $table . '.' . $col . ': sequence in sync', 'table' => $table );
	}

	private function lit( $value ) {
		return str_replace( "'", "''", (string) $value );
	}

	private function qname( $seq ) {
		$parts   = array();
		$current = '';
		$quoted  = false;
		$len     = strlen( (string) $seq );
		for ( $i = 0; $i < $len; ++$i ) {
			$ch = substr( (string) $seq, $i, 1 );
			if ( '"' === $ch ) {
				$quoted  = ! $quoted;
				$current .= $ch;
				continue;
			}
			if ( '.' === $ch && ! $quoted ) {
				$parts[] = $current;
				$current = '';
				continue;
			}
			$current .= $ch;
		}
		$parts[] = $current;
		$out     = array();
		foreach ( $parts as $part ) {
			$part  = trim( $part );
			$out[] = '"' . $this->ident( trim( $part, '"' ) ) . '"';
		}
		return implode( '.', $out );
	}

	private function ident( $value ) {
		return str_replace( '"', '""', (string) $value );
	}
}
