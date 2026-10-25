<?php
declare( strict_types=1 );

namespace Slonik\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Slonik\Cli\InspectCommand;
use Slonik\Schema\SchemaChecker;

final class InspectCommandTest extends TestCase {

	public function test_translate_golden(): void {
		$res = InspectCommand::translate( 'SELECT a FROM t LIMIT 2, 2', array() );
		$this->assertSame( 0, $res['code'] );
		$this->assertSame( 'SELECT "a" FROM "t" LIMIT 2 OFFSET 2', $res['out'] );
	}

	public function test_translate_error_exit_two(): void {
		$res = InspectCommand::translate( 'FROBNICATE THE TABLE', array() );
		$this->assertSame( 2, $res['code'] );
		$this->assertStringContainsString( 'Unparseable', $res['out'] );
	}

	public function test_translate_noop_exits_zero(): void {
		$res = InspectCommand::translate( 'SET NAMES utf8', array() );
		$this->assertSame( 0, $res['code'] );
		$this->assertSame( '', $res['out'] );
	}

	public function test_translate_show_plan(): void {
		$res = InspectCommand::translate( 'SELECT a FROM t LIMIT 2, 2', array( 'show-plan' => true ) );
		$this->assertSame( 0, $res['code'] );
		$this->assertStringContainsString( 'SELECT', $res['out'] );
		$this->assertStringContainsString( 'LIMIT 2 OFFSET 2', $res['out'] );
	}

	private static function checker( $map ) {
		return new SchemaChecker(
			new ArrayProbe( $map ),
			'public',
			array(
				array( 'table' => 'wp_posts', 'columns' => array( 'ID', 'post_title' ), 'identity' => 'ID' ),
			)
		);
	}

	public function test_schema_all_ok(): void {
		$map = array(
			"SELECT count(*) FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'wp_posts'" => '2',
			"SELECT count(*) FROM pg_attribute a JOIN pg_class c ON c.oid = a.attrelid JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public' AND c.relname = 'wp_posts' AND a.attnum > 0 AND NOT a.attisdropped AND col_description(a.attrelid, a.attnum) LIKE '{\"mysql\":%}'" => '2',
			'SELECT COALESCE(MAX("ID"), 0) FROM "wp_posts"' => '5',
			'SELECT pg_get_serial_sequence(\'"wp_posts"\', \'ID\')' => 'wp_posts_ID_seq',
			'SELECT last_value FROM "wp_posts_ID_seq"' => '5',
		);
		$results = self::checker( $map )->check();
		foreach ( $results as $result ) {
			$this->assertSame( 'ok', $result['status'], $result['id'] );
		}
	}

	public function test_schema_sequence_behind_warns_and_repairs(): void {
		$map = array(
			"SELECT count(*) FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'wp_posts'" => '2',
			"SELECT count(*) FROM pg_attribute a JOIN pg_class c ON c.oid = a.attrelid JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public' AND c.relname = 'wp_posts' AND a.attnum > 0 AND NOT a.attisdropped AND col_description(a.attrelid, a.attnum) LIKE '{\"mysql\":%}'" => '2',
			'SELECT COALESCE(MAX("ID"), 0) FROM "wp_posts"' => '9',
			'SELECT pg_get_serial_sequence(\'"wp_posts"\', \'ID\')' => 'wp_posts_ID_seq',
			'SELECT last_value FROM "wp_posts_ID_seq"' => '5',
		);
		$checker = self::checker( $map );
		$results = $checker->check();
		$found   = false;
		foreach ( $results as $result ) {
			if ( 'H-C14' === $result['id'] ) {
				$this->assertSame( 'warn', $result['status'] );
				$found = true;
			}
		}
		$this->assertTrue( $found );
		$repairs = $checker->repairs();
		$this->assertCount( 1, $repairs );
		$this->assertStringStartsWith( 'SELECT setval(', $repairs[0] );
	}

	public function test_repair_refuses_non_setval(): void {
		$checker = new SchemaChecker( new ArrayProbe( array() ), 'public', array() );
		$this->expectException( \RuntimeException::class );
		$checker->repairStatement( 'DROP TABLE wp_posts' );
	}

	public function test_repair_refuses_stacked_setval(): void {
		$stacked = "SELECT setval('s', 9); DROP TABLE wp_posts";
		$checker = new SchemaChecker( new ArrayProbe( array( $stacked => 'x' ) ), 'public', array() );
		$this->expectException( \RuntimeException::class );
		$checker->repairStatement( $stacked );
	}

	public function test_dotted_sequence_name_quoted_once(): void {
		$map = array(
			'SELECT COALESCE(MAX("ID"), 0) FROM "wp_posts"' => '9',
			'SELECT pg_get_serial_sequence(\'"wp_posts"\', \'ID\')' => 'public."weird.seq"',
			'SELECT last_value FROM "public"."weird.seq"' => '5',
		);
		$checker = new SchemaChecker(
			new ArrayProbe( $map ),
			'public',
			array( array( 'table' => 'wp_posts', 'columns' => array( 'ID' ), 'identity' => 'ID' ) )
		);
		$repairs = $checker->repairs();
		$this->assertCount( 1, $repairs );
	}
}
