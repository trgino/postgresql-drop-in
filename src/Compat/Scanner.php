<?php
/**
 * Static compat scanner (docs/10 §2 layer 1; K-16 wpdb-API scope).
 *
 * Pipeline: FileSource → Collector → SqlExtractor → RuleTags → MinTranslator
 * → ScanReport. Extraction failure degrades to 'unverified'; parse failure
 * of the target file stays a 'skipped' notice (scan never dies). All SQL
 * templates are redacted (P-19) before entering findings.
 *
 * Simplifications (ledger): PG EXPLAIN/PREPARE validation deferred
 * (schema:check covers live validation); SAPMA ruleset v1 = H-09, R-046.
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Compat;

use Slonik\Audit\Redactor;
use Slonik\Sql\MinTranslator;
use Slonik\Sql\TranslationException;
use Slonik\Sql\Unsupported;

final class Scanner {

	const STATEMENT_KEYWORDS = array(
		'select', 'insert', 'update', 'delete', 'replace', 'show', 'set', 'describe',
		'alter', 'create', 'drop', 'truncate', 'call', 'begin', 'start', 'commit',
		'rollback', 'with', 'load', 'do', 'lock', 'unlock', 'flush', 'rename', 'grant', 'revoke',
	);

	/** @var FileSource */
	private $source;

	/** @var array{type?:string, slug?:string, version?:string} */
	private $target;

	/**
	 * @param FileSource $source Scan input.
	 * @param array      $target Optional meta {type, slug, version} (tree_hash derived).
	 */
	public function __construct( FileSource $source, $target = array() ) {
		$this->source = $source;
		$this->target = $target;
	}

	public function scan() : ScanReport {
		$collector = new Collector( $this->source );
		$files     = $this->source->files();
		$entries   = $collector->collectFiles( $files );
		$by_path   = array();
		foreach ( $entries as $entry ) {
			$by_path[ (string) $entry['path'] ][] = $entry;
		}
		$findings  = array();
		$seen      = array();
		$hash_rows = array();
		foreach ( $files as $file ) {
			$hash_rows[] = (string) $file['path'] . '|' . hash( 'sha256', (string) $file['contents'] );
			foreach ( $by_path[ (string) $file['path'] ] ?? array() as $entry ) {
				$finding = $this->classify( $entry );
				$key     = $finding['file'] . ':' . $finding['line'] . ':' . $finding['status'] . ':' . $finding['sql_template'];
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;
				$findings[]   = $finding;
			}
		}
		sort( $hash_rows );
		$hash_rows[] = 'tree-end';
		$tree_hash   = 'sha256:' . hash( 'sha256', implode( "\n", $hash_rows ) );

		$target = array(
			'type'      => isset( $this->target['type'] ) ? (string) $this->target['type'] : 'path',
			'slug'      => isset( $this->target['slug'] ) ? (string) $this->target['slug'] : '',
			'version'   => isset( $this->target['version'] ) && '' !== (string) $this->target['version']
				? (string) $this->target['version']
				: self::detectVersion( $files ),
			'tree_hash' => $tree_hash,
		);
		return new ScanReport( $findings, $target );
	}

	/**
	 * The plugin version from its main file header.
	 *
	 * @param array<int,array{path:string,contents:string}> $files Scanned files.
	 * @return string Version ('' when the header has none).
	 */
	public static function detectVersion( array $files ): string {
		foreach ( $files as $file ) {
			$head = (string) ( $file['contents'] ?? '' );
			if ( false === strpos( $head, 'Plugin Name:' ) ) {
				continue;
			}
			if ( preg_match( '/^[ \t\/*#@]*Version:[ \t]*(.+)$/mi', $head, $m ) ) {
				return trim( $m[1] );
			}
		}
		return '';
	}

	/**
	 * Classify one collector entry into a finding.
	 *
	 * @param array $entry Collector entry.
	 * @return array{file:string, line:int, status:string, sql_template:string, rules:array<int,string>, message:string, remediation:string}
	 */
	private function classify( $entry ) {
		$path = (string) $entry['path'];
		$line = (int) $entry['line'];
		$kind = (string) $entry['kind'];

		if ( 'skipped' === $kind ) {
			return $this->finding( $path, $line, 'skipped', '', array(),
				'file skipped: unparseable PHP or scanner prerequisite missing',
				'fix syntax or provide nikic/php-parser for static scan' );
		}
		if ( 'info' === $kind ) {
			return $this->finding( $path, $line, 'info', (string) $entry['method'] . '()', array(),
				'direct MySQL client use is out of scope for wpdb translation (K-16 informational)',
				'switch to the wpdb API so calls go through Slonik' );
		}

		$extractor       = new SqlExtractor();
		$method          = (string) $entry['method'];
		$sql             = $extractor->extract( $entry['sqlNode'], $method );
		// P-19: raw literals never leave, in any status. redact_template keeps
		// the ‹DYN› marker and quoted-identifier shapes; rules tag the
		// redacted shape so literal text cannot forge rule hits.
		$redacted = Redactor::redact_template( $sql['template'] );

		if ( in_array( $method, array( 'insert', 'update', 'delete', 'replace' ), true ) && ! $sql['dynamic'] ) {
			return $this->finding( $path, $line, 'compatible', $redacted, array(),
				'wpdb composes this call shape; table set verified statically', '' );
		}
		if ( $sql['dynamic'] ) {
			return $this->finding( $path, $line, 'unverified', $redacted, array(),
				'dynamic SQL cannot be statically verified',
				'build literals or %s placeholders instead of concatenating user input' );
		}
		$rules = RuleTags::fromTemplate( $redacted );
		try {
			MinTranslator::translate( $sql['template'] );
		} catch ( \Throwable $e ) {
			$first = '';
			if ( preg_match( '/^\s*([A-Za-z]+)/', $sql['template'], $m ) ) {
				$first = strtolower( $m[1] );
			}
			if ( ! in_array( $first, Scanner::STATEMENT_KEYWORDS, true ) ) {
				return $this->finding( $path, $line, 'unverified', $redacted, $rules,
					'message: partial SQL fragment cannot be translated on its own',
					'translate the full assembled statement at runtime, or verify via wp slonik schema:check' );
			}
			// A construct without a PostgreSQL equivalent: the report
			// says which one, so the plugin author can act on it.
			$construct = ( $e instanceof TranslationException && $e->isUnsupported() )
				? $e->construct()
				: RuleTags::unsupportedConstruct( $redacted );
			if ( '' !== $construct ) {
				$rules[] = Unsupported::rule( $construct );
				return $this->finding( $path, $line, 'incompatible', $redacted, $rules,
					'no PostgreSQL equivalent: ' . Unsupported::label( $construct ),
					'rewrite the statement to a supported MySQL subset (see the rule table)', $construct );
			}
			return $this->finding( $path, $line, 'incompatible', $redacted, $rules,
				'translation failed: ' . $e->getMessage(),
				'rewrite the statement to a supported MySQL subset (see the rule table)' );
		}
		if ( count( $rules ) > 0 ) {
			return $this->finding( $path, $line, 'warning', $redacted, $rules,
				'translates further but deviates per rule(s)',
				'review statement semantics against the rule(s) before relying on online results' );
		}
		return $this->finding( $path, $line, 'compatible', $redacted, array(),
			'parses and translates', '' );
	}

	/**
	 * @param string $path      File path.
	 * @param int    $line      Line number.
	 * @param string $status    Finding status.
	 * @param string $template  Redacted SQL template.
	 * @param array  $rules     Rule ids.
	 * @param string $message   Why.
	 * @param string $remediation What to do.
	 * @param string $construct The construct without an equivalent ('' when unknown).
	 * @return array{file:string, line:int, status:string, sql_template:string, rules:array<int,string>, message:string, remediation:string, construct:string}
	 */
	private function finding( $path, $line, $status, $template, $rules, $message, $remediation, $construct = '' ) {
		return array(
			'file'         => $path,
			'line'         => $line,
			'status'       => $status,
			'sql_template' => (string) $template,
			'rules'        => (array) $rules,
			'message'      => (string) $message,
			'remediation'  => (string) $remediation,
			'construct'    => (string) $construct,
		);
	}
}
