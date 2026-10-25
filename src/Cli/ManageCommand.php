<?php
/**
 * `wp slonik install-dropin|uninstall-dropin|chain` commands.
 *
 * Entry methods do I/O and exit (live-proven); all branching they
 * contain is covered by Installer unit tests plus the pure helpers
 * below (preflight gate, status rendering).
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Cli;

use Slonik\Chain\Installer;
use Slonik\Health\Checks\ConstantsCheck;
use Slonik\Health\Checks\DropinCheck;
use Slonik\Health\Checks\PhpCheck;
use Slonik\Health\Env;
use Slonik\Health\Result;
use Slonik\Health\Runner;

final class ManageCommand {

	/**
	 * Preflight gate (P-16): any blocker refuses install unless forced.
	 *
	 * @param string[] $statuses Result statuses.
	 * @param bool     $force    --force flag.
	 * @return bool
	 */
	public static function preflightOk( $statuses, $force ) {
		if ( $force ) {
			return true;
		}
		return ! in_array( Result::BLOCK, (array) $statuses, true );
	}

	public static function renderChainStatus( $state ) {
		$state = (array) $state + array(
			'state'  => 'none',
			'class'  => null,
			'backup' => false,
			'file'   => '',
		);
		$out   = 'drop-in: ' . $state['file'] . "\n";
		$out  .= 'state: ' . $state['state'] . "\n";
		$out  .= 'chained class: ' . ( null === $state['class'] ? 'n/a' : $state['class'] ) . "\n";
		$out  .= 'backup: ' . ( $state['backup'] ? 'present' : 'absent' ) . "\n";
		return $out;
	}

	/**
	 * WP-CLI entry: wp slonik install-dropin [--force] [--dry-run].
	 *
	 * @param array $args  Positional args (unused).
	 * @param array $assoc Assoc args.
	 */
	public static function installDropin( $args, $assoc ) {
		$assoc   = (array) $assoc;
		$force   = ! empty( $assoc['force'] );
		$dry_run = ! empty( $assoc['dry-run'] );
		$version = defined( 'WPSLONIK_VERSION' ) ? WPSLONIK_VERSION : 'n/a';
		$env     = Env::detect();
		$pre     = Runner::run( array( new PhpCheck(), new ConstantsCheck(), new DropinCheck( $version ) ), $env );
		$statuses = array();
		foreach ( $pre as $result ) {
			$statuses[] = $result->status;
		}
		if ( ! self::preflightOk( $statuses, $force ) ) {
			echo HealthCommand::renderTable( $pre );
			echo "refusing: blockers present; rerun with --force\n";
			exit( 1 );
		}
		$template_file = dirname( __DIR__, 2 ) . '/dropin/db.php';
		$template      = is_file( $template_file ) ? file_get_contents( $template_file ) : '';
		if ( ! is_string( $template ) || '' === $template ) {
			echo "error: loader template missing\n";
			exit( 1 );
		}
		$installer = new Installer( $env->contentDir(), $template );
		$res       = $installer->install( $version, $force, $dry_run );
		echo $res['message'] . "\n";
		if ( ! $res['ok'] ) {
			exit( 1 );
		}
		echo self::guardHint() . "\n";
		exit( 0 );
	}

	public static function guardHint() {
		return 'hint: add "require: ' . dirname( __DIR__, 2 ) . '/wp-cli-guard.php" to wp-cli.yml to guard wp db *';
	}

	/**
	 * Refusal message for a drop-in removal while PG mode is on.
	 *
	 * Removing the drop-in while the site runs on PostgreSQL would fall
	 * back to MySQL, whose copy is stale (same rule as the migration
	 * tool's reset-target, P-16).
	 *
	 * @param bool $pg_enabled Site runs in PG mode.
	 * @return string Empty when the removal may proceed.
	 */
	public static function uninstallRefusal( $pg_enabled ) {
		if ( ! $pg_enabled ) {
			return '';
		}
		return 'refusing: the site runs on PostgreSQL (WPSLONIK_ENABLED=true); set it to false first — removing the drop-in now would fall back to MySQL';
	}

	/**
	 * WP-CLI entry: wp slonik uninstall-dropin [--yes].
	 *
	 * @param array $args  Positional args (unused).
	 * @param array $assoc Assoc args.
	 */
	public static function uninstallDropin( $args, $assoc ) {
		$assoc = (array) $assoc;
		if ( empty( $assoc['yes'] ) ) {
			echo "refusing: rerun with --yes (does not move data, restores the backed-up db.php if any)\n";
			exit( 1 );
		}
		$pg_enabled = defined( 'WPSLONIK_ENABLED' ) && true === WPSLONIK_ENABLED;
		$refusal    = self::uninstallRefusal( $pg_enabled );
		if ( '' !== $refusal ) {
			echo $refusal . "\n";
			exit( 2 );
		}
		$env       = Env::detect();
		$installer = new Installer( $env->contentDir(), '' );
		$res       = $installer->uninstall();
		echo $res['message'] . "\n";
		exit( $res['ok'] ? 0 : 1 );
	}

	/**
	 * WP-CLI entry: wp slonik chain <build|status|restore>.
	 *
	 * @param array $args  Positional args (subcommand first).
	 * @param array $assoc Assoc args (unused).
	 */
	public static function chain( $args, $assoc ) {
		$args      = array_values( (array) $args );
		$sub       = isset( $args[0] ) ? (string) $args[0] : '';
		$version   = defined( 'WPSLONIK_VERSION' ) ? WPSLONIK_VERSION : 'n/a';
		$installer = new Installer( Env::detect()->contentDir(), '' );
		if ( 'status' === $sub ) {
			echo self::renderChainStatus( $installer->status() );
			exit( 0 );
		}
		if ( 'build' === $sub ) {
			$res = $installer->build( $version );
			echo $res['message'] . "\n";
			exit( $res['ok'] ? 0 : 1 );
		}
		if ( 'restore' === $sub ) {
			$res = $installer->restore();
			echo $res['message'] . "\n";
			exit( $res['ok'] ? 0 : 1 );
		}
		echo "usage: wp slonik chain <build|status|restore>\n";
		exit( 3 );
	}
}
