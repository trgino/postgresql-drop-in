<?php
declare( strict_types=1 );

namespace Slonik\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Slonik\Migrate\MigrateConfig;

final class MigrateConfigTest extends TestCase {

	private function goodDefs() {
		return array(
			'DB_HOST'           => 'mysql.internal',
			'DB_NAME'           => 'wp',
			'DB_USER'           => 'wp',
			'DB_PASSWORD'       => 'mysqlpass',
			'WPSLONIK_DB_HOST'  => 'pg.example.com:5433',
			'WPSLONIK_DB_NAME'  => 'wordpress',
			'WPSLONIK_DB_USER'  => 'migrator',
			'WPSLONIK_DB_PASSWORD' => 'pgpass',
		);
	}

	public function test_defaults_and_splitting(): void {
		$c = MigrateConfig::fromArray( $this->goodDefs() );
		$this->assertSame( 'mysql.internal', $c->source()['host'] );
		$this->assertSame( 3306, $c->source()['port'] );
		$this->assertSame( 'pg.example.com', $c->target()['host'] );
		$this->assertSame( 5433, $c->target()['port'] );
		$this->assertSame( 'public', $c->target()['schema'] );
		$this->assertSame( 'prefer', $c->target()['sslmode'] );
		$this->assertSame( 'postgresql-drop-in', $c->target()['app'] );
		$this->assertSame( 1000, $c->options()['batch_rows'] );
		$this->assertSame( 8 * 1024 * 1024, $c->options()['batch_bytes'] );
		$this->assertSame( 'abort', $c->options()['invalid_utf8'] );
		$this->assertSame( 'checksum', $c->options()['verify'] );
		$this->assertSame( 'wp_', $c->options()['prefix'] );
		$this->assertSame( array(), $c->options()['exclude_tables'] );
		$this->assertFalse( $c->options()['drop_target'] );
	}

	public function test_inline_target_port_beats_define(): void {
		$defs            = $this->goodDefs();
		$defs['WPSLONIK_DB_PORT'] = 6000;
		$c               = MigrateConfig::fromArray( $defs );
		$this->assertSame( 5433, $c->target()['port'] );

		$defs2 = $this->goodDefs();
		unset( $defs2['WPSLONIK_DB_HOST'] );
		$defs2['WPSLONIK_DB_HOST'] = 'pg-node';
		$defs2['WPSLONIK_DB_PORT'] = 6000;
		$this->assertSame( 6000, MigrateConfig::fromArray( $defs2 )->target()['port'] );
	}

	public function test_ipv6_bracketed_host(): void {
		$defs            = $this->goodDefs();
		$defs['WPSLONIK_DB_HOST'] = '[::1]:5432';
		$c               = MigrateConfig::fromArray( $defs );
		$this->assertSame( '[::1]', $c->target()['host'] );
		$this->assertSame( 5432, $c->target()['port'] );
	}

	public function test_enabled_flips_detected(): void {
		$defs = $this->goodDefs();
		$this->assertFalse( MigrateConfig::fromArray( $defs )->enabledFlag() );
		$defs['WPSLONIK_ENABLED'] = true;
		$this->assertTrue( MigrateConfig::fromArray( $defs )->enabledFlag() );
		$defs['WPSLONIK_ENABLED'] = false;
		$this->assertFalse( MigrateConfig::fromArray( $defs )->enabledFlag() );
		$defs['WPSLONIK_ENABLED'] = '1';
		$this->assertTrue( MigrateConfig::fromArray( $defs )->enabledFlag() );
	}

	public function test_missing_source_defs_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		$defs = $this->goodDefs();
		unset( $defs['DB_NAME'] );
		MigrateConfig::fromArray( $defs );
	}

	public function test_missing_target_defs_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		$defs = $this->goodDefs();
		unset( $defs['WPSLONIK_DB_NAME'] );
		MigrateConfig::fromArray( $defs );
	}

	public function test_bad_schema_identifier_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		$defs = $this->goodDefs();
		$defs['WPSLONIK_DB_SCHEMA'] = 'wp; drop schema';
		MigrateConfig::fromArray( $defs );
	}

	public function test_bad_sslmode_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		$defs = $this->goodDefs();
		$defs['WPSLONIK_DB_SSLMODE'] = 'hopefully';
		MigrateConfig::fromArray( $defs );
	}

	public function test_bad_policy_and_verify_rejected(): void {
		$defs = $this->goodDefs();
		$defs['WPSLONIK_MIGRATE_ON_INVALID_UTF8'] = 'maybe';
		try {
			MigrateConfig::fromArray( $defs );
			$this->fail( 'policy must be rejected' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'ON_INVALID_UTF8', $e->getMessage() );
		}
		$defs2 = $this->goodDefs();
		$defs2['WPSLONIK_MIGRATE_VERIFY'] = 'ssl';
		try {
			MigrateConfig::fromArray( $defs2 );
			$this->fail( 'verify mode must be rejected' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'WPSLONIK_MIGRATE_VERIFY', $e->getMessage() );
		}
	}

	public function test_batch_sanity_bounds(): void {
		$defs = $this->goodDefs();
		$defs['WPSLONIK_MIGRATE_BATCH_ROWS'] = 0;
		try {
			MigrateConfig::fromArray( $defs );
			$this->fail( 'zero batch rows must be rejected' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'BATCH_ROWS', $e->getMessage() );
		}
		$defs2 = $this->goodDefs();
		$defs2['WPSLONIK_MIGRATE_BATCH_BYTES'] = 7;
		try {
			MigrateConfig::fromArray( $defs2 );
			$this->fail( 'tiny batch bytes must be rejected' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'BATCH_BYTES', $e->getMessage() );
		}
	}

	public function test_lists_folded_and_drop_target_truthiness(): void {
		$defs = $this->goodDefs();
		$defs['WPSLONIK_MIGRATE_EXCLUDE_TABLES'] = array( 'wp_a', '', 7, 'wp_b ' );
		$defs['WPSLONIK_MIGRATE_DROP_TARGET']    = '1';
		$c = MigrateConfig::fromArray( $defs );
		$this->assertSame( array( 'wp_a', 'wp_b ' ), $c->options()['exclude_tables'] );
		$this->assertTrue( $c->options()['drop_target'] );
		$defs['WPSLONIK_MIGRATE_DROP_TARGET'] = 'yes';
		$this->assertFalse( MigrateConfig::fromArray( $defs )->options()['drop_target'] );
	}
}
