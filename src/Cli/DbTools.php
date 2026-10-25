<?php
/**
 * `wp slonik dump|restore` thin wrappers over pg_dump/pg_restore (K-40).
 *
 * The password travels via the PGPASSWORD environment variable only —
 * never as a CLI flag, never in logged output. Command construction is
 * pure and unit-tested; only runBinary() touches the OS.
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Cli;

use Slonik\Health\Env;

final class DbTools {

	/**
	 * @param string $binary pg_dump path.
	 * @param array  $conn   host, port, user, pass, name.
	 * @param string $file   Output file.
	 * @return array Array with cmd and env keys.
	 */
	public static function buildDump( $binary, $conn, $file ) {
		return array(
			'cmd' => array(
				$binary,
				'-h', (string) $conn['host'],
				'-p', (string) $conn['port'],
				'-U', (string) $conn['user'],
				'-d', (string) $conn['name'],
				'-F', 'c',
				'-f', (string) $file,
			),
			'env' => array( 'PGPASSWORD' => (string) $conn['pass'] ),
		);
	}

	/**
	 * @param string $binary pg_restore path.
	 * @param array  $conn   host, port, user, pass, name.
	 * @param string $file   Input file.
	 * @return array Array with cmd and env keys.
	 */
	public static function buildRestore( $binary, $conn, $file ) {
		return array(
			'cmd' => array(
				$binary,
				'-h', (string) $conn['host'],
				'-p', (string) $conn['port'],
				'-U', (string) $conn['user'],
				'-d', (string) $conn['name'],
				'-c',
				(string) $file,
			),
			'env' => array( 'PGPASSWORD' => (string) $conn['pass'] ),
		);
	}

	/**
	 * @param array $built buildDump/buildRestore output.
	 * @return string Log-safe command line (password masked).
	 */
	public static function redacted( $built ) {
		$built = (array) $built;
		$cmd   = isset( $built['cmd'] ) ? (array) $built['cmd'] : array();
		$env   = isset( $built['env'] ) ? (array) $built['env'] : array();
		$pw    = isset( $env['PGPASSWORD'] ) ? (string) $env['PGPASSWORD'] : '';
		$text  = implode( ' ', array_map( 'escapeshellarg', $cmd ) );
		if ( '' !== $pw ) {
			$text = str_replace( $pw, '***', $text );
		}
		return 'PGPASSWORD=*** ' . $text;
	}

	/**
	 * @param string $name Binary name (pg_dump).
	 * @param string $path PATH-style directory list (default: getenv PATH).
	 * @return string|null Full path or null.
	 */
	public static function findBinary( $name, $path = '' ) {
		if ( '' === $path ) {
			$path = getenv( 'PATH' );
			if ( ! is_string( $path ) ) {
				return null;
			}
		}
		$suffixes = ( 'Windows' === PHP_OS_FAMILY || '\\' === DIRECTORY_SEPARATOR ) ? array( '.exe', '.bat', '' ) : array( '' );
		foreach ( explode( PATH_SEPARATOR, (string) $path ) as $dir ) {
			foreach ( $suffixes as $suffix ) {
				$candidate = rtrim( $dir, '/\\' ) . DIRECTORY_SEPARATOR . $name . $suffix;
				if ( is_file( $candidate ) ) {
					return $candidate;
				}
			}
		}
		return null;
	}

	/**
	 * @param string $binary Binary path.
	 * @param array  $conn   Connection parts.
	 * @param string $file   Dump file.
	 * @param string $mode   dump|restore.
	 * @return int Exit code: 0 ok, 1 failure.
	 */
	public static function runBinary( $binary, $conn, $file, $mode ) {
		if ( ! is_file( (string) $binary ) ) {
			echo 'error: binary not found: ' . $binary . "\n";
			return 1;
		}
		$built = 'restore' === $mode
			? self::buildRestore( $binary, $conn, $file )
			: self::buildDump( $binary, $conn, $file );
		echo self::redacted( $built ) . "\n";
		$descriptors = array(
			0 => array( 'file', 'php://stdin', 'r' ),
			1 => array( 'file', 'php://stdout', 'w' ),
			2 => array( 'file', 'php://stderr', 'w' ),
		);
		$env     = array_merge( is_array( getenv() ) ? getenv() : array(), $built['env'] );
		$process = proc_open( $built['cmd'], $descriptors, $pipes, null, $env );
		if ( ! is_resource( $process ) ) {
			echo "error: could not start process\n";
			return 1;
		}
		return proc_close( $process );
	}

	/**
	 * WP-CLI entry: wp slonik dump --file=… [--binary=…].
	 *
	 * @param array $args  Positional args (unused).
	 * @param array $assoc Assoc args.
	 */
	public static function dump( $args, $assoc ) {
		exit( self::transfer( (array) $assoc, 'dump' ) );
	}

	/**
	 * WP-CLI entry: wp slonik restore --file=… [--binary=…].
	 *
	 * @param array $args  Positional args (unused).
	 * @param array $assoc Assoc args.
	 */
	public static function restore( $args, $assoc ) {
		exit( self::transfer( (array) $assoc, 'restore' ) );
	}

	/**
	 * @param array  $assoc Assoc args.
	 * @param string $mode  dump|restore.
	 * @return int Exit code (no exit call; entries exit).
	 */
	public static function transfer( $assoc, $mode ) {
		if ( empty( $assoc['file'] ) ) {
			echo 'usage: wp slonik ' . $mode . " --file=…\n";
			return 3;
		}
		if ( 'restore' === $mode && empty( $assoc['yes'] ) ) {
			echo "refusing: restoring overwrites the target database; rerun with --yes\n";
			return 2;
		}
		$env     = Env::detect();
		$defines = $env->defines();
		$host    = isset( $defines['WPSLONIK_DB_HOST'] ) ? (string) $defines['WPSLONIK_DB_HOST'] : '127.0.0.1';
		$binary  = 'dump' === $mode ? 'pg_dump' : 'pg_restore';
		if ( ! empty( $assoc['binary'] ) ) {
			$resolved = (string) $assoc['binary'];
		} elseif ( defined( 'WPSLONIK_PGDUMP_PATH' ) && '' !== (string) constant( 'WPSLONIK_PGDUMP_PATH' ) ) {
			$resolved = rtrim( (string) constant( 'WPSLONIK_PGDUMP_PATH' ), '/\\' ) . DIRECTORY_SEPARATOR . $binary;
		} else {
			$resolved = self::findBinary( $binary );
		}
		if ( ! is_string( $resolved ) || ! is_file( $resolved ) ) {
			echo 'error: ' . $binary . " not found (set WPSLONIK_PGDUMP_PATH or --binary=…)\n";
			return 1;
		}
		list( $host, $port ) = HealthCommand::splitHost( $host );
		$conn = array(
			'host' => $host,
			'port' => $port,
			'user' => isset( $defines['WPSLONIK_DB_USER'] ) ? (string) $defines['WPSLONIK_DB_USER'] : '',
			'pass' => isset( $defines['WPSLONIK_DB_PASSWORD'] ) ? (string) $defines['WPSLONIK_DB_PASSWORD'] : '',
			'name' => isset( $defines['WPSLONIK_DB_NAME'] ) ? (string) $defines['WPSLONIK_DB_NAME'] : '',
		);
		$code = self::runBinary( $resolved, $conn, (string) $assoc['file'], $mode );
		return $code;
	}
}
