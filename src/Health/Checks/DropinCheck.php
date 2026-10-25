<?php
/**
 * H-C11/H-C12/H-C23: drop-in presence, conflict scan, version handshake, drift.
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Health\Checks;

use Slonik\Chain\DropinMarker;
use Slonik\Health\Check;
use Slonik\Health\Env;
use Slonik\Health\Result;

final class DropinCheck implements Check {

	const CONFLICTS = array(
		'HyperDB'      => 'HyperDB',
		'LudicrousDB'  => 'LudicrousDB',
		'QM_DB'        => 'Query Monitor',
		'W3TC'         => 'W3 Total Cache',
		'SQLite'       => 'SQLite drop-in',
		'sqlite'       => 'SQLite drop-in',
	);

	/** @var string */
	private $plugin_version;

	public function __construct( $plugin_version ) {
		$this->plugin_version = (string) $plugin_version;
	}

	public function id() {
		return 'H-C11';
	}

	public function check( Env $env ) {
		$dir = $env->contentDir();
		if ( '' === $dir ) {
			return Result::ok( 'H-C11', 'no content dir known; drop-in unchecked' );
		}
		$file = rtrim( $dir, '/\\' ) . '/db.php';
		if ( ! is_file( $file ) ) {
			return Result::ok( 'H-C11', 'no db.php drop-in installed' );
		}
		$code = file_get_contents( $file );
		if ( ! is_string( $code ) ) {
			return Result::warn( 'H-C11', 'db.php unreadable' );
		}
		$parsed = DropinMarker::parse( $code );
		if ( null === $parsed ) {
			foreach ( self::CONFLICTS as $needle => $owner ) {
				if ( false !== stripos( $code, $needle ) ) {
					return Result::block(
						'H-C11',
						'db.php belongs to ' . $owner,
						array( 'See the chaining strategy in docs/03 §7 (wp slonik chain).' )
					);
				}
			}
			return Result::warn(
				'H-C11',
				'unrecognized db.php without Slonik marker',
				array( 'Back it up, then run wp slonik install-dropin.' )
			);
		}
		if ( $parsed['version'] !== $this->plugin_version ) {
			return Result::block(
				'H-C12',
				'drop-in v' . $parsed['version'] . ' ≠ plugin v' . $this->plugin_version,
				array( 'Run wp slonik install-dropin --force.' )
			);
		}
		if ( ! DropinMarker::verify( $code ) ) {
			return Result::block(
				'H-C23',
				'installed db.php drifted from the recorded hash',
				array( 'Run wp slonik install-dropin --force to reinstall.' )
			);
		}
		return Result::ok( 'H-C12', 'drop-in v' . $parsed['version'] . ' matches plugin' );
	}
}
