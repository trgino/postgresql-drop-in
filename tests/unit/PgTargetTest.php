<?php
declare( strict_types=1 );

namespace Slonik\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Slonik\Migrate\PgTarget;

final class PgTargetTest extends TestCase {

	public function test_dsn_contains_connection_parts(): void {
		$dsn = PgTarget::dsn(
			array(
				'host' => 'db.example',
				'port' => 5433,
				'name' => 'wp',
			)
		);
		$this->assertStringContainsString( 'host=db.example;', $dsn );
		$this->assertStringContainsString( 'port=5433;', $dsn );
		$this->assertStringContainsString( 'dbname=wp', $dsn );
	}

	public function test_dsn_application_name_strips_spaces_and_separators(): void {
		$dsn = PgTarget::dsn(
			array(
				'host' => 'h',
				'port' => 5432,
				'name' => 'wp',
				'app'  => 'WP Slonik; migrate',
			)
		);
		$this->assertStringContainsString( 'application_name=WPSlonikmigrate', $dsn );
		$this->assertStringNotContainsString( 'Slonik;', $dsn );
	}

	public function test_dsn_ssl_options_are_passed_through(): void {
		$dsn = PgTarget::dsn(
			array(
				'host'        => 'h',
				'port'        => 5432,
				'name'        => 'wp',
				'sslmode'     => 'verify-full',
				'sslrootcert' => '/etc/ssl/cert.pem',
			)
		);
		$this->assertStringContainsString( 'sslmode=verify-full;', $dsn );
		$this->assertStringContainsString( 'sslrootcert=/etc/ssl/cert.pem', $dsn );
	}
}
