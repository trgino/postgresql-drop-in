<?php
/**
 * nikic/php-parser host-version factory (docs/10 §2, K-36 vendoring model).
 *
 * The library is a composer dependency for M5; packaging must vendor it at
 * release (ledger: M8 blocker). create() refuses cleanly when absent.
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Compat;

final class HostParserFactory implements ParserFactory {

	public function create() {
		if ( ! class_exists( \PhpParser\ParserFactory::class ) ) {
			throw new \RuntimeException( 'nikic/php-parser is not installed; static scan requires a composer context (vendor/autoload.php)' );
		}
		$factory = new \PhpParser\ParserFactory();
		return $factory->createForHostVersion();
	}
}
