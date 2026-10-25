<?php
/**
 * Compat rule tags for scanner findings (docs/05 rules; docs/10 §2.3).
 *
 * Maps template patterns to rule IDs. Only translated-OK deviations get
 * 'warning'; parse/untranslatable failures are 'incompatible'.
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Compat;

use Slonik\Sql\Unsupported;

final class RuleTags {

	const PATTERNS = array(
		'H-09' => '/\bMATCH\s*\([\s\S]*\)\s*AGAINST\b/i',
		'R-046' => '/\bREGEXP\b|\bRLIKE\b/i',
	);


	/**
	 * The unsupported function the template uses, if any.
	 *
	 * @param string $template SQL template (redacted-safe; patterns only).
	 * @return string The function name, or '' when there is none.
	 */
	public static function unsupportedConstruct( $template ) {
		foreach ( Unsupported::FUNCTIONS as $name ) {
			if ( preg_match( '/\\b' . preg_quote( (string) $name, '/' ) . '\\s*\\(/i', (string) $template ) ) {
				return (string) $name;
			}
		}
		return '';
	}

	/**
	 * @param string $template SQL template (redacted-safe; patterns only).
	 * @return array<int,string>
	 */
	public static function fromTemplate( $template ) {
		$ids = array();
		foreach ( self::PATTERNS as $id => $pattern ) {
			if ( preg_match( $pattern, (string) $template ) ) {
				$ids[] = (string) $id;
			}
		}
		return $ids;
	}
}
