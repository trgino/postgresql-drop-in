<?php
declare( strict_types=1 );

namespace Slonik\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Slonik\Schema\MinDdl;
use Slonik\Sql\TranslationException;

final class MinDdlTest extends TestCase {

	/**
	 * DROP TABLE IF EXISTS and TRUNCATE have direct PostgreSQL
	 * equivalents (the plugin uninstall and the cleanup paths use them).
	 */
	public function test_drop_table_if_exists(): void {
		$out = MinDdl::translate( 'DROP TABLE IF EXISTS wp_x' );
		$this->assertSame( array( 'DROP TABLE IF EXISTS "wp_x"' ), $out );

		$out = MinDdl::translate( 'DROP TABLE IF EXISTS `id`' );
		$this->assertSame( array( 'DROP TABLE IF EXISTS "id"' ), $out );
	}

	public function test_truncate_table(): void {
		$out = MinDdl::translate( 'TRUNCATE TABLE wp_x' );
		$this->assertSame( array( 'TRUNCATE TABLE "wp_x"' ), $out );
	}

	/**
	 * D-66: the mapped PostgreSQL type carries its own width, so the
	 * MySQL width is dropped ("bytea(124)" is a syntax error).
	 */
	public function test_the_mysql_width_is_dropped_from_the_mapped_types(): void {
		$out = MinDdl::translate( 'CREATE TABLE t ( a varbinary(124) DEFAULT NULL, b double(17,6) NOT NULL, c binary(16) )' );
		$this->assertStringContainsString( '"a" bytea DEFAULT NULL', $out[0] );
		$this->assertStringContainsString( '"b" double precision NOT NULL', $out[0] );
		$this->assertStringContainsString( '"c" bytea', $out[0] );
	}

	/**
	 * D-68: the MySQL escape "\0" is decoded by the parser, so a binary
	 * default reaches the emitter with its bytes: it has to go out in
	 * PostgreSQL's hex format (the raw bytes would end the literal).
	 */
	public function test_a_binary_default_becomes_the_postgresql_hex_literal(): void {
		$out = MinDdl::translate( "CREATE TABLE t ( a binary(16) NOT NULL DEFAULT '\\0\\0' )" );
		$this->assertStringContainsString( '"a" bytea NOT NULL DEFAULT \'\x0000\'', $out[0] );
	}

	/**
	 * D-68: a NUL byte in a text default cannot be stored by PostgreSQL;
	 * the parser would die with "unterminated quoted string".
	 */
	public function test_a_nul_byte_in_a_text_default_fails_closed(): void {
		$this->expectException( TranslationException::class );
		MinDdl::translate( "CREATE TABLE t ( a text NOT NULL DEFAULT '\\0' )" );
	}

	/**
	 * The type mapping rule (docs/05 R-064): a MySQL type with a
	 * PostgreSQL equivalent keeps it, otherwise the encompassing upper
	 * type is used - the text family becomes text, the binary family
	 * bytea, the integers the next wider integer.
	 *
	 * The table locks the mapping so it cannot drift silently.
	 */
	public function test_the_type_mapping_follows_the_upper_type_rule(): void {
		$map = array(
			// Integers: the next wider type, so every MySQL value fits.
			'tinyint'    => 'smallint',
			'smallint'   => 'smallint',
			'mediumint'  => 'integer',
			'int'        => 'integer',
			'integer'    => 'integer',
			'bigint'     => 'bigint',
			'year'       => 'smallint',
			// Text: PostgreSQL has no length-limited text above varchar,
			// so the whole family becomes text.
			'tinytext'   => 'text',
			'text'       => 'text',
			'mediumtext' => 'text',
			'longtext'   => 'text',
			// Binary: bytea holds every byte sequence.
			'tinyblob'   => 'bytea',
			'blob'       => 'bytea',
			'mediumblob' => 'bytea',
			'longblob'   => 'bytea',
			'varbinary(16)' => 'bytea',
			'binary'     => 'bytea',
			// The direct equivalents.
			'datetime'   => 'timestamp(0) without time zone',
			'timestamp'  => 'timestamp(0) without time zone',
			'date'       => 'date',
			'time'       => 'time',
			'json'       => 'jsonb',
			'float'      => 'real',
			'double'     => 'double precision',
			'bit'        => 'bit',
			'varchar(191)' => 'varchar(191)',
			'char(10)'     => 'char(10)',
			'decimal(10,2)' => 'decimal(10,2)',
		);
		foreach ( $map as $mysql => $pg ) {
			$out = MinDdl::translate( 'CREATE TABLE t ( c ' . $mysql . ' )' );
			$this->assertStringContainsString( '"c" ' . $pg, $out[0], $mysql . ' -> ' . $pg );
		}
	}

