<?php
/**
 * H-C16: critical entries in the runtime compat log.
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Health\Checks;

use Slonik\Audit\CompatLog;
use Slonik\Health\Check;
use Slonik\Health\Env;
use Slonik\Health\Result;

final class CompatCheck implements Check {

	/** @var string */
	private $file;

	public function __construct( $file ) {
		$this->file = (string) $file;
	}

	public function id() {
		return 'H-C16';
	}

	public function check( Env $env ) {
		unset( $env );
		if ( '' === $this->file || ! is_file( $this->file ) ) {
			return Result::ok( $this->id(), 'no compat entries' );
		}
		$log    = new CompatLog( $this->file );
		$cutoff = time() - 86400;
		$recent = 0;
		foreach ( $log->read( 500 ) as $entry ) {
			if ( in_array( $entry['kind'], array( 'error', 'warn' ), true ) && (int) $entry['last'] >= $cutoff ) {
				++$recent;
			}
		}
		if ( $recent > 0 ) {
			return Result::warn(
				$this->id(),
				$recent . ' error/warn shape(s) in the last 24h',
				array( 'Run wp slonik compat-log list to inspect redacted entries.' )
			);
		}
		return Result::ok( $this->id(), 'no recent compat errors' );
	}
}
