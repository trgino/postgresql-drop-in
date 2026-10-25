<?php
declare( strict_types=1 );

namespace Slonik\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Slonik\Chain\DropinMarker;
use Slonik\Health\Checks\ConstantsCheck;
use Slonik\Health\Checks\DbCheck;
use Slonik\Health\Checks\DropinCheck;
use Slonik\Health\Checks\PhpCheck;
use Slonik\Health\Env;
use Slonik\Health\Probe;
use Slonik\Health\Runner;
use Slonik\Schema\SpellingAudit;

final class HealthTest extends TestCase {

	public function test_rollup_worst_status(): void {
		$env     = Env::fromValues(
			array(
				'php_version' => '8.2.0',
				'extensions'  => array(),
				'wp_version'  => '7.1.3',
				'defines'     => array(),
				'content_dir' => sys_get_temp_dir(),
			)
		);
		$results = Runner::run( array( new PhpCheck(), new ConstantsCheck() ), $env );
		$this->assertArrayHasKey( 'H-C01', $results );
		$this->assertSame( 'block', $results['H-C01']->status );
		$this->assertSame( 'block', Runner::worst( $results ) );
		$this->assertSame( 2, Runner::exitCode( $results ) );
	}

	public function test_rollup_all_ok(): void {
		$env     = Env::fromValues(
			array(
				'php_version' => '8.2.0',
				'extensions'  => array( 'pdo_pgsql' ),
				'wp_version'  => '7.1.3',
				'defines'     => array(),
				'content_dir' => sys_get_temp_dir(),
			)
		);
		$results = Runner::run( array( new PhpCheck(), new ConstantsCheck() ), $env );
		$this->assertSame( 'ok', Runner::worst( $results ) );
		$this->assertSame( 0, Runner::exitCode( $results ) );
	}

	private static function envWithDir( $dir, $defines = array() ) {
		return Env::fromValues(
			array(
				'php_version' => '8.2.0',
				'extensions'  => array( 'pdo_pgsql' ),
				'wp_version'  => '7.1.3',
				'defines'     => $defines,
				'content_dir' => $dir,
			)
		);
	}

