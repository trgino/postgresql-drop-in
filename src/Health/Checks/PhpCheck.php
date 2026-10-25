<?php
/**
 * H-C01: PHP, WordPress and pdo_pgsql platform check.
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

final class PhpCheck implements Check {

	public function id() {
		return 'H-C01';
	}

	public function check( Env $env ) {
		if ( version_compare( $env->phpVersion(), '7.4', '<' ) ) {
			return Result::block(
				$this->id(),
				'PHP ' . $env->phpVersion() . ' below minimum 7.4',
				array( 'Upgrade PHP to 7.4 or newer (8.x recommended).' )
			);
		}
		if ( ! $env->hasExtension( 'pdo_pgsql' ) ) {
			return Result::block(
				$this->id(),
				'PHP ' . $env->phpVersion() . ' without pdo_pgsql',
				array( 'Ask the host to install the pgsql extension (e.g. apt install php-pgsql).' )
			);
		}
		$wp = $env->wpVersion();
		if ( null === $wp ) {
			return Result::warn( $this->id(), 'PHP ' . $env->phpVersion() . ' + pdo_pgsql; WP version unknown' );
		}
		if ( version_compare( $wp, '6.4', '<' ) ) {
			return Result::block(
				$this->id(),
				'WordPress ' . $wp . ' below minimum 6.4',
				array( 'Upgrade WordPress to 6.4 or newer.' )
			);
		}
		return Result::ok( $this->id(), 'PHP ' . $env->phpVersion() . ' + pdo_pgsql, WP ' . $wp );
	}
}
