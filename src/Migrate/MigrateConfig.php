<?php
/**
 * Migration configuration from wp-config.php defines (docs/15 §2).
 *
 * Source = DB_* (the site still runs MySQL), target = WPSLONIK_DB_*.
 * WPSLONIK_ENABLED stays undefined/false during migration (P-16); the CLI
 * reads enabledFlag() and refuses run when it is true. Passwords are
 * never echoed: describe() redacts them (docs/15 §1/§8).
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Migrate;

use Slonik\Sql\HostPort;

final class MigrateConfig {

	const POLICIES  = array( 'abort' => true, 'replace' => true, 'skip-row' => true );
	const VERIFY    = array( 'count' => true, 'checksum' => true );
	const SSL_MODES = array(
		'disable'     => true,
		'allow'       => true,
		'prefer'      => true,
		'require'     => true,
		'verify-ca'   => true,
		'verify-full' => true,
	);

	/** @var array{host:string,port:int,user:string,pass:string,name:string} */
	private $source;

	/** @var array{host:string,port:int,name:string,user:string,pass:string,schema:string,sslmode:string,sslrootcert:string,persistent:bool,app:string} */
	private $target;

	/** @var array{prefix:string,batch_rows:int,batch_bytes:int,invalid_utf8:string,verify:string,exclude_tables:array<int,string>,include_tables:array<int,string>,drop_target:bool} */
	private $options;

	/** @var bool True when WPSLONIK_ENABLED is defined and truish. */
	private $enabled;

	private function __construct(
		array $source,
		array $target,
		array $options,
		$enabled
	) {
		$this->source  = $source;
		$this->target  = $target;
		$this->options = $options;
		$this->enabled = ( true === $enabled ) || ( '1' === (string) $enabled );
	}

	/**
	 * Build from a define map (tests): name => value, absent keys omitted.
	 *
	 * @param array<string, mixed>  $defs        Define values (DB_*, WPSLONIK_DB_*, WPSLONIK_MIGRATE_*).
	 * @param string                $table_prefix WP table prefix.
	 * @return self
	 */
	public static function fromArray( array $defs, $table_prefix = 'wp_' ) {
		$src_host = self::req( $defs, 'DB_HOST' );
		$src_user = self::req( $defs, 'DB_USER' );
		$src_name = self::req( $defs, 'DB_NAME' );
		list( $src_host, $src_port ) = self::split_host( $src_host, self::num( $defs, 'DB_PORT', 3306 ) );

		$tgt_host = self::req( $defs, 'WPSLONIK_DB_HOST' );
		$tgt_user = self::req( $defs, 'WPSLONIK_DB_USER' );
		$tgt_name = self::req( $defs, 'WPSLONIK_DB_NAME' );
		list( $tgt_host, $tgt_port ) = self::split_host( $tgt_host, self::num( $defs, 'WPSLONIK_DB_PORT', 5432 ) );

		$schema = 'public';
		if ( isset( $defs['WPSLONIK_DB_SCHEMA'] ) && is_string( $defs['WPSLONIK_DB_SCHEMA'] ) && '' !== $defs['WPSLONIK_DB_SCHEMA'] ) {
			$schema = $defs['WPSLONIK_DB_SCHEMA'];
		}
		if ( '' !== $schema && 1 !== preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', $schema ) ) {
			throw new \InvalidArgumentException( 'WPSLONIK_DB_SCHEMA must be a simple PostgreSQL identifier' );
		}

		$sslmode = 'prefer';
		if ( isset( $defs['WPSLONIK_DB_SSLMODE'] ) && is_string( $defs['WPSLONIK_DB_SSLMODE'] ) && '' !== $defs['WPSLONIK_DB_SSLMODE'] ) {
			$sslmode = $defs['WPSLONIK_DB_SSLMODE'];
		}
		if ( ! isset( self::SSL_MODES[ $sslmode ] ) ) {
			throw new \InvalidArgumentException( 'WPSLONIK_DB_SSLMODE must be one of disable, allow, prefer, require, verify-ca, verify-full' );
		}

		$policy = isset( $defs['WPSLONIK_MIGRATE_ON_INVALID_UTF8'] ) ? (string) $defs['WPSLONIK_MIGRATE_ON_INVALID_UTF8'] : 'abort';
		if ( ! isset( self::POLICIES[ $policy ] ) ) {
			throw new \InvalidArgumentException( 'WPSLONIK_MIGRATE_ON_INVALID_UTF8 must be abort, replace or skip-row' );
		}

		$verify = isset( $defs['WPSLONIK_MIGRATE_VERIFY'] ) ? (string) $defs['WPSLONIK_MIGRATE_VERIFY'] : 'checksum';
		if ( ! isset( self::VERIFY[ $verify ] ) ) {
			throw new \InvalidArgumentException( 'WPSLONIK_MIGRATE_VERIFY must be count or checksum' );
		}

		$batch_rows = self::num( $defs, 'WPSLONIK_MIGRATE_BATCH_ROWS', 1000 );
		if ( $batch_rows < 1 ) {
			throw new \InvalidArgumentException( 'WPSLONIK_MIGRATE_BATCH_ROWS must be >= 1' );
		}

		$batch_bytes = self::num( $defs, 'WPSLONIK_MIGRATE_BATCH_BYTES', 8 * 1024 * 1024 );
		if ( $batch_bytes < 1024 ) {
			throw new \InvalidArgumentException( 'WPSLONIK_MIGRATE_BATCH_BYTES must be >= 1024' );
		}

		$exclude = self::strlist( $defs, 'WPSLONIK_MIGRATE_EXCLUDE_TABLES' );
		$include = self::strlist( $defs, 'WPSLONIK_MIGRATE_INCLUDE_TABLES' );

		$drop = isset( $defs['WPSLONIK_MIGRATE_DROP_TARGET'] )
			&& ( true === $defs['WPSLONIK_MIGRATE_DROP_TARGET'] || 1 === $defs['WPSLONIK_MIGRATE_DROP_TARGET'] || '1' === (string) $defs['WPSLONIK_MIGRATE_DROP_TARGET'] );

		$password = '';
		if ( isset( $defs['WPSLONIK_DB_PASSWORD'] ) && is_scalar( $defs['WPSLONIK_DB_PASSWORD'] ) ) {
			$password = (string) $defs['WPSLONIK_DB_PASSWORD'];
		}

		$enabled = isset( $defs['WPSLONIK_ENABLED'] ) ? $defs['WPSLONIK_ENABLED'] : false;

		return new self(
			array(
				'host' => $src_host,
				'port' => $src_port,
				'user' => $src_user,
				'pass' => isset( $defs['DB_PASSWORD'] ) && is_scalar( $defs['DB_PASSWORD'] ) ? (string) $defs['DB_PASSWORD'] : '',
				'name' => $src_name,
			),
			array(
				'host'        => $tgt_host,
				'port'        => $tgt_port,
				'name'        => $tgt_name,
				'user'        => $tgt_user,
				'pass'        => $password,
				'schema'      => $schema,
				'sslmode'     => $sslmode,
				'sslrootcert' => isset( $defs['WPSLONIK_DB_SSLROOTCERT'] ) && is_string( $defs['WPSLONIK_DB_SSLROOTCERT'] ) ? $defs['WPSLONIK_DB_SSLROOTCERT'] : '',
				'persistent'  => isset( $defs['WPSLONIK_DB_PERSISTENT'] ) && true === $defs['WPSLONIK_DB_PERSISTENT'],
				'app'         => isset( $defs['WPSLONIK_DB_APP_NAME'] ) && is_string( $defs['WPSLONIK_DB_APP_NAME'] ) && '' !== $defs['WPSLONIK_DB_APP_NAME'] ? $defs['WPSLONIK_DB_APP_NAME'] : 'postgresql-drop-in',
			),
			array(
				'prefix'         => (string) $table_prefix,
				'batch_rows'     => $batch_rows,
				'batch_bytes'    => $batch_bytes,
				'invalid_utf8'   => $policy,
				'verify'         => $verify,
				'exclude_tables' => $exclude,
				'include_tables' => $include,
				'drop_target'    => $drop,
			),
			$enabled
		);
	}

	/**
	 * Read the same values from real constants (CLI path).
	 *
	 * @return self
	 */
	public static function fromRuntime() {
		$names = array(
			'DB_HOST',
			'DB_PORT',
			'DB_NAME',
			'DB_USER',
			'DB_PASSWORD',
			'WPSLONIK_ENABLED',
			'WPSLONIK_DB_HOST',
			'WPSLONIK_DB_PORT',
			'WPSLONIK_DB_NAME',
			'WPSLONIK_DB_USER',
			'WPSLONIK_DB_PASSWORD',
			'WPSLONIK_DB_SCHEMA',
			'WPSLONIK_DB_SSLMODE',
			'WPSLONIK_DB_SSLROOTCERT',
			'WPSLONIK_DB_PERSISTENT',
			'WPSLONIK_DB_APP_NAME',
			'WPSLONIK_MIGRATE_BATCH_ROWS',
			'WPSLONIK_MIGRATE_BATCH_BYTES',
			'WPSLONIK_MIGRATE_ON_INVALID_UTF8',
			'WPSLONIK_MIGRATE_VERIFY',
			'WPSLONIK_MIGRATE_EXCLUDE_TABLES',
			'WPSLONIK_MIGRATE_INCLUDE_TABLES',
			'WPSLONIK_MIGRATE_DROP_TARGET',
		);
		$defs = array();
		foreach ( $names as $name ) {
			if ( defined( $name ) ) {
				$defs[ $name ] = constant( $name );
			}
		}
		$prefix = isset( $GLOBALS['table_prefix'] ) ? (string) $GLOBALS['table_prefix'] : 'wp_';
		return self::fromArray( $defs, $prefix );
	}

	public function source() {
		return $this->source;
	}

	public function target() {
		return $this->target;
	}

	public function options() {
		return $this->options;
	}

	/** @return bool WPSLONIK_ENABLED is defined and truish (P-16 flip check). */
	public function enabledFlag() {
		return $this->enabled;
	}

	/** Parse host:port from a define value; inline port beats the port define. */
	private static function split_host( $host, $default_port ) {
		$host = trim( (string) $host );
		if ( '' === $host ) {
			throw new \InvalidArgumentException( 'DB_HOST / WPSLONIK_DB_HOST must be a non-empty host' );
		}
		// One parser for the runtime and the tool (audit V-09):
		// [::1]:5432, host:port, ::1 and the unix socket path.
		return HostPort::split( $host, $default_port );
	}

	private static function req( $defs, $name ) {
		if ( ! isset( $defs[ $name ] ) || ! is_string( $defs[ $name ] ) || '' === $defs[ $name ] ) {
			throw new \InvalidArgumentException( $name . ' must be a non-empty string (wp-config.php)' );
		}
		return (string) $defs[ $name ];
	}

	private static function num( $defs, $name, $default ) {
		if ( ! isset( $defs[ $name ] ) || ! is_numeric( (string) $defs[ $name ] ) ) {
			return (int) $default;
		}
		return (int) (string) $defs[ $name ];
	}

	private static function strlist( $defs, $name ) {
		$raw = isset( $defs[ $name ] ) && is_array( $defs[ $name ] ) ? $defs[ $name ] : array();
		$out = array();
		foreach ( $raw as $item ) {
			if ( is_string( $item ) && '' !== $item ) {
				$out[] = $item;
			}
		}
		return $out;
	}
}
