<?php
/**
 * Regression gate for the PostgreSQL acceptance fixtures.
 *
 * The statements in tools/pg-accept/statements.sql must translate (the
 * PostgreSQL side of the gate runs in CI with a real server). This test
 * keeps the translation half honest without a database.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Slonik\Sql\MinTranslator;

final class PgAcceptFixtureTest extends TestCase {

	private const ROOT = __DIR__ . '/../..';

	public function test_every_fixture_statement_translates(): void {
		$lines = file( self::ROOT . '/tools/pg-accept/statements.sql', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		$count = 0;
		foreach ( (array) $lines as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line || 0 === strpos( $line, '--' ) ) {
				continue;
			}
			++$count;
			$plan = MinTranslator::translate( $line );
			$this->assertNotEmpty( $plan->getSteps(), 'no plan for: ' . $line );
		}
		$this->assertGreaterThan( 10, $count, 'fixture statement list looks empty' );
	}

	public function test_fixture_covers_the_mapped_functions(): void {
		$lines = (string) file_get_contents( self::ROOT . '/tools/pg-accept/statements.sql' );
		foreach ( array( 'ABS(', 'CONCAT(', 'IFNULL(', 'RAND(', 'MD5(', 'CAST(' ) as $needle ) {
			$this->assertStringContainsString( $needle, $lines, 'fixture misses ' . $needle );
		}
	}

	/**
	 * The golden rows are the MySQL oracle: the same file is checked
	 * against PostgreSQL (the gate) and against the other MySQL
	 * versions (the oracle check).
	 */
	public function test_every_golden_row_has_a_value(): void {
		$lines = file( self::ROOT . '/tools/pg-accept/results.sql', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		$count = 0;
		foreach ( (array) $lines as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line || 0 === strpos( $line, '--' ) ) {
				continue;
			}
			$this->assertStringContainsString( '|||', $line, 'golden row without a value: ' . $line );
			++$count;
		}
		$this->assertGreaterThan( 50, $count, 'golden result list looks empty' );
	}

	/**
	 * The oracle check exists and knows the shared comparison.
	 */
	public function test_the_oracle_check_is_packaged(): void {
		$this->assertFileExists( self::ROOT . '/tools/pg-accept/oracle.php' );
		$this->assertFileExists( self::ROOT . '/tools/pg-accept/compare.php' );
		$code = (string) file_get_contents( self::ROOT . '/tools/pg-accept/oracle.php' );
		$this->assertStringContainsString( 'compare.php', $code );
		$this->assertStringContainsString( 'slonik_same_value', $code );
	}
}
