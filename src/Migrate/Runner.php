<?php
/**
 * Migration orchestrator (docs/15 §4–§7): connects, plans, and runs the
 * phases — schema staging, data copy, final phase (indexes, sequences,
 * ANALYZE), verification, resume, status, reset-target.
 *
 * No WP classes and no WP_CLI calls here: the CLI layer (Slonik\
 * Cli\MigrateCommand) owns entry + exit codes; this class owns the flow.
 * Output goes through the injected $io callable as plain text. Secrets
 * are never echoed (redaction contract, docs/15 §1.2).
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Migrate;

use PDO;

use Slonik\Schema\ColumnOverrides;
use Slonik\Schema\SequenceSync;

final class Runner {

	const EXIT_OK      = 0;
	const EXIT_WARN    = 1;
	const EXIT_BLOCKED = 2;
	const EXIT_VERIFY  = 4;

	/** @var MigrateConfig */
	private $cfg;

	/** @var callable */
	private $io;

	/** @var MySqlSource|null */
	private $src;

	/** @var PgTarget|null */
	private $tgt;

	/** @var State|null */
	private $state;

	/** @var string|null Rehearsal override: schema the census looks at. */

	/** @var string Case-sensitivity mode of the target (citext|degraded). */
	private $ci_mode = '';
	private $pg_schema_override;

	/**
	 * @param MigrateConfig $cfg Validated config.
	 * @param callable      $io  Output sink: function(string $text): void.
	 */
	public function __construct( MigrateConfig $cfg, callable $io ) {
		$this->cfg = $cfg;
		$this->io  = $io;
	}

	public function out( $text ) {
		( $this->io )( (string) $text );
	}

	/**
	 * Preflight (docs/15 §4): environment + source + target checks, no writes.
	 *
	 * @param array $assoc CLI assoc args (format).
	 * @return int Exit code.
	 */
	public function preflight( array $assoc ): int {
		$this->describe();

		$plan = $this->buildPlan( true, array() );
		$code = $this->reportPlan( $plan, $assoc );
		if ( array() !== $plan['errors'] || count( $plan['blockers'] ) > 0 ) {
			return self::EXIT_BLOCKED;
		}
		return $code;
	}

	/**
	 * No writes: full analysis + plan (docs/15 §4); safe even with
	 * WPSLONIK_ENABLED=true (read-only).
	 *
	 * @param array $assoc CLI assoc args.
	 * @return int Exit code.
	 */
	public function dryRun( array $assoc ): int {
		$this->describe();
		$plan = $this->buildPlan( true, array() );
		$this->reportPlan( $plan, $assoc );
		$this->out( '' );
		$this->out( 'dry-run wrote nothing (source and target untouched); plan only.' );
		if ( array() !== $plan['errors'] || count( $plan['blockers'] ) > 0 ) {
			return self::EXIT_BLOCKED;
		}
		return $this->warnCode( $plan['warnings'] );
	}

	/**
	 * Rehearsal (K-49): schema + data + final phase inside ONE target
	 * transaction, then ROLLBACK. Nothing persists.
	 *
	 * @param array $assoc CLI assoc args (yes/tables).
	 * @return int Exit code.
	 */
	public function rehearse( array $assoc ): int {
		$gate = $this->writeGate( $assoc, 'rehearse' );
		if ( self::EXIT_OK !== $gate ) {
			return $gate;
		}
		$this->describe();
		$real_schema = (string) $this->cfg->target()['schema'];
		$plan = $this->buildPlan( false, self::subset( $assoc ) );
		if ( array() !== $plan['errors'] ) {
			foreach ( $plan['errors'] as $error ) {
				$this->out( 'blocker: ' . $error );
			}
			return self::EXIT_BLOCKED;
		}
		$rows = self::eligible( $plan['plan']['tables'] );
		if ( array() === $rows ) {
			$this->out( 'nothing to rehearse (no planned tables in scope)' );
			return self::EXIT_OK;
		}

		try {
			$this->connect_both();
			$this->snapshot();
			$this->out( 'rehearsal: one transaction, rolled back at the end (K-49)' );
			$this->tgt->begin();
			$real_state = $this->state;
			$this->out( 'rehearsal schema: isolated <' . $real_schema . '_rehearsal> inside the transaction' );
			try {
				// Everything lands in a transaction-local rehearsal schema:
				// SET LOCAL is scoped to the txn, CREATE SCHEMA + tables +
				// COPY + final phase + the state table roll back as one —
				// a dirty/populated real target cannot distort the proof.
				$rehearsal = $real_schema . '_rehearsal';
				$quote     = function ( $ident ) {
					return '"' . str_replace( '"', '""', (string) $ident ) . '"';
				};
				$this->tgt->exec( 'CREATE SCHEMA ' . $quote( $rehearsal ) );
				$this->tgt->exec( 'SET LOCAL search_path TO ' . $quote( $rehearsal ) . ', public' );
				$this->state = new State( $this->tgt->pdo(), $rehearsal );
				$this->state->ensureTable();
				$this->pg_schema_override = $rehearsal;
				$stats = $this->schemaAndCopy( $rows, true );
				$final = array();
				foreach ( $stats['rows'] as $fname => $fcount ) {
					$final[ $fname ] = (int) $fcount;
				}
				$this->finalPhase( $final, $assoc, true, $stats['errors'] );
				$this->pg_schema_override = null;
				$this->state              = $real_state;
				$this->tgt->rollBack();
			} catch ( \Throwable $e ) {
				$this->pg_schema_override = null;
				$this->state              = $real_state;
				$this->tgt->rollBack();
				throw $e;
			}
		} catch ( \Throwable $e ) {
			$this->out( 'error: rehearsal failed: ' . $e->getMessage() );
			return self::EXIT_BLOCKED;
		}

		$this->reportCopy( $stats, $rows );
		$this->out( '' );
		$this->out( 'rolled back: target schema unchanged (rehearsal proof done)' );
		return array() === $stats['errors'] ? self::EXIT_OK : self::EXIT_WARN;
	}

	/**
	 * Full run (docs/15 §5); resume re-enters the same path ('done' state
	 * short-circuits; interrupted tables continue from the watermark).
	 *
	 * @param array $assoc CLI assoc args.
	 * @return int Exit code.
	 */
	public function run( array $assoc ): int {
		$gate = $this->writeGate( $assoc, 'run' );
		if ( self::EXIT_OK !== $gate ) {
			return $gate;
		}
		$this->describe();
		$plan = $this->buildPlan( true, self::subset( $assoc ) );
		if ( array() !== $plan['errors'] || count( $plan['blockers'] ) > 0 ) {
			foreach ( $plan['errors'] as $error ) {
				$this->out( 'blocker: ' . $error );
			}
			return self::EXIT_BLOCKED;
		}
		$rows = self::eligible( $plan['plan']['tables'] );
		if ( array() === $rows ) {
			$this->out( 'nothing to run: all scoped tables are done/excluded' );
			return self::EXIT_OK;
		}
		if ( array() !== $plan['warnings'] && empty( $assoc['quiet'] ) ) {
			foreach ( $plan['warnings'] as $warning ) {
				$this->out( 'warning: ' . $warning );
			}
		}

		try {
			$this->connect_both();
			$this->snapshot();
			$this->state->ensureTable();
			$this->acquireLock();
			$this->out( 'run: ' . count( $rows ) . ' table(s) in scope' );
			$stats = $this->schemaAndCopy( $rows, false );
			// Final phase sweeps ALL done tables (idempotent), so resume
			// converges sequences/indexes/ANALYZE beyond the merged tables.
			$final_map = array();
			foreach ( $this->state->all() as $sname => $srow ) {
				if ( 'done' === (string) $srow['status'] ) {
					$final_map[ (string) $sname ] = (int) $srow['rows_done'];
				}
			}
			foreach ( $stats['rows'] as $fname => $fcount ) {
				$final_map[ $fname ] = (int) $fcount;
			}
			$this->finalPhase( $final_map, $assoc, false, $stats['errors'] );
			$this->state->unlock();
		} catch ( \Throwable $e ) {
			$this->out( 'error: migration aborted: ' . $e->getMessage() );
			return self::EXIT_BLOCKED;
		}

		$this->reportCopy( $stats, $rows );
		$verify = $this->verifyPhase( $rows, $assoc );
		if ( array() !== $verify['mismatches'] ) {
			$this->out( Report::nextSteps() );
			return self::EXIT_VERIFY;
		}
		if ( array() !== $stats['errors'] ) {
			$this->out( Report::nextSteps() );
			return self::EXIT_WARN;
		}
		$this->out( Report::nextSteps() );
		return self::result_code( $stats, $plan );
	}

	/**
	 * Exit code for a finished run.
	 *
	 * Errors (copy, schema or final phase) mean the run is not clean:
	 * the operator must see a warning exit code, not success (audit
	 * D-19).
	 *
	 * @param array $stats Run stats with an 'errors' list.
	 * @param array $plan  Plan with a 'warnings' list.
	 * @return int
	 */
	public static function result_code( array $stats, array $plan = array() ) {
		if ( array() !== (array) ( $stats['errors'] ?? array() ) ) {
			return self::EXIT_WARN;
		}
		$warnings = (array) ( $plan['warnings'] ?? array() );
		return array() === $warnings ? self::EXIT_OK : self::EXIT_WARN;
	}

	/**
	 * Progress/errors per table from the state table (docs/15 §3 status).
	 *
	 * @param array $assoc CLI assoc args.
	 * @return int Exit code.
	 */
	public function status( array $assoc ): int {
		try {
			$this->connect_both();
			$rows = $this->state->all();
		} catch ( \Throwable $e ) {
			$this->out( 'error: status failed: ' . $e->getMessage() );
			return self::EXIT_BLOCKED;
		}
		$out = array();
		foreach ( $rows as $name => $row ) {
			$out[] = array(
				'table'   => $name,
				'status'  => $row['status'],
				'rows'    => $row['rows_done'],
				'skipped' => $row['skipped'],
				'note'    => $row['note'],
			);
		}
		$fmt = self::format_opt( $assoc );
		$this->out( Report::format( '' === $fmt ? 'table' : $fmt, $out, array( 'table', 'status', 'rows', 'skipped', 'note' ) ) );
		if ( array() === $out ) {
			$this->out( 'no migration state yet (wp slonik migrate --dry-run | run first)' );
		}
		return self::EXIT_OK;
	}

	/**
	 * Verification (docs/15 §6): count + checksum + sequence lag.
	 *
	 * @param array $assoc CLI assoc args.
	 * @return int Exit code (4 on any mismatch).
	 */
	public function verify( array $assoc ): int {
		try {
			$this->connect_both();
			$state = $this->state->all();
		} catch ( \Throwable $e ) {
			$this->out( 'error: verify failed: ' . $e->getMessage() );
			return self::EXIT_BLOCKED;
		}
		if ( array() === $state ) {
			$this->out( 'no migration state — run or resume first' );
			return self::EXIT_BLOCKED;
		}
		$rows = array();
		foreach ( $state as $name => $row ) {
			if ( 'done' !== (string) $row['status'] ) {
				continue;
			}
			$rows[] = array( 'name' => $name, 'engine' => '', 'rows' => (int) $row['rows_done'], 'pk' => true, 'state' => 'done', 'note' => '' );
		}
		$rows = self::scope( $rows, self::subset( $assoc ) );
		if ( array() === $rows ) {
			$this->out( 'no done tables in scope to verify' );
			return self::EXIT_BLOCKED;
		}
		$result = $this->verifyPhase( $rows, $assoc );
		if ( array() !== $result['mismatches'] ) {
			return self::EXIT_VERIFY;
		}
		return self::EXIT_OK;
	}

	/**
	 * The reset outcome for the given state and target tables.
	 *
	 * An empty state means the migrated tables are unknown: dropping
	 * them is impossible, so the operator is told instead of getting a
	 * "done" that changed nothing.
	 *
	 * @param array $state The migration state rows (table => row).
	 * @param array $dots  The tables in the target schema (name => true).
	 * @return array{action:string,message:string} action: drop|state|block.
	 */
	public static function resetOutcome( array $state, array $dots ): array {
		if ( array() !== $state ) {
			return array(
				'action'  => 'drop',
				'message' => 'drop the migrated tables',
			);
		}
		$others = array();
		foreach ( array_keys( $dots ) as $name ) {
			if ( 'wpslonik_migration' === (string) $name ) {
				continue;
			}
			$others[] = (string) $name;
		}
		if ( array() !== $others ) {
			return array(
				'action'  => 'block',
				'message' => 'the target has tables but no migration state; reset-target only drops the migrated tables: ' . implode( ', ', $others ),
			);
		}
		return array(
			'action'  => 'state',
			'message' => 'nothing to reset but the migration state; dropping the state table',
		);
	}

	/**
	 * Destructive helper: drop migrated tables + state (docs/15 §3).
	 * Requires WPSLONIK_MIGRATE_DROP_TARGET=true AND --yes; refuses while
	 * WPSLONIK_ENABLED=true (the site may be live on the target).
	 *
	 * @param array $assoc CLI assoc args.
	 * @return int Exit code.
	 */
	public function resetTarget( array $assoc ): int {
		if ( ! $this->cfg->options()['drop_target'] ) {
			$this->out( 'blocker: reset-target requires WPSLONIK_MIGRATE_DROP_TARGET=true in wp-config.php' );
			return self::EXIT_BLOCKED;
		}
		if ( empty( $assoc['yes'] ) ) {
			$this->out( 'blocker: reset-target is destructive; pass --yes explicitly' );
			return self::EXIT_BLOCKED;
		}
		if ( $this->cfg->enabledFlag() ) {
			$this->out( 'blocker: WPSLONIK_ENABLED=true (site may be live on this target); flip it off first' );
			return self::EXIT_BLOCKED;
		}
		try {
			$this->connect_both();
			$state   = $this->state->all();
			$outcome = self::resetOutcome( $state, $this->targetDots() );
			if ( 'block' === $outcome['action'] ) {
				$this->out( 'blocker: ' . $outcome['message'] );
				return self::EXIT_BLOCKED;
			}
			foreach ( array_keys( $state ) as $name ) {
				$this->out( 'drop: ' . $name );
				$this->tgt->exec( 'DROP TABLE IF EXISTS ' . $this->pgq( (string) $name ) );
			}
			$this->tgt->exec( 'DROP TABLE IF EXISTS ' . $this->state->qualifiedName() );
			$this->out( 'state' === $outcome['action'] ? $outcome['message'] : 'target reset done' );
		} catch ( \Throwable $e ) {
			$this->out( 'error: reset failed: ' . $e->getMessage() );
			return self::EXIT_BLOCKED;
		}
		return self::EXIT_OK;
	}

	//
	// Phases (live-DB orchestration).
	//

	/**
	 * Schema + copy phases, tables in docs order (§5 steps 3–4).
	 *
	 * @param array $tables   Eligible plan rows.
	 * @param bool  $inTxn    Inside an existing transaction (rehearse).
	 * @return array{rows:array<string,int>,errors:array<int,string>,skipped:array<string,int>}
	 */
	private function schemaAndCopy( array $tables, bool $inTxn ): array {
		$stats = array(
			'rows'    => array(),
			'errors'  => array(),
			'skipped' => array(),
			'nul'     => array(),
			'warnings' => array(),
		);

		// Phase 3: schema for ALL tables first (fail fast, docs §5.3).
		$staged = array();
		$census = $this->targetDots(); // interrupted runs: skip existing tables.
		foreach ( $tables as $row ) {
			$name = (string) $row['name'];
			try {
				$stage = SchemaStage::stage( $this->src->showCreate( $name ), $this->ddl_options() );
			} catch ( \Throwable $e ) {
				$stats['errors'][] = 'schema: ' . $name . ': ' . $e->getMessage();
				$this->state->set( $name, 'error', '', 0, 0, 'schema: ' . $e->getMessage() );
				continue;
			}
			$staged[ $name ] = $stage;
			if ( isset( $census[ $name ] ) ) {
				$this->out( 'notice: ' . $name . ': table exists; keeping it (resume)' );
				continue;
			}
			try {
				$this->tgt->exec( $stage['table'] );
				foreach ( $stage['notices'] as $notice ) {
					$this->out( 'notice: ' . $name . ': ' . $notice );
				}
				foreach ( $stage['comments'] as $comment ) {
					$this->tgt->exec( $comment );
				}
				$this->state->set( $name, 'schema', '', 0, 0, '' );
			} catch ( \Throwable $e ) {
				$stats['errors'][] = 'schema: ' . $name . ': ' . $e->getMessage();
				$this->state->set( $name, 'error', '', 0, 0, 'schema: ' . $e->getMessage() );
				continue;
			}
		}

		// Phase 4: copy all tables.
		foreach ( $tables as $row ) {
			$name = (string) $row['name'];
			if ( ! isset( $staged[ $name ] ) ) {
				continue;
			}
			$prev = $this->state->get( $name );
			$pk   = $this->src->primaryKeys( $name );
			try {
				$meta = $this->colMeta( $name, $staged[ $name ]['skip_columns'] );
			} catch ( \Throwable $e ) {
				$stats['errors'][] = 'meta: ' . $name . ': ' . $e->getMessage();
				$this->state->set( $name, 'error', '', 0, 0, 'meta: ' . $e->getMessage() );
				continue;
			}
			$keyless = array() === $pk;
			if ( $keyless && isset( $prev['rows_done'] ) ) {
				// Keyless restarts from zero: purge the partial target rows.
				$this->tgt->exec( 'TRUNCATE ' . $this->pgq( $name ) );
				$this->state->set( $name, 'pending', '', 0, 0, '' );
			}
			try {
				$copier = new TableCopier(
					$this->src,
					$this->tgt,
					$this->state,
					array(
						'batch_rows'   => (int) $this->cfg->options()['batch_rows'],
						'batch_bytes'  => (int) $this->cfg->options()['batch_bytes'],
						'invalid_utf8' => (string) $this->cfg->options()['invalid_utf8'],
						'in_txn'       => $inTxn,
					)
				);
				$res = $copier->copyTable( $name, $meta, self::skipSet( $staged[ $name ]['skip_columns'] ), $pk );
			} catch ( \Throwable $e ) {
				$stats['errors'][] = 'copy: ' . $name . ': ' . $e->getMessage();
				continue;
			}
			$stats['rows'][ $name ]    = (int) $res['rows'];
			$stats['skipped'][ $name ] = (int) $res['skipped'];
			$stats['nul'][ $name ]     = (int) ( $res['nul'] ?? 0 );
			if ( (int) $stats['nul'][ $name ] > 0 ) {
				$stats['warnings'][] = 'copy: ' . $name . ': ' . $stats['nul'][ $name ]
					. ' NUL byte(s) removed (PostgreSQL text cannot store them; the affected values are lossy)';
			}
			if ( '' !== (string) $res['error'] ) {
				$stats['errors'][] = 'copy: ' . $name . ': ' . $res['error'];
			}
		}
		return $stats;
	}

	/**
	 * Final phase (docs/15 §5.3/§7): secondary indexes, identity
	 * sequence sync, ANALYZE. Already-present indexes are skipped so the
	 * phase is idempotent across resume (M6 live-proof finding); setval
	 * and ANALYZE re-run safely.
	 *
	 * @param array $rows_map table → copied rows (0 for tables done previously).
	 * @param array $errors   Collected errors (by reference).
	 * @return void
	 */
	private function finalPhase( array $rows_map, array $assoc, bool $inTxn, array &$errors = array() ) {
		foreach ( $rows_map as $name => $count ) {
			try {
				$stage = SchemaStage::stage( $this->src->showCreate( $name ), $this->ddl_options() );
				foreach ( $stage['extra'] as $stmt ) {
					try {
						$this->tgt->exec( $stmt );
					} catch ( \Throwable $e ) {
						if ( false !== strpos( $e->getMessage(), 'already exists' ) ) {
							$this->out( 'notice: ' . $name . ': index exists; skipping' );
							continue;
						}
						throw $e;
					}
				}
				foreach ( $this->identityCols( $name ) as $col ) {
					$this->tgt->exec( SequenceSync::sql( $name, $col ) );
				}
				$this->tgt->exec( 'ANALYZE ' . $this->pgq( $name ) );
			} catch ( \Throwable $e ) {
				// A failed index/sequence/ANALYZE is not a clean run: the
				// error must reach the exit code (audit D-19).
				$message = 'final phase: ' . $name . ': ' . $e->getMessage();
				$errors[] = $message;
				$this->out( 'error: ' . $message );
				$prev = $this->state->get( $name );
				$this->state->set(
					$name,
					'done',
					isset( $prev['last_key'] ) ? (string) $prev['last_key'] : '',
					isset( $prev['rows_done'] ) ? (int) $prev['rows_done'] : 0,
					isset( $prev['skipped'] ) ? (int) $prev['skipped'] : 0,
					$message
				);
			}
		}
	}

	/**
	 * Verify phase (docs/15 §6). Runs per table; rows compared via
	 * canonicalization; sequence lag checked for identity columns.
	 *
	 * @param array $tables Plan rows.
	 * @return array{mismatches:array<int,string>,lags:array<int,string>,ok:array<int,string>}
	 */
	private function verifyPhase( array $tables, array $assoc ): array {
		$result = array(
			'ok'         => array(),
			'mismatches' => array(),
			'lags'       => array(),
		);
		$limit = 500;
		foreach ( $tables as $row ) {
			$name = (string) $row['name'];
			$pk   = $this->src->primaryKeys( $name );
			$prev = $this->state->get( $name );
			$skipped = (int) ( $prev['skipped'] ?? 0 );
			try {
				// The verification must use the same projection as the
				// copy: generated/spatial columns are not in the target
				// (audit D-09).
				$skip_columns = array();
				try {
					$stage        = SchemaStage::stage( $this->src->showCreate( $name ) );
					$skip_columns = (array) $stage['skip_columns'];
				} catch ( \Throwable $stage_error ) {
					$skip_columns = array();
				}
				$meta   = $this->colMeta( $name, $skip_columns );
				$types  = array();
				$columns = array();
				foreach ( $meta as $col_name => $col_row ) {
					$columns[] = $col_name;
					// A column-level override can make a text column target
					// bytea (audit D-74): the verifier has to compare the
					// bytes, not the text.
					$types[]   = ColumnOverrides::isBytea( $name, $col_name )
						? 'bytea'
						: strtolower( (string) $col_row['data_type'] );
				}
				$verifier = new Verifier();
				$v = $verifier->verifyTable( $name, $columns, $types, $this->src, $this->tgt->pdo(), $pk, $limit, $skipped );
			} catch ( \Throwable $e ) {
				$result['mismatches'][] = 'verify: ' . $name . ': ' . $e->getMessage();
				continue;
			}
			$line = 'verify: ' . $name . ': src=' . $v['count_src'] . ' tgt=' . $v['count_tgt'];
			if ( $v['count_tgt'] === Verifier::expectedTargetCount( $v['count_src'], $skipped ) && array() !== $pk && 0 === $skipped ) {
				$line .= ' hashed=' . $v['hash_rows'];
			}
			if ( '' !== (string) $v['note'] ) {
				$line .= ' (' . $v['note'] . ')';
			}
			if ( 'ok' === $v['status'] ) {
				$seq_errors = array();
				foreach ( $this->identityCols( $name ) as $col ) {
					try {
						$lag = Verifier::sequenceLag( $this->tgt->pdo(), $name, $col );
						if ( 'lag' === $lag['status'] ) {
							$seq_errors[] = $lag['detail'];
						}
					} catch ( \Throwable $e ) {
						$seq_errors[] = $col . ': ' . $e->getMessage();
					}
				}
				if ( array() === $seq_errors ) {
					$this->out( $line . ' ok' );
					$result['ok'][] = $name;
					continue;
				}
				$this->out( $line . ' sequence lag: ' . implode( ', ', $seq_errors ) );
				$result['mismatches'][] = 'sequence lag: ' . $name . ' (' . implode( ', ', $seq_errors ) . ')';
				continue;
			}
			$this->out( $line . ' ' . $v['error'] );
			$result['mismatches'][] = $name . ': ' . $v['error'];
		}
		return $result;
	}

	//
	// Connections, plan and gates.
	//

	private function describe() {
		$src = $this->cfg->source();
		$tgt = $this->cfg->target();
		$opt = $this->cfg->options();
		$this->out( 'source: ' . $src['host'] . ':' . $src['port'] . '/' . $src['name'] . ' user ' . $src['user'] );
		$this->out( 'target: ' . $tgt['host'] . ':' . $tgt['port'] . '/' . $tgt['name'] . ' schema ' . $tgt['schema'] . ' sslmode ' . $tgt['sslmode'] );
		$this->out( 'policy: invalid_utf8=' . $opt['invalid_utf8'] . ' verify=' . $opt['verify'] . ' batch_rows=' . $opt['batch_rows'] . ' batch_bytes=' . $opt['batch_bytes'] );
	}

	private function connect_both() {
		if ( null === $this->src ) {
			$this->src = MySqlSource::connect( $this->cfg->source() );
		}
		if ( null === $this->tgt ) {
			$this->tgt   = PgTarget::connect( $this->cfg->target() );
			$this->tgt->applySessionDefaults( (string) $this->cfg->target()['schema'] );
			$this->state = new State( $this->tgt->pdo(), (string) $this->cfg->target()['schema'] );
		}
	}

	/**
	 * Consistent snapshot on the source (docs/15 §5.1). Only meaningful
	 * for InnoDB; MyISAM relies on maintenance mode (warned by Plan).
	 */
	private function snapshot() {
		try {
			// Transaction stays open for the whole run; connection close
			// rolls it back (read-only usage, docs/15 §1.1).
			$this->src->exec( 'SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ' );
			$this->src->exec( 'START TRANSACTION WITH CONSISTENT SNAPSHOT' );
		} catch ( \Throwable $e ) {
			$this->out( 'warning: source snapshot not taken (' . $e->getMessage() . '); consistency relies on maintenance mode' );
		}
	}

	/**
	 * Write-mode gate (docs/15 §1.7/§5.0). $allowNoMaintenance replays the
	 * --yes check when maintenance detection is unavailable (CLI contexts
	 * outside WP boot).
	 *
	 * @return int EXIT_OK when gated through, else the blocking exit code.
	 */
	private function writeGate( array $assoc, string $mode ): int {
		if ( $this->cfg->enabledFlag() ) {
			$this->out( 'blocker: ' . $mode . ': WPSLONIK_ENABLED=true must be OFF during migration (P-16); cutover flips it AFTER verify' );
			return self::EXIT_BLOCKED;
		}
		$maintenance = false;
		$undeterminable = false;
		if ( defined( 'ABSPATH' ) ) {
			$maintenance = is_file( constant( 'ABSPATH' ) . '.maintenance' );
		} else {
			$undeterminable = true;
		}
		if ( ! $maintenance && ! $undeterminable && empty( $assoc['yes'] ) ) {
			$this->out( 'blocker: ' . $mode . ': maintenance mode not enabled. K-42 says the OPERATOR enables it; pass --yes to proceed anyway.' );
			return self::EXIT_BLOCKED;
		}
		if ( $undeterminable && empty( $assoc['yes'] ) ) {
			$this->out( 'blocker: ' . $mode . ': maintenance state undeterminable outside WP boot; pass --yes if the operator has enabled it.' );
			return self::EXIT_BLOCKED;
		}
		return self::EXIT_OK;
	}

	private function acquireLock() {
		if ( ! $this->state->lock() ) {
			throw new \RuntimeException( 'another migrate runner holds the advisory lock' );
		}
	}

	/**
	 * Build the plan (inventory + state + target census) and return the
	 * aggregate used by all commands.
	 *
	 * @param bool  $needTarget Read state/target census (false for rehearse temp state).
	 * @param array $subset     --tables subset (already split).
	 * @return array{plan:array{tables:array<int,array>,warnings:array<int,string>,blockers:array<int,string>,excluded:array<int,string>},warnings:array<int,string>,errors:array<int,string>,blockers:array<int,string>}
	 */
	private function buildPlan( bool $needTarget, array $subset ): array {
		$errors    = array();
		$warnings  = array();
		$inventory = array();
		$anomalies = array();
		$state     = array();
		$dots      = array();

		try {
			$this->connect_both();
		} catch ( \Throwable $e ) {
			return array( 'plan' => array( 'tables' => array(), 'warnings' => array(), 'blockers' => array(), 'excluded' => array() ), 'warnings' => $warnings, 'errors' => array( 'connect: ' . $e->getMessage() ), 'blockers' => array() );
		}

		$opt = $this->cfg->options();
		try {
			foreach ( $this->src->tables( $opt['prefix'], $opt['include_tables'], $opt['exclude_tables'] ) as $row ) {
				$name      = (string) $row['table_name'];
				$row['pk'] = array() !== $this->src->primaryKeys( $name );
				$inventory[] = $row;
			}
			$anomalies = $this->src->anomalies( $opt['prefix'] );
		} catch ( \Throwable $e ) {
			$errors[] = 'source inventory failed: ' . $e->getMessage();
		}

		if ( $needTarget ) {
			try {
				$state = $this->state->all();
			} catch ( \Throwable $t ) {
				$state = array(); // state table may not exist yet (dry-run/run fresh).
			}
			try {
				$dots = $this->targetDots();
			} catch ( \Throwable $t ) {
				$errors[] = 'target census failed: ' . $t->getMessage();
			}
			try {
				$schema = (string) $this->cfg->target()['schema'];
				$stmt   = $this->tgt->pdo()->prepare( 'SELECT 1 FROM pg_namespace WHERE nspname = ?' );
				$stmt->execute( array( $schema ) );
				if ( false === (bool) $stmt->fetchColumn() ) {
					$warnings[] = 'target schema ' . $schema . ' does not exist yet; run creates it (CREATE SCHEMA IF NOT EXISTS)';
				}
			} catch ( \Throwable $t ) {
				$errors[] = 'target schema check failed: ' . $t->getMessage();
			}
		}

		$plan = Plan::filter(
			Plan::build(
				$inventory,
				(string) $opt['prefix'],
				(array) $opt['include_tables'],
				(array) $opt['exclude_tables'],
				$state,
				$anomalies,
				$dots
			),
			$subset
		);

		// D-32: the column charsets are checked too: a latin1 column in
		// a utf8mb4 table is converted by the copy, the operator has to
		// know it before the run.
		foreach ( $plan['tables'] as $row ) {
			$name = (string) $row['name'];
			if ( 'planned' !== (string) $row['state'] && 'new' !== (string) $row['state'] ) {
				continue;
			}
			try {
				foreach ( $this->src->columnsOf( $name ) as $col ) {
					$charset = Plan::charsetOf( (string) ( $col['character_set_name'] ?? '' ) );
					if ( '' === $charset || Plan::isUtf8Charset( $charset ) ) {
						continue;
					}
					$plan['warnings'][] = 'column charset is ' . $charset . ': ' . $name . '.' . (string) ( $col['column_name'] ?? '' ) . ' (the copy converts it to UTF-8)';
				}
			} catch ( \Throwable $t ) {
				$errors[] = 'column charset check failed: ' . $name . ': ' . $t->getMessage();
			}
		}

		return array(
			'plan'     => $plan,
			'warnings' => $warnings,
			'errors'   => $errors,
			'blockers' => $plan['blockers'],
		);
	}

	/** @return array<string,true> pg_tables census of the target schema. */
	private function targetDots(): array {
		$schema = (string) ( null !== $this->pg_schema_override ? $this->pg_schema_override : $this->cfg->target()['schema'] );
		$stmt = $this->tgt->pdo()->prepare( 'SELECT tablename FROM pg_tables WHERE schemaname = ?' );
		$stmt->execute( array( $schema ) );
		$out = array();
		foreach ( (array) $stmt->fetchAll( PDO::FETCH_COLUMN ) as $name ) {
			$out[ (string) $name ] = true;
		}
		return $out;
	}

	/** Re-key the source columnsOf() rows and drop skipped columns. */
	private function colMeta( string $table, array $skip ) {
		$meta = array();
		foreach ( $this->src->columnsOf( $table ) as $row ) {
			$name = (string) $row['column_name'];
			if ( in_array( $name, (array) $skip, true ) ) {
				continue;
			}
			$meta[ $name ] = $row;
		}
		return $meta;
	}

	/** Identity columns of a source table (extra contains auto_increment). */
	private function identityCols( string $table ): array {
		$out = array();
		foreach ( $this->src->columnsOf( $table ) as $row ) {
			if ( false !== strpos( (string) ( $row['extra'] ?? '' ), 'auto_increment' ) ) {
				$out[] = (string) $row['column_name'];
			}
		}
		return $out;
	}

	private static function skipSet( array $skip ): array {
		$out = array();
		foreach ( (array) $skip as $name ) {
			$out[ (string) $name ] = true;
		}
		return $out;
	}

	/** Filter plan rows to the copyable ones: state not done/blocked/excluded. */
	private static function eligible( array $tables ): array {
		$out = array();
		foreach ( (array) $tables as $row ) {
			if ( in_array( (string) $row['state'], array( 'done', 'blocked', 'excluded' ), true ) ) {
				continue;
			}
			$out[] = $row;
		}
		return $out;
	}

	private function reportPlan( array $plan, array $assoc ): int {
		$rows = self::planRows( $plan['plan'] );
		$fmt  = self::format_opt( $assoc );
		$this->out( Report::format( '' === $fmt ? 'table' : $fmt, $rows, array( 'name', 'engine', 'rows', 'pk', 'state', 'note' ) ) );
		foreach ( $plan['warnings'] as $warning ) {
			$this->out( 'warning: ' . $warning );
		}
		foreach ( $plan['blockers'] as $blocker ) {
			$this->out( 'blocker: ' . $blocker );
		}
		$this->out( Report::planSummary( array(
			'tables'   => $rows,
			'warnings' => $plan['warnings'],
			'blockers' => $plan['blockers'],
		) ) );
		return $this->warnCode( $plan['warnings'] );
	}

	private function reportCopy( array $stats, array $tables ): void {
		foreach ( $tables as $row ) {
			$name = (string) $row['name'];
			if ( isset( $stats['rows'][ $name ] ) ) {
				$this->out( 'copied: ' . $name . ' rows=' . $stats['rows'][ $name ]
					. ' skipped=' . ( $stats['skipped'][ $name ] ?? 0 )
					. ' nul=' . ( $stats['nul'][ $name ] ?? 0 ) );
			}
		}
		foreach ( $stats['warnings'] as $warning ) {
			$this->out( 'warning: ' . $warning );
		}
		foreach ( $stats['errors'] as $error ) {
			$this->out( 'error: ' . $error );
		}
	}

	private function warnCode( array $warnings ): int {
		return array() === $warnings ? self::EXIT_OK : self::EXIT_WARN;
	}

	/**
	 * DDL options for the schema stage (the case-sensitivity mode).
	 *
	 * @return array<string,string>
	 */
	private function ddl_options(): array {
		if ( '' === $this->ci_mode ) {
			$this->ci_mode = \Slonik\Schema\CiMode::effective(
				defined( 'WPSLONIK_CI_MODE' ) ? constant( 'WPSLONIK_CI_MODE' ) : null,
				\Slonik\Schema\CiMode::citext_available( $this->tgt->pdo() )
			);
		}
		return array( 'ci_mode' => $this->ci_mode );
	}

	private function pgq( string $name ): string {
		return '"' . str_replace( '"', '""', $name ) . '"';
	}

	private static function planRows( array $plan ): array {
		$rows = array();
		foreach ( (array) ( $plan['tables'] ?? array() ) as $row ) {
			$rows[] = array(
				'name'  => $row['name'],
				'engine' => $row['engine'],
				'rows'  => $row['rows'],
				'pk'    => $row['pk'] ? 'yes' : 'no',
				'state' => $row['state'],
				'note'  => $row['note'],
			);
		}
		return $rows;
	}

	private static function format_opt( array $assoc ): string {
		$fmt = isset( $assoc['format'] ) ? (string) $assoc['format'] : '';
		if ( '' === $fmt ) {
			return '';
		}
		$fmt = strtolower( $fmt );
		return in_array( $fmt, array( 'table', 'json', 'yaml' ), true ) ? $fmt : '';
	}

	private static function subset( array $assoc ): array {
		$raw = isset( $assoc['tables'] ) ? (string) $assoc['tables'] : '';
		if ( '' === $raw ) {
			return array();
		}
		$out = array();
		foreach ( explode( ',', $raw ) as $name ) {
			$name = trim( $name );
			if ( '' !== $name ) {
				$out[] = $name;
			}
		}
		return $out;
	}

	private static function scope( array $rows, array $subset ): array {
		if ( array() === $subset ) {
			return $rows;
		}
		$out = array();
		foreach ( $rows as $row ) {
			if ( in_array( (string) $row['name'], $subset, true ) ) {
				$out[] = $row;
			}
		}
		return $out;
	}
}
