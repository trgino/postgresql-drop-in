<?php
declare( strict_types=1 );

namespace Slonik\Tests\Unit;

use Slonik\Health\Probe;

final class ArrayProbe implements Probe {

	/** @var array */
	private $map;

	public function __construct( $map ) {
		$this->map = (array) $map;
	}

	public function queryValue( $sql ) {
		$key = (string) $sql;
		if ( ! array_key_exists( $key, $this->map ) ) {
			throw new \RuntimeException( 'no stub for: ' . $key );
		}
		$value = $this->map[ $key ];
		if ( $value instanceof \Throwable ) {
			throw $value;
		}
		return $value;
	}

	public function execOk( $sql ) {
		$this->queryValue( $sql );
		return true;
	}
}
