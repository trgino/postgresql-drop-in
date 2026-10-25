<?php
/**
 * The value comparison shared by the acceptance gate and the oracle
 * check.
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

if ( ! function_exists( 'slonik_same_value' ) ) {
	/**
	 * Are the two values the same?
	 *
	 * Numbers are compared by value: MySQL's decimal scale and
	 * PostgreSQL's numeric scale need not match (the value does).
	 *
	 * @param string $actual   The value from the database.
	 * @param string $expected The expected value.
	 * @return bool
	 */
	function slonik_same_value( $actual, $expected ) {
		if ( $actual === $expected ) {
			return true;
		}
		if ( is_numeric( (string) $actual ) && is_numeric( (string) $expected ) ) {
			return abs( (float) $actual - (float) $expected ) < 0.000000001;
		}
		$a = strtotime( (string) $actual );
		$b = strtotime( (string) $expected );
		if ( false === $a || false === $b ) {
			return false;
		}
		return $a === $b;
	}
}
