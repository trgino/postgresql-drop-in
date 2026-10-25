<?php
declare( strict_types=1 );

namespace Slonik\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Slonik\Dialect\Pg\MinEmitter;

/**
 * The compatibility matrix found these two in the wild (WooCommerce,
 * WP Mail SMTP, Wordfence, Elementor).
 */
final class MatrixFunctionTest extends TestCase {

	public function test_database_maps_to_current_database(): void {
		$this->assertSame(
			'SELECT CURRENT_DATABASE() FROM "t"',
			MinEmitter::emit( 'SELECT DATABASE() FROM t' )
		);
	}

	public function test_version_maps_to_the_postgresql_version(): void {
		$this->assertSame(
			'SELECT VERSION() FROM "t"',
			MinEmitter::emit( 'SELECT VERSION() FROM t' )
		);
	}

	/**
	 * The information_schema pattern the plugins use. The schema name
	 * is upper case in MySQL; PostgreSQL's own information_schema has
	 * lower case names and a different column set - the matrix found
	 * this pattern in three plugins (known gap, docs/18).
	 */
	public function test_the_information_schema_pattern_translates(): void {
		$this->assertSame(
			'SELECT COUNT(*) FROM "INFORMATION_SCHEMA"."STATISTICS" WHERE "TABLE_SCHEMA" = CURRENT_DATABASE() AND "TABLE_NAME" = \'x\'',
			MinEmitter::emit(
				'SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'x\''
			)
		);
	}
}
