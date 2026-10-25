<?php
declare( strict_types=1 );

namespace Slonik\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class WpslonikDbTest extends TestCase {

	public static function setUpBeforeClass(): void {
		if ( ! class_exists( 'wpdb', false ) ) {
			require_once dirname( __DIR__ ) . '/fixtures/wpinc/class-wpdb.php';
		}
		require_once dirname( __DIR__, 2 ) . '/src/Runtime/WpslonikDb.php';
	}

	public function test_db_version_needs_no_connection(): void {
		$db = new \WPSLONIK_DB( 'u', 'p', 'n', 'h' );
		$this->assertSame( '8.4.0', $db->db_version() );
		$this->assertFalse( $db->is_mysql );
	}

	public function test_found_rows_probe_matcher(): void {
		$this->assertTrue( \Slonik\Runtime\WpslonikDb::is_found_rows_probe( 'SELECT FOUND_ROWS()' ) );
		$this->assertTrue( \Slonik\Runtime\WpslonikDb::is_found_rows_probe( 'select found_rows( )' ) );
		$this->assertTrue( \Slonik\Runtime\WpslonikDb::is_found_rows_probe( 'SELECT FOUND_ROWS();' ) );
		$this->assertFalse( \Slonik\Runtime\WpslonikDb::is_found_rows_probe( 'SELECT * FROM t' ) );
		$this->assertFalse( \Slonik\Runtime\WpslonikDb::is_found_rows_probe( 'SELECT FOUND_ROWS(), 1' ) );
	}

	public function test_ddl_matcher_mirrors_upstream_wpdb(): void {
		$ddl = array(
			'CREATE TABLE IF NOT EXISTS `wfls_2fa_secrets` (`id` int(11) unsigned NOT NULL AUTO_INCREMENT)',
			"CREATE TABLE wp_wfblocks7 (\n  `id` bigint(20) unsigned NOT NULL,\n  PRIMARY KEY  (`id`)\n) ENGINE=InnoDB",
			'drop table if exists `wp_wftrafficstats`',
			'TRUNCATE TABLE wp_options',
			'alter table wp_options add key idx_name (option_name)',
			"\n\tDROP TABLE `wp_x`",
			'  DROP   TABLE   `wp_x`',
		);
		foreach ( $ddl as $q ) {
			$this->assertTrue( \Slonik\Runtime\WpslonikDb::is_ddl_query( $q ), $q );
		}
		$other = array(
			'SELECT * FROM wp_wfblocks7',
			'INSERT INTO wp_options (a) VALUES (1)',
			'show tables like "wp_%"',
			'CREATEE TABLE t',
			'SELECT created FROM t',
			'dropping',
		);
		foreach ( $other as $q ) {
			$this->assertFalse( \Slonik\Runtime\WpslonikDb::is_ddl_query( $q ), $q );
		}
	}

	public function test_found_rows_uses_pre_flush_count_without_pg(): void {
		$db = new \WPSLONIK_DB( 'u', 'p', 'n', 'h' );
		$db->num_rows = 7;
		$this->assertSame( 1, $db->query( 'SELECT FOUND_ROWS()' ) );
		$this->assertSame( '7', $db->last_result[0]->{'FOUND_ROWS()'} );
	}

	public function test_mem_scalar_reads_first_column(): void {
		$row        = new \stdClass();
		$row->count = '109';
		$this->assertSame( 109, \Slonik\Runtime\WpslonikDb::mem_scalar( array( $row ) ) );
		$this->assertSame( 0, \Slonik\Runtime\WpslonikDb::mem_scalar( array() ) );
	}

	public function test_untranslatable_returns_false_without_pg(): void {
		$db = new \WPSLONIK_DB( 'u', 'p', 'n', 'h' );
		$this->assertFalse( $db->query( 'FROBNICATE THE TABLE' ) );
		$this->assertSame( 'Unparseable statement.', $db->last_error );
	}

	public function test_slow_query_logged_redacted(): void {
		$file = sys_get_temp_dir() . '/slonik-slow-' . getmypid() . '.jsonl';
		@unlink( $file );
		$db = new \Slonik\Runtime\WpslonikDb( 'u', 'p', 'n', '127.0.0.1:1', $file, 0 );
		$this->assertFalse( $db->query( 'SELECT * FROM t WHERE x = 1' ) );
		$entries = ( new \Slonik\Audit\CompatLog( $file ) )->read( 10 );
		$kinds   = array();
		foreach ( $entries as $e ) {
			$kinds[ $e['kind'] ] = true;
		}
		$this->assertTrue( isset( $kinds['slow'] ), 'slow kind missing: ' . json_encode( $entries ) );
		foreach ( $entries as $e ) {
			$this->assertStringNotContainsString( 'hunter2', $e['sql'] );
		}
		@unlink( $file );
	}