	/**
	 * enum and set have no PostgreSQL equivalent: text plus a CHECK
	 * keeps the value list (the upper type, the constraint separate).
	 */
	public function test_enum_and_set_use_the_upper_type_with_a_check(): void {
		$out  = MinDdl::translate( "CREATE TABLE t ( c enum('a','b') NOT NULL )" );
		$enum = implode( "\n", $out );
		$this->assertStringContainsString( '"c" text NOT NULL', $enum );
		$this->assertStringContainsString( "CHECK (\"c\" IN ('a', 'b'))", $enum );

		$out = MinDdl::translate( "CREATE TABLE t ( c set('a','b') NOT NULL )" );
		$this->assertStringContainsString( '"c" text NOT NULL', implode( "\n", $out ) );
	}

	//
	// D-02: the case-sensitivity mode decides the column types.
	//

	public function test_citext_mode_creates_text_columns_as_citext(): void {
		$out = MinDdl::translate(
			'CREATE TABLE `wp_x` (`name` varchar(191) NOT NULL, `body` text NOT NULL)',
			array( 'ci_mode' => \Slonik\Schema\CiMode::CITEXT )
		);
		$this->assertStringContainsString( '"name" citext NOT NULL', $out[0] );
		$this->assertStringContainsString( '"body" text NOT NULL', $out[0] );
	}

	public function test_degraded_mode_keeps_plain_text_columns(): void {
		$out = MinDdl::translate(
			'CREATE TABLE `wp_x` (`name` varchar(191) NOT NULL)',
			array( 'ci_mode' => \Slonik\Schema\CiMode::DEGRADED )
		);
		$this->assertStringContainsString( '"name" varchar(191) NOT NULL', $out[0] );
	}

	public function test_default_mode_keeps_plain_text_columns(): void {
		$out = MinDdl::translate( 'CREATE TABLE `wp_x` (`name` varchar(191) NOT NULL)' );
		$this->assertStringContainsString( '"name" varchar(191) NOT NULL', $out[0] );
		$this->assertStringNotContainsString( 'citext', $out[0] );
	}

	//
	// D-33/D-47/D-48/D-50: DDL details WordPress core depends on.
	//

	public function test_on_update_becomes_a_trigger(): void {
		$out = MinDdl::translate( 'CREATE TABLE `wp_posts` (`id` int NOT NULL, `post_modified` datetime NOT NULL DEFAULT \'0000-00-00 00:00:00\' ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (`id`))' );
		$joined = implode( "\n", $out );
		$this->assertStringContainsString( 'CREATE OR REPLACE FUNCTION', $joined );
		$this->assertStringContainsString( 'CREATE OR REPLACE TRIGGER', $joined );
		$this->assertStringContainsString( 'BEFORE UPDATE', $joined );
		$this->assertStringNotContainsString( 'ON UPDATE', $joined );
		// The trigger and function names must carry the full column name.
		$this->assertStringContainsString( 'wpslonik_touch_wp_posts_post_modified', $joined );
		$this->assertStringContainsString( 'wp_posts__post_modified_update', $joined );
	}

	public function test_on_update_trigger_can_run_twice(): void {
		$out = MinDdl::translate( 'CREATE TABLE `wp_touch` (`id` int NOT NULL, `touched` datetime NOT NULL DEFAULT \'0000-00-00 00:00:00\' ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (`id`))' );
		$joined = implode( "\n", $out );
		// The re-run of the same DDL must not fail: PostgreSQL replaces the
		// trigger (MySQL's CREATE TABLE IF NOT EXISTS is a no-op).
		$this->assertStringContainsString( 'CREATE OR REPLACE TRIGGER "wp_touch__touched_update"', $joined );
		$this->assertStringNotContainsString( "\nCREATE TRIGGER ", $joined );
	}

	public function test_enum_keeps_its_value_list(): void {
			$out = MinDdl::translate( 'CREATE TABLE `wp_x` (`status` enum(\'draft\',\'publish\') NOT NULL DEFAULT \'draft\')' );
		$joined = implode( "\n", $out );
		$this->assertStringContainsString( 'CHECK ("status" IN (\'draft\', \'publish\'))', $joined );
	}

