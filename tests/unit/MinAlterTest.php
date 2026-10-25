<?php
declare( strict_types=1 );

namespace Slonik\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Slonik\Schema\CiMode;
use Slonik\Schema\MinAlter;
use Slonik\Sql\TranslationException;

final class MinAlterTest extends TestCase {

	/**
	 * D-79 v1: ADD COLUMN reuses the CREATE TABLE column machinery on a
	 * mini-parsed statement; the AFTER marker is dropped (PostgreSQL has
	 * no column order, so it is cosmetic).
	 */
	public function test_add_column_drops_the_after_marker(): void {
		$out = MinAlter::translate( "ALTER TABLE wp_wfhits ADD COLUMN `attackLogTime` double(15,4) unsigned NOT NULL AFTER `id`" );
		$this->assertCount( 2, $out );
		$this->assertStringStartsWith( 'ALTER TABLE "wp_wfhits" ADD COLUMN "attackLogTime" double precision NOT NULL', $out[0] );
		$this->assertStringNotContainsString( 'AFTER', $out[0] );
		$this->assertSame( "COMMENT ON COLUMN \"wp_wfhits\".\"attackLogTime\" IS '{\"mysql\":\"double(15,4) unsigned\"}'", $out[1] );
	}

	public function test_add_column_enum_becomes_text_with_check(): void {
		$out = MinAlter::translate( "ALTER TABLE wp_wfconfig ADD COLUMN autoload enum('yes','no') NOT NULL DEFAULT 'yes'" );
		$this->assertStringStartsWith( 'ALTER TABLE "wp_wfconfig" ADD COLUMN "autoload" text NOT NULL DEFAULT \'yes\'', $out[0] );
		$this->assertStringContainsString( "CHECK (\"autoload\" IN ('yes', 'no'))", $out[0] );
	}

	/**
	 * MySQL allows the COLUMN keyword to be omitted; wordfence's
	 * schema-upgrade phase uses the bare ADD form.
	 */
	public function test_add_column_without_the_column_keyword(): void {
		$out = MinAlter::translate( "ALTER TABLE `wp_wfsnipcache` ADD `type` INT UNSIGNED NOT NULL DEFAULT 2" );
		$this->assertStringStartsWith( 'ALTER TABLE "wp_wfsnipcache" ADD COLUMN "type" bigint NOT NULL DEFAULT 2', $out[0] );
		$this->assertStringContainsString( 'CHECK ("type" >= 0)', $out[0] );
	}

	public function test_add_column_default_null_stays_nullable(): void {
		$out = MinAlter::translate( "ALTER TABLE wp_wflogins ADD COLUMN hitID int(10) unsigned DEFAULT NULL AFTER `id`" );
		$this->assertStringStartsWith( 'ALTER TABLE "wp_wflogins" ADD COLUMN "hitID" bigint DEFAULT NULL', $out[0] );
		$this->assertStringNotContainsString( 'NOT NULL', $out[0] );
	}

	/**
	 * The runtime per-table type overrides keep applying on ALTER: the
	 * added column lands with the same payload type the migration built.
	 */
	public function test_ci_mode_flows_into_the_added_column(): void {
		$out = MinAlter::translate( "ALTER TABLE wp_t ADD COLUMN slug varchar(200) NOT NULL DEFAULT ''", array( 'ci_mode' => CiMode::CITEXT ) );
		$this->assertStringContainsString( '"slug" citext NOT NULL', $out[0] );
	}

	public function test_add_column_on_update_gets_the_touch_trigger(): void {
		$out = MinAlter::translate( "ALTER TABLE wp_t ADD COLUMN touched datetime NOT NULL DEFAULT '0000-00-00 00:00:00' ON UPDATE CURRENT_TIMESTAMP" );
		$this->assertStringContainsString( 'ALTER TABLE "wp_t" ADD COLUMN "touched" timestamp(0) without time zone NOT NULL', $out[0] );
		$found = false;
		foreach ( $out as $sql ) {
			if ( 0 === strpos( $sql, 'CREATE OR REPLACE FUNCTION "wpslonik_touch_wp_t_touched"' ) ) {
				$found = true;
			}
		}
		$this->assertTrue( $found );
	}

	/**
	 * The keyed index lands with the CREATE TABLE naming convention.
	 */
	public function test_add_named_index(): void {
		$out = MinAlter::translate( 'ALTER TABLE wp_wfhits ADD INDEX `attackLogTime` (`attackLogTime`)' );
		$this->assertSame(
			array( 'CREATE INDEX IF NOT EXISTS "wp_wfhits__attackLogTime" ON "wp_wfhits" ("attackLogTime")' ),
			$out
		);
	}

	/**
	 * An anonymous MySQL index is auto-named after the column by MySQL
	 * itself; the extract keeps that behavior through the name prefix.
	 */
	public function test_add_anonymous_key_gets_the_column_name(): void {
		$out = MinAlter::translate( 'ALTER TABLE `wp_wfissues` ADD KEY (`lastUpdated`)' );
		$this->assertSame(
			array( 'CREATE INDEX IF NOT EXISTS "wp_wfissues__lastUpdated" ON "wp_wfissues" ("lastUpdated")' ),
			$out
		);
	}

