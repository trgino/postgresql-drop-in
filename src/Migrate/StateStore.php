<?php
/**
 * Copy-phase state seam (real store: State backed by the target PDO;
 * unit tests inject in-memory fakes).
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Migrate;

interface StateStore {

	/**
	 * @return array|null State row or null when unknown.
	 */
	public function get( $table );

	/**
	 * Upsert one table's state.
	 *
	 * @param string $table    Table name.
	 * @param string $status   pending|copy|done|error|skipped.
	 * @param string $lastKey  Encoded watermark ('' = start).
	 * @return bool
	 */
	public function set( $table, $status, $lastKey, $rowsDone, $skipped, $note );
}