	public function test_zerofill_is_dropped(): void {
		$out = MinDdl::translate( 'CREATE TABLE `wp_y` (`n` int(10) unsigned zerofill NOT NULL DEFAULT 0)' );
		$this->assertStringNotContainsString( 'zerofill', $out[0] );
	}

	public function test_long_index_names_are_hashed(): void {
		$long = str_repeat( 'a', 70 );
		$out  = MinDdl::translate( 'CREATE TABLE `wp_z` (`id` int NOT NULL, `col` int NOT NULL, KEY `' . $long . '` (`col`))' );
		$name = '';
		if ( preg_match( '/CREATE INDEX (?:IF NOT EXISTS )?"([^"]+)"/', $out[1], $m ) ) {
			$name = $m[1];
		}
		$this->assertNotSame( '', $name );
		$this->assertLessThanOrEqual( 63, strlen( $name ) );
		$this->assertStringContainsString( 'wp_z__', $name );
	}

	/**
	 * D-71: the runtime DDL has to be idempotent: the plugin creates the
	 * table again after the migration already created it, and a second
	 * CREATE INDEX would fail ("relation already exists").
	 */
	public function test_the_index_creation_is_idempotent(): void {
		$out = MinDdl::translate( 'CREATE TABLE IF NOT EXISTS `wp_x` (`id` int NOT NULL, `col` int NOT NULL, KEY `col` (`col`))' );
		$this->assertStringContainsString( 'CREATE INDEX IF NOT EXISTS "wp_x__col"', $out[1] );
	}

	public function test_unique_key_keeps_its_name(): void {
		$out = MinDdl::translate( 'CREATE TABLE `wp_terms` (`term_id` int NOT NULL, `slug` varchar(200) NOT NULL, UNIQUE KEY `slug` (`slug`), PRIMARY KEY (`term_id`))' );
		$this->assertStringContainsString( 'CONSTRAINT "slug" UNIQUE ("slug")', $out[0] );
	}

	public function test_unsigned_int_widens_to_bigint(): void {
		$out = MinDdl::translate( 'CREATE TABLE `wp_x` (`id` int(10) unsigned NOT NULL, PRIMARY KEY (`id`))' );
		$this->assertStringContainsString( '"id" bigint NOT NULL', $out[0] );
	}

	public function test_unsigned_bigint_gets_a_range_check(): void {
		$out = MinDdl::translate( 'CREATE TABLE `wp_y` (`n` bigint(20) unsigned NOT NULL, PRIMARY KEY (`n`))' );
		$this->assertStringContainsString( 'CHECK ("n" >= 0)', $out[0] );
	}

	public function test_signed_int_stays_integer(): void {
		$out = MinDdl::translate( 'CREATE TABLE `wp_z` (`id` int(11) NOT NULL, PRIMARY KEY (`id`))' );
		$this->assertStringContainsString( '"id" integer NOT NULL', $out[0] );
		$this->assertStringNotContainsString( 'CHECK', $out[0] );
	}

	public function test_if_not_exists_is_preserved(): void {
		$out = MinDdl::translate( 'CREATE TABLE IF NOT EXISTS `wp_demo` (`id` int NOT NULL, PRIMARY KEY (`id`))' );
		$this->assertStringContainsString( 'CREATE TABLE IF NOT EXISTS "wp_demo"', $out[0] );
	}

	public function test_temporary_is_preserved(): void {
		$out = MinDdl::translate( 'CREATE TEMPORARY TABLE `wp_tmp` (`id` int NOT NULL, PRIMARY KEY (`id`))' );
		$this->assertStringContainsString( 'CREATE TEMPORARY TABLE "wp_tmp"', $out[0] );
	}

	public function test_create_table_like_is_rejected(): void {
		$this->expectException( TranslationException::class );
		MinDdl::translate( 'CREATE TABLE `wp_copy` LIKE `wp_posts`' );
	}

	public function test_create_table_as_select_is_rejected(): void {
		$this->expectException( TranslationException::class );
		MinDdl::translate( 'CREATE TABLE `wp_copy` AS SELECT * FROM `wp_posts`' );
	}