	public function test_add_unique_key(): void {
		$out = MinAlter::translate( 'ALTER TABLE wp_t ADD UNIQUE KEY `slug` (`slug`)' );
		$this->assertSame(
			array( 'CREATE UNIQUE INDEX IF NOT EXISTS "wp_t__slug" ON "wp_t" ("slug")' ),
			$out
		);
	}

	/**
	 * WooCommerce re-declares the primary key of migrated lookup tables
	 * in one statement: the drop goes first, the re-add follows.
	 */
	public function test_drop_and_add_primary_key_in_one_statement(): void {
		$out = MinAlter::translate( 'ALTER TABLE wp_wc_order_product_lookup DROP PRIMARY KEY, ADD PRIMARY KEY (order_item_id, order_id)' );
		$this->assertSame(
			array(
				'ALTER TABLE "wp_wc_order_product_lookup" DROP CONSTRAINT "wp_wc_order_product_lookup_pkey"',
				'ALTER TABLE "wp_wc_order_product_lookup" ADD PRIMARY KEY ("order_item_id", "order_id")',
			),
			$out
		);
	}

	public function test_alter_column_set_string_default(): void {
		$out = MinAlter::translate( "ALTER TABLE wp_wffilemods ALTER COLUMN oldMD5 SET DEFAULT ''" );
		$this->assertSame(
			array( "ALTER TABLE \"wp_wffilemods\" ALTER COLUMN \"oldMD5\" SET DEFAULT ''" ),
			$out
		);
	}

	public function test_alter_column_set_numeric_default(): void {
		$out = MinAlter::translate( 'ALTER TABLE wp_t ALTER COLUMN hits SET DEFAULT 100' );
		$this->assertSame(
			array( 'ALTER TABLE "wp_t" ALTER COLUMN "hits" SET DEFAULT 100' ),
			$out
		);
	}

	/**
	 * SET DEFAULT NULL is MySQL's way of carrying no meaningful value;
	 * PostgreSQL has the same literal, so it goes through unchanged.
	 */
	public function test_alter_column_set_null_default(): void {
		$out = MinAlter::translate( 'ALTER TABLE wp_t ALTER COLUMN hitID SET DEFAULT NULL' );
		$this->assertSame(
			array( 'ALTER TABLE "wp_t" ALTER COLUMN "hitID" SET DEFAULT NULL' ),
			$out
		);
	}

	public function test_alter_column_drop_default(): void {
		$out = MinAlter::translate( 'ALTER TABLE wp_t ALTER COLUMN hits DROP DEFAULT' );
		$this->assertSame(
			array( 'ALTER TABLE "wp_t" ALTER COLUMN "hits" DROP DEFAULT' ),
			$out
		);
	}

	//
	// The v1 scope fail-loud cases (docs/05 R-078).
	//

	public function test_add_column_with_auto_increment_fails_loud(): void {
		$this->expectException( TranslationException::class );
		$this->expectExceptionMessageMatches( '/AUTO_INCREMENT/' );
		MinAlter::translate( 'ALTER TABLE wp_t ADD COLUMN `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT' );
	}

	public function test_foreign_key_fails_loud(): void {
		$this->expectException( TranslationException::class );
		$this->expectExceptionMessageMatches( '/FOREIGN/' );
		MinAlter::translate( 'ALTER TABLE wp_t ADD CONSTRAINT `wp_t_ibfk_1` FOREIGN KEY (`a`) REFERENCES `wp_u` (`id`)' );
	}

	public function test_fulltext_key_fails_loud(): void {
		$this->expectException( TranslationException::class );
		$this->expectExceptionMessageMatches( '/FULLTEXT/' );
		MinAlter::translate( 'ALTER TABLE wp_t ADD FULLTEXT KEY `idx` (`col`)' );
	}

	public function test_drop_column_fails_loud(): void {
		$this->expectException( TranslationException::class );
		$this->expectExceptionMessageMatches( '/DROP COLUMN/' );
		MinAlter::translate( 'ALTER TABLE wp_t DROP COLUMN c' );
	}

	public function test_drop_index_fails_loud(): void {
		$this->expectException( TranslationException::class );
		MinAlter::translate( 'ALTER TABLE wp_t DROP INDEX `idx`' );
	}

	public function test_change_redefinition_fails_loud(): void {
		$this->expectException( TranslationException::class );
		$this->expectExceptionMessageMatches( '/CHANGE/' );
		MinAlter::translate( "ALTER TABLE wp_wfhoover CHANGE `hostKey` `hostKey` varbinary(255) NULL DEFAULT NULL" );
	}

	public function test_modify_column_fails_loud(): void {
		$this->expectException( TranslationException::class );
		MinAlter::translate( 'ALTER TABLE wp_t MODIFY COLUMN col varchar(50) NOT NULL' );
	}
}
