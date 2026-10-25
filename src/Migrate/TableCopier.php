<?php
/**
 * Table data copier (docs/15 §5.1/§5.2): per-batch COPY with the state
 * row committed in the same transaction as the data; keyset pagination
 * on the primary key; single-pass unbuffered streaming when there is
 * none (resume for keyless tables = truncate + restart, docs §5.3).
 * Row transforms per §5.2 with policy handling; no silent truncation.
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Migrate;

use Slonik\Schema\ColumnOverrides;

	final class TableCopier {

	/** @var CopySource */
	private $src;

	/** @var PgTarget */
	private $tgt;

	/** @var StateStore */
	private $state;	/** @var array{batch_rows:int,batch_bytes:int,invalid_utf8:string} */
	private $opts;

	/**
	 * @param CopySource $src   Row source.
	 * @param PgTarget   $tgt   Copy writer.
	 * @param StateStore $state State store.
	 * @param array      $opts  {batch_rows?,batch_bytes?,invalid_utf8?,in_txn?}.
	 *                          in_txn=true: caller owns the transaction
	 *                          (rehearsal) — flushGroup skips begin/commit.
	 */
	public function __construct( CopySource $src, PgTarget $tgt, StateStore $state, array $opts = array() ) {
		$this->src   = $src;
		$this->tgt   = $tgt;
		$this->state = $state;
		$this->opts  = array(
			'batch_rows'   => isset( $opts['batch_rows'] ) ? (int) $opts['batch_rows'] : 1000,
			'batch_bytes'  => isset( $opts['batch_bytes'] ) ? (int) $opts['batch_bytes'] : 8 * 1024 * 1024,
			'invalid_utf8' => isset( $opts['invalid_utf8'] ) ? (string) $opts['invalid_utf8'] : 'abort',
			'in_txn'       => ! empty( $opts['in_txn'] ),
		);
	}

	/**
	 * Copy one table.
	 *
	 * @param string                 $table    Source table name.
	 * @param array                  $meta     column_name-keyed rows from columnsOf()
	 *                                         (data_type/column_type/datetime_precision/extra).
	 * @param array                  $skip     Set of excluded columns (spatial/generated).
	 * @param array<int,string>      $pk       Primary key columns.
	 * @return array{rows:int,batches:int,skipped:int,error:string}
	 * @throws \Throwable Non-data failures (connection/copy) rethrow after
	 *                    the error state row is stored.
	 */
	public function copyTable( $table, array $meta, array $skip = array(), array $pk = array() ) {
		$table   = (string) $table;
		$columns = array();
		foreach ( array_keys( $meta ) as $name ) {
			if ( isset( $skip[ (string) $name ] ) ) {
				continue;
			}
			$columns[] = (string) $name;
		}
		$row = $this->state->get( $table );
		$st  = array(
			'rows_done' => isset( $row['rows_done'] ) ? (int) $row['rows_done'] : 0,
			'skipped'   => isset( $row['skipped'] ) ? (int) $row['skipped'] : 0,
			'nul'       => self::nulFromNote( isset( $row['note'] ) ? (string) $row['note'] : '' ),
			'last_key'  => isset( $row['last_key'] ) ? (string) $row['last_key'] : '',
			'batches'   => 0,
			'error'     => '',
		);
		if ( isset( $row['status'] ) && 'done' === $row['status'] ) {
			return array(
				'rows'    => $st['rows_done'],
				'batches' => 0,
				'skipped' => $st['skipped'],
				'nul'     => $st['nul'],
				'error'   => '',
			);
		}
		$this->state->set( $table, 'copy', $st['last_key'], $st['rows_done'], $st['skipped'], self::note( $st ) );

		$colmeta = array();
		foreach ( $columns as $name ) {
			$col = isset( $meta[ $name ] ) && is_array( $meta[ $name ] ) ? $meta[ $name ] : array();
			$colmeta[ $name ] = array(
				'type'               => strtolower( (string) ( $col['data_type'] ?? '' ) ),
				'unsigned'           => false !== strpos( (string) ( $col['column_type'] ?? '' ), ' unsigned' ),
				'datetime_precision' => (int) ( $col['datetime_precision'] ?? 0 ),
				// A column-level override can make a text column target
				// bytea (audit D-74): the payload keeps its bytes.
				'binary'             => ColumnOverrides::isBytea( $table, $name ),
			);
		}

		try {
			if ( array() === $pk ) {
				$this->copyNoKey( $table, $columns, $colmeta, $st );
			} else {
				$this->copyKeyed( $table, $columns, $colmeta, $pk, $st );
			}
			$this->state->set( $table, 'done', $st['last_key'], $st['rows_done'], $st['skipped'], self::note( $st ) );
			return array(
				'rows'    => $st['rows_done'],
				'batches' => $st['batches'],
				'skipped' => $st['skipped'],
				'nul'     => $st['nul'],
				'error'   => '',
			);
		} catch ( MigrateDataError $e ) {
			$this->state->set( $table, 'error', $st['last_key'], $st['rows_done'], $st['skipped'], $e->getMessage() );
			return array( 'rows' => $st['rows_done'], 'batches' => $st['batches'], 'skipped' => $st['skipped'], 'nul' => $st['nul'], 'error' => $e->getMessage() );
		} catch ( \Throwable $e ) {
			$this->state->set( $table, 'error', $st['last_key'], $st['rows_done'], $st['skipped'], 'unclassified: ' . get_class( $e ) . ' ' . $e->getMessage() );
			throw $e;
		}
	}

	/**
	 * State note for a copy in progress: the removed NUL byte count.
	 *
	 * PostgreSQL text cannot store a NUL byte, so the copy removes it
	 * (RowTransform). The count travels in the state note so a resumed
	 * run keeps counting and the operator sees the loss (audit D-63).
	 *
	 * @param array $st Running counters.
	 * @return string
	 */
	private static function note( array $st ): string {
		return 'nul=' . (int) ( $st['nul'] ?? 0 );
	}

	/**
	 * @param string $note State note.
	 * @return int The NUL byte count carried by the note.
	 */
	private static function nulFromNote( $note ): int {
		if ( is_string( $note ) && preg_match( '/(?:^|\s)nul=(\d+)/', $note, $m ) ) {
			return (int) $m[1];
		}
		return 0;
	}

	/** Per-row cell transform; throws the policies (§5.2). */
	private function transformRow( array $assoc, array $columns, array $colmeta, array &$st ) {
		$xform = RowTransform::row( $assoc, $columns, $colmeta, $this->opts['invalid_utf8'] );
		$st['nul'] += (int) $xform['nul'];
		return $xform['cells'];
	}

	/**
	 * Keyed tables: one batch query per step; state committed per flushed
	 * group; watermark advanced (resume continues from it).
	 *
	 * @param array $st Running counters (in/out by ref).
	 */
	private function copyKeyed( $table, array $columns, array $colmeta, array $pk, array &$st ) {
		$limit = max( 1, $this->opts['batch_rows'] );
		while ( true ) {
			$sql       = QueryPlanner::batchSql( $table, $columns, $pk, $st['last_key'], $limit );
			$cellsRows = array();
			$tuples    = array();
			foreach ( $this->src->stream( $sql ) as $assoc ) {
				try {
					$cellsRows[] = $this->transformRow( $assoc, $columns, $colmeta, $st );
				} catch ( SkipRowSignal $s ) {
					++$st['skipped'];
					continue;
				}
				$tuple = array();
				foreach ( $pk as $pkcol ) {
					$tuple[] = isset( $assoc[ (string) $pkcol ] ) ? (string) $assoc[ (string) $pkcol ] : '';
				}
				$tuples[] = $tuple;
			}
			if ( array() === $cellsRows ) {
				break;
			}
			$line_batches = CopyBatch::batches( $cellsRows, $this->opts['batch_rows'], $this->opts['batch_bytes'] );
			$offset       = 0;
			foreach ( $line_batches as $lines ) {
				$last = $tuples[ $offset + count( $lines ) - 1 ];
				$this->flushGroup( $table, $lines, $pk, $last, $st );
				$offset += count( $lines );
			}
			if ( count( $cellsRows ) < $limit ) {
				break;
			}
		}
	}

	/**
	 * Keyless tables: one unbuffered pass, groups flushed per bounds; no
	 * watermark (resume = truncate + restart, docs/15 §5.3).
	 *
	 * @param array $st Running counters (in/out by ref).
	 */
	private function copyNoKey( $table, array $columns, array $colmeta, array &$st ) {
		$sql   = 'SELECT ' . QueryPlanner::selectColumnsSql( $columns ) . ' FROM ' . MySqlSource::ident( $table );
		$buf   = array();
		$bytes = 0;
		foreach ( $this->src->stream( $sql ) as $assoc ) {
			try {
				$cells = $this->transformRow( $assoc, $columns, $colmeta, $st );
			} catch ( SkipRowSignal $s ) {
				++$st['skipped'];
				continue;
			}
			$line = CopyBatch::cell( isset( $cells[0] ) ? $cells[0] : null );
			for ( $i = 1, $n = count( $cells ); $i < $n; ++$i ) {
				$line .= "\t" . CopyBatch::cell( $cells[ $i ] );
			}
			$lb = strlen( $line ) + 1;
			if ( array() !== $buf
				&& ( $bytes + $lb > $this->opts['batch_bytes'] || count( $buf ) + 1 > $this->opts['batch_rows'] ) ) {
				$this->flushGroup( $table, $buf, array(), array(), $st );
				$buf   = array();
				$bytes = 0;
			}
			$buf[]  = $line;
			$bytes += $lb;
		}
		if ( array() !== $buf ) {
			$this->flushGroup( $table, $buf, array(), array(), $st );
		}
	}

	/**
	 * One COPY call + state update, committed atomically.
	 *
	 * @param array<int,string> $lines Copy lines (escaped, tab-delimited).
	 * @param array<int,string> $pk    PK column names ([] when keyless).
	 * @param array<int,string> $last  PK tuple covering these lines ([] when keyless).
	 * @param array             $st    Running state counters (by ref).
	 * @throws \Throwable On COPY/connection failure (transaction rolled back;
	 *                    last committed state row stays the resume point).
	 */
	private function flushGroup( $table, array $lines, array $pk, array $last, array &$st ) {
		if ( array() === $lines ) {
			return;
		}
		// in_txn (rehearsal): the caller owns the transaction — skip the
		// per-group begin/commit; everything commits with the outer txn.
		$owns_txn = empty( $this->opts['in_txn'] );
		if ( $owns_txn ) {
			$this->tgt->begin();
		}
		try {
			$this->tgt->copy( $table, $lines );
			if ( array() !== $pk ) {
				$st['last_key'] = QueryPlanner::encodeKey( $last );
			}
			$st['rows_done'] += count( $lines );
			++$st['batches'];
			$this->state->set( $table, 'copy', $st['last_key'], $st['rows_done'], $st['skipped'], self::note( $st ) );
			if ( $owns_txn ) {
				$this->tgt->commit();
			}
		} catch ( \Throwable $copy_error ) {
			if ( $owns_txn ) {
				$this->tgt->rollBack();
			}
			throw $copy_error;
		}
	}
}