	public function test_failure_is_logged_redacted(): void {
		$file = sys_get_temp_dir() . '/slonik-compat-hook-' . getmypid() . '.jsonl';
		@unlink( $file );
		$db = new \Slonik\Runtime\WpslonikDb( 'u', 'p', 'n', '127.0.0.1:1', $file );
		$this->assertFalse( $db->query( "SELECT * FROM t WHERE secret = 'hunter2'" ) );
		$log     = new \Slonik\Audit\CompatLog( $file );
		$entries = $log->read( 10 );
		$this->assertCount( 1, $entries );
		$this->assertSame( 'error', $entries[0]['kind'] );
		$this->assertStringNotContainsString( 'hunter2', $entries[0]['sql'] );
		@unlink( $file );
	}

	//
	// D-34/D-35/D-40: the wpdb contract on PostgreSQL.
	//

	public function test_real_escape_is_postgres_safe(): void {
		$db = new \WPSLONIK_DB( 'u', 'p', 'n', 'h' );
		// Only the quote is doubled: with standard_conforming_strings=on a
		// backslash is a literal character, MySQL escaping would double it.
		$this->assertSame( "O''Brien", $db->_real_escape( "O'Brien" ) );
		$this->assertSame( 'back\\slash', $db->_real_escape( 'back\\slash' ) );
		$this->assertSame( '', $db->_real_escape( array() ) );
	}

	public function test_placeholder_escape_is_a_noop_for_postgres(): void {
		$db = new \WPSLONIK_DB( 'u', 'p', 'n', 'h' );
		$this->assertSame( '50% off', $db->add_placeholder_escape( '50% off' ) );
		$this->assertSame( '50% off', $db->remove_placeholder_escape( '50% off' ) );
	}

	public function test_charset_and_collate_are_filled_in(): void {
		$db = new \WPSLONIK_DB( 'u', 'p', 'n', 'h' );
		$this->assertSame( 'utf8mb4', $db->charset );
		$this->assertSame( 'utf8mb4_unicode_ci', $db->collate );
		$this->assertSame( 'u', $db->dbuser );
		$this->assertSame( 'n', $db->dbname );
	}

	public function test_col_info_row_maps_pdo_metadata(): void {
		$row = \Slonik\Runtime\WpslonikDb::col_info_row(
			array(
				'name'        => 'ID',
				'native_type' => 'int4',
				'len'         => 4,
				'table'       => 'wp_posts',
			)
		);
		$this->assertSame( 'ID', $row['name'] );
		$this->assertSame( 'int4', $row['type'] );
		$this->assertSame( 4, $row['max_length'] );
		$this->assertSame( 'wp_posts', $row['table'] );
		$this->assertArrayHasKey( 'not_null', $row );
		$this->assertArrayHasKey( 'primary_key', $row );
	}

	public function test_load_col_info_is_overridden(): void {
		$this->assertTrue( method_exists( '\WPSLONIK_DB', 'load_col_info' ) );
	}

	/**
	 * D-41: the runtime must put the configured schema on the search
	 * path, otherwise the site reads and writes 'public' while the
	 * migration filled the configured schema.
	 */
	public function test_search_path_sql_quotes_the_schema(): void {
		$this->assertSame( 'SET search_path TO "wp", public', \Slonik\Runtime\WpslonikDb::search_path_sql( 'wp' ) );
		$this->assertSame( 'SET search_path TO "public", public', \Slonik\Runtime\WpslonikDb::search_path_sql( 'public' ) );
		$this->assertSame( 'SET search_path TO "we""ird", public', \Slonik\Runtime\WpslonikDb::search_path_sql( 'we"ird' ) );
	}

	/**
	 * D-05: RETURNING is only used when the identity column is generated
	 * by the database (not written by the statement itself).
	 */
	public function test_returning_clause_only_for_generated_identities(): void {
		$this->assertSame( '"ID"', \Slonik\Runtime\WpslonikDb::returning_clause( 'ID', array( 'post_title' ) ) );
		$this->assertSame( '', \Slonik\Runtime\WpslonikDb::returning_clause( 'ID', array( 'ID', 'post_title' ) ) );
		$this->assertSame( '', \Slonik\Runtime\WpslonikDb::returning_clause( null, array( 'post_title' ) ) );
		$this->assertSame( '', \Slonik\Runtime\WpslonikDb::returning_clause( '', array() ) );
	}
}
