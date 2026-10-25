<?php
declare( strict_types=1 );

namespace Slonik\Sql;

final class PlanStep {

	const MODE_QUERY = 'query';
	const MODE_EXEC  = 'exec';

	/** @var string */
	private $sql;

	/** @var string */
	private $mode;

	/** @var string|null */
	private $mem_key;

	/** @var array Parameter values bound to $1, $2 … placeholders in $sql. */
	private $params = array();

	/**
	 * @param string      $sql     PG SQL.
	 * @param string      $mode    MODE_QUERY (fetch rows) or MODE_EXEC.
	 * @param string|null $mem_key When set on a query step, row count is stored under this memory key instead of last_result/num_rows.
	 * @param array       $params  Parameter values for $1, $2 … placeholders (in placeholder order).
	 */
	public function __construct( $sql, $mode = self::MODE_QUERY, $mem_key = null, array $params = array() ) {
		$this->sql     = $sql;
		$this->mode    = $mode;
		$this->mem_key = $mem_key;
		$this->params  = $params;
	}

	public function getSql() {
		return $this->sql;
	}

	public function getMode() {
		return $this->mode;
	}

	public function getMemKey() {
		return $this->mem_key;
	}

	/**
	 * @return array Parameter values (index 0 = $1).
	 */
	public function getParams() {
		return $this->params;
	}

	public function isQuery() {
		return self::MODE_QUERY === $this->mode;
	}
}
