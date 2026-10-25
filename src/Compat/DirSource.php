<?php
/**
 * Directory file source (.php files, recursive, deterministic order).
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Compat;

final class DirSource implements FileSource {

	/** @var string Absolute directory path. */
	private $dir;

	public function __construct( $dir ) {
		$this->dir = (string) $dir;
	}

	public function files() : array {
		$out = array();
		if ( ! is_dir( $this->dir ) ) {
			return $out;
		}
		$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->dir, \FilesystemIterator::SKIP_DOTS ) );
		/**
		 * @var \SplFileInfo $item
		 */
		foreach ( $it as $item ) {
			if ( 'php' !== strtolower( $item->getExtension() ) ) {
				continue;
			}
			$pathname = str_replace( '\\', '/', (string) $item->getPathname() );
			$root     = str_replace( '\\', '/', $this->dir );
			$rel      = ltrim( substr( $pathname, strlen( rtrim( $root, '/' ) ) ), '/' );
			$contents = (string) file_get_contents( $pathname );
			$out[]    = array(
				'path'     => $rel,
				'contents' => $contents,
			);
		}
		usort( $out, static fn( $a, $b ) => strcmp( $a['path'], $b['path'] ) );
		return $out;
	}
}
