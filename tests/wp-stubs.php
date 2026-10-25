<?php
/**
 * WordPress function stubs for the unit suite.
 *
 * The unit suite runs without WordPress. The few WordPress functions the
 * production code calls are stubbed here so the call path is exercised
 * (and the site timezone is used instead of the server timezone).
 *
 * @package Slonik
 */

if ( ! function_exists( 'wp_date' ) ) {
	/**
	 * Stub of WordPress' wp_date(): the site timezone.
	 *
	 * @param string   $format    Date format.
	 * @param int|null $timestamp Unix timestamp.
	 * @param mixed    $timezone  Timezone (unused).
	 * @return string
	 */
	function wp_date( $format, $timestamp = null, $timezone = null ) {
		$ts = null === $timestamp ? time() : (int) $timestamp;
		return 'SITE-' . date( (string) $format, $ts );
	}
}
