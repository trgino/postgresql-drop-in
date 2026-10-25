<?php
/**
 * Detects the tables that exist under two spellings in one schema
 * (audit D-82, health check H-C26).
 *
 * PostgreSQL folds the identifiers into one name, but a plugin that quotes
 * its table names can create a second relation whose name differs only in
 * the case. The two relations then hold different data: the site reads one
 * of them and the other one looks empty.
 *
 * The audit lists the relation names of a schema that collide under
 * lower(), together with their size on disk. It only reports; no cleanup
 * is performed.
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Schema;

final class SpellingAudit {

	/**
	 * The query that lists the colliding names as a JSON array.
	 *
	 * @param string $schema Target schema name.
	 * @return string
	 */
	public static function query( $schema ) {
		$schema = str_replace( "'", "''", (string) $schema );
		return "SELECT COALESCE(json_agg(g)::text, '[]') FROM ("
			. " SELECT json_agg(json_build_object('name', c.relname, 'bytes', pg_total_relation_size(c.oid)) ORDER BY c.relname) AS g"
			. ' FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace'
			. " WHERE n.nspname = '" . $schema . "'"
			. " AND c.relkind IN ('r','p')"
			. ' GROUP BY lower(c.relname) HAVING count(*) > 1'
			. ' ) d';
	}

	/**
	 * Normalizes the JSON of the query into the groups.
	 *
	 * @param string $json The JSON text of the query result.
	 * @return array<int, array<int, array{name: string, bytes: int}>>
	 */
	public static function groups( $json ) {
		$decoded = json_decode( (string) $json, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}
		$groups = array();
		foreach ( $decoded as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}
			$entries = array();
			foreach ( $group as $entry ) {
				if ( ! is_array( $entry ) || ! isset( $entry['name'] ) ) {
					continue;
				}
				$entries[] = array(
					'name'  => (string) $entry['name'],
					'bytes' => isset( $entry['bytes'] ) ? (int) $entry['bytes'] : 0,
				);
			}
			if ( count( $entries ) < 2 ) {
				continue;
			}
			usort( $entries, array( self::class, 'compareEntries' ) );
			$groups[] = $entries;
		}
		usort( $groups, array( self::class, 'compareGroups' ) );
		return $groups;
	}

	/**
	 * One line per group: the names with their size on disk.
	 *
	 * @param array $groups The groups of groups().
	 * @return string[]
	 */
	public static function lines( $groups ) {
		$lines = array();
		foreach ( (array) $groups as $group ) {
			$parts = array();
			foreach ( (array) $group as $entry ) {
				$parts[] = (string) $entry['name'] . ' (' . (int) $entry['bytes'] . ' B)';
			}
			if ( array() !== $parts ) {
				$lines[] = implode( ' | ', $parts );
			}
		}
		return $lines;
	}

	/**
	 * @param array $a
	 * @param array $b
	 * @return int
	 */
	private static function compareEntries( $a, $b ) {
		return strcmp( (string) $a['name'], (string) $b['name'] );
	}

	/**
	 * @param array $a
	 * @param array $b
	 * @return int
	 */
	private static function compareGroups( $a, $b ) {
		return strcmp( (string) $a[0]['name'], (string) $b[0]['name'] );
	}
}
