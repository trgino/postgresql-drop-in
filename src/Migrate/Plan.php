<?php
/**
 * Pure migration plan builder (docs/15 §4): inventory rows in,
 * planned tables + warnings + blockers out. No DB access here.
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Migrate;

final class Plan {

	/**
	 * Charsets that are already UTF-8 (utf8mb3 is the old name of the
	 * 3-byte variant, utf8 the old name of both).
	 *
	 * @var array<string,true>
	 */
	const UTF8_CHARSETS = array(
		'utf8mb4' => true,
		'utf8mb3' => true,
		'utf8'    => true,
	);

	/**
	 * The charset of a collation (utf8mb4_0900_ai_ci -> utf8mb4).
	 *
	 * @param string $collation Collation name.
	 * @return string Charset name, or '' when unknown.
	 */
	public static function charsetOf( string $collation ): string {
		$collation = trim( $collation );
		if ( '' === $collation ) {
			return '';
		}
		$pos = strpos( $collation, '_' );
		return false === $pos ? strtolower( $collation ) : strtolower( substr( $collation, 0, $pos ) );
	}

	/**
	 * Is the charset already UTF-8?
	 *
	 * @param string $charset Charset name.
	 * @return bool
	 */
	public static function isUtf8Charset( string $charset ): bool {
		return isset( self::UTF8_CHARSETS[ strtolower( trim( $charset ) ) ] );
	}

	/**
	 * Build the migration plan.
	 *
	 * @param array<int,array> $inventory     information_schema tables rows
	 *                                        (table_name/engine/table_type/table_rows/data_length,
	 *                                        optionally pk_present).
	 * @param string           $prefix        wp table prefix.
	 * @param array<int,string>$include      Extra tables beyond the prefix.
	 * @param array<int,string>$exclude      Excluded table names.
	 * @param array<string,array>$state      Existing wpslonik_migration rows keyed by table.
	 * @param array<int,array> $anomalies     Views/triggers/routines/events/FK reports.
	 * @param array<string,true>$target_dots  Table names already present in the target schema.
	 * @return array{tables:array<int,array>,warnings:array<int,string>,blockers:array<int,string>,excluded:array<int,string>}
	 */
	public static function build(
		array $inventory,
		string $prefix,
		array $include,
		array $exclude,
		array $state,
		array $anomalies,
		array $target_dots
	): array {
		$planned     = array();
		$warnings    = array();
		$excluded    = array();
		$include_map = array_fill_keys( array_values( $include ), true );

		foreach ( (array) $inventory as $row ) {
			$name = (string) ( $row['table_name'] ?? '' );
			if ( '' === $name ) {
				continue;
			}
			if ( in_array( $name, (array) $exclude, true ) ) {
				$excluded[] = $name;
				$planned[]  = self::planned( $row, $state, 'excluded', 'excluded by WPSLONIK_MIGRATE_EXCLUDE_TABLES' );
				continue;
			}
			if ( 'VIEW' === (string) ( $row['table_type'] ?? '' ) ) {
				$warnings[] = 'view skipped: ' . $name;
				continue;
			}
			$note = '';
			if ( 'MyISAM' === (string) ( $row['engine'] ?? '' ) ) {
				$warnings[] = 'MyISAM table: ' . $name . ' (snapshot consistency relies on maintenance mode)';
			}
			$charset = self::charsetOf( (string) ( $row['table_collation'] ?? '' ) );
			if ( '' !== $charset && ! self::isUtf8Charset( $charset ) ) {
				$warnings[] = 'table charset is ' . $charset . ': ' . $name . ' (the copy converts it to UTF-8)';
			}
			if ( isset( $include_map[ $name ] ) ) {
				$note = 'explicitly included';
			} elseif ( 0 !== strpos( $name, $prefix ) ) {
				continue; // Neither prefix nor include: not our business.
			}
			$planned[] = self::planned( $row, $state, 'planned', $note );
		}

		foreach ( (array) $anomalies as $anom ) {
			$kind = (string) ( $anom['kind'] ?? '' );
			$name = (string) ( $anom['name'] ?? '' );
			$tab  = (string) ( $anom['table'] ?? '' );
			if ( '' === $name ) {
				continue;
			}
			if ( 'published_views' === $kind || 'views' === $kind ) {
				continue; // Already surfaced per-row above.
			}
			$warnings[] = $kind . ' skipped (reported, not migrated): ' . $name . ( '' !== $tab ? ' (on ' . $tab . ')' : '' );
		}

		foreach ( (array) $planned as $i => $row ) {
			$name = (string) $row['name'];
			if ( in_array( (string) $row['state'], array( 'done', 'excluded', 'blocked' ), true ) ) {
				continue;
			}
			if ( isset( $target_dots[ $name ] ) && ! isset( $state[ $name ]['status'] ) ) {
				$planned[ $i ]['state'] = 'blocked';
				$planned[ $i ]['note']  = 'target table exists but no migration state (reset-target or a clean schema first)';
				continue;
			}
			if ( isset( $target_dots[ $name ] ) && isset( $state[ $name ]['status'] ) && 'done' !== $state[ $name ]['status'] ) {
				// Interrupted copy: PK tables resume from the committed
				// watermark; keyless tables truncate + restart (docs/15 §5.3).
				$planned[ $i ]['note'] = 'resuming interrupted copy from committed state';
			}
		}

		$blockers = array();
		foreach ( $planned as $row ) {
			if ( 'blocked' === $row['state'] ) {
				$blockers[] = (string) $row['note'];
			}
		}

		return array(
			'tables'   => $planned,
			'warnings' => $warnings,
			'blockers' => $blockers,
			'excluded' => $excluded,
		);
	}

	/**
	 * Keep only the tables of the --tables subset.
	 *
	 * A name that is not in the plan is reported: dropping it silently
	 * would migrate less than the operator asked for.
	 *
	 * @param array    $plan   The built plan.
	 * @param string[] $subset Table names (already split).
	 * @return array The plan with the subset applied.
	 */
	public static function filter( array $plan, array $subset ): array {
		if ( array() === $subset ) {
			return $plan;
		}
		$rows  = array();
		$found = array();
		foreach ( (array) $plan['tables'] as $row ) {
			$name = (string) $row['name'];
			if ( ! in_array( $name, $subset, true ) ) {
				continue;
			}
			$rows[]  = $row;
			$found[] = $name;
		}
		foreach ( $subset as $name ) {
			if ( ! in_array( (string) $name, $found, true ) ) {
				$plan['warnings'][] = 'unknown table in --tables: ' . (string) $name;
			}
		}
		$plan['tables'] = $rows;
		return $plan;
	}

	private static function planned( array $row, array $state, string $state_out, string $note = '' ): array {		$name = (string) ( $row['table_name'] ?? '' );
		$st   = isset( $state[ $name ]['status'] ) ? (string) $state[ $name ]['status'] : 'new';
		if ( 'excluded' === $state_out ) {
			$st = 'excluded';
		}
		return array(
			'name'       => $name,
			'engine'     => (string) ( $row['engine'] ?? '' ),
			'rows'       => (int) ( $row['table_rows'] ?? 0 ),
			'pk'         => (bool) ( $row['pk'] ?? false ),
			'state'      => $st,
			'note'       => $note,
			'rows_done'  => (int) ( $state[ $name ]['rows_done'] ?? 0 ),
		);
	}
}