	public function test_basic_table(): void {
		$this->assertSame(
			array(
				'CREATE TABLE "wp_test" ("ID" bigint NOT NULL GENERATED BY DEFAULT AS IDENTITY CHECK ("ID" >= 0), "name" varchar(200) NOT NULL default \'\', PRIMARY KEY ("ID"))',
				'CREATE INDEX IF NOT EXISTS "wp_test__name" ON "wp_test" ("name")',
				'COMMENT ON COLUMN "wp_test"."ID" IS \'{"mysql":"bigint(20) unsigned"}\'',
				'COMMENT ON COLUMN "wp_test"."name" IS \'{"mysql":"varchar(200)"}\'',
			),
			MinDdl::translate( 'CREATE TABLE wp_test (ID bigint(20) unsigned NOT NULL auto_increment, name varchar(200) NOT NULL default \'\', PRIMARY KEY (ID), KEY name (name(191)))' )
		);
	}

	public function test_zero_date_default_becomes_sentinel(): void {
		$statements = MinDdl::translate( 'CREATE TABLE t (d datetime NOT NULL default \'0000-00-00 00:00:00\')' );
		$this->assertSame( 'CREATE TABLE "t" ("d" timestamp(0) without time zone NOT NULL default \'0001-01-01 00:00:00\')', $statements[0] );
	}

	public function test_fractional_zero_date_default_becomes_sentinel(): void {
		$statements = MinDdl::translate( 'CREATE TABLE t (d datetime NOT NULL default \'0000-00-00 00:00:00.000\')' );
		$this->assertSame( 'CREATE TABLE "t" ("d" timestamp(0) without time zone NOT NULL default \'0001-01-01 00:00:00.000\')', $statements[0] );
	}

	public function test_fulltext_throws(): void {
		$this->expectException( TranslationException::class );
		MinDdl::translate( 'CREATE TABLE t (a text, FULLTEXT KEY ft (a))' );
	}

	/**
	 * D-74: ActionScheduler keeps a serialized schedule object in a
	 * longtext column. PHP's serialize() writes NUL bytes into the
	 * private/protected property names ("\0*\0prop"), and PostgreSQL's
	 * text type cannot store a NUL byte, so the column is created as
	 * bytea instead.
	 */
	public function test_the_action_scheduler_schedule_column_is_created_as_bytea(): void {
		$out = MinDdl::translate( 'CREATE TABLE wp_actionscheduler_actions ( action_id bigint(20) unsigned NOT NULL auto_increment, schedule longtext, PRIMARY KEY (action_id) )' );
		$this->assertStringContainsString( '"schedule" bytea', $out[0] );
	}

	/**
	 * The override is prefix independent: the table name ends with the
	 * ActionScheduler table, whatever the WordPress prefix is.
	 */
	public function test_the_action_scheduler_override_is_prefix_independent(): void {
		$out = MinDdl::translate( 'CREATE TABLE shop_actionscheduler_actions ( schedule longtext )' );
		$this->assertStringContainsString( '"schedule" bytea', $out[0] );
	}

	/**
	 * The catalog keeps the original MySQL type, so dbDelta still sees
	 * the column as longtext and does not try to ALTER it back.
	 */
	public function test_the_original_mysql_type_is_kept_for_the_catalog(): void {
		$out = MinDdl::translate( 'CREATE TABLE wp_actionscheduler_actions ( schedule longtext )' );
		$comment = '';
		foreach ( $out as $statement ) {
			if ( 0 === strpos( $statement, 'COMMENT ON COLUMN' ) ) {
				$comment = $statement;
			}
		}
		$this->assertStringContainsString( '"mysql":"longtext"', $comment );
	}

	/**
	 * Another table keeps the generic mapping: the override is a
	 * column-level rule, not a blanket text-to-bytea rule.
	 */
	public function test_other_tables_keep_the_generic_text_mapping(): void {
		$out = MinDdl::translate( 'CREATE TABLE wp_options ( schedule longtext )' );
		$this->assertStringContainsString( '"schedule" text', $out[0] );
	}

	public function test_constraint_prefix_throws(): void {
		foreach ( array(
			'CREATE TABLE t (a integer NOT NULL, CONSTRAINT c PRIMARY KEY (a))',
			'CREATE TABLE t (a integer NOT NULL, CONSTRAINT c UNIQUE (a))',
			'CREATE TABLE t (a integer NOT NULL, CONSTRAINT c UNIQUE KEY u (a))',
			'CREATE TABLE t (a integer NOT NULL, CHECK (a > 0))',
		) as $sql ) {
			try {
				MinDdl::translate( $sql );
			} catch ( TranslationException $e ) {
				continue;
			}
			$this->fail( "No throw for: $sql" );
		}
		$this->assertTrue( true );
	}
}
