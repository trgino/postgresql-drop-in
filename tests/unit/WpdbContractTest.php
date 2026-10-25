<?php
declare( strict_types=1 );

namespace Slonik\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class WpdbContractTest extends TestCase {

	public static function setUpBeforeClass(): void {
		if ( ! class_exists( 'wpdb' ) ) {
			require_once dirname( __DIR__, 2 ) . '/vendor/php-stubs/wordpress-stubs/wordpress-stubs.php';
		}
	}

	public function test_parent_has_expected_methods(): void {
		foreach ( array( '__construct', 'db_connect', 'query', 'get_results', 'get_row', 'get_var', 'prepare', 'insert', 'update', 'delete', 'replace', 'db_version', 'flush' ) as $m ) {
			$this->assertTrue( method_exists( 'wpdb', $m ), "wpdb::$m missing" );
		}
	}

	public function test_parent_has_contract_properties(): void {
		foreach ( array( 'dbuser', 'dbpassword', 'dbname', 'dbhost', 'last_query', 'last_result', 'num_rows', 'rows_affected', 'insert_id', 'last_error', 'queries', 'num_queries', 'prefix', 'is_mysql', 'charset', 'collate' ) as $p ) {
			$this->assertTrue( property_exists( 'wpdb', $p ), "wpdb::\$$p missing" );
		}
	}
}
