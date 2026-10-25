<?php
/**
 * PDO-backed Probe.
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Health;

use PDO;

final class PdoProbe implements Probe {

	/** @var PDO */
	private $pdo;

	public function __construct( PDO $pdo ) {
		$this->pdo = $pdo;
	}

	public function queryValue( $sql ) {
		$stmt = $this->pdo->query( (string) $sql );
		if ( false === $stmt ) {
			throw new \RuntimeException( 'query failed' );
		}
		return $stmt->fetchColumn();
	}

	public function execOk( $sql ) {
		$this->pdo->exec( (string) $sql );
		return true;
	}
}
