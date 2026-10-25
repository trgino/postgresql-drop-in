<?php
/**
 * Case-sensitivity mode (audit D-02).
 *
 * MySQL compares text case-insensitively by default; PostgreSQL does not.
 * Two modes are supported:
 *
 * - citext: the short text columns are created as `citext`, so the
 *   comparison is case-insensitive in the database itself. This needs the
 *   `citext` extension in the target database.
 * - degraded: the extension is missing (or the operator asked for it), so
 *   the comparisons are rewritten to `lower(a) = lower(b)`. The values
 *   behave like MySQL, but the indexes cannot be used for those
 *   comparisons.
 *
 * The mode is decided once (per connection) and reported to the operator
 * in the health check and in the admin panel, together with what has to
 * be installed for the full mode.
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Schema;

use PDO;

final class CiMode {

	/** @var string citext: the extension is used. */
	const CITEXT = 'citext';

	/** @var string degraded: lower() comparison fallback. */
	const DEGRADED = 'degraded';

	/**
	 * Effective mode for a connection.
	 *
	 * @param string|null $configured WPSLONIK_CI_MODE ('citext'|'degraded').
	 * @param bool        $available  The citext extension is installed.
	 * @return string
	 */
	public static function effective( $configured, $available ) {
		$mode = strtolower( trim( (string) $configured ) );
		if ( self::DEGRADED === $mode ) {
			return self::DEGRADED;
		}
		if ( self::CITEXT === $mode ) {
			return $available ? self::CITEXT : self::DEGRADED;
		}
		return $available ? self::CITEXT : self::DEGRADED;
	}

	/**
	 * Is the citext extension installed in this database?
	 *
	 * @param PDO $pdo Connected PDO.
	 * @return bool
	 */
	public static function citext_available( PDO $pdo ) {
		try {
			$value = $pdo->query( "SELECT count(*) FROM pg_extension WHERE extname = 'citext'" )->fetchColumn();
		} catch ( \Throwable $e ) {
			return false;
		}
		return (int) $value > 0;
	}

	/**
	 * Operator-visible description of a mode.
	 *
	 * @param string $mode Mode.
	 * @return array{label:string,summary:string,needs:string}
	 */
	public static function describe( $mode ) {
		if ( self::CITEXT === $mode ) {
			return array(
				'label'   => 'citext (full MySQL-like behaviour)',
				'summary' => 'Text columns are case-insensitive in the database (citext); no query rewriting needed.',
				'needs'   => '',
			);
		}
		return array(
			'label'   => 'degraded (lower() comparison)',
			'summary' => 'The citext extension is missing, so text comparisons are rewritten with lower() (lower(a) = lower(b)): the values behave like MySQL, but the indexes cannot be used for them.',
			'needs'   => 'Install the citext extension for the full behaviour (see the steps below).',
		);
	}

	/**
	 * What the operator has to do for the full mode.
	 *
	 * @return string[]
	 */
	public static function installSteps() {
		return array(
			'1. Install the extension in the target database: CREATE EXTENSION citext;',
			'2. The command needs a superuser (or a role with rights to create the extension).',
			'3. Re-run the migration so the text columns are created as citext.',
			'4. Check the result with: wp slonik health',
		);
	}
}
