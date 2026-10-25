<?php
declare( strict_types=1 );

namespace Slonik\Tests\Unit\Sql;

use PHPUnit\Framework\TestCase;
use Slonik\Sql\ExecutionPlan;
use Slonik\Sql\PlanStep;

final class ExecutionPlanTest extends TestCase {

	public function test_to_array_serializes_steps_and_meta(): void {
		$plan = new ExecutionPlan(
			ExecutionPlan::KIND_SELECT,
			array(
				new PlanStep( "SELECT 1", PlanStep::MODE_QUERY ),
				new PlanStep( "SELECT COUNT(*) FROM t", PlanStep::MODE_QUERY, 'found_rows' ),
			)
		);
		$plan->setMeta( array( 'insert' => array( 'table' => 'wp_posts' ) ) );
		$arr = $plan->toArray();
		$this->assertSame( 'select', $arr['kind'] );
		$this->assertSame( array(
			array( "SELECT 1", 'query', null, array() ),
			array( "SELECT COUNT(*) FROM t", 'query', 'found_rows', array() ),
		), $arr['steps'] );
		$this->assertSame( array( 'insert' => array( 'table' => 'wp_posts' ) ), $arr['meta'] );
	}

	public function test_from_array_rebuilds_an_equivalent_plan(): void {
		$arr = array(
			'kind' => ExecutionPlan::KIND_INSERT,
			'steps' => array(
				array( "INSERT INTO t (a) VALUES (1)", 'exec', null ),
				array( "SELECT lastval()", 'query', 'found_rows' ),
			),
			'meta' => array( 'insert' => array( 'table' => 't', 'columns' => array( 'a' ) ) ),
		);
		$plan = ExecutionPlan::fromArray( $arr );
		$this->assertSame( ExecutionPlan::KIND_INSERT, $plan->getKind() );
		$steps = $plan->getSteps();
		$this->assertCount( 2, $steps );
		$this->assertSame( "INSERT INTO t (a) VALUES (1)", $steps[0]->getSql() );
		$this->assertSame( 'exec', $steps[0]->getMode() );
		$this->assertNull( $steps[0]->getMemKey() );
		$this->assertSame( "SELECT lastval()", $steps[1]->getSql() );
		$this->assertSame( 'query', $steps[1]->getMode() );
		$this->assertSame( 'found_rows', $steps[1]->getMemKey() );
		$this->assertSame( array( 'insert' => array( 'table' => 't', 'columns' => array( 'a' ) ) ), $plan->getMeta() );
	}

	public function test_from_array_is_tolerant_of_missing_keys(): void {
		$plan = ExecutionPlan::fromArray( array( 'kind' => 'delete' ) );
		$this->assertSame( ExecutionPlan::KIND_DELETE, $plan->getKind() );
		$this->assertSame( array(), $plan->getSteps() );
		$this->assertSame( array(), $plan->getMeta() );
	}

	public function test_roundtrip_preserves_the_plan(): void {
		$original = new ExecutionPlan(
			ExecutionPlan::KIND_UPDATE,
			array( new PlanStep( "UPDATE t SET a=1", 'exec' ) )
		);
		$rebuilt = ExecutionPlan::fromArray( $original->toArray() );
		$this->assertSame( $original->toArray(), $rebuilt->toArray() );
	}
}
