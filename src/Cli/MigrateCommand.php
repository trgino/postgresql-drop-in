<?php
/**
 * `wp slonik migrate …` entry (docs/15 §3): thin dispatcher over the
 * Runner orchestration. Only usage parsing + exit codes live here.
 *
 * Commands:
 *   wp slonik migrate preflight
 *   wp slonik migrate --dry-run        (also: preflight-style plan)
 *   wp slonik migrate --rehearse      (K-49: one-txn proof, rolled back)
 *   wp slonik migrate run
 *   wp slonik migrate resume           (same path as run)
 *   wp slonik migrate status
 *   wp slonik migrate verify
 *   wp slonik migrate reset-target --yes   (needs DROP_TARGET=true)
 *
 * Exit codes: 0 ok / 1 warnings / 2 blocking / 3 usage / 4 verify mismatch.
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Cli;

use Slonik\Migrate\MigrateConfig;
use Slonik\Migrate\Runner;

final class MigrateCommand {

	const EXIT_NONE   = 0;
	const EXIT_WARN    = 1;
	const EXIT_BLOCKED = 2;
	const EXIT_USAGE   = 3;
	const EXIT_VERIFY  = 4;

	/** WP-CLI entry. */
	public static function entry( $args, $assoc ) {
		exit( self::dispatch( (array) $args, (array) $assoc ) );
	}

	/**
	 * @param array $args  Positional args.
	 * @param array $assoc Assoc args (WP-CLI strips the --).
	 * @return int Exit code.
	 */
	public static function dispatch( array $args, array $assoc ): int {
		$sub = isset( $args[0] ) ? strtolower( (string) $args[0] ) : '';

		if ( '' !== $sub && ! in_array( $sub, array( 'preflight', 'run', 'resume', 'status', 'verify', 'reset-target' ), true ) ) {
			self::usage();
			return self::EXIT_USAGE;
		}

		if ( '' === $sub && empty( $assoc['dry-run'] ) && empty( $assoc['rehearse'] ) ) {
			self::usage();
			return self::EXIT_USAGE;
		}

		try {
			$cfg = MigrateConfig::fromRuntime();
		} catch ( \InvalidArgumentException $e ) {
			echo 'error: ' . $e->getMessage() . ' (set the defines in wp-config.php, docs/15 §2)' . "\n";
			return self::EXIT_USAGE;
		}

		$runner = new Runner( $cfg, function ( $text ) {
			echo (string) $text . "\n";
		} );

		switch ( $sub ) {
			case 'preflight':
				return $runner->preflight( $assoc );
			case 'status':
				return $runner->status( $assoc );
			case 'verify':
				return $runner->verify( $assoc );
			case 'reset-target':
				return $runner->resetTarget( $assoc );
			case 'run':
			case 'resume':
				// Same code path: state rows short-circuit done tables.
				return $runner->run( $assoc );
		}

		// No subcommand: --dry-run or --rehearse (usage path handled above).
		if ( ! empty( $assoc['dry-run'] ) ) {
			return $runner->dryRun( $assoc );
		}
		return $runner->rehearse( $assoc );
	}

	private static function usage() {
		echo 'usage: wp slonik migrate <preflight|run|resume|status|verify|reset-target> [--tables=a,b] [--format=table|json|yaml] [--yes]' . "\n";
		echo '       wp slonik migrate --dry-run | --rehearse' . "\n";
	}
}
