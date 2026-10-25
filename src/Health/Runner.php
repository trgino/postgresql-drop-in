<?php
/**
 * Health check runner with worst-status rollup.
 *
 * A throwing check becomes a BLOCKER carrying the crash message —
 * a failed probe must never report green.
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Health;

use Throwable;

final class Runner {

	/**
	 * @param Check[] $checks
	 * @param Env     $env
	 * @return array<string,Result> Results keyed by check id.
	 */
	public static function run( $checks, Env $env ) {
		$results = array();
		foreach ( (array) $checks as $check ) {
			$id = method_exists( $check, 'id' ) ? $check->id() : get_class( $check );
			try {
				$produced = $check->check( $env );
			} catch ( Throwable $e ) {
				$produced = Result::block( (string) $id, 'check crashed: ' . $e->getMessage() );
			}
			$list = $produced instanceof Result ? array( $produced ) : (array) $produced;
			foreach ( $list as $result ) {
				if ( ! $result instanceof Result ) {
					$result = Result::block( (string) $id, 'check returned no result' );
				}
				$results[ $result->id ] = $result;
			}
		}
		return $results;
	}

	/**
	 * @param array<string,Result> $results
	 * @return string Worst status: block > warn > ok.
	 */
	public static function worst( $results ) {
		$worst = Result::OK;
		foreach ( (array) $results as $result ) {
			if ( ! $result instanceof Result ) {
				continue;
			}
			if ( Result::BLOCK === $result->status ) {
				return Result::BLOCK;
			}
			if ( Result::WARN === $result->status ) {
				$worst = Result::WARN;
			}
		}
		return $worst;
	}

	/**
	 * CI-compatible exit code: 0 green, 1 warning, 2 blocker.
	 *
	 * @param array<string,Result> $results
	 * @return int
	 */
	public static function exitCode( $results ) {
		$worst = self::worst( $results );
		if ( Result::BLOCK === $worst ) {
			return 2;
		}
		if ( Result::WARN === $worst ) {
			return 1;
		}
		return 0;
	}
}
