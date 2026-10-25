<?php
/**
 * `wp slonik health|status` commands.
 *
 * Thin formatters over Slonik\Health: all logic lives in the service
 * (K-13/K-15 single source of truth). Rendering is pure and unit-tested;
 * only the entry methods touch WP-CLI / PDO (live-proven, never in suite).
 * CLI output is English.
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Cli;

use PDO;
use Slonik\Chain\DropinMarker;
use Slonik\Health\Checks\CompatCheck;
use Slonik\Health\Checks\ConstantsCheck;
use Slonik\Health\Checks\DbCheck;
use Slonik\Health\Checks\DropinCheck;
use Slonik\Health\Checks\GuardCheck;
use Slonik\Health\Checks\PhpCheck;
use Slonik\Health\Env;
use Slonik\Health\PdoProbe;
use Slonik\Health\Result;
use Slonik\Health\Runner;
use Throwable;

final class HealthCommand {

	const DB_CHECKS = array( 'H-C02', 'H-C03', 'H-C04', 'H-C05', 'H-C06', 'H-C10', 'H-C26' );

	public static function renderTable( $results, $severity = null ) {
		$rank  = array( 'ok' => 0, 'warn' => 1, 'block' => 2 );
		$min   = isset( $rank[ (string) $severity ] ) ? $rank[ (string) $severity ] : 0;
		$lines = array();
		$block = 0;
		$warn  = 0;
		foreach ( (array) $results as $result ) {
			if ( ! $result instanceof Result ) {
				continue;
			}
			if ( $rank[ $result->status ] < $min ) {
				continue;
			}
			if ( 'block' === $result->status ) {
				++$block;
				$glyph = '✖';
			} elseif ( 'warn' === $result->status ) {
				++$warn;
				$glyph = '!';
			} else {
				$glyph = '✔';
			}
			$line = $glyph . ' ' . $result->id . ' ' . $result->message;
			if ( 'ok' !== $result->status && array() !== $result->remediation ) {
				$line .= '   → ' . $result->remediation[0];
			}
			$lines[] = $line;
		}
		$parts = array();
		if ( $block > 0 ) {
			$parts[] = $block . ( 1 === $block ? ' blocker' : ' blockers' );
		}
		if ( $warn > 0 ) {
			$parts[] = $warn . ( 1 === $warn ? ' warning' : ' warnings' );
		}
		$lines[] = 'Summary: ' . ( array() === $parts ? 'all green' : implode( ', ', $parts ) );
		return implode( "\n", $lines ) . "\n";
	}

	public static function renderJson( $results ) {
		$out = array();
		foreach ( (array) $results as $result ) {
			if ( ! $result instanceof Result ) {
				continue;
			}
			$out[] = array(
				'id'          => $result->id,
				'status'      => $result->status,
				'message'     => $result->message,
				'remediation' => $result->remediation,
			);
		}
		$json = json_encode( $out );
		return is_string( $json ) ? $json : '[]';
	}

	/**
	 * @param array<string,Result> $results
	 * @param array                $assoc assoc args (format, severity).
	 * @return int Exit code: 0 green, 1 warning, 2 blocker, 3 usage error.
	 */
	public static function runHealth( $results, $assoc ) {
		$format = isset( $assoc['format'] ) ? (string) $assoc['format'] : 'table';
		if ( 'table' !== $format && 'json' !== $format ) {
			echo "error: unknown format '" . $format . "' (want table|json)\n";
			return 3;
		}
		$severity = isset( $assoc['severity'] ) ? (string) $assoc['severity'] : null;
		if ( null !== $severity && ! in_array( $severity, array( 'ok', 'warn', 'block' ), true ) ) {
			echo "error: unknown severity '" . $severity . "' (want ok|warn|block)\n";
			return 3;
		}
		echo 'json' === $format ? self::renderJson( $results ) . "\n" : self::renderTable( $results, $severity );
		return Runner::exitCode( $results );
	}

	public static function renderStatus( $info ) {
		$info = (array) $info + array(
			'version' => 'n/a',
			'pg'      => 'n/a',
			'dropin'  => 'n/a',
			'errors'  => 'n/a',
		);
		return 'Slonik ' . $info['version'] . ' | PG ' . $info['pg'] . ' | drop-in ' . $info['dropin'] . ' | errors ' . $info['errors'];
	}

	/**
	 * WP-CLI entry: wp slonik health.
	 *
	 * @param array $args  Positional args (unused).
	 * @param array $assoc Assoc args (format, severity).
	 */
	public static function health( $args, $assoc ) {
		$code = self::runHealth( self::gather(), (array) $assoc );
		exit( $code );
	}

	/**
	 * WP-CLI entry: wp slonik status.
	 *
	 * @param array $args  Positional args (unused).
	 * @param array $assoc Assoc args (unused).
	 */
	public static function status( $args, $assoc ) {
		echo self::renderStatus( self::statusInfo() ) . "\n";
		exit( 0 );
	}

	/**
	 * @param string $host Raw host value (may carry :port).
	 * @return array Array(host, port). Split only on a single colon (IPv6 passes through).
	 */
	public static function splitHost( $host ) {
		$host = (string) $host;
		if ( 1 !== substr_count( $host, ':' ) ) {
			return array( $host, 5432 );
		}
		$parts = explode( ':', $host, 2 );
		return array( $parts[0], (int) $parts[1] );
	}

	/**
	 * @param string $content_dir wp-content path.
	 * @return string Drop-in label: none|foreign|v<version>[ DRIFT].
	 */
	public static function dropinLabel( $content_dir ) {
		$file = rtrim( (string) $content_dir, '/\\' ) . '/db.php';
		if ( ! is_file( $file ) ) {
			return 'none';
		}
		$code = file_get_contents( $file );
		if ( ! is_string( $code ) ) {
			return 'foreign';
		}
		$parsed = DropinMarker::parse( $code );
		if ( null === $parsed ) {
			return 'foreign';
		}
		$label = 'v' . $parsed['version'];
		if ( ! DropinMarker::verify( $code ) ) {
			$label .= ' DRIFT';
		}
		return $label;
	}

	/**
	 * @param string $content_dir wp-content path.
	 * @return string[] wp-cli.yml candidates (project + global).
	 */
	public static function guardFiles( $content_dir ) {
		$files = array( dirname( rtrim( (string) $content_dir, '/\\' ) ) . '/wp-cli.yml' );
		$home  = getenv( 'HOME' );
		if ( ! is_string( $home ) || '' === $home ) {
			$drive = getenv( 'HOMEDRIVE' );
			$path  = getenv( 'HOMEPATH' );
			if ( is_string( $drive ) && is_string( $path ) ) {
				$home = $drive . $path;
			}
		}
		if ( is_string( $home ) && '' !== $home ) {
			$files[] = rtrim( $home, '/\\' ) . '/.wp-cli/config.yml';
		}
		return $files;
	}

	/**
	 * @return array<string,Result> Full health results (shared by CLI + admin).
	 */
	public static function tabResults() {
		return self::gather();
	}

	private static function gather() {		$env     = Env::detect();
		$version = defined( 'WPSLONIK_VERSION' ) ? WPSLONIK_VERSION : 'n/a';
		$checks  = array( new PhpCheck(), new ConstantsCheck(), new DropinCheck( $version ) );
		$cdir    = $env->contentDir();
		if ( '' !== $cdir ) {
			$checks[] = new GuardCheck( self::guardFiles( $cdir ) );
			$checks[] = new CompatCheck( rtrim( $cdir, '/\\' ) . '/wpslonik/compat-log.jsonl' );
		}
		$defines = $env->defines();
		if ( isset( $defines['WPSLONIK_ENABLED'] ) && true === $defines['WPSLONIK_ENABLED'] ) {
			$dbcheck = self::dbCheck( $defines );
			if ( null !== $dbcheck ) {
				$checks[] = $dbcheck;
			}
		}
		return Runner::run( $checks, $env );
	}

	/**
	 * @param array $defines WPSLONIK_* defines.
	 * @return DbCheck|null Null when credentials are absent (subset stays bootstrap-safe).
	 */
	private static function dbCheck( $defines ) {
		$host = isset( $defines['WPSLONIK_DB_HOST'] ) ? (string) $defines['WPSLONIK_DB_HOST'] : '';
		$user = isset( $defines['WPSLONIK_DB_USER'] ) ? (string) $defines['WPSLONIK_DB_USER'] : '';
		$name = isset( $defines['WPSLONIK_DB_NAME'] ) ? (string) $defines['WPSLONIK_DB_NAME'] : '';
		if ( '' === $host || '' === $user || '' === $name ) {
			return null;
		}
		$pass = isset( $defines['WPSLONIK_DB_PASSWORD'] ) ? (string) $defines['WPSLONIK_DB_PASSWORD'] : '';
		list( $host, $port ) = self::splitHost( $host );
		try {
			$pdo = new PDO(
				'pgsql:host=' . $host . ';port=' . $port . ';dbname=' . $name,
				$user,
				$pass,
				array( PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION )
			);
		} catch ( Throwable $e ) {
			$message = $e->getMessage();
			$probe   = new class($message) implements \Slonik\Health\Probe {
				/** @var string */
				private $message;
				public function __construct( $message ) {
					$this->message = (string) $message;
				}
				public function queryValue( $sql ) {
					throw new \RuntimeException( $this->message );
				}
				public function execOk( $sql ) {
					throw new \RuntimeException( $this->message );
				}
			};
			return new DbCheck( $probe, array( 'H-C02' ) );
		}
		return new DbCheck( new PdoProbe( $pdo ), self::DB_CHECKS );
	}

	private static function statusInfo() {
		$version = defined( 'WPSLONIK_VERSION' ) ? WPSLONIK_VERSION : 'n/a';
		$env     = Env::detect();
		$pg      = 'n/a';
		$defines = $env->defines();
		if ( isset( $defines['WPSLONIK_ENABLED'] ) && true === $defines['WPSLONIK_ENABLED'] ) {
			$dbcheck = self::dbCheck( $defines );
			if ( null !== $dbcheck ) {
				$res = Runner::run( array( $dbcheck ), $env );
				if ( isset( $res['H-C03'] ) && 'ok' === $res['H-C03']->status ) {
					$pg = $res['H-C03']->message;
				}
			}
		}
		$dropin = 'none';
		$dir    = $env->contentDir();
		if ( '' !== $dir ) {
			$dropin = self::dropinLabel( $dir );
		}
		return array(
			'version' => $version,
			'pg'      => $pg,
			'dropin'  => $dropin,
			'errors'  => 'n/a',
		);
	}
}
