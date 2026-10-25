<?php
/**
 * Static compat scan report (docs/10 §2.4 stable JSON schema 🟡).
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Compat;

final class ScanReport {

	/** @var array{type:string, slug:string, version:string, tree_hash:string} */
	private $target;

	/** @var array<int, array{file:string, line:int, status:string, sql_template:string, rules:array<int,string>, message:string, remediation:string}> */
	private $findings;

	/** @var array{tool:string, version:string, pg:string, ruleset:string} */
	private $generated_by;

	/**
	 * @param array                                                $findings Findings list.
	 * @param array{type?:string, slug?:string, version?:string, tree_hash?:string} $target Scan target meta.
	 */
	public function __construct( $findings, $target = array() ) {
		$this->findings     = (array) $findings;
		$this->target       = array(
			'type'      => isset( $target['type'] ) ? (string) $target['type'] : 'path',
			'slug'      => isset( $target['slug'] ) ? (string) $target['slug'] : '',
			'version'   => isset( $target['version'] ) ? (string) $target['version'] : '',
			'tree_hash' => isset( $target['tree_hash'] ) ? (string) $target['tree_hash'] : '',
		);
		$this->generated_by = array(
			'tool'    => 'postgresql-drop-in',
			'version' => defined( 'WPSLONIK_VERSION' ) ? (string) WPSLONIK_VERSION : '0.0.0-dev',
			'pg'      => 'n/a',
			'ruleset' => 'v1',
		);
	}

	/**
	 * The scan target meta (type/slug/version/tree_hash).
	 *
	 * @return array{type:string, slug:string, version:string, tree_hash:string}
	 */
	public function target() {
		return $this->target;
	}

	/**
	 * @return array{compatible:int, warning:int, incompatible:int, unverified:int, info:int}
	 */
	public function summary() {		$summary = array(
			'compatible'   => 0,
			'warning'      => 0,
			'incompatible' => 0,
			'unverified'   => 0,
			'info'         => 0,
			'skipped'      => 0,
		);
		foreach ( $this->findings as $f ) {
			if ( isset( $summary[ $f['status'] ] ) ) {
				++$summary[ $f['status'] ];
			}
		}
		return $summary;
	}

	/**
	 * @return array<int, array{file:string, line:int, status:string, sql_template:string, rules:array<int,string>, message:string, remediation:string}>
	 */
	public function findings() {
		return $this->findings;
	}

	/**
	 * Stable JSON per docs/10 §2.4 (key order fixed, pretty-printed).
	 */
	public function toJson() : string {
		return (string) json_encode(
			array(
				'target'       => $this->target,
				'summary'      => $this->summary(),
				'findings'     => $this->findings,
				'generated_by' => $this->generated_by,
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);
	}
}
