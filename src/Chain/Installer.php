<?php
/**
 * Drop-in installer + chain build/restore (K-25, docs/03 §7).
 *
 * Backup-then-install always: an existing db.php is never destroyed,
 * only moved to wpslonik/chained/ with a recorded hash. Class-B
 * drop-ins (own connection) are refused even with --force.
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Chain;

final class Installer {

	/** @var string */
	private $content_dir;

	/** @var string */
	private $loader_template;

	public function __construct( $content_dir, $loader_template ) {
		$this->content_dir     = rtrim( (string) $content_dir, '/\\' );
		$this->loader_template = (string) $loader_template;
	}

	private function dbFile() {
		return $this->content_dir . '/db.php';
	}

	private function backupFile() {
		// Non-executable extension on purpose: the backup is a byte copy of
		// a third-party drop-in and must never run from the web root (D-17).
		return $this->content_dir . '/wpslonik/chained/db.php.bak';
	}

	private function backupHashFile() {
		return $this->content_dir . '/wpslonik/chained/db.php.bak.sha256';
	}

	private function cacheFile( $hash ) {
		return $this->content_dir . '/cache/wpslonik/chain/db-' . $hash . '.php';
	}

	/**
	 * Atomic file write: temp file in the same directory + rename.
	 *
	 * A half-written db.php is a white screen, so the write must either
	 * land completely or not at all (D-16). The result is read back and
	 * compared before the call reports success.
	 *
	 * @param string $path     Target path.
	 * @param string $contents File contents.
	 * @return bool True when the file is on disk with the expected bytes.
	 */
	public static function writeAtomic( $path, $contents ) {
		$path = (string) $path;
		$dir  = dirname( $path );
		if ( ! is_dir( $dir ) ) {
			return false;
		}
		$tmp  = $path . '.tmp-' . uniqid( '', true );
		$code = (string) $contents;
		if ( false === @file_put_contents( $tmp, $code ) ) {
			@unlink( $tmp );
			return false;
		}
		@chmod( $tmp, 0644 );
		if ( ! @rename( $tmp, $path ) ) {
			@unlink( $tmp );
			return false;
		}
		$written = file_get_contents( $path );
		if ( ! is_string( $written ) || ! hash_equals( hash( 'sha256', $code ), hash( 'sha256', $written ) ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Insert the ABSPATH guard into PHP source so a direct web request
	 * cannot execute it (D-17).
	 *
	 * @param string $code PHP source.
	 * @return string Guarded source.
	 */
	public static function guardCode( $code ) {
		$code = (string) $code;
		$pos  = strpos( $code, '<?php' );
		if ( false === $pos ) {
			return "<?php\ndefined( 'ABSPATH' ) || exit;\n?>\n" . $code;
		}
		$at = $pos + 5;
		return substr( $code, 0, $at ) . "\ndefined( 'ABSPATH' ) || exit;" . substr( $code, $at );
	}

	/**
	 * Write directory guard files (no listing, no direct access).
	 *
	 * @param string $dir Directory path.
	 * @return void
	 */
	public static function guardDir( $dir ) {
		$dir = (string) $dir;
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return;
		}
		$index = $dir . '/index.php';
		if ( ! is_file( $index ) ) {
			self::writeAtomic( $index, "<?php\n// Silence is golden.\n" );
		}
		$htaccess = $dir . '/.htaccess';
		if ( ! is_file( $htaccess ) ) {
			self::writeAtomic(
				$htaccess,
				"# WP-Slonik: this directory holds internal artifacts only.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\n"
			);
		}
	}

	/**
	 * Create a directory tree inside the content dir (0755) and drop guard
	 * files into the directories WP-Slonik owns. Shared parents (e.g. the
	 * site's `cache/`) are never guarded: other plugins live there.
	 *
	 * @param string $dir Directory path.
	 * @return bool
	 */
	private function ensureDir( $dir ) {
		$root = $this->content_dir;
		$path = $root;
		$rel  = ltrim( str_replace( '\\', '/', (string) substr( (string) $dir, strlen( $root ) ) ), '/' );
		foreach ( explode( '/', $rel ) as $part ) {
			if ( '' === $part ) {
				continue;
			}
			$path .= '/' . $part;
			if ( ! is_dir( $path ) && ! @mkdir( $path, 0755, true ) && ! is_dir( $path ) ) {
				return false;
			}
			$owned = ltrim( str_replace( '\\', '/', substr( $path, strlen( $root ) ) ), '/' );
			if ( 0 === strpos( $owned, 'wpslonik' ) || 0 === strpos( $owned, 'cache/wpslonik' ) ) {
				self::guardDir( $path );
			}
		}
		return is_dir( (string) $dir );
	}

	/**
	 * The drop-in file: marker line inserted after the PHP open tag.
	 * Bytes before `<?php` are emitted as output on every request and
	 * break JSON clients (K-55 PG case 1), so the marker must live
	 * inside the PHP section. Falls back to the prefixed form only when
	 * the template has no open tag on its own first line.
	 *
	 * @param string $version Plugin version for the marker.
	 * @param string $template Loader template.
	 * @return string
	 */
	private static function compose( $version, $template ) {
		$template = (string) $template;
		$marker   = DropinMarker::build( $version, $template );
		if ( 0 === strpos( $template, '<?php' ) ) {
			$nl = strpos( $template, "\n" );
			if ( false !== $nl ) {
				return substr( $template, 0, $nl + 1 ) . $marker . substr( $template, $nl + 1 );
			}
		}
		return $marker . $template;
	}

	/**
	 * @param string $version Plugin version for the marker.
	 * @param bool   $force   Overwrite a foreign drop-in (never a class-B one).
	 * @param bool   $dry_run Report only.
	 * @return array ok, message, class?, backup?
	 */
	public function install( $version, $force, $dry_run ) {
		$db = $this->dbFile();
		if ( ! is_file( $db ) ) {
			if ( $dry_run ) {
				return array( 'ok' => true, 'message' => 'would install drop-in v' . $version );
			}
			if ( ! self::writeAtomic( $db, self::compose( $version, $this->loader_template ) ) ) {
				return array( 'ok' => false, 'message' => 'could not write db.php' );
			}
			$this->ensureDir( $this->content_dir . '/wpslonik' );
			return array( 'ok' => true, 'message' => 'installed drop-in v' . $version );
		}
		$code   = file_get_contents( $db );
		$parsed = DropinMarker::parse( $code );
		if ( null !== $parsed && $parsed['version'] === (string) $version && DropinMarker::verify( $code ) ) {
			return array( 'ok' => true, 'message' => 'drop-in v' . $version . ' already installed' );
		}
		if ( null !== $parsed ) {
			if ( ! $force ) {
				return array( 'ok' => false, 'message' => 'drop-in v' . $parsed['version'] . ' installed; rerun with --force to upgrade' );
			}
			if ( $dry_run ) {
				return array( 'ok' => true, 'message' => 'would upgrade drop-in to v' . $version );
			}
			// Keep a copy of the drop-in we replace (rollback safety).
			$own_backup = $this->content_dir . '/wpslonik/own/db.php.bak';
			$this->ensureDir( dirname( $own_backup ) );
			self::writeAtomic( $own_backup, (string) $code );
			if ( ! self::writeAtomic( $db, self::compose( $version, $this->loader_template ) ) ) {
				return array( 'ok' => false, 'message' => 'could not write db.php' );
			}
			return array( 'ok' => true, 'message' => 'upgraded drop-in to v' . $version );
		}
		$class = Classifier::classify( is_string( $code ) ? $code : '' );
		if ( 'B' === $class ) {
			$name = Classifier::className( is_string( $code ) ? $code : '' );
			return array(
				'ok'      => false,
				'message' => 'db.php (' . ( '' !== $name ? $name : 'unknown class' ) . ') manages its own connection (class B); remove it manually, it cannot be chained',
			);
		}
		if ( ! $force ) {
			return array( 'ok' => false, 'message' => 'foreign db.php (class ' . $class . '); rerun with --force to back it up and install' );
		}
		if ( $dry_run ) {
			return array( 'ok' => true, 'message' => 'would back up foreign db.php (class ' . $class . ') and install v' . $version, 'class' => $class );
		}
		$backup_dir = dirname( $this->backupFile() );
		if ( ! $this->ensureDir( $backup_dir ) ) {
			return array( 'ok' => false, 'message' => 'could not create backup dir' );
		}
		if ( ! self::writeAtomic( $this->backupFile(), (string) $code )
			|| ! self::writeAtomic( $this->backupHashFile(), hash( 'sha256', (string) $code ) )
			|| ! self::writeAtomic( $db, DropinMarker::build( $version, $this->loader_template ) . $this->loader_template ) ) {
			return array( 'ok' => false, 'message' => 'could not write backup or drop-in' );
		}
		$this->ensureDir( $this->content_dir . '/wpslonik' );
		return array(
			'ok'      => true,
			'message' => 'backed up foreign db.php (class ' . $class . '); installed v' . $version . '; run wp slonik chain build to activate chaining',
			'class'   => $class,
			'backup'  => $this->backupFile(),
		);
	}

	/**
	 * Decide what an uninstall may touch (D-11).
	 *
	 * While the site runs on PostgreSQL the drop-in must stay: removing it
	 * would silently fall back to MySQL (the stale copy). A foreign drop-in
	 * is never deleted.
	 *
	 * @param bool $pg_enabled     Site runs in PG mode.
	 * @param bool $dropin_exists  A db.php is present.
	 * @param bool $dropin_is_ours The db.php carries our marker.
	 * @param bool $has_backup     A chained backup exists.
	 * @return array{action:string,reason:string} action keep|restore|remove|none.
	 */
	public static function planUninstall( $pg_enabled, $dropin_exists, $dropin_is_ours, $has_backup ) {
		if ( $pg_enabled ) {
			return array(
				'action' => 'keep',
				'reason' => 'site runs on PostgreSQL (WPSLONIK_ENABLED=true); removing the drop-in would silently fall back to MySQL',
			);
		}
		if ( $has_backup ) {
			return array( 'action' => 'restore', 'reason' => 'chained backup restored' );
		}
		if ( ! $dropin_exists ) {
			return array( 'action' => 'none', 'reason' => 'no drop-in present' );
		}
		if ( ! $dropin_is_ours ) {
			return array( 'action' => 'keep', 'reason' => 'foreign db.php; refusing to delete' );
		}
		return array( 'action' => 'remove', 'reason' => 'Slonik drop-in removed' );
	}

	/**
	 * Uninstall plan for this installation.
	 *
	 * @param bool $pg_enabled Site runs in PG mode.
	 * @return array{action:string,reason:string}
	 */
	public function uninstallPlan( $pg_enabled ) {
		$db     = $this->dbFile();
		$exists = is_file( $db );
		$ours   = false;
		if ( $exists ) {
			$code = file_get_contents( $db );
			$ours = is_string( $code ) && null !== DropinMarker::parse( $code );
		}
		return self::planUninstall( (bool) $pg_enabled, $exists, $ours, is_file( $this->backupFile() ) );
	}

	/**
	 * Remove the artifacts this plugin owns (log dir, chain cache).
	 *
	 * @return array{ok:bool,message:string}
	 */
	public function cleanup() {
		$removed = array();
		foreach ( array( $this->content_dir . '/wpslonik', $this->content_dir . '/cache/wpslonik' ) as $dir ) {
			if ( is_dir( $dir ) && self::rmTree( $dir ) ) {
				$removed[] = $dir;
			}
		}
		return array(
			'ok'      => true,
			'message' => ( array() === $removed ) ? 'no artifacts to remove' : 'removed: ' . implode( ', ', $removed ),
		);
	}

	/**
	 * Recursive delete of a directory tree.
	 *
	 * @param string $dir Directory path.
	 * @return bool
	 */
	private static function rmTree( $dir ) {
		$items = @scandir( (string) $dir );
		if ( ! is_array( $items ) ) {
			return false;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = rtrim( (string) $dir, '/\\' ) . '/' . $item;
			if ( is_dir( $path ) ) {
				self::rmTree( $path );
				continue;
			}
			@unlink( $path );
		}
		return @rmdir( (string) $dir );
	}

	/**
	 * Write a note file for the operator (uninstall cannot show UI).
	 *
	 * @param string $dir  Target directory.
	 * @param string $text Note contents.
	 * @return void
	 */
	public function writeNotice( $dir, $text ) {
		if ( ! is_dir( $dir ) ) {
			@mkdir( $dir, 0755, true );
		}
		self::writeAtomic( rtrim( (string) $dir, '/\\' ) . '/PLUGIN-REMOVED.txt', (string) $text );
	}

	/**
	 * @return array ok, message
	 */
	public function restore() {
		if ( ! is_file( $this->backupFile() ) ) {
			return array( 'ok' => false, 'message' => 'no chained backup to restore' );
		}
		$backup = file_get_contents( $this->backupFile() );
		if ( ! is_string( $backup ) ) {
			return array( 'ok' => false, 'message' => 'chained backup unreadable' );
		}
		$recorded = is_file( $this->backupHashFile() ) ? file_get_contents( $this->backupHashFile() ) : null;
		if ( is_string( $recorded ) && ! hash_equals( trim( $recorded ), hash( 'sha256', $backup ) ) ) {
			return array( 'ok' => false, 'message' => 'chained backup hash mismatch; refusing to restore' );
		}
		if ( ! self::writeAtomic( $this->dbFile(), $backup ) ) {
			return array( 'ok' => false, 'message' => 'could not write db.php' );
		}
		return array( 'ok' => true, 'message' => 'restored original db.php' );
	}

	/**
	 * Remove our own drop-in (marker-verified) or restore the backup.
	 * A foreign db.php is never deleted.
	 *
	 * @return array ok, message
	 */
	public function uninstall() {
		if ( is_file( $this->backupFile() ) ) {
			return $this->restore();
		}
		$db = $this->dbFile();
		if ( ! is_file( $db ) ) {
			return array( 'ok' => true, 'message' => 'no db.php to remove' );
		}
		$code = file_get_contents( $db );
		if ( ! is_string( $code ) || null === DropinMarker::parse( $code ) ) {
			return array( 'ok' => false, 'message' => 'db.php is foreign; refusing to delete (back it up manually)' );
		}
		if ( ! unlink( $db ) ) {
			return array( 'ok' => false, 'message' => 'could not remove db.php' );
		}
		return array( 'ok' => true, 'message' => 'removed Slonik drop-in' );
	}

	/**
	 * Compile the backed-up class-A drop-in against WPSLONIK_DB.
	 *
	 * @param string $version Plugin version (unused today, reserved for cache keying).
	 * @return array ok, message, file?
	 */
	public function build( $version ) {
		unset( $version );
		if ( ! is_file( $this->backupFile() ) ) {
			return array( 'ok' => false, 'message' => 'no chained backup; nothing to build' );
		}
		$code = file_get_contents( $this->backupFile() );
		if ( ! is_string( $code ) ) {
			return array( 'ok' => false, 'message' => 'chained backup unreadable' );
		}
		if ( 'A' !== Classifier::classify( $code ) ) {
			return array( 'ok' => false, 'message' => 'chained db.php is not class A; refusing to compile' );
		}
		$reparented = self::reparent( $code );
		if ( null === $reparented ) {
			return array( 'ok' => false, 'message' => 'no extends wpdb clause found to reparent' );
		}
		$compiled = self::guardCode( $reparented );
		$hash = hash( 'sha256', (string) $code );
		$file = $this->cacheFile( $hash );
		$dir  = dirname( $file );
		if ( ! $this->ensureDir( $dir ) ) {
			return array( 'ok' => false, 'message' => 'could not create chain cache dir' );
		}
		if ( ! self::writeAtomic( $file, $compiled ) ) {
			return array( 'ok' => false, 'message' => 'could not write compiled chain file' );
		}
		return array( 'ok' => true, 'message' => 'compiled class-A chain', 'file' => $file );
	}

	/**
	 * @return array state, class?, backup?, file?
	 */
	public function status() {
		$st = array( 'state' => 'none', 'class' => null, 'backup' => false, 'file' => $this->dbFile() );
		if ( is_file( $this->backupFile() ) ) {
			$st['backup'] = true;
			$code         = file_get_contents( $this->backupFile() );
			$st['class']  = Classifier::classify( is_string( $code ) ? $code : '' );
			$st['state']  = 'chained';
		}
		if ( is_file( $this->dbFile() ) ) {
			$parsed = DropinMarker::parse( file_get_contents( $this->dbFile() ) );
			if ( null !== $parsed ) {
				$st['state'] = 'installed';
			}
		}
		return $st;
	}

	/**
	 * Coherent compiled chain file, or null.
	 *
	 * Coherent = backup + recorded hash + class-A backup + compiled cache
	 * whose bytes equal a fresh reparent. The loader consumes this; all
	 * hash logic lives here.
	 *
	 * @return string|null Cache file path or null.
	 */
	public function compiledFile() {
		if ( ! is_file( $this->backupFile() ) || ! is_file( $this->backupHashFile() ) ) {
			return null;
		}
		$code     = file_get_contents( $this->backupFile() );
		$recorded = file_get_contents( $this->backupHashFile() );
		if ( ! is_string( $code ) || ! is_string( $recorded ) ) {
			return null;
		}
		if ( ! hash_equals( trim( $recorded ), hash( 'sha256', $code ) ) ) {
			return null;
		}
		if ( 'A' !== Classifier::classify( $code ) ) {
			return null;
		}
		$reparented = self::reparent( $code );
		if ( null === $reparented ) {
			return null;
		}
		$compiled = self::guardCode( $reparented );
		$file     = $this->cacheFile( hash( 'sha256', $code ) );
		if ( ! is_file( $file ) ) {
			return null;
		}
		$cached = file_get_contents( $file );
		if ( ! is_string( $cached ) || $cached !== $compiled ) {
			return null;
		}
		return $file;
	}

	/**
	 * Replace the `extends wpdb` clause of the first declared class with
	 * `extends WPSLONIK_DB` (token-based rebuild).
	 *
	 * @param string $code PHP source.
	 * @return string|null Compiled source or null when no clause found.
	 */
	public static function reparent( $code ) {
		$tokens = token_get_all( (string) $code );
		$count  = count( $tokens );
		for ( $i = 0; $i < $count; ++$i ) {
			$token = $tokens[ $i ];
			if ( ! is_array( $token ) || T_CLASS !== $token[0] ) {
				continue;
			}
			$target = -1;
			for ( $j = $i + 1; $j < $count; ++$j ) {
				$inner = $tokens[ $j ];
				if ( ! is_array( $inner ) ) {
					if ( '{' === $inner ) {
						break;
					}
					continue;
				}
				if ( T_EXTENDS === $inner[0] ) {
					for ( $k = $j + 1; $k < $count; ++$k ) {
						$name = $tokens[ $k ];
						if ( ! is_array( $name ) ) {
							continue;
						}
						if ( T_STRING === $name[0] ) {
							if ( 'wpdb' === strtolower( (string) $name[1] ) ) {
								$target = $k;
							}
							break 2;
						}
					}
					break 2;
				}
			}
			if ( $target < 0 ) {
				return null;
			}
			$out = '';
			foreach ( $tokens as $n => $piece ) {
				if ( $n === $target ) {
					$out .= 'WPSLONIK_DB';
					continue;
				}
				$out .= is_array( $piece ) ? $piece[1] : $piece;
			}
			return $out;
		}
		return null;
	}
}
