<?php
/**
 * H-C22: WPSLONIK_* constants coherence (+ unknown-constant typo guard).
 *
 * Transparent mode (WPSLONIK_ENABLED unset/false) is always ok: the
 * drop-in does nothing, so no constant can block.
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

final class ConstantsCheck implements Check {

	const KNOWN = array(
		'WPSLONIK_ENABLED',
		'WPSLONIK_DB_HOST',
		'WPSLONIK_DB_PORT',
		'WPSLONIK_DB_USER',
		'WPSLONIK_DB_PASSWORD',
		'WPSLONIK_DB_NAME',
		'WPSLONIK_DB_SCHEMA',
		'WPSLONIK_DB_SSLMODE',
		'WPSLONIK_DB_SSLROOTCERT',
		'WPSLONIK_DB_PERSISTENT',
		'WPSLONIK_DB_APP_NAME',
		'WPSLONIK_CI_MODE',
		'WPSLONIK_COMPAT_SLOW_MS',
		'WPSLONIK_ALLOW_WP_DB',
		'WPSLONIK_BEHIND_POOLER',
		'WPSLONIK_CACHE',
		'WPSLONIK_VERSION',
		'WPSLONIK_PLUGIN_FILE',
		'WPSLONIK_PLUGIN_DIR',
		'WPSLONIK_PGDUMP_PATH',
		'WPSLONIK_MIGRATE_BATCH_ROWS',
		'WPSLONIK_MIGRATE_BATCH_BYTES',
		'WPSLONIK_MIGRATE_ON_INVALID_UTF8',
		'WPSLONIK_MIGRATE_VERIFY',
		'WPSLONIK_MIGRATE_EXCLUDE_TABLES',
		'WPSLONIK_MIGRATE_INCLUDE_TABLES',
		'WPSLONIK_MIGRATE_DROP_TARGET',
	);

	/**
	 * Documented, but not implemented yet: setting them has no effect,
	 * so the operator is told instead of getting a false "ok".
	 *
	 * @var array<string,true>
	 */
	const NOT_IMPLEMENTED = array(
		'WPSLONIK_DB_TIMEZONE'          => true,
		'WPSLONIK_DB_STATEMENT_TIMEOUT'  => true,
		'WPSLONIK_STRICT_TRUNCATE'      => true,
		'WPSLONIK_COMPAT_LOG'           => true,
		'WPSLONIK_COMPAT_LOG_SAMPLE'    => true,
		'WPSLONIK_CHAIN'                => true,
		'WPSLONIK_REMOTE_COMPAT_LIST'   => true,
	);

	const REQUIRED = array(
		'WPSLONIK_DB_HOST',
		'WPSLONIK_DB_USER',
		'WPSLONIK_DB_NAME',
	);

	public function id() {
		return 'H-C22';
	}

	public function check( Env $env ) {
		$defines = $env->defines();
		$unknown = array();
		$pending = array();
		foreach ( $defines as $name => $value ) {
			if ( in_array( (string) $name, self::KNOWN, true ) ) {
				continue;
			}
			if ( isset( self::NOT_IMPLEMENTED[ (string) $name ] ) ) {
				$pending[] = (string) $name;
				continue;
			}
			$unknown[] = (string) $name;
		}
		$enabled = isset( $defines['WPSLONIK_ENABLED'] ) ? $defines['WPSLONIK_ENABLED'] : null;
		if ( true !== $enabled ) {
			return Result::ok( $this->id(), 'PG mode off; drop-in transparent' );
		}
		$missing = array();
		foreach ( self::REQUIRED as $name ) {
			if ( ! isset( $defines[ $name ] ) || '' === (string) $defines[ $name ] ) {
				$missing[] = $name;
			}
		}
		if ( array() !== $missing ) {
			return Result::block(
				$this->id(),
				'PG mode on but missing: ' . implode( ', ', $missing ),
				array( 'Define them in wp-config.php before require wp-settings.php (see docs/16).' )
			);
		}
		if ( array() !== $unknown ) {
			return Result::warn(
				$this->id(),
				'Unknown WPSLONIK_* constants (possible typo): ' . implode( ', ', $unknown ),
				array( 'Remove or correct them; known names are listed in docs/16.' )
			);
		}
		if ( array() !== $pending ) {
			return Result::warn(
				$this->id(),
				'Known but not implemented yet: ' . implode( ', ', $pending ),
				array( 'These settings are documented but have no effect yet; they will arrive with a later milestone.' )
			);
		}
		return Result::ok( $this->id(), 'WPSLONIK_* constants coherent' );
	}
}
