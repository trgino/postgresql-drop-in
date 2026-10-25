<?php
/**
 * In-memory schema catalog (M3a).
 *
 * Loaded from information_schema + pg_constraint per connection (see load()),
 * rebuilt after every DDL batch. COMMENT JSON written by MinDdl is the
 * durable store for original MySQL types (read by the M3b emulator).
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Schema;

use PDO;

final class Catalog {

	/** @var array table-lower => column-lower => stored spelling */
	private $columns = array();

	/** @var array table-lower => bool per column-lower */
	private $nullable = array();

	/** @var array table-lower => pg type per column-lower */
	private $pgtypes = array();

	/** @var array table-lower => identity flag per column-lower */
	private $identity = array();

	/** @var array table-lower => original MySQL type per column-lower */
	private $mysqltypes = array();

	/** @var array table-lower => primary key columns */
	private $primary = array();

	/** @var array table-lower => primary key name */
	private $primary_names = array();

	/** @var array table-lower => list of unique key column lists */
	private $unique = array();

	/** @var array table-lower => list of unique key names (parallel to $unique) */
	private $unique_names = array();

	/** @var string Schema the catalog was loaded from ('' = search path). */
	private $schema = '';

	/**
	 * Cache-invalidation key: changes whenever the catalog contents change
	 * (new connection or DDL reload). Translation plans are keyed by this,
	 * so a stale plan can never outlive the schema it was built against.
	 *
	 * @return string
	 */
	public function cacheKey(): string {
		return hash( 'sha256', $this->schema . "\0" . json_encode( array(
			'columns' => $this->columns,
			'primary' => $this->primary,
			'unique'  => $this->unique,
		) ) );
	}

	/**
	 * @param array $columns Rows: table, column, nullable(bool), default(mixed, informational).
	 * @param array $keys    Rows: table, name, columns(array), primary(bool).
	 * @param string $schema  Schema the rows came from.
	 * @return Catalog
	 */
	public static function fromRows( array $columns, array $keys, $schema = '' ) {
		$self          = new self();
		$self->schema  = (string) $schema;
		foreach ( $columns as $row ) {
			$t = strtolower( (string) $row['table'] );
			$c = strtolower( (string) $row['column'] );
			$self->columns[ $t ][ $c ]  = (string) $row['column'];
			$self->nullable[ $t ][ $c ] = (bool) $row['nullable'];
			if ( isset( $row['pgtype'] ) && '' !== (string) $row['pgtype'] ) {
				$self->pgtypes[ $t ][ $c ] = (string) $row['pgtype'];
			}
			if ( ! empty( $row['identity'] ) ) {
				$self->identity[ $t ][ $c ] = true;
			}
			if ( isset( $row['mysqltype'] ) && '' !== (string) $row['mysqltype'] ) {
				$self->mysqltypes[ $t ][ $c ] = (string) $row['mysqltype'];
			}
		}
		foreach ( $keys as $key ) {
			$t    = strtolower( (string) $key['table'] );
			$cols = array_values( array_map( 'strval', (array) $key['columns'] ) );
			$name = isset( $key['name'] ) ? (string) $key['name'] : '';
			if ( ! empty( $key['primary'] ) ) {
				$self->primary[ $t ]       = $cols;
				$self->primary_names[ $t ] = $name;
				continue;
			}
			$self->unique[ $t ][]       = $cols;
			$self->unique_names[ $t ][] = $name;
		}
		return $self;
	}

	/**
	 * Schema the catalog belongs to ('' = the search path).
	 *
	 * @return string
	 */
	public function schema() {
		return $this->schema;
	}

	/**
	 * Column query for a schema.
	 *
	 * @param string $schema Schema name.
	 * @return string
	 */
	public static function columnsSql( $schema ) {
		$quoted = str_replace( "'", "''", (string) $schema );
		return "SELECT table_name, column_name, is_nullable, data_type, is_identity FROM information_schema.columns WHERE table_schema = '" . $quoted . "'";
	}

	/**
	 * Comment query for a schema.
	 *
	 * @param string $schema Schema name.
	 * @return string
	 */
	public static function commentsSql( $schema ) {
		return "SELECT c.relname AS table_name, a.attname AS column_name, col_description(c.oid, a.attnum) AS comment " .
			"FROM pg_class c JOIN pg_attribute a ON a.attrelid = c.oid " .
			"WHERE c.relnamespace = to_regnamespace('" . str_replace( "'", "''", (string) $schema ) . "') AND a.attnum > 0 AND NOT a.attisdropped";
	}

	/**
	 * Key (primary/unique) query for a schema.
	 *
	 * @param string $schema Schema name.
	 * @return string
	 */
	public static function keysSql( $schema ) {
		return "SELECT c.relname AS table_name, co.conname AS key_name, co.contype AS key_type, a.attname AS column_name " .
			"FROM pg_constraint co JOIN pg_class c ON c.oid = co.conrelid " .
			"JOIN pg_attribute a ON a.attrelid = c.oid AND a.attnum = ANY(co.conkey), " .
			"generate_subscripts(co.conkey, 1) AS s(n) " .
			"WHERE c.relnamespace = to_regnamespace('" . str_replace( "'", "''", (string) $schema ) . "') AND a.attnum = co.conkey[s.n] AND co.contype IN ('p','u') " .
			"ORDER BY c.relname, co.conname, s.n";
	}

	/**
	 * @param PDO    $pdo    Connected PDO.
	 * @param string $schema Schema name (already validated by caller).
	 * @return Catalog
	 */
	public static function load( PDO $pdo, $schema ) {
		$columns = array();
		$stmt    = $pdo->query( self::columnsSql( $schema ) );
		foreach ( $stmt->fetchAll( PDO::FETCH_ASSOC ) as $row ) {
			$columns[] = array(
				'table'    => (string) $row['table_name'],
				'column'   => (string) $row['column_name'],
				'nullable' => 'YES' === strtoupper( (string) $row['is_nullable'] ),
				'default'  => null,
				'pgtype'   => (string) $row['data_type'],
				'identity' => 'YES' === strtoupper( (string) $row['is_identity'] ),
			);
		}
		$stmt = $pdo->query( self::commentsSql( $schema ) );
		$comments = array();
		foreach ( $stmt->fetchAll( PDO::FETCH_ASSOC ) as $row ) {
			$decoded = json_decode( (string) $row['comment'], true );
			if ( is_array( $decoded ) && isset( $decoded['mysql'] ) && is_string( $decoded['mysql'] ) ) {
				$comments[ strtolower( (string) $row['table_name'] ) ][ strtolower( (string) $row['column_name'] ) ] = $decoded['mysql'];
			}
		}
		foreach ( $columns as $i => $col ) {
			$t = strtolower( $col['table'] );
			$c = strtolower( $col['column'] );
			if ( isset( $comments[ $t ][ $c ] ) ) {
				$columns[ $i ]['mysqltype'] = $comments[ $t ][ $c ];
			}
		}
		$keys = array();
		$stmt = $pdo->query( self::keysSql( $schema ) );
		$by_key = array();
		foreach ( $stmt->fetchAll( PDO::FETCH_ASSOC ) as $row ) {
			$id = (string) $row['table_name'] . "\0" . (string) $row['key_name'];
			if ( ! isset( $by_key[ $id ] ) ) {
				$by_key[ $id ] = array(
					'table'   => (string) $row['table_name'],
					'name'    => (string) $row['key_name'],
					'columns' => array(),
					'primary' => 'p' === (string) $row['key_type'],
				);
			}
			$by_key[ $id ]['columns'][] = (string) $row['column_name'];
		}
		return self::fromRows( $columns, array_values( $by_key ), (string) $schema );
	}

	public function canonical( $table, $column ) {
		$t = strtolower( (string) $table );
		$c = strtolower( (string) $column );
		return isset( $this->columns[ $t ][ $c ] ) ? $this->columns[ $t ][ $c ] : null;
	}

	public function primaryKey( $table ) {
		$t = strtolower( (string) $table );
		return isset( $this->primary[ $t ] ) ? $this->primary[ $t ] : array();
	}

	/**
	 * Generated (identity) column of a table, canonical spelling.
	 *
	 * @param string $table Table name.
	 * @return string|null Column name or null when the table has none.
	 */
	public function identityColumn( $table ) {
		$t = strtolower( (string) $table );
		if ( ! isset( $this->identity[ $t ] ) ) {
			return null;
		}
		foreach ( $this->identity[ $t ] as $c => $is_identity ) {
			if ( $is_identity ) {
				return isset( $this->columns[ $t ][ $c ] ) ? $this->columns[ $t ][ $c ] : $c;
			}
		}
		return null;
	}

	public function primaryKeyName( $table ) {
		$t = strtolower( (string) $table );
		return isset( $this->primary_names[ $t ] ) ? $this->primary_names[ $t ] : null;
	}

	public function uniqueKeys( $table ) {
		$t = strtolower( (string) $table );
		return isset( $this->unique[ $t ] ) ? $this->unique[ $t ] : array();
	}

	public function uniqueKeyEntries( $table ) {
		$t       = strtolower( (string) $table );
		$entries = array();
		foreach ( $this->uniqueKeys( $table ) as $i => $cols ) {
			$entries[] = array(
				'name'    => isset( $this->unique_names[ $t ][ $i ] ) ? $this->unique_names[ $t ][ $i ] : '',
				'columns' => $cols,
			);
		}
		return $entries;
	}

	public function isNullable( $table, $column ) {
		$t = strtolower( (string) $table );
		$c = strtolower( (string) $column );
		return isset( $this->nullable[ $t ][ $c ] ) ? $this->nullable[ $t ][ $c ] : null;
	}

	public function nullableAny( $column ) {
		$c     = strtolower( (string) $column );
		$found = false;
		foreach ( $this->nullable as $cols ) {
			if ( ! isset( $cols[ $c ] ) ) {
				continue;
			}
			$found = true;
			if ( $cols[ $c ] ) {
				return true;
			}
		}
		return $found ? false : null;
	}

	public function pgType( $table, $column ) {
		$t = strtolower( (string) $table );
		$c = strtolower( (string) $column );
		return isset( $this->pgtypes[ $t ][ $c ] ) ? $this->pgtypes[ $t ][ $c ] : null;
	}

	public function isIdentity( $table, $column ) {
		$t = strtolower( (string) $table );
		$c = strtolower( (string) $column );
		return isset( $this->identity[ $t ][ $c ] );
	}

	public function mysqlType( $table, $column ) {
		$t = strtolower( (string) $table );
		$c = strtolower( (string) $column );
		return isset( $this->mysqltypes[ $t ][ $c ] ) ? $this->mysqltypes[ $t ][ $c ] : null;
	}

	public function coltype( $lower ) {
		$lower = strtolower( (string) $lower );
		foreach ( $this->pgtypes as $cols ) {
			if ( isset( $cols[ $lower ] ) ) {
				return $cols[ $lower ];
			}
		}
		return null;
	}

	public function columns( $table ) {
		$t = strtolower( (string) $table );
		return isset( $this->columns[ $t ] ) ? array_values( $this->columns[ $t ] ) : array();
	}

	public function colmap() {		$map = array();
		foreach ( $this->columns as $cols ) {
			foreach ( $cols as $lower => $stored ) {
				if ( ! isset( $map[ $lower ] ) ) {
					$map[ $lower ] = $stored;
				}
			}
		}
		return $map;
	}
}
