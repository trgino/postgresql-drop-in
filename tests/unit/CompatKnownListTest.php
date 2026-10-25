<?php
declare( strict_types=1 );

namespace Slonik\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Slonik\Compat\KnownList;
use Slonik\Cli\CompatKnownCommand;

final class CompatKnownListTest extends TestCase {

	private function tmpFile() {
		return sys_get_temp_dir() . '/slonik-known-' . getmypid() . '.json';
	}

	public function test_packaged_default_schema1(): void {
		$list = KnownList::loadFile( dirname( __DIR__, 2 ) . '/compat/known-compat.json' );
		$this->assertSame( 1, $list['schema'] );
		$this->assertIsArray( $list['entries'] );
	}

	public function test_load_flattens_entries(): void {
		$file = $this->tmpFile();
		file_put_contents( $file, json_encode( array(
			'schema'  => 1,
			'entries' => array(
				array(
					'slug'      => 'woocommerce',
					'version'   => '9.4.2',
					'tree_hash' => 'sha256:' . str_repeat( 'a', 64 ),
					'result'    => 'warning',
					'deviations' => array( 'R-046' ),
					'tested_with' => array( 'wp' => '6.4', 'pg' => '16' ),
				),
			),
		) ) );
		$list = KnownList::loadFile( $file );
		$this->assertCount( 1, $list['entries'] );
		$this->assertSame( array( 'wp' => '6.4', 'pg' => '16' ), $list['entries'][0]['tested_with'] );
		$this->assertSame( array( 'R-046' ), $list['entries'][0]['deviations'] );
		@unlink( $file );
	}

	public function test_exact_match(): void {
		$file = $this->tmpFile();
		$hash = 'sha256:' . str_repeat( 'b', 64 );
		file_put_contents( $file, json_encode( array(
			'schema'  => 1,
			'entries' => array(
				array( 'slug' => 'woocommerce', 'version' => '9.4.2', 'tree_hash' => $hash, 'result' => 'compatible' ),
				array( 'slug' => 'woocommerce', 'version' => '9.4.3', 'tree_hash' => $hash, 'result' => 'incompatible' ),
			),
		) ) );
		$this->assertSame( 'compatible', KnownList::lookup( array( $file ), 'woocommerce', '9.4.2', $hash ) );
		$this->assertSame( 'incompatible', KnownList::lookup( array( $file ), 'woocommerce', '9.4.3', $hash ) );
		$this->assertNull( KnownList::lookup( array( $file ), 'woocommerce', '9.4.2', 'sha256:' . str_repeat( 'c', 64 ) ) );
		$this->assertNull( KnownList::lookup( array( $file ), 'woocommerce', '9.4.4', $hash ) );
		$this->assertNull( KnownList::lookup( array( $file ), 'jetpack', '1.0.0', $hash ) );
		@unlink( $file );
	}

	public function test_malformed_file_degrades_to_empty(): void {
		$file = $this->tmpFile();
		file_put_contents( $file, '{broken' );
		$list = KnownList::loadFile( $file );
		$this->assertSame( 1, $list['schema'] );
		$this->assertSame( array(), $list['entries'] );
		@unlink( $file );
	}

	public function test_add_rejects_bad_args(): void {
		$this->assertSame( 3, CompatKnownCommand::exitFor( 'add', array( 'slug' => 'x', 'version' => '1.0', 'tree_hash' => 'notahash' ) ) );
		$this->assertSame( 3, CompatKnownCommand::exitFor( 'check', array() ) );
		$this->assertSame( 3, CompatKnownCommand::exitFor( 'bogus', array() ) );
	}

	public function test_check_unverified_not_error(): void {
		$this->assertSame( array( 'unverified', 'unknown slug: jetpack' ), CompatKnownCommand::checkOutcome( array(), 'jetpack', '9.4.4', 'sha256:' . str_repeat( 'a', 64 ) ) );
	}

	public function test_add_upserts_and_rewrites(): void {
		$file = $this->tmpFile();
		@unlink( $file );
		$hash = 'sha256:' . str_repeat( 'd', 64 );
		$this->assertSame( 0, CompatKnownCommand::saveEntry( $file, array( 'slug' => 'slonik', 'version' => '0.0.0', 'tree_hash' => $hash, 'result' => 'compatible' ) ) );
		$this->assertSame( 0, CompatKnownCommand::saveEntry( $file, array( 'slug' => 'slonik', 'version' => '0.0.1', 'tree_hash' => $hash, 'result' => 'warning' ) ) );
		$list = KnownList::loadFile( $file );
		$this->assertCount( 2, $list['entries'] );
		// same triple upsert replaces, keeps one
		$this->assertSame( 0, CompatKnownCommand::saveEntry( $file, array( 'slug' => 'slonik', 'version' => '0.0.1', 'tree_hash' => $hash, 'result' => 'incompatible' ) ) );
		$list = KnownList::loadFile( $file );
		$this->assertCount( 2, $list['entries'] );
		$this->assertSame( 'incompatible', KnownList::lookup( array( $file ), 'slonik', '0.0.1', $hash ) );
		@unlink( $file );
	}

	public function test_write_file_rejects_invalid_result(): void {
		$file = $this->tmpFile();
		$this->assertSame( 3, KnownList::writeFile(
			$file,
			array( 'slug' => 'x', 'version' => '1.0', 'tree_hash' => 'sha256:' . str_repeat( 'a', 64 ), 'result' => 'bogus' )
		) );
		$this->assertFileDoesNotExist( $file );
	}

	public function test_write_file_is_atomic_no_tmp_litter(): void {
		$file = $this->tmpFile();
		@unlink( $file );
		$hash = 'sha256:' . str_repeat( 'e', 64 );
		$this->assertSame( 0, KnownList::writeFile(
			$file,
			array( 'slug' => 'x', 'version' => '1.0', 'tree_hash' => $hash, 'result' => 'compatible' )
		) );
		$this->assertFileExists( $file );
		$this->assertFileDoesNotExist( $file . '.tmp' );
		@unlink( $file );
	}
}
