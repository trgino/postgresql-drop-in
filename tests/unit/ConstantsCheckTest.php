<?php
declare( strict_types=1 );

namespace Slonik\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Slonik\Health\Checks\ConstantsCheck;
use Slonik\Health\Env;

final class ConstantsCheckTest extends TestCase {

	/**
	 * D-15: the catalogue follows the code. Every constant the code
	 * reads has to be known to the check, otherwise the operator gets a
	 * false "unknown constant" warning for a setting that works.
	 */
	public function test_catalogue_covers_every_constant_the_code_reads(): void {
		$root = dirname( __DIR__, 2 );
		$read = array();
		foreach ( self::phpFiles( $root . '/src' ) as $file ) {
			$code = (string) file_get_contents( $file );
			if ( preg_match_all( "/'WPSLONIK_[A-Z0-9_]+'/", $code, $matches ) ) {
				foreach ( $matches[0] as $name ) {
					$name = rtrim( trim( $name, "'" ), '_' );
					$read[ $name ] = true;
				}
			}
		}
		// WPSLONIK_DB is the drop-in class name, not a constant.
		unset( $read['WPSLONIK_DB'] );

		$known = array_flip( ConstantsCheck::KNOWN );
		foreach ( array_keys( ConstantsCheck::NOT_IMPLEMENTED ) as $name ) {
			$known[ $name ] = true;
		}

		$missing = array();
		foreach ( array_keys( $read ) as $name ) {
			if ( ! isset( $known[ $name ] ) ) {
				$missing[] = $name;
			}
		}
		sort( $missing );
		$this->assertSame( array(), $missing, 'Constants the code reads are missing from the catalogue.' );
	}

	/**
	 * A constant the code honours must not be reported.
	 */
	public function test_a_known_constant_is_ok(): void {
		$result = $this->check( array(
			'WPSLONIK_ENABLED'        => true,
			'WPSLONIK_DB_HOST'        => '127.0.0.1',
			'WPSLONIK_DB_USER'        => 'wp',
			'WPSLONIK_DB_NAME'        => 'wp',
			'WPSLONIK_DB_PORT'        => '5432',
			'WPSLONIK_DB_SSLMODE'     => 'require',
			'WPSLONIK_CI_MODE'        => 'auto',
			'WPSLONIK_COMPAT_SLOW_MS' => '250',
		) );
		$this->assertSame( 'ok', $result->status );
	}

	/**
	 * A typo must be reported.
	 */
	public function test_an_unknown_constant_warns(): void {
		$result = $this->check( array(
			'WPSLONIK_ENABLED'     => true,
			'WPSLONIK_DB_HOST'     => '127.0.0.1',
			'WPSLONIK_DB_USER'     => 'wp',
			'WPSLONIK_DB_NAME'     => 'wp',
			'WPSLONIK_DB_HOSTNAME' => 'typo',
		) );
		$this->assertSame( 'warn', $result->status );
		$this->assertStringContainsString( 'WPSLONIK_DB_HOSTNAME', $result->message );
	}

	/**
	 * A documented constant that is not implemented yet must not look
	 * like it works.
	 */
	public function test_a_not_implemented_constant_warns(): void {
		$result = $this->check( array(
			'WPSLONIK_ENABLED'    => true,
			'WPSLONIK_DB_HOST'    => '127.0.0.1',
			'WPSLONIK_DB_USER'    => 'wp',
			'WPSLONIK_DB_NAME'    => 'wp',
			'WPSLONIK_DB_TIMEZONE' => 'Europe/Istanbul',
		) );
		$this->assertSame( 'warn', $result->status );
		$this->assertStringContainsString( 'not implemented', $result->message );
	}

	/**
	 * PG mode off: nothing can block.
	 */
	public function test_transparent_mode_is_ok(): void {
		$result = $this->check( array( 'WPSLONIK_ENABLED' => false ) );
		$this->assertSame( 'ok', $result->status );
	}

	private function check( array $defines ) {
		$env = Env::fromValues(
			array(
				'php_version' => '8.2.0',
				'extensions'  => array( 'pdo_pgsql' ),
				'wp_version'  => '7.1.3',
				'defines'     => $defines,
				'content_dir' => sys_get_temp_dir(),
			)
		);
		$check = new ConstantsCheck();
		return $check->check( $env );
	}

	/**
	 * @param string $dir Directory to walk.
	 * @return array<string> PHP file paths.
	 */
	private static function phpFiles( $dir ) {
		$files = array();
		$it    = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir ) );
		foreach ( $it as $file ) {
			if ( $file->isFile() && 'php' === strtolower( (string) $file->getExtension() ) ) {
				$files[] = $file->getPathname();
			}
		}
		return $files;
	}
}
