<?php
/**
 * H-C25: wp db guard wiring present.
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Health\Checks;

use Slonik\Health\Check;
use Slonik\Health\Env;
use Slonik\Health\Result;

final class GuardCheck implements Check {

	/** @var string[] */
	private $files;

	/**
	 * @param string[] $files wp-cli.yml paths to scan for the guard require.
	 */
	public function __construct( $files ) {
		$this->files = array_values( (array) $files );
	}

	public function id() {
		return 'H-C25';
	}

	public function check( Env $env ) {
		foreach ( $this->files as $file ) {
			$code = @file_get_contents( (string) $file );
			if ( is_string( $code ) && ( false !== strpos( $code, 'wp-cli-guard.php' ) || false !== strpos( $code, 'guard-db.php' ) ) ) {
				return Result::ok( $this->id(), 'wp db guard wired in ' . $file );
			}
		}
		return Result::warn(
			$this->id(),
			'wp db guard not wired',
			array( 'Add "require: ' . dirname( __DIR__, 3 ) . '/wp-cli-guard.php" to wp-cli.yml; never run wp db * on a PG site.' )
		);
	}
}
