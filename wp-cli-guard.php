<?php
/**
 * `wp db *` guard wiring (K-40/K-51, docs/09 §6). Shipped require point.
 *
 * Loaded via wp-cli.yml `require:` — this is the packaged file referenced
 * by the install hint. Blocks every `wp db` subcommand while PG mode is
 * on, unless the operator knowingly bypasses with WPSLONIK_ALLOW_WP_DB=1
 * (rollback MySQL maintenance). Reads wp-config.php with token_get_all —
 * never loads WordPress. Undecidable values never block.
 *
 * Works without WP boot: WP_CLI is often the only class present.
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

if ( ! class_exists( 'Slonik\\Cli\\DbGuard' ) ) {
	require_once __DIR__ . '/src/Cli/DbGuard.php';
}

if ( ! class_exists( 'WP_CLI' ) ) {
	return;
}

WP_CLI::add_hook(
	'before_invoke:db',
	function () {
		$cwd    = getcwd();
		$config = false !== $cwd ? \Slonik\Cli\DbGuard::findConfig( $cwd ) : null;
		$allow  = getenv( 'WPSLONIK_ALLOW_WP_DB' );
		$const  = null !== $config ? \Slonik\Cli\DbGuard::readConstant( $config, 'WPSLONIK_ALLOW_WP_DB' ) : null;
		$allow  = \Slonik\Cli\DbGuard::allowValue( is_string( $allow ) ? $allow : '', null !== $const ? $const : '' );
		if ( null === $config ) {
			return;
		}
		if ( \Slonik\Cli\DbGuard::shouldBlock( \Slonik\Cli\DbGuard::readEnabled( $config ), is_string( $allow ) ? $allow : '' ) ) {
			WP_CLI::error( \Slonik\Cli\DbGuard::MESSAGE );
		}
	}
);
