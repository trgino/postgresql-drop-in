<?php
/**
 * Uninstall handler: removes the drop-in and the artifacts it owns, but
 * only when that is safe (audit D-11).
 *
 * Rules:
 * - While the site runs on PostgreSQL (WPSLONIK_ENABLED === true) the
 *   drop-in stays: removing it would silently fall back to MySQL, whose
 *   copy is stale. A note file tells the operator what to do.
 * - A foreign db.php is never deleted; a chained backup is restored.
 * - Only files this plugin wrote are removed (wp-content/wpslonik/ and
 *   wp-content/cache/wpslonik/).
 *
 * @package Slonik
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/src/Chain/DropinMarker.php';
require_once __DIR__ . '/src/Chain/Classifier.php';
require_once __DIR__ . '/src/Chain/Installer.php';

$wpslonik_content_dir = '';
if ( defined( 'WP_CONTENT_DIR' ) ) {
	$wpslonik_content_dir = (string) WP_CONTENT_DIR;
} elseif ( defined( 'ABSPATH' ) ) {
	$wpslonik_content_dir = rtrim( (string) ABSPATH, '/\\' ) . '/wp-content';
}
if ( '' === $wpslonik_content_dir ) {
	return;
}

$wpslonik_pg_enabled = defined( 'WPSLONIK_ENABLED' ) && true === WPSLONIK_ENABLED;
$wpslonik_installer  = new \Slonik\Chain\Installer( $wpslonik_content_dir, '' );
$wpslonik_plan       = $wpslonik_installer->uninstallPlan( $wpslonik_pg_enabled );

if ( 'keep' === $wpslonik_plan['action'] ) {
	$wpslonik_installer->writeNotice(
		$wpslonik_content_dir . '/wpslonik',
		"WP-Slonik was uninstalled but the site still runs on PostgreSQL.\n"
		. 'The drop-in was kept on purpose: removing it would fall back to MySQL, whose copy is stale.\n'
		. "To finish the removal:\n"
		. "  1. set WPSLONIK_ENABLED to false in wp-config.php\n"
		. "  2. delete wp-content/db.php (or run: wp slonik uninstall-dropin --yes)\n"
		. "  3. delete wp-content/wpslonik/ and wp-content/cache/wpslonik/\n"
	);
	return;
}

if ( 'restore' === $wpslonik_plan['action'] ) {
	$wpslonik_installer->restore();
} elseif ( 'remove' === $wpslonik_plan['action'] ) {
	$wpslonik_installer->uninstall();
}

$wpslonik_installer->cleanup();
