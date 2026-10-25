<?php
/**
 * Health check result value.
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Health;

final class Result {

	const OK    = 'ok';
	const WARN  = 'warn';
	const BLOCK = 'block';

	/** @var string */
	public $id;

	/** @var string */
	public $status;

	/** @var string */
	public $message;

	/** @var string[] */
	public $remediation;

	public function __construct( $id, $status, $message, $remediation = array() ) {
		$this->id          = (string) $id;
		$this->status      = (string) $status;
		$this->message     = (string) $message;
		$this->remediation = array_values( (array) $remediation );
	}

	public static function ok( $id, $message ) {
		return new self( $id, self::OK, $message );
	}

	public static function warn( $id, $message, $remediation = array() ) {
		return new self( $id, self::WARN, $message, $remediation );
	}

	public static function block( $id, $message, $remediation = array() ) {
		return new self( $id, self::BLOCK, $message, $remediation );
	}
}
