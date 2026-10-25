<?php
declare( strict_types=1 );

namespace Slonik\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Slonik\Cli\HealthCommand;
use Slonik\Health\Result;

final class CliRenderTest extends TestCase {

	private static function sample() {
		return array(
			'H-C01' => Result::ok( 'H-C01', 'pdo_pgsql 8.2' ),
			'H-C06' => Result::block( 'H-C06', 'citext missing', array( 'CREATE EXTENSION citext;' ) ),
			'H-C10' => Result::warn( 'H-C10', 'pool?' ),
		);
	}

	public function test_table_golden(): void {
		$this->assertSame(
			"✔ H-C01 pdo_pgsql 8.2\n✖ H-C06 citext missing   → CREATE EXTENSION citext;\n! H-C10 pool?\nSummary: 1 blocker, 1 warning\n",
			HealthCommand::renderTable( self::sample() )
		);
	}

	public function test_json_stable_keys(): void {
		$decoded = json_decode( HealthCommand::renderJson( self::sample() ), true );
		$this->assertSame( 'block', $decoded[1]['status'] );
		$this->assertSame( array( 'id', 'status', 'message', 'remediation' ), array_keys( $decoded[1] ) );
	}

	public function test_exit_codes(): void {
		$this->assertSame( 2, HealthCommand::runHealth( self::sample(), array() ) );
		$ok = array( 'H-C01' => Result::ok( 'H-C01', 'fine' ) );
		$this->assertSame( 0, HealthCommand::runHealth( $ok, array() ) );
		$warn = array( 'H-C10' => Result::warn( 'H-C10', 'pool?' ) );
		$this->assertSame( 1, HealthCommand::runHealth( $warn, array() ) );
		$this->assertSame( 3, HealthCommand::runHealth( $ok, array( 'format' => 'xml' ) ) );
	}

	public function test_severity_filter(): void {
		$out = HealthCommand::renderTable( self::sample(), 'block' );
		$this->assertStringNotContainsString( 'H-C01', $out );
		$this->assertStringNotContainsString( 'H-C10', $out );
		$this->assertStringContainsString( 'H-C06', $out );
	}

	public function test_status_line(): void {
		$this->assertSame(
			'Slonik 0.0.0-dev | PG 16.5 (db wordpress) | drop-in v0.0.0-dev | errors n/a',
			HealthCommand::renderStatus(
				array(
					'version' => '0.0.0-dev',
					'pg'      => '16.5 (db wordpress)',
					'dropin'  => 'v0.0.0-dev',
					'errors'  => 'n/a',
				)
			)
		);
	}

	public function test_split_host(): void {
		$this->assertSame( array( 'h', 5432 ), HealthCommand::splitHost( 'h' ) );
		$this->assertSame( array( 'h', 5433 ), HealthCommand::splitHost( 'h:5433' ) );
		$this->assertSame( array( '::1', 5432 ), HealthCommand::splitHost( '::1' ) );
	}

	public function test_dropin_label_marks_drift(): void {
		$dir  = sys_get_temp_dir() . '/slonik-label-' . getmypid();
		@mkdir( $dir, 0777, true );
		$body = "<?php // loader\n";
		file_put_contents( $dir . '/db.php', \Slonik\Chain\DropinMarker::build( '0.0.0-dev', $body ) . $body );
		$this->assertSame( 'v0.0.0-dev', HealthCommand::dropinLabel( $dir ) );
		file_put_contents( $dir . '/db.php', \Slonik\Chain\DropinMarker::build( '0.0.0-dev', $body ) . $body . "// x\n" );
		$this->assertSame( 'v0.0.0-dev DRIFT', HealthCommand::dropinLabel( $dir ) );
		$this->assertSame( 'none', HealthCommand::dropinLabel( sys_get_temp_dir() . '/slonik-label-missing' ) );
	}

	public function test_guard_files_include_project_yml(): void {
		$files = HealthCommand::guardFiles( '/wp/wp-content' );
		$this->assertContains( '/wp/wp-cli.yml', $files );
		foreach ( $files as $file ) {
			$this->assertIsString( $file );
		}
	}
}