	private static function fixtureDir() {
		$dir = sys_get_temp_dir() . '/slonik-health-' . getmypid();
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
		}
		return $dir;
	}

	public function test_dropin_missing_is_ok(): void {
		$dir = self::fixtureDir() . '-missing';
		@mkdir( $dir, 0777, true );
		$results = Runner::run( array( new DropinCheck( '0.0.0-dev' ) ), self::envWithDir( $dir ) );
		$this->assertSame( 'ok', $results['H-C11']->status );
	}

	public function test_dropin_marker_ok(): void {
		$dir  = self::fixtureDir() . '-ok';
		@mkdir( $dir, 0777, true );
		$body = "<?php // loader\n";
		file_put_contents( $dir . '/db.php', DropinMarker::build( '0.0.0-dev', $body ) . $body );
		$results = Runner::run( array( new DropinCheck( '0.0.0-dev' ) ), self::envWithDir( $dir ) );
		$this->assertSame( 'ok', $results['H-C12']->status );
	}

	public function test_dropin_version_mismatch_blocks(): void {
		$dir  = self::fixtureDir() . '-ver';
		@mkdir( $dir, 0777, true );
		$body = "<?php // loader\n";
		file_put_contents( $dir . '/db.php', DropinMarker::build( '0.0.0-old', $body ) . $body );
		$results = Runner::run( array( new DropinCheck( '0.0.0-dev' ) ), self::envWithDir( $dir ) );
		$this->assertSame( 'block', $results['H-C12']->status );
	}

	public function test_dropin_drift_blocks(): void {
		$dir  = self::fixtureDir() . '-drift';
		@mkdir( $dir, 0777, true );
		$body = "<?php // loader\n";
		file_put_contents( $dir . '/db.php', DropinMarker::build( '0.0.0-dev', $body ) . $body . "// tampered\n" );
		$results = Runner::run( array( new DropinCheck( '0.0.0-dev' ) ), self::envWithDir( $dir ) );
		$this->assertSame( 'block', $results['H-C23']->status );
	}

	public function test_dropin_hyperdb_conflict_blocks(): void {
		$dir = self::fixtureDir() . '-hyper';
		@mkdir( $dir, 0777, true );
		file_put_contents( $dir . '/db.php', "<?php class HyperDB extends wpdb {}\n" );
		$results = Runner::run( array( new DropinCheck( '0.0.0-dev' ) ), self::envWithDir( $dir ) );
		$this->assertSame( 'block', $results['H-C11']->status );
		$this->assertStringContainsString( 'HyperDB', $results['H-C11']->message );
	}

	public function test_dropin_lowercase_conflict_blocks(): void {
		$dir = self::fixtureDir() . '-hyperlow';
		@mkdir( $dir, 0777, true );
		file_put_contents( $dir . '/db.php', "<?php class hyperdb extends wpdb {}\n" );
		$results = Runner::run( array( new DropinCheck( '0.0.0-dev' ) ), self::envWithDir( $dir ) );
		$this->assertSame( 'block', $results['H-C11']->status );
	}

	public function test_crash_becomes_block(): void {
		$bad = new class() implements \Slonik\Health\Check {
			public function check( \Slonik\Health\Env $env ) {
				throw new \RuntimeException( 'boom' );
			}
		};
		$results = Runner::run( array( $bad ), self::envWithDir( sys_get_temp_dir() ) );
		$first   = reset( $results );
		$this->assertSame( 'block', $first->status );
		$this->assertStringContainsString( 'boom', $first->message );
	}

	public function test_constants_enabled_missing_required_blocks(): void {
		$results = Runner::run(
			array( new ConstantsCheck() ),
			self::envWithDir( sys_get_temp_dir(), array( 'WPSLONIK_ENABLED' => true ) )
		);
		$this->assertSame( 'block', $results['H-C22']->status );
	}

	public function test_constants_unknown_warns(): void {
		$results = Runner::run(
			array( new ConstantsCheck() ),
			self::envWithDir(
				sys_get_temp_dir(),
				array(
					'WPSLONIK_ENABLED' => true,
					'WPSLONIK_DB_HOST' => 'h',
					'WPSLONIK_DB_USER' => 'u',
					'WPSLONIK_DB_NAME' => 'd',
					'WPSLONIK_TYPO'    => 1,
				)
			)
		);
		$this->assertSame( 'warn', $results['H-C22']->status );
	}

	public function test_constants_own_plugin_names_known(): void {
		$results = Runner::run(
			array( new ConstantsCheck() ),
			self::envWithDir(
				sys_get_temp_dir(),
				array(
					'WPSLONIK_ENABLED'     => true,
					'WPSLONIK_DB_HOST'     => 'h',
					'WPSLONIK_DB_USER'     => 'u',
					'WPSLONIK_DB_NAME'     => 'd',
					'WPSLONIK_VERSION'     => '0.0.0-dev',
					'WPSLONIK_PLUGIN_FILE' => '/x/slonik.php',
				)
			)
		);
		$this->assertSame( 'ok', $results['H-C22']->status );
	}

	public function test_db_version_ok_and_old_blocks(): void {
		$wants   = array( 'H-C02', 'H-C03' );
		$good    = array( 'SELECT 1' => '1', 'SHOW server_version_num' => '160015' );
		$results = Runner::run( array( new DbCheck( new ArrayProbe( $good ), $wants ) ), self::envWithDir( sys_get_temp_dir() ) );
		$this->assertSame( 'ok', $results['H-C03']->status );
		$stale   = array( 'SELECT 1' => '1', 'SHOW server_version_num' => '150009' );
		$results = Runner::run( array( new DbCheck( new ArrayProbe( $stale ), $wants ) ), self::envWithDir( sys_get_temp_dir() ) );
		$this->assertSame( 'block', $results['H-C03']->status );
	}

	public function test_db_probe_throw_blocks_with_message(): void {
		$probe   = new ArrayProbe( array( 'SELECT 1' => new \RuntimeException( 'conn refused' ) ) );
		$results = Runner::run( array( new DbCheck( $probe, array( 'H-C02' ) ) ), self::envWithDir( sys_get_temp_dir() ) );
		$this->assertSame( 'block', $results['H-C02']->status );
		$this->assertStringContainsString( 'conn refused', $results['H-C02']->message );
	}

	public function test_db_citext_missing_warns_with_the_install_steps(): void {
		$wants = array( 'H-C06' );
		$map   = array( 'SELECT count(*) FROM pg_extension WHERE extname = \'citext\'' => '0' );
		$env   = self::envWithDir( sys_get_temp_dir(), array( 'WPSLONIK_ENABLED' => true ) );
		$results = Runner::run( array( new DbCheck( new ArrayProbe( $map ), $wants ) ), $env );
		// The degraded fallback works, so this is a warning - but the
		// operator has to see what to install (audit D-02).
		$this->assertSame( 'warn', $results['H-C06']->status );
		$this->assertStringContainsString( 'degraded', $results['H-C06']->message );
		$remediation = implode( ' ', $results['H-C06']->remediation );
		$this->assertStringContainsString( 'CREATE EXTENSION citext', $remediation );
		$this->assertStringContainsString( 'superuser', $remediation );
		$env2    = self::envWithDir( sys_get_temp_dir(), array( 'WPSLONIK_ENABLED' => true, 'WPSLONIK_CI_MODE' => 'degraded' ) );
		$results = Runner::run( array( new DbCheck( new ArrayProbe( $map ), $wants ) ), $env2 );
		$this->assertSame( 'warn', $results['H-C06']->status );
	}

	public function test_db_citext_installed_is_ok(): void {
		$wants = array( 'H-C06' );
		$map   = array( 'SELECT count(*) FROM pg_extension WHERE extname = \'citext\'' => '1' );
		$env   = self::envWithDir( sys_get_temp_dir(), array( 'WPSLONIK_ENABLED' => true ) );
		$results = Runner::run( array( new DbCheck( new ArrayProbe( $map ), $wants ) ), $env );
		$this->assertSame( 'ok', $results['H-C06']->status );
		$this->assertStringContainsString( 'citext', $results['H-C06']->message );
	}

	public function test_db_privilege_encoding_pool(): void {
		$map = array(
			'SELECT 1' => '1',
			'SHOW server_encoding' => 'UTF8',
			"SELECT has_schema_privilege(current_schema(), 'CREATE')" => false,
		);
		$results = Runner::run( array( new DbCheck( new ArrayProbe( $map ), array( 'H-C04', 'H-C05', 'H-C10' ) ) ), self::envWithDir( sys_get_temp_dir() ) );
		$this->assertSame( 'ok', $results['H-C04']->status );
		$this->assertSame( 'block', $results['H-C05']->status );
		$this->assertSame( 'ok', $results['H-C10']->status );
	}

	public function test_db_pool_flag_warns(): void {
		$env = Env::fromValues(
			array(
				'php_version' => '8.2.0',
				'extensions'  => array( 'pdo_pgsql' ),
				'wp_version'  => '7.1.3',
				'defines'     => array(),
				'content_dir' => sys_get_temp_dir(),
				'behind_pool' => true,
			)
		);
		$results = Runner::run( array( new DbCheck( new ArrayProbe( array() ), array( 'H-C10' ) ) ), $env );
		$this->assertSame( 'warn', $results['H-C10']->status );
	}

	public function test_spelling_audit_groups_sort_the_names(): void {
		$json   = '[ [{"name":"wp_wfconfig","bytes":16384},{"name":"wp_wfConfig","bytes":0}] ]';
		$groups = SpellingAudit::groups( $json );
		$this->assertCount( 1, $groups );
		$this->assertSame( 'wp_wfConfig', $groups[0][0]['name'] );
		$this->assertSame( 0, $groups[0][0]['bytes'] );
	}

	public function test_spelling_audit_query_shape(): void {
		$sql = SpellingAudit::query( 'public' );
		$this->assertStringContainsString( 'GROUP BY lower(c.relname)', $sql );
		$this->assertStringContainsString( 'HAVING count(*) > 1', $sql );
		$this->assertStringContainsString( 'pg_total_relation_size', $sql );
		$this->assertStringContainsString( "relkind IN ('r','p')", $sql );
		$this->assertStringContainsString( "'public'", $sql );
	}

	public function test_spelling_audit_lines_format(): void {
		$groups = array(
			array(
				array(
					'name'  => 'wp_wfConfig',
					'bytes' => 0,
				),
				array(
					'name'  => 'wp_wfconfig',
					'bytes' => 16384,
				),
			),
		);
		$this->assertSame( array( 'wp_wfConfig (0 B) | wp_wfconfig (16384 B)' ), SpellingAudit::lines( $groups ) );
	}

	public function test_db_spelling_audit_warns(): void {
		$json = '[ [{"name":"wp_wfConfig","bytes":0},{"name":"wp_wfconfig","bytes":16384}],'
			. ' [{"name":"wp_xTable","bytes":0},{"name":"wp_xtable","bytes":4096}] ]';
		$map  = array( SpellingAudit::query( 'public' ) => $json );
		$results = Runner::run(
			array( new DbCheck( new ArrayProbe( $map ), array( 'H-C26' ) ) ),
			self::envWithDir( sys_get_temp_dir() )
		);
		$this->assertSame( 'warn', $results['H-C26']->status );
		$this->assertSame( '2 table name(s) exist with two spellings', $results['H-C26']->message );
		$remediation = implode( ' ', $results['H-C26']->remediation );
		$this->assertStringContainsString( 'wp_wfConfig', $remediation );
		$this->assertStringContainsString( 'wp_wfconfig', $remediation );
		$this->assertStringContainsString( 'No automatic cleanup is performed.', $remediation );
	}

	public function test_db_spelling_audit_clean_schema_is_ok(): void {
		$map     = array( SpellingAudit::query( 'public' ) => '[]' );
		$results = Runner::run(
			array( new DbCheck( new ArrayProbe( $map ), array( 'H-C26' ) ) ),
			self::envWithDir( sys_get_temp_dir() )
		);
		$this->assertSame( 'ok', $results['H-C26']->status );
		$this->assertSame( 'no table name has two spellings', $results['H-C26']->message );
	}

	public function test_db_spelling_audit_probe_failure_blocks(): void {
		$map     = array( SpellingAudit::query( 'public' ) => new \RuntimeException( 'conn refused' ) );
		$results = Runner::run(
			array( new DbCheck( new ArrayProbe( $map ), array( 'H-C26' ) ) ),
			self::envWithDir( sys_get_temp_dir() )
		);
		$this->assertSame( 'block', $results['H-C26']->status );
		$this->assertStringContainsString( 'conn refused', $results['H-C26']->message );
	}
}
