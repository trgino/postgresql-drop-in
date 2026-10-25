<?php
/**
 * In-memory file source for unit tests.
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Compat;

final class ArraySource implements FileSource {

	/** @var array<string,string> */
	private $map;

	/**
	 * @param array<string,string> $map path => contents.
	 */
	public function __construct( $map ) {
		$this->map = (array) $map;
	}

	public function files() : array {
		$out = array();
		foreach ( $this->map as $path => $contents ) {
			$out[] = array(
				'path'     => (string) $path,
				'contents' => (string) $contents,
			);
		}
		return $out;
	}
}
