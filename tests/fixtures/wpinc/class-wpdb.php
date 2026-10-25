<?php
/**
 * Minimal wpdb stand-in for loader tests.
 *
 * Mirrors the WP 6.4 contract surface asserted in WpdbContractTest (13 methods,
 * 12 properties) so both files pass regardless of class-declaration order in
 * the suite process. If the WP surface changes, update both files together.
 * Bodies are inert; LoaderTest never executes queries.
 */

class wpdb {

	public $dbuser;
	public $dbpassword;
	public $dbname;
	public $dbhost;
	public $last_query;
	public $last_result;
	public $num_rows = 0;
	public $rows_affected = 0;
	public $insert_id = 0;
	public $last_error = '';
	public $queries = array();
	public $num_queries = 0;
	public $prefix = 'wp_';
	public $is_mysql = true;
	public $charset = 'utf8mb4';
	public $collate = '';

	public function __construct() {
	}

	public function db_connect() {
		return true;
	}

	public function query( $query ) {
		return 'parent:' . $query;
	}

	public function get_results() {
		return null;
	}

	public function get_row() {
		return null;
	}

	public function get_var() {
		return null;
	}

	public function prepare() {
		return null;
	}

	public function insert() {
		return false;
	}

	public function update() {
		return false;
	}

	public function delete() {
		return false;
	}

	public function replace() {
		return false;
	}

	public function db_version() {
		return '';
	}

	public function flush() {
		$this->last_result   = array();
		$this->last_query    = null;
		$this->rows_affected = 0;
		$this->num_rows      = 0;
		$this->last_error    = '';
	}
}
