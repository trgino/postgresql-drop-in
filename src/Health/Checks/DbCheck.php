<?php
/**
 * Database health checks (H-C02..C06, H-C10, H-C26) behind the Probe interface.
 *
 * One Check object may return several Results (one per wanted id); the
 * Runner flattens them.
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Health\Checks;

use Slonik\Health\Check;
use Slonik\Health\Env;
use Slonik\Health\Probe;
use Slonik\Health\Result;
use Slonik\Schema\SpellingAudit;
use Throwable;

final class DbCheck implements Check {

	/** @var Probe */
	private $probe;

	/** @var string[] */
	private $wants;

	/**
	 * @param Probe    $probe Database probe.
	 * @param string[] $wants Check ids to run.
	 */
	public function __construct( Probe $probe, $wants ) {
		$this->probe = $probe;
		$this->wants = array_values( (array) $wants );
	}

	public function id() {
		return 'H-C02';
	}

	/**
	 * @param Env $env
	 * @return Result[]
	 */
	public function check( Env $env ) {
		$out = array();
		foreach ( $this->wants as $id ) {
			try {
				$out[] = $this->runOne( (string) $id, $env );
			} catch ( Throwable $e ) {
				$out[] = Result::block( (string) $id, 'probe failed: ' . $e->getMessage() );
			}
		}
		return $out;
	}

	private function runOne( $id, Env $env ) {
		switch ( $id ) {
			case 'H-C02':
				$this->probe->queryValue( 'SELECT 1' );
				return Result::ok( $id, 'PG connection ok' );
			case 'H-C03':
				$num = (int) $this->probe->queryValue( 'SHOW server_version_num' );
				if ( $num < 160000 ) {
					return Result::block(
						$id,
						'PostgreSQL version_num ' . $num . ' below 160000',
						array( 'Upgrade PostgreSQL to 16 or newer.' )
					);
				}
				return Result::ok( $id, 'PostgreSQL ' . self::dotted( $num ) );
			case 'H-C04':
				$enc = (string) $this->probe->queryValue( 'SHOW server_encoding' );
				if ( 'UTF8' !== strtoupper( $enc ) ) {
					return Result::block(
						$id,
						'server_encoding is ' . $enc . ', need UTF8',
						array( "Recreate the database with ENCODING 'UTF8'." )
					);
				}
				return Result::ok( $id, 'server_encoding UTF8' );
			case 'H-C05':
				// Probe the configured schema, not current_schema(): with
				// WPSLONIK_DB_SCHEMA the site lives in another schema.
				$schema = (string) $env->define( 'WPSLONIK_DB_SCHEMA' );
				if ( '' === $schema ) {
					$schema = 'public';
				}
				$can = $this->probe->queryValue( "SELECT has_schema_privilege('" . str_replace( "'", "''", $schema ) . "', 'CREATE')" );
				if ( true !== $can && 't' !== $can && '1' !== (string) $can && 1 !== $can ) {
					return Result::block(
						$id,
						'no CREATE privilege on the target schema',
						array( 'GRANT CREATE ON SCHEMA ' . $schema . ' to the WP user (PG15+: public needs an explicit grant).' )
					);
				}
				return Result::ok( $id, 'CREATE privilege ok (' . $schema . ')' );
			case 'H-C06':
				$count  = (int) $this->probe->queryValue( "SELECT count(*) FROM pg_extension WHERE extname = 'citext'" );
				$mode   = \Slonik\Schema\CiMode::effective( $env->define( 'WPSLONIK_CI_MODE' ), $count > 0 );
				$info   = \Slonik\Schema\CiMode::describe( $mode );
				if ( \Slonik\Schema\CiMode::CITEXT === $mode ) {
					return Result::ok( $id, 'citext installed - ' . $info['label'] );
				}
				$hints = array( $info['summary'] );
				if ( '' !== $info['needs'] ) {
					$hints[] = $info['needs'];
				}
				foreach ( \Slonik\Schema\CiMode::installSteps() as $step ) {
					$hints[] = $step;
				}
				return Result::warn(
					$id,
					'citext extension missing - ' . $info['label'],
					$hints
				);
			case 'H-C10':
				if ( $env->behindPool() ) {
					return Result::warn(
						$id,
						'behind a pooler (transaction mode): session state and advisory locks may not survive',
						array( 'Prefer session pooling, or accept the limitation explicitly.' )
					);
				}
				return Result::ok( $id, 'no pooler flagged' );
			case 'H-C26':
				// Two relations whose names differ only in the case hold
				// different data: the site reads one of them and the other
				// one looks empty (audit D-82).
				$schema = (string) $env->define( 'WPSLONIK_DB_SCHEMA' );
				if ( '' === $schema ) {
					$schema = 'public';
				}
				$groups = SpellingAudit::groups( $this->probe->queryValue( SpellingAudit::query( $schema ) ) );
				if ( array() === $groups ) {
					return Result::ok( $id, 'no table name has two spellings' );
				}
				return Result::warn(
					$id,
					count( $groups ) . ' table name(s) exist with two spellings',
					array_merge(
						array(
							'The copy with data is the one with a non-zero size; merge it into the spelling the plugin uses and drop the other copy. No automatic cleanup is performed.',
						),
						SpellingAudit::lines( $groups )
					)
				);
		}
		throw new \RuntimeException( 'unknown check id: ' . $id );
	}

	private static function dotted( $num ) {
		$num   = (int) $num;
		$major = (int) ( $num / 10000 );
		$minor = (int) ( ( $num % 10000 ) / 100 );
		$patch = $num % 100;
		return $major . '.' . $minor . '.' . $patch;
	}
}
