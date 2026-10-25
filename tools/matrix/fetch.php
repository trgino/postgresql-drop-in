<?php
/**
 * Fetch the plugin archives of the compatibility matrix.
 *
 * The archives come from the WordPress.org plugin directory; the
 * extracted plugins stay outside the repository (they are not ours to
 * redistribute).
 *
 * Usage:
 *   php tools/matrix/fetch.php [--out=tools/matrix/out/plugins] [--only=woocommerce]
 *
 * Exit code: 0 when every requested plugin is on disk, 1 otherwise.
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

$root = dirname( __DIR__, 2 );
$out  = $root . '/tools/matrix/out/plugins';
$only = '';
foreach ( array_slice( $argv, 1 ) as $arg ) {
	if ( 0 === strpos( $arg, '--out=' ) ) {
		$out = $root . '/' . ltrim( substr( $arg, 6 ), '/' );
	} elseif ( 0 === strpos( $arg, '--only=' ) ) {
		$only = substr( $arg, 7 );
	}
}
if ( ! is_dir( $out ) && ! mkdir( $out, 0777, true ) && ! is_dir( $out ) ) {
	fwrite( STDERR, "cannot create {$out}\n" );
	exit( 1 );
}

/**
 * Quote a path for the shell.
 *
 * escapeshellarg() puts single quotes on Windows, where cmd.exe does
 * not understand them.
 *
 * @param string $value The value.
 * @return string
 */
function slonik_matrix_quote( $value ) {
	if ( DIRECTORY_SEPARATOR === '\\' ) {
		return '"' . str_replace( '"', '""', (string) $value ) . '"';
	}
	return "'" . str_replace( "'", "'\\''", (string) $value ) . "'";
}

/**
 * Download a URL to a file.
 *
 * The PHP curl needs a CA bundle; the system curl (Windows: the cert
 * store) is the fallback when it is missing.
 *
 * @param string $url  The URL.
 * @param string $file The target file.
 * @return bool
 */
function slonik_matrix_download( $url, $file ) {
	if ( function_exists( 'curl_init' ) ) {
		$handle = curl_init( $url );
		if ( false !== $handle ) {
			curl_setopt( $handle, CURLOPT_FOLLOWLOCATION, true );
			curl_setopt( $handle, CURLOPT_TIMEOUT, 180 );
			curl_setopt( $handle, CURLOPT_USERAGENT, 'wp-slonik-matrix/1.0' );
			$body = curl_exec( $handle );
			$code = (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE );
			curl_close( $handle );
			if ( is_string( $body ) && 200 === $code && '' !== $body ) {
				return false !== file_put_contents( $file, $body );
			}
		}
	}
	// The system curl: the Windows certificate store, no CA bundle.
	// No "2>/dev/null": cmd.exe does not know that path.
	$command = 'curl -fsSL --retry 2 --max-time 180 -o ' . slonik_matrix_quote( $file ) . ' ' . slonik_matrix_quote( $url );
	exec( $command, $ignored, $code );
	if ( 0 !== $code ) {
		return false;
	}
	return is_file( $file ) && filesize( $file ) > 0;
}

/**
 * Extract a zip archive into a directory.
 *
 * @param string $zip  The archive.
 * @param string $dest The destination.
 * @return bool
 */
function slonik_matrix_unzip( $zip, $dest ) {
	if ( ! class_exists( 'ZipArchive' ) ) {
		return false;
	}
	$archive = new ZipArchive();
	if ( true !== $archive->open( $zip ) ) {
		return false;
	}
	$ok = $archive->extractTo( $dest );
	$archive->close();
	return (bool) $ok;
}

$manifest = json_decode( (string) file_get_contents( $root . '/tools/matrix/plugins.json' ), true );
$slugs    = array();
foreach ( array( 'tier1', 'popular' ) as $group ) {
	foreach ( (array) ( $manifest[ $group ] ?? array() ) as $slug ) {
		$slugs[] = $slug;
	}
}
if ( '' !== $only ) {
	$slugs = array( $only );
}

$missing = 0;
foreach ( $slugs as $slug ) {
	$dir = $out . '/' . $slug;
	if ( is_dir( $dir ) ) {
		echo "have   {$slug}\n";
		continue;
	}
	echo 'fetch  ' . $slug . ' … ';
	$zip = $out . '/' . $slug . '.zip';
	$url = 'https://downloads.wordpress.org/plugin/' . $slug . '.zip';
	if ( ! slonik_matrix_download( $url, $zip ) ) {
		echo "FAIL (not on wordpress.org?)\n";
		++$missing;
		continue;
	}
	if ( ! slonik_matrix_unzip( $zip, $out ) || ! is_dir( $dir ) ) {
		echo "FAIL (cannot extract)\n";
		++$missing;
		continue;
	}
	echo "ok\n";
}

echo "\n" . count( $slugs ) . " requested, {$missing} missing\n";
exit( $missing > 0 ? 1 : 0 );
