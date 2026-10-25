<?php
/**
 * Target PostgreSQL connection + COPY execution for the migration.
 *
 * Connection values come from MigrateConfig::target() (resolved host:port,
 * sslmode, schema, app name — docs/16 §2.1 rules, same DSN semantics as
 * the runtime).
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Migrate;

use PDO;

final class PgTarget {

	/** @var PDO */
	private $pdo;

	public function __construct( PDO $pdo ) {
		$this->pdo = $pdo;
	}

	public static function connect( array $target ) {
		$options = array(
			PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_STRINGIFY_FETCHES  => true,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			PDO::ATTR_TIMEOUT            => 10,
		);
		if ( isset( $target['persistent'] ) && true === $target['persistent'] ) {
			$options[ PDO::ATTR_PERSISTENT ] = true;
		}
		$dsn = self::dsn( $target );
		return new self( new PDO( $dsn, (string) ( $target['user'] ?? '' ), (string) ( $target['pass'] ?? '' ), $options ) );
	}

	/** Pure DSN builder (WPSLONIK_DB_* rule parity with the runtime). */
	public static function dsn( array $target ) {
		$host = (string) ( $target['host'] ?? '' );
		$port = (int) ( $target['port'] ?? 5432 );
		$dsn  = 'pgsql:';
		if ( '' !== $host ) {
			if ( 0 === strpos( $host, ':' ) ) {
				$dsn .= 'host=' . substr( $host, 1 ) . ';';
			} else {
				$dsn .= 'host=' . $host . ';';
			}
		}
		$dsn .= 'port=' . $port . ';';
		$dsn .= 'dbname=' . (string) ( $target['name'] ?? '' ) . ';';
		$mode = (string) ( $target['sslmode'] ?? '' );
		if ( '' !== $mode ) {
			$dsn .= 'sslmode=' . $mode . ';';
			$cert = (string) ( $target['sslrootcert'] ?? '' );
			if ( '' !== $cert ) {
				$dsn .= 'sslrootcert=' . $cert . ';';
			}
		}
		$app = (string) ( $target['app'] ?? '' );
		if ( '' !== $app ) {
			// Spaces and the DSN separator are dropped (not replaced): a
			// stray ';' would cut the DSN short (audit D-38).
			$dsn .= 'application_name=' . str_replace( array( ' ', ';' ), '', $app ) . ';';
		}
		return rtrim( $dsn, ';' );
	}

	public function pdo() {
		return $this->pdo;
	}

	/**
	 * Session defaults (docs/16 §2.1, runtime parity): the target schema
	 * leads search_path (schema-stage tables are unqualified), bytea reads
	 * back as hex so verifier canonicalization is deterministic.
	 *
	 * @param string $schema Target schema (public default).
	 */
	public function applySessionDefaults( $schema ) {
		$schema = (string) ( '' !== (string) $schema ? $schema : 'public' );
		$q      = '"' . str_replace( '"', '""', $schema ) . '"';
		$this->pdo->exec( 'SET search_path TO ' . $q . ', public' );
		$this->pdo->exec( 'SET bytea_output TO hex' );
		$this->pdo->exec( 'SET client_min_messages TO warning' );
		return true;
	}

	/** One COPY bundle inside the current transaction (or none). */
	public function copy( $table, array $lines ) {
		$table = (string) $table;
		// The NULL marker is CopyBatch's: PDO::pgsqlCopyFromArray mangles
		// the PostgreSQL "\N" marker (audit D-62).
		$count = $this->pdo->pgsqlCopyFromArray( $table, $lines, "\t", CopyBatch::NULL_MARKER );
		return false === $count ? 0 : (int) $count;
	}

	public function exec( $sql ) {
		$this->pdo->exec( (string) $sql );
	}

	public function begin() {
		$this->pdo->beginTransaction();
	}

	public function commit() {
		$this->pdo->commit();
	}

	public function rollBack() {
		$this->pdo->rollBack();
	}
}
