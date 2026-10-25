<?php
declare( strict_types=1 );

namespace Slonik\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Slonik\Sql\HostPort;

final class HostPortTest extends TestCase {

	/**
	 * The host and the port are parsed in one place: the runtime and
	 * the migration tool must agree (audit V-09: the runtime split
	 * "[::1]:5432" wrongly).
	 */
	public function test_plain_host_uses_the_default_port(): void {
		$this->assertSame( array( '127.0.0.1', 5432 ), HostPort::split( '127.0.0.1' ) );
	}

	public function test_host_with_port(): void {
		$this->assertSame( array( 'db.example.com', 5433 ), HostPort::split( 'db.example.com:5433' ) );
	}

	public function test_bracketed_ipv6_with_port(): void {
		$this->assertSame( array( '[::1]', 5432 ), HostPort::split( '[::1]:5432' ) );
		$this->assertSame( array( '[2001:db8::1]', 5433 ), HostPort::split( '[2001:db8::1]:5433' ) );
	}

	public function test_plain_ipv6_has_no_port(): void {
		$this->assertSame( array( '::1', 5432 ), HostPort::split( '::1' ) );
	}

	public function test_unix_socket_path(): void {
		$this->assertSame( array( ':/var/run/postgresql', 5432 ), HostPort::split( ':/var/run/postgresql' ) );
	}

	public function test_empty_host(): void {
		$this->assertSame( array( '', 5432 ), HostPort::split( '' ) );
	}

	/**
	 * The DSN fragment of the runtime: the IPv6 host keeps its
	 * brackets, the socket path loses the leading colon.
	 */
	public function test_dsn_fragment(): void {
		$this->assertSame( 'host=127.0.0.1;port=5432;', HostPort::dsn( '127.0.0.1' ) );
		$this->assertSame( 'host=[::1];port=5432;', HostPort::dsn( '[::1]:5432' ) );
		$this->assertSame( 'host=/var/run/postgresql;port=5432;', HostPort::dsn( ':/var/run/postgresql' ) );
	}
}
