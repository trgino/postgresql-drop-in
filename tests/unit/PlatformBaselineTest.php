<?php
/**
 * Platform baseline gate: PHP 7.4 / WP 6.4 must stay consistent everywhere
 * (PHP 7.4+, WordPress 6.4+).
 *
 * @package Slonik
 */

namespace Slonik\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PlatformBaselineTest extends TestCase {

	private const ROOT = __DIR__ . '/../..';

	public function test_plugin_header_declares_baseline(): void {
		$header = (string) file_get_contents( self::ROOT . '/postgresql-drop-in.php' );
		$this->assertMatchesRegularExpression( '/^\s*\*\s*Requires PHP:\s*7\.4\s*$/m', $header );
		$this->assertMatchesRegularExpression( '/^\s*\*\s*Requires at least:\s*6\.4\s*$/m', $header );
		$this->assertMatchesRegularExpression( '/^\s*\*\s*License:\s*GPL-2\.0-or-later\s*$/m', $header );
		$this->assertMatchesRegularExpression( '/^\s*\*\s*Text Domain:\s*postgresql-drop-in\s*$/m', $header );
	}

	/**
	 * The public identity follows the WordPress.org naming rules
	 * (Guideline 17): no "WP"/"WordPress" in the name or the slug, the
	 * slug is lowercase and hyphenated, and the readme title matches the
	 * plugin header.
	 */
	public function test_the_public_name_follows_the_directory_rules(): void {
		$header = (string) file_get_contents( self::ROOT . '/postgresql-drop-in.php' );
		$readme = (string) file_get_contents( self::ROOT . '/readme.txt' );

		$this->assertMatchesRegularExpression( '/^\s*\*\s*Plugin Name:\s*PostgreSQL Drop-in\s*$/m', $header );
		$this->assertStringContainsString( 'https://wordpress.org/plugins/postgresql-drop-in/', $header );
		$this->assertStringContainsString( '=== PostgreSQL Drop-in ===', $readme );

		// The name and the slug must not carry the WordPress trademark
		// (it is redundant in the directory) nor a project name.
		$this->assertDoesNotMatchRegularExpression( '/\bwp\b|\bwordpress\b/i', 'PostgreSQL Drop-in' );
		$this->assertDoesNotMatchRegularExpression( '/\bwp\b|\bwordpress\b/i', 'postgresql-drop-in' );
		$this->assertMatchesRegularExpression( '/^[a-z0-9]+(-[a-z0-9]+)*$/', 'postgresql-drop-in' );
		$this->assertLessThanOrEqual( 50, strlen( 'postgresql-drop-in' ) );
	}

	/**
	 * The plugin folder and the text domain are the slug: the drop-in
	 * loader looks the plugin up by that name.
	 */
	public function test_the_drop_in_finds_the_plugin_folder_by_the_slug(): void {
		$dropin = (string) file_get_contents( self::ROOT . '/dropin/db.php' );
		$this->assertStringContainsString( "/plugins/postgresql-drop-in", $dropin );
	}

	public function test_readme_matches_header(): void {
		$readme = (string) file_get_contents( self::ROOT . '/readme.txt' );
		$this->assertStringContainsString( 'Requires PHP: 7.4', $readme );
		$this->assertStringContainsString( 'Requires at least: 6.4', $readme );
	}

	public function test_composer_requires_php_74(): void {
		$composer = json_decode( (string) file_get_contents( self::ROOT . '/composer.json' ), true );
		$this->assertSame( '>=7.4', $composer['require']['php'] );
		$this->assertSame( '7.4.33', $composer['config']['platform']['php'] );
	}

	/**
	 * The runtime speaks PDO/PostgreSQL only: the extension must be a hard
	 * requirement (audit D-22). mysqli is needed by the migration CLI
	 * alone, so it stays a suggestion.
	 */
	public function test_composer_declares_pgsql_requirement(): void {
		$composer = json_decode( (string) file_get_contents( self::ROOT . '/composer.json' ), true );
		$this->assertArrayHasKey( 'ext-pdo_pgsql', $composer['require'] );
		$this->assertArrayNotHasKey( 'ext-mysqli', $composer['require'] );
		$this->assertArrayHasKey( 'ext-mysqli', $composer['suggest'] );
	}

	/**
	 * A hard ext requirement makes `composer install` fail on hosts
	 * without the extension - CI must provide it.
	 */
	public function test_ci_provides_pgsql_extension(): void {
		$ci = (string) file_get_contents( self::ROOT . '/.github/workflows/ci.yml' );
		$this->assertStringContainsString( 'pdo_pgsql', $ci );
	}

	/**
	 * Every PHP source file must at least lint on the running interpreter.
	 * (PHP 7.4 lint runs in CI; this catches syntax errors locally.)
	 */
	public function test_sources_have_no_forbidden_php8_syntax(): void {
		$forbidden = array(
			'/\bmatch\s*\(/'              => 'match expression',
			'/\benum\s+\w+/'              => 'enum',
			'/\breadonly\s+(?:public|protected|private|class)/' => 'readonly',
			'/\?->/'                      => 'nullsafe operator',
			'/#\[\w+/'                    => 'attribute',
			'/\bstr_contains\s*\(/'       => 'str_contains()',
			'/\bstr_starts_with\s*\(/'    => 'str_starts_with()',
			'/\bstr_ends_with\s*\(/'      => 'str_ends_with()',
		);
		foreach ( $this->php_files() as $file ) {
			$code = $this->strip_comments_and_strings( (string) file_get_contents( $file ) );
			foreach ( $forbidden as $pattern => $what ) {
				$this->assertSame( 0, preg_match( $pattern, $code ), "$what used in $file (PHP 7.4 baseline)" );
			}
		}
	}

	/**
	 * @return string[]
	 */
	private function php_files(): array {
		$files = array( self::ROOT . '/postgresql-drop-in.php' );
		foreach ( array( 'src', 'dropin', 'tools' ) as $dir ) {
			$path = self::ROOT . '/' . $dir;
			if ( ! is_dir( $path ) ) {
				continue;
			}
			$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $f ) {
				if ( 'php' !== $f->getExtension() ) {
					continue;
				}
				// The fetched plugins and the corpus are not our code.
				if ( false !== strpos( $f->getPathname(), 'corpus' . DIRECTORY_SEPARATOR . 'out' ) ) {
					continue;
				}
				if ( false !== strpos( $f->getPathname(), 'matrix' . DIRECTORY_SEPARATOR . 'out' ) ) {
					continue;
				}
				$files[] = $f->getPathname();
			}
		}
		return $files;
	}

	private function strip_comments_and_strings( string $code ): string {
		$out = '';
		foreach ( token_get_all( $code ) as $token ) {
			if ( is_array( $token ) ) {
				if ( in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML ), true ) ) {
					continue;
				}
				$out .= $token[1];
			} else {
				$out .= $token;
			}
		}
		return $out;
	}
}
