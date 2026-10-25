<?php
declare( strict_types=1 );

namespace Slonik\Sql;

final class ExecutionPlan {

	const KIND_SELECT = 'select';
	const KIND_INSERT = 'insert';
	const KIND_UPDATE = 'update';
	const KIND_DELETE = 'delete';
	const KIND_DDL    = 'ddl';
	const KIND_SHOW   = 'show';
	const KIND_NOOP   = 'noop';
	const KIND_TXN    = 'txn';

	/** @var string */
	private $kind;

	/** @var PlanStep[] */
	private $steps;

	/** @var array Plan metadata (e.g. insert table/columns for sequence sync). */
	private $meta = array();

	/**
	 * @param string     $kind  One of the KIND_* constants.
	 * @param PlanStep[] $steps Steps in execution order.
	 */
	public function __construct( $kind, array $steps = array() ) {
		$this->kind  = $kind;
		$this->steps = $steps;
	}

	public function getKind() {
		return $this->kind;
	}

	public function getSteps() {
		return $this->steps;
	}

	public function getMeta() {
		return $this->meta;
	}

	public function setMeta( array $meta ) {
		$this->meta = $meta;
	}

	/**
	 * Serialize the plan into a cacheable plain array.
	 *
	 * @return array{kind: string, steps: list<array{0: string, 1: string, 2: ?string, 3: list}>, meta: array}
	 */
	public function toArray(): array {
		$steps = array();
		foreach ( $this->steps as $step ) {
			$steps[] = array(
				$step->getSql(),
				$step->getMode(),
				$step->getMemKey(),
				$step->getParams(),
			);
		}
		return array(
			'kind' => $this->kind,
			'steps' => $steps,
			'meta' => $this->meta,
		);
	}

	/**
	 * Rebuild an ExecutionPlan from a serialized array (e.g. from cache).
	 *
	 * @param array $data Output of toArray().
	 * @return ExecutionPlan
	 */
	public static function fromArray( array $data ): self {
		$steps = array();
		foreach ( (array) ( $data['steps'] ?? array() ) as $step ) {
			$steps[] = new PlanStep(
				(string) ( $step[0] ?? '' ),
				(string) ( $step[1] ?? PlanStep::MODE_QUERY ),
				isset( $step[2] ) ? (string) $step[2] : null,
				is_array( $step[3] ?? null ) ? $step[3] : array()
			);
		}
		$plan = new self( (string) ( $data['kind'] ?? ExecutionPlan::KIND_SELECT ), $steps );
		$plan->setMeta( is_array( $data['meta'] ?? null ) ? $data['meta'] : array() );
		return $plan;
	}
}
