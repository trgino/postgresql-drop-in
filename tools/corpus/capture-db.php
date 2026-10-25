<?php
/**
 * Slonik corpus capture drop-in (development tool, NOT shipped with the plugin).
 *
 * Copy to wp-content/db.php on a plain MySQL/MariaDB WordPress install. Every
 * SQL statement that goes through wpdb::query() is appended as one JSON
 * line to a file. The statements are the raw MySQL SQL that WordPress and its
 * plugins really emit; they become the translation/test corpus
 * (real SQL used as both target and regression set).
 *
 * Configuration (environment variables or constants):
 *   SLONIK_CORPUS_FILE    output file (default: WP_CONTENT_DIR/slonik-corpus.jsonl)
 *   SLONIK_CORPUS_SOURCE  source label, e.g. "core@6.8" or "plugin:foo@1.2"
 *
 * Not captured: statements wpdb sends directly through mysqli (SET NAMES,
 * SET SESSION sql_mode). Those are fixed strings handled by the runtime.
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

if ( ! class_exists( 'Slonik_Corpus_DB' ) ) {

	/**
	 * wpdb subclass that records every executed statement.
	 */
	class Slonik_Corpus_DB extends wpdb {

		/**
		 * Free-form label for the statements recorded next ("flow" or section name).
		 *
		 * @var string
		 */
		public $corpus_label = '';

		/**
		 * Per-process sequence number.
		 *
		 * @var int
		 */
		private $corpus_seq = 0;

		/**
		 * Output file path.
		 *
		 * @var string
		 */
		private $corpus_file;

		/**
		 * Source label.
		 *
		 * @var string
		 */
		private $corpus_source;

		/**
		 * Recording re-entrancy guard.
		 *
		 * @var bool
		 */
		private $corpus_busy = false;

		/**
		 * Constructor.
		 *
		 * @param string $dbuser     DB user.
		 * @param string $dbpassword DB password.
		 * @param string $dbname     DB name.
		 * @param string $dbhost     DB host.
		 */
		public function __construct( $dbuser, $dbpassword, $dbname, $dbhost ) {
			$file = getenv( 'SLONIK_CORPUS_FILE' );
			if ( false === $file || '' === $file ) {
				$file = defined( 'SLONIK_CORPUS_FILE' ) ? SLONIK_CORPUS_FILE : WP_CONTENT_DIR . '/slonik-corpus.jsonl';
			}
			$source = getenv( 'SLONIK_CORPUS_SOURCE' );
			if ( false === $source || '' === $source ) {
				$source = defined( 'SLONIK_CORPUS_SOURCE' ) ? SLONIK_CORPUS_SOURCE : 'unknown';
			}
			$this->corpus_file   = (string) $file;
			$this->corpus_source = (string) $source;

			parent::__construct( $dbuser, $dbpassword, $dbname, $dbhost );
		}

		/**
		 * Runs the statement through the parent and records it afterwards.
		 *
		 * wpdb::_do_query() is private, so the hook point is query(). Every
		 * wpdb helper (get_results, insert, update, ...) funnels through it, and
		 * $last_query holds the SQL after the "query" filter ran.
		 *
		 * @param string $query SQL.
		 * @return int|bool
		 */
		public function query( $query ) {
			$start  = microtime( true );
			$result = parent::query( $query );
			$this->corpus_record( '' !== (string) $this->last_query ? (string) $this->last_query : (string) $query, $start );
			return $result;
		}

		/**
		 * Appends one JSONL record.
		 *
		 * @param string $query SQL.
		 * @param float  $start Start timestamp.
		 */
		private function corpus_record( $query, $start ) {
			if ( $this->corpus_busy ) {
				return;
			}
			$this->corpus_busy = true;

			$error = (string) $this->last_error;
			$ok    = '' === $error;

			$caller = '';
			if ( function_exists( 'wp_debug_backtrace_summary' ) ) {
				$caller = (string) wp_debug_backtrace_summary( __CLASS__, 3, true );
			}

			$record = array(
				'v'      => 1,
				'seq'    => ++$this->corpus_seq,
				'pid'    => function_exists( 'getmypid' ) ? getmypid() : 0,
				'ts'     => round( $start, 6 ),
				'ms'     => round( ( microtime( true ) - $start ) * 1000, 3 ),
				'src'    => $this->corpus_source,
				'label'  => $this->corpus_label,
				'ctx'    => 'cli' === PHP_SAPI ? 'cli' : 'web',
				'ok'     => $ok,
				'err'    => $error,
				'caller' => $caller,
				'sql'    => $query,
			);

			$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;
			$json  = json_encode( $record, $flags );
			if ( false !== $json ) {
				// Single write under an exclusive lock: safe for concurrent PHP processes.
				file_put_contents( $this->corpus_file, $json . "\n", FILE_APPEND | LOCK_EX );
			}

			$this->corpus_busy = false;
		}
	}
}

// Standard drop-in contract: provide the global $wpdb.
$GLOBALS['wpdb'] = new Slonik_Corpus_DB( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
