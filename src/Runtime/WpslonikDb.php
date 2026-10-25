<?php
/**
 * WPSLONIK_DB runtime skeleton (M2a thin slice).
 *
 * Lazy PDO: the constructor never connects, so construction is unit-testable
 * without PostgreSQL. First query connects.
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Runtime;

use PDO;
use Slonik\Audit\CompatLog;
use Slonik\Audit\Redactor;
use Slonik\Audit\SourceGuesser;
use Slonik\Cache\CacheInterface;
use Slonik\Schema\Catalog;
use Slonik\Schema\SequenceSync;
use Slonik\Sql\ExecutionPlan;
use Slonik\Sql\MinTranslator;
use Slonik\Sql\TranslationException;
use Slonik\Sql\HostPort;

class WpslonikDb extends \wpdb {

	/** @var string */
	public $last_pg_query = '';

	/** @var PDO|null */
	private $pg = null;

	/** @var Catalog|null */
	private $catalog = null;

	/** @var array Step memory (e.g. found_rows). */
	private $mem = array();

	/** @var bool Whether inside an explicit transaction. */
	private $in_transaction = false;

	/** @var string */
	private $pg_user = '';
	/** @var string */
	private $pg_password = '';
	/** @var string */
	private $pg_name = '';
	/** @var string */
	private $pg_host = '';

	/** @var string Compat log file ('': disabled). */
	private $compat_log_file = '';

	/** @var CompatLog|null Cached per-request log instance (avoids re-read per logged query). */
	private $compat_log = null;

	/** @var CacheInterface|null Translation-plan cache (null = caching disabled/unavailable). */
	private $translation_cache = null;

	/** @var bool Whether CacheFactory::make() has already been tried. */
	private $translation_cache_attempted = false;

	/** @var array<string,\PDOStatement> Server-side prepared statements keyed by statement shape. */
	private $statement_cache = array();

	/** @var \PDOStatement|null Last executed statement (column metadata source). */
	private $last_stmt = null;

	/** @var int Slow-translation threshold in ms. */
	private $slow_ms = 50;

	/** @var string Case-sensitivity mode (citext|degraded). */
	private $ci_mode = '';

	public function __construct( $dbuser, $dbpassword, $dbname, $dbhost, $compat_log_file = '', $slow_ms = null, $cache = null ) {
		$this->pg_user     = (string) $dbuser;
		$this->pg_password = (string) $dbpassword;
		$this->pg_name     = (string) $dbname;
		$this->pg_host     = (string) $dbhost;
		$this->is_mysql    = false;
		$this->compat_log_file = (string) $compat_log_file;
		// Keep the wpdb contract in sync: the parent properties are read
		// by core (dbDelta, get_charset_collate, show_errors) and were
		// empty because the parent constructor is never called (D-35).
		$this->dbuser     = (string) $dbuser;
		$this->dbpassword = (string) $dbpassword;
		$this->dbname     = (string) $dbname;
		$this->dbhost     = (string) $dbhost;
		$this->charset    = self::charset_name();
		$this->collate    = self::collate_name();
		if ( null !== $slow_ms ) {
			$this->slow_ms = max( 0, (int) $slow_ms );
		} elseif ( defined( 'WPSLONIK_COMPAT_SLOW_MS' ) && is_numeric( (string) constant( 'WPSLONIK_COMPAT_SLOW_MS' ) ) ) {
			$this->slow_ms = max( 0, (int) (string) constant( 'WPSLONIK_COMPAT_SLOW_MS' ) );
		}
		if ( $cache instanceof CacheInterface ) {
			$this->translation_cache = $cache;
		}
	}

	/**
	 * Connection charset (DB_CHARSET, utf8mb4 by default).
	 *
	 * @return string
	 */
	public static function charset_name() {
		if ( defined( 'DB_CHARSET' ) && is_string( DB_CHARSET ) && '' !== DB_CHARSET ) {
			return (string) DB_CHARSET;
		}
		return 'utf8mb4';
	}

	/**
	 * Collation name (DB_COLLATE, utf8mb4_unicode_ci by default).
	 *
	 * @return string
	 */
	public static function collate_name() {
		if ( defined( 'DB_COLLATE' ) && is_string( DB_COLLATE ) && '' !== DB_COLLATE ) {
			return (string) DB_COLLATE;
		}
		return 'utf8mb4_unicode_ci';
	}

	/**
	 * Escape a value for PostgreSQL.
	 *
	 * The parent escapes for MySQL (mysqli_real_escape_string or
	 * addslashes): with standard_conforming_strings=on a backslash is a
	 * literal character in PostgreSQL, so MySQL escaping would double
	 * backslashes and mangle values (audit D-34). Only the quote is
	 * doubled here.
	 *
	 * @param mixed $data Value.
	 * @return string
	 */
	public function _real_escape( $data ) {
		if ( ! is_scalar( $data ) ) {
			return '';
		}
		return str_replace( "'", "''", (string) $data );
	}

	/**
	 * No-op: PostgreSQL needs no placeholder markers.
	 *
	 * WordPress 6.5+ wraps '%' with a backslash so a later prepare() pass
	 * cannot re-interpret it. In PostgreSQL that backslash is a literal
	 * character, so the marker would corrupt every value with a percent
	 * sign (audit D-34).
	 *
	 * @param string $query Query text.
	 * @return string
	 */
	public function add_placeholder_escape( $query ) {
		return $query;
	}

	/**
	 * No-op counterpart of add_placeholder_escape().
	 *
	 * @param string $query Query text.
	 * @return string
	 */
	public function remove_placeholder_escape( $query ) {
		return $query;
	}

	/**
	 * RETURNING clause for a generated identity column.
	 *
	 * MySQL reports the id of the first generated row; PostgreSQL can
	 * return it with the statement itself. A column the statement writes
	 * itself is not generated, so no RETURNING is used (audit D-05).
	 *
	 * @param string|null $identity Identity column (canonical spelling).
	 * @param array       $inserted Columns the statement writes.
	 * @return string Clause (with the quoted column) or ''.
	 */
	public static function returning_clause( $identity, array $inserted ) {
		if ( null === $identity || '' === (string) $identity ) {
			return '';
		}
		foreach ( (array) $inserted as $column ) {
			if ( 0 === strcasecmp( (string) $column, (string) $identity ) ) {
				return '';
			}
		}
		return '"' . str_replace( '"', '""', (string) $identity ) . '"';
	}

	/**
	 * Column metadata of the last result set.
	 *
	 * The parent reads it from a mysqli result; PostgreSQL has none, so
	 * it is filled from the PDO statement (audit D-40).
	 *
	 * @return void
	 */
	protected function load_col_info() {
		$this->col_info = array();
		$stmt           = $this->last_stmt;
		if ( ! $stmt instanceof \PDOStatement ) {
			return;
		}
		try {
			$count = $stmt->columnCount();
		} catch ( \Exception $e ) {
			return;
		}
		for ( $i = 0; $i < $count; $i++ ) {
			try {
				$meta = $stmt->getColumnMeta( $i );
			} catch ( \Exception $e ) {
				$meta = array();
			}
			$this->col_info[] = self::col_info_row( is_array( $meta ) ? $meta : array() );
		}
	}

	/**
	 * Map PDO column metadata to a wpdb col_info row.
	 *
	 * @param array $meta PDO column metadata.
	 * @return array
	 */
	public static function col_info_row( array $meta ) {
		$name = (string) ( $meta['name'] ?? '' );
		return array(
			'name'         => $name,
			'table'        => (string) ( $meta['table'] ?? '' ),
			'def'          => (string) ( $meta['def'] ?? '' ),
			'max_length'   => (int) ( $meta['len'] ?? 0 ),
			'not_null'     => ! empty( $meta['not_null'] ),
			'primary_key'  => false,
			'unique_key'   => false,
			'multiple_key' => false,
			'numeric'      => (bool) preg_match( '/^(int|integer|smallint|bigint|numeric|decimal|real|double|float|serial)/i', (string) ( $meta['native_type'] ?? '' ) ),
			'blob'         => false,
			'type'         => (string) ( $meta['native_type'] ?? '' ),
			'unsigned'     => false,
			'zerofill'     => false,
		);
	}

	public function db_connect( $allow_bail = true ) {
		if ( $this->pg instanceof PDO ) {
			return true;
		}
		$dsn      = 'pgsql:' . $this->parse_host( $this->pg_host ) . 'dbname=' . $this->pg_name;
		$this->pg = new PDO(
			$dsn,
			$this->pg_user,
			$this->pg_password,
			array(
				PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
				PDO::ATTR_STRINGIFY_FETCHES  => true,
				PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
				// Server-side prepared statements: the statement shape
				// ($1, $2 …) is planned once and reused for every execute
				// with different bound values (M7.2).
				PDO::ATTR_EMULATE_PREPARES   => false,
			)
		);
		$this->pg->exec( "SET client_encoding='UTF8'" );
		$this->pg->exec( 'SET standard_conforming_strings=on' );
		$this->pg->exec( "SET bytea_output='hex'" );
		// The configured schema must be the one the site reads and writes
		// (the migration tool fills the same schema).
		$this->pg->exec( self::search_path_sql( $this->schema_name() ) );
		$this->catalog = Catalog::load( $this->pg, $this->schema_name() );
		// The case-sensitivity mode: citext when the extension is there,
		// otherwise the lower() fallback (audit D-02).
		$this->ci_mode = \Slonik\Schema\CiMode::effective(
			defined( 'WPSLONIK_CI_MODE' ) ? constant( 'WPSLONIK_CI_MODE' ) : null,
			\Slonik\Schema\CiMode::citext_available( $this->pg )
		);
		return true;
	}

	/**
	 * The case-sensitivity mode of this connection.
	 *
	 * @return string
	 */
	public function ci_mode() {
		// Until the connection is up the safe fallback is used; the probed
		// mode replaces it from the first connection on.
		if ( '' === $this->ci_mode ) {
			return \Slonik\Schema\CiMode::DEGRADED;
		}
		return $this->ci_mode;
	}

	/**
	 * Session search path for a schema.
	 *
	 * @param string $schema Schema name.
	 * @return string
	 */
	public static function search_path_sql( $schema ) {
		$name = str_replace( '"', '""', (string) $schema );
		return 'SET search_path TO "' . $name . '", public';
	}

	private function schema_name() {
		return defined( 'WPSLONIK_DB_SCHEMA' ) && is_string( WPSLONIK_DB_SCHEMA ) && '' !== WPSLONIK_DB_SCHEMA ? WPSLONIK_DB_SCHEMA : 'public';
	}

	/**
	 * Whether the SQL is a bare FOUND_ROWS() probe.
	 *
	 * M2a approximation for R-004: answered from the previous SELECT's row
	 * count (proper COUNT(*) rewrite lands in M3 with the ExecutionPlan).
	 *
	 * @param string $sql SQL text.
	 * @return bool
	 */
	public static function is_found_rows_probe( $sql ) {
		$norm = strtoupper( str_replace( array( ' ', "\t", "\n", "\r", "\v", "\f" ), '', $sql ) );
		$norm = rtrim( $norm, ';' );
		return 'SELECTFOUND_ROWS()' === $norm;
	}

	/**
	 * Whether the upstream wpdb answers the statement with the raw driver
	 * result instead of a row or affected-rows count (class-wpdb.php:
	 * create/alter/truncate/drop branch). A successful DDL statement is
	 * truthy even when nothing was affected; plugins such as the Wordfence
	 * 2FA controller check that truthiness on activate.
	 *
	 * @param string $sql SQL text.
	 * @return bool
	 */
	public static function is_ddl_query( $sql ) {
		return 1 === preg_match( '/^\s*(create|alter|truncate|drop)\s/i', (string) $sql );
	}

	public function query( $query ) {
		if ( function_exists( 'apply_filters' ) ) {
			$query = apply_filters( 'query', $query );
		}
		$prev_rows = $this->num_rows;
		$this->flush();
		$this->last_query = $query;
		++$this->num_queries;
		if ( self::is_found_rows_probe( (string) $query ) ) {
			$prev                  = isset( $this->mem['found_rows'] ) ? $this->mem['found_rows'] : $prev_rows;
			$row                   = new \stdClass();
			$row->{'FOUND_ROWS()'} = (string) $prev;
			$this->last_result     = array( $row );
			$this->num_rows        = 1;
			$this->last_pg_query   = 'SELECT ' . $prev . ' AS "FOUND_ROWS()"';
			return 1;
		}
		$in_txn = $this->in_transaction;
		try {
			$t0 = hrtime( true );
			$catalog = $this->catalog;
			$use_cache = $catalog instanceof Catalog && null !== $this->translationCache();
			if ( $use_cache ) {
				$cache_key = 'tr:' . hash( 'sha256', (string) $query . "\0" . $this->ci_mode() . "\0" . $catalog->cacheKey() );
				$cached    = $this->translationCache()->get( $cache_key );
				if ( null !== $cached ) {
					$plan = ExecutionPlan::fromArray( $cached );
				} else {
					$plan = MinTranslator::translate(
						(string) $query,
						$catalog,
						array( 'ci_mode' => $this->ci_mode(), 'parametrize' => true )
					);
					$this->translationCache()->set( $cache_key, $plan->toArray() );
				}
			} else {
				$plan = MinTranslator::translate(
					(string) $query,
					$catalog,
					array( 'ci_mode' => $this->ci_mode(), 'parametrize' => true )
				);
			}
			$slow   = (int) ( ( hrtime( true ) - $t0 ) / 1000000 );
			if ( $slow >= $this->slow_ms ) {
				$this->logKind( 'slow', 'MS:' . $slow, $query );
			}
			$this->db_connect();
			$sqls = array();
			foreach ( $plan->getSteps() as $step ) {
				$sqls[] = $step->getSql();
			}
			$this->last_pg_query = implode( ";\n", $sqls );
			$kind = $plan->getKind();
			if ( ExecutionPlan::KIND_NOOP === $kind ) {
				$this->last_result   = array();
				$this->num_rows      = 0;
				$this->rows_affected = 0;
				return 0;
			}
			if ( ExecutionPlan::KIND_TXN === $kind ) {
				foreach ( $plan->getSteps() as $step ) {
					$sql = $step->getSql();
					$this->pg->exec( $sql );
					if ( 'BEGIN' === $sql ) {
						$this->in_transaction = true;
					} else {
						$this->in_transaction = false;
					}
				}
				$this->last_result   = array();
				$this->num_rows      = 0;
				$this->rows_affected = 0;
				return 0;
			}
			if ( ExecutionPlan::KIND_DDL === $kind ) {
				// A multi-statement plan runs inside one transaction,
				// mirroring the generic path: MySQL applies an ALTER
				// TABLE with several actions atomically, and a
				// half-applied DDL plan would leave the schema
				// inconsistent (R-078).
				$auto_txn = ! $in_txn && 1 < count( $plan->getSteps() );
				if ( $auto_txn ) {
					$this->pg->exec( 'BEGIN' );
				}
				try {
					foreach ( $plan->getSteps() as $step ) {
						$this->pg->exec( $step->getSql() );
					}
				} catch ( \Exception $step_error ) {
					if ( $auto_txn ) {
						try {
							$this->pg->exec( 'ROLLBACK' );
						} catch ( \Exception $ignored ) {
						}
					}
					throw $step_error;
				}
				if ( $auto_txn ) {
					$this->pg->exec( 'COMMIT' );
				}
				$this->catalog       = Catalog::load( $this->pg, $this->schema_name() );
				// The schema changed: a statement planned against the old
				// layout may be stale, so drop the prepared-statement cache.
				$this->statement_cache = array();
				$this->last_result   = array();
				$this->num_rows      = 0;
				$this->rows_affected = 0;
				// Upstream wpdb returns the driver's raw success value for
				// the create/alter/truncate/drop statements, not the
				// affected count (always 0, whether the table was built
				// or already present); the Wordfence 2FA controller
				// fatal-ed on the falsy 0 (D-78).
				return self::is_ddl_query( (string) $query ) ? true : 0;
			}
			if ( $in_txn ) {
				$savepoint = 'slonik_sp_' . $this->num_queries;
				$this->pg->exec( 'SAVEPOINT ' . $savepoint );
			}
			if ( ExecutionPlan::KIND_SELECT === $kind || ExecutionPlan::KIND_SHOW === $kind ) {
				$first = true;
				foreach ( $plan->getSteps() as $step ) {
					$params = $step->getParams();
					if ( array() === $params ) {
						$stmt = $this->pg->query( $step->getSql() );
					} else {
						$stmt = $this->pg_prepare( $step->getSql() );
						$stmt->execute( $params );
					}
					$rows = $stmt->fetchAll();
					if ( $first ) {
						$this->last_stmt    = $stmt;
						$this->last_result = $this->shape_rows( $stmt, $rows );
						$this->num_rows    = count( $this->last_result );
						$first             = false;
						continue;
					}
					if ( null !== $step->getMemKey() ) {
						$this->mem[ $step->getMemKey() ] = self::mem_scalar( $rows );
					}
				}
				return $this->num_rows;
			}
			$affected = 0;
			$auto_txn = ! $in_txn && count( $plan->getSteps() ) > 1;
			if ( $auto_txn ) {
				$this->pg->exec( 'BEGIN' );
			}
			try {
				$meta      = $plan->getMeta();
				$returning = '';
				if ( ExecutionPlan::KIND_INSERT === $kind && isset( $meta['insert'] ) ) {
					$table     = (string) $meta['insert']['table'];
					$columns   = (array) $meta['insert']['columns'];
					$identity  = $this->catalog instanceof Catalog ? $this->catalog->identityColumn( $table ) : null;
					$returning = self::returning_clause( $identity, $columns );
				}
				foreach ( $plan->getSteps() as $step ) {
					$sql   = $step->getSql();
					if ( '' !== $returning ) {
						// The id comes with the statement (MySQL reports the
						// first generated id; audit D-05).
						$ids = $this->pg_query( $sql . ' RETURNING ' . $returning, $step->getParams() )
							->fetchAll( PDO::FETCH_NUM );
						$affected     = count( $ids );
						$this->insert_id = ( isset( $ids[0][0] ) ) ? (int) $ids[0][0] : 0;
						continue;
					}
					$affected = $this->pg_exec( $sql, $step->getParams() );
				}
				$this->rows_affected = $affected;
				if ( ExecutionPlan::KIND_INSERT === $kind ) {
					if ( '' === $returning ) {
						// No id was generated: MySQL reports 0.
						$this->insert_id = 0;
					}
					if ( isset( $meta['insert'] ) ) {
						$sync = SequenceSync::needs_sync( $this->catalog, $meta['insert']['table'], $meta['insert']['columns'] );
						if ( null !== $sync ) {
							$this->pg->exec( SequenceSync::sql( $meta['insert']['table'], $sync ) );
						}
					}
				}
			} catch ( \Exception $step_error ) {
				if ( $auto_txn ) {
					try {
						$this->pg->exec( 'ROLLBACK' );
					} catch ( \Exception $ignored ) {
					}
				}
				throw $step_error;
			}
			if ( $auto_txn ) {
				$this->pg->exec( 'COMMIT' );
			}
			$this->last_result = array();
			$this->num_rows    = 0;
			return $this->rows_affected;
		} catch ( \Exception $e ) {
			if ( $in_txn ) {
				try {
					$this->pg->exec( 'ROLLBACK TO SAVEPOINT slonik_sp_' . $this->num_queries );
				} catch ( \Exception $ignored ) {
				}
			}
			$this->logCompat( $this->last_query, $e );
			if ( $e instanceof TranslationException ) {
				$this->last_error = $e->getMessage();
			} else {
				$this->last_error = ErrorMapper::map( $e instanceof \PDOException ? (string) $e->getCode() : '', $e->getMessage() );
			}
			return false;
		}
	}

	/**
	 * Read a scalar memory value from fetched rows (first column of first row).
	 *
	 * @param array $rows Fetched row objects.
	 * @return int
	 */
	public static function mem_scalar( $rows ) {
		if ( array() === $rows ) {
			return 0;
		}
		$vars = get_object_vars( $rows[0] );
		if ( array() === $vars ) {
			return 0;
		}
		return (int) reset( $vars );
	}

	/**
	 * Record a query failure in the compat log (silent on any failure).
	 *
	 * @param string     $mysql Original MySQL SQL.
	 * @param \Exception $e     Failure.
	 */
	private function logCompat( $mysql, $e ) {
		$this->logKind( 'error', '', $mysql );
	}

	/**
	 * Record a compat entry of any kind (silent on any failure).
	 *
	 * @param string $kind  error|warn|slow|unsupported.
	 * @param string $rule  Rule marker (e.g. MS:<ms>).
	 * @param string $mysql Original MySQL SQL.
	 */
	private function logKind( $kind, $rule, $mysql ) {
		if ( '' === $this->compat_log_file ) {
			return;
		}
		try {
			$redacted = Redactor::redact( (string) $mysql );
			$log      = $this->compatLog();
			$log->append(
				array(
					'kind'   => (string) $kind,
					'rule'   => (string) $rule,
					'shape'  => hash( 'sha256', $redacted ),
					'sql'    => $redacted,
					'source' => $this->guessSource(),
				)
			);
		} catch ( \Throwable $ignored ) {
		}
	}

	/**
	 * Cached compat log for this request (single file re-read when unset).
	 *
	 * @return CompatLog
	 */
	private function compatLog() {
		if ( null === $this->compat_log ) {
			$this->compat_log = new CompatLog( $this->compat_log_file );
		}
		return $this->compat_log;
	}

	/**
	 * Translation-plan cache for this request. Lazily built from the
	 * WPSLONIK_CACHE constant when none was injected (tests inject a fake).
	 *
	 * @return CacheInterface|null null when caching is disabled/unavailable.
	 */
	private function translationCache(): ?CacheInterface {
		if ( null === $this->translation_cache && ! $this->translation_cache_attempted ) {
			$this->translation_cache_attempted = true;
			$this->translation_cache = \Slonik\Cache\CacheFactory::make();
		}
		return $this->translation_cache;
	}

	/**
	 * Server-side prepared statement for a statement shape ($1, $2 …).
	 * Prepared once per shape per connection, then reused for every
	 * execute with different bound values (M7.2). The cache is cleared
	 * whenever the catalog is reloaded after DDL.
	 *
	 * @param string $sql Statement shape with $1, $2 … placeholders.
	 * @return \PDOStatement
	 */
	private function pg_prepare( $sql ) {
		if ( isset( $this->statement_cache[ $sql ] ) ) {
			return $this->statement_cache[ $sql ];
		}
		$stmt = $this->pg->prepare( $sql );
		$this->statement_cache[ $sql ] = $stmt;
		return $stmt;
	}

	/**
	 * Run a query, binding parameters when the shape has any.
	 *
	 * @param string $sql  Statement shape with $1, $2 … placeholders.
	 * @param array  $params Bound values in placeholder order.
	 * @return \PDOStatement
	 */
	private function pg_query( $sql, array $params = array() ) {
		if ( array() === $params ) {
			return $this->pg->query( $sql );
		}
		$stmt = $this->pg_prepare( $sql );
		$stmt->execute( $params );
		return $stmt;
	}

	/**
	 * Run an executing statement, binding parameters when the shape has
	 * any. Returns the affected-row count.
	 *
	 * @param string $sql   Statement shape with $1, $2 … placeholders.
	 * @param array  $params Bound values in placeholder order.
	 * @return int Affected rows.
	 */
	private function pg_exec( $sql, array $params = array() ) {
		if ( array() === $params ) {
			return $this->pg->exec( $sql );
		}
		$stmt = $this->pg_prepare( $sql );
		$stmt->execute( $params );
		return (int) $stmt->rowCount();
	}

	/**
	 * Attribute the current call to a source (plugin/theme/core) via stack.
	 *
	 * @return string Source label or 'unknown'.
	 */
	private function guessSource() {
		$frames = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 30 );
		$fallback = '';
		foreach ( $frames as $frame ) {
			$file = isset( $frame['file'] ) ? (string) $frame['file'] : '';
			if ( '' === $file ) {
				continue;
			}
			if ( false !== strpos( str_replace( '\\', '/', $file ), '/postgresql-drop-in/src' ) ) {
				continue;
			}
			$slug = SourceGuesser::slug( $file );
			if ( 0 === strpos( $slug, 'plugin:' ) && 'plugin:postgresql-drop-in' !== $slug ) {
				return $slug;
			}
			if ( 0 === strpos( $slug, 'theme:' ) ) {
				return $slug;
			}
			if ( 'core' === $slug && '' === $fallback ) {
				$fallback = 'core';
			}
		}
		return '' !== $fallback ? $fallback : 'unknown';
	}

	private function parse_host( $host ) {
		return HostPort::dsn( $host );
	}

	private function shape_rows( $stmt, $rows ) {
		$count = $stmt->columnCount();
		$types = array();
		for ( $i = 0; $i < $count; ++$i ) {
			$meta        = $stmt->getColumnMeta( $i );
			$types[ $i ] = isset( $meta['native_type'] ) ? (string) $meta['native_type'] : '';
		}
		$shaped = array();
		foreach ( $rows as $row ) {
			$vars = get_object_vars( $row );
			$new  = new \stdClass();
			$j    = 0;
			foreach ( $vars as $key => $value ) {
				$type      = isset( $types[ $j ] ) ? $types[ $j ] : '';
				$new->$key = ResultShaper::map_value( $type, $value );
				++$j;
			}
			$shaped[] = $new;
		}
		return $shaped;
	}

	/**
	 * Report a MySQL-8-like version string without touching the connection.
	 *
	 * Parent reads it via mysqli on $dbh; PG has no equivalent at this layer.
	 * Any 8.x string satisfies core's >= 5.7 floor checks.
	 *
	 * @return string
	 */
	public function db_version() {
		return '8.4.0';
	}

	/**
	 * Report the PostgreSQL server version string.
	 *
	 * Parent reads it via mysqli on $dbh (fatal on null). Only used by core
	 * for engine detection (e.g. MariaDB checks in dbDelta).
	 *
	 * @return string
	 */
	public function db_server_info() {
		$this->db_connect();
		$pg = $this->pg;
		if ( ! $pg instanceof PDO ) {
			return '16.0';
		}
		$version = $pg->query( 'SHOW server_version' )->fetchColumn();
		return is_string( $version ) && '' !== $version ? $version : '16.0';
	}
}

if ( ! class_exists( 'WPSLONIK_DB', false ) ) {
	class_alias( 'Slonik\\Runtime\\WpslonikDb', 'WPSLONIK_DB' );
}
