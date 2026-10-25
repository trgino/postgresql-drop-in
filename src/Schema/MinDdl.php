<?php
/**
 * Minimal CREATE TABLE translation (M2a thin slice).
 *
 * M2a simplifications (hardened in M3): UNSIGNED dropped without CHECK,
 * ON UPDATE dropped without trigger, ENUM widths dropped, no catalog.
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Schema;

use Slonik\Sql\MysqlParser;
use Slonik\Sql\PgQuote;
use Slonik\Sql\TranslationException;
use WP_MySQL_Lexer;

final class MinDdl {

	private static $int_types = array(
		'BIGINT'    => 'bigint',
		'INT'       => 'integer',
		'INTEGER'   => 'integer',
		'MEDIUMINT' => 'integer',
		'SMALLINT'  => 'smallint',
		'TINYINT'   => 'smallint',
		'YEAR'      => 'smallint',
	);

	private static $word_types = array(
		'DATETIME'   => 'timestamp(0) without time zone',
		'TIMESTAMP'  => 'timestamp(0) without time zone',
		'DATE'       => 'date',
		'TIME'       => 'time',
		'TINYTEXT'   => 'text',
		'TEXT'       => 'text',
		'MEDIUMTEXT' => 'text',
		'LONGTEXT'   => 'text',
		'TINYBLOB'   => 'bytea',
		'BLOB'       => 'bytea',
		'MEDIUMBLOB' => 'bytea',
		'LONGBLOB'   => 'bytea',
		'VARBINARY'  => 'bytea',
		'BINARY'     => 'bytea',
		'JSON'       => 'jsonb',
		'FLOAT'      => 'real',
		'DOUBLE'     => 'double precision',
		'BIT'        => 'bit',
	);

	/**
	 * DROP TABLE [IF EXISTS] -> the PostgreSQL statement.
	 *
	 * The plugin uninstall paths use it (contact-form-7, WooCommerce);
	 * the name is quoted like every other identifier.
	 *
	 * @param \WP_Parser_Node $stmt drop_table_stmt node.
	 * @return string
	 * @throws TranslationException When the statement has no table name.
	 */
	private static function drop_table( $stmt ): string {
		$names = array();
		foreach ( $stmt->get_descendant_nodes( 'table_ident' ) as $ident ) {
			$token = $ident->get_first_descendant_token();
			if ( null === $token ) {
				continue;
			}
			$names[] = '"' . str_replace( '"', '""', (string) $token->get_value() ) . '"';
		}
		if ( array() === $names ) {
			throw new TranslationException( 'DROP TABLE without a table name.' );
		}
		$if_exists = null !== $stmt->get_first_descendant_node( 'if_exists' ) ? 'IF EXISTS ' : '';
		return 'DROP TABLE ' . $if_exists . implode( ', ', $names );
	}

	/**
	 * TRUNCATE TABLE -> the PostgreSQL statement.
	 *
	 * @param \WP_Parser_Node $stmt truncate_stmt node.
	 * @return string
	 * @throws TranslationException When the statement has no table name.
	 */
	private static function truncate_table( $stmt ): string {
		$ident = $stmt->get_first_descendant_node( 'table_ident' );
		$token = null !== $ident ? $ident->get_first_descendant_token() : null;
		if ( null === $token ) {
			throw new TranslationException( 'TRUNCATE without a table name.' );
		}
		return 'TRUNCATE TABLE "' . str_replace( '"', '""', (string) $token->get_value() ) . '"';
	}

	/**
	 * @param string $mysql CREATE TABLE statement.
	 * @return string[] CREATE TABLE + CREATE INDEX statements.
	 * @throws TranslationException When out of scope.
	 */
	public static function translate( $mysql, array $options = array() ) {
		$ci_mode = isset( $options['ci_mode'] ) ? (string) $options['ci_mode'] : '';
		$ast = MysqlParser::parse( $mysql );
		if ( null === $ast ) {
			throw new TranslationException( 'Unparseable statement.' );
		}
		$simple = $ast->get_first_descendant_node( 'simple_statement' );
		$stmt   = null !== $simple ? $simple->get_first_child_node() : null;
		if ( null === $stmt ) {
			throw new TranslationException( 'Not a DDL statement.' );
		}
		// A NUL byte in a column default is checked on the parsed token
		// (the MySQL escape "\0" is decoded by the parser), see
		// column_def() - audit D-68.
		if ( 'drop_table_stmt' === $stmt->rule_name ) {
			return array( self::drop_table( $stmt ) );
		}
		if ( 'truncate_stmt' === $stmt->rule_name ) {
			return array( self::truncate_table( $stmt ) );
		}
		if ( 'create_table_stmt' !== $stmt->rule_name ) {
			throw new TranslationException( 'Not a CREATE TABLE statement.' );
		}
		$table_tok = $stmt->get_first_descendant_node( 'table_ident' );
		$table_tok = null !== $table_tok ? $table_tok->get_first_descendant_token() : null;
		if ( null === $table_tok ) {
			throw new TranslationException( 'CREATE TABLE without table name.' );
		}
		// CREATE TABLE ... AS SELECT / ... LIKE have no column list: the
		// header must not be rebuilt into an empty table (audit D-06).
		if ( null !== $stmt->get_first_descendant_node( 'as_create_query_expression' ) ) {
			throw TranslationException::unsupported( 'CREATE TABLE AS SELECT', 'CREATE TABLE ... AS SELECT is not supported.' );
		}
		foreach ( $stmt->get_descendant_tokens() as $token ) {
			if ( 'LIKE' === strtoupper( (string) $token->get_value() ) ) {
				throw TranslationException::unsupported( 'CREATE TABLE LIKE', 'CREATE TABLE ... LIKE is not supported.' );
			}
		}
		if ( null === $stmt->get_first_descendant_node( 'table_element_list' ) ) {
			throw new TranslationException( 'CREATE TABLE without a column list is not supported.' );
		}
		$header = 'CREATE TABLE ';
		if ( null !== $stmt->get_first_descendant_node( 'opt_temporary' ) ) {
			$header = 'CREATE TEMPORARY TABLE ';
		}
		if ( null !== $stmt->get_first_descendant_node( 'opt_if_not_exists' ) ) {
			$header .= 'IF NOT EXISTS ';
		}
		$table = '"' . str_replace( '"', '""', $table_tok->get_value() ) . '"';
		$bare  = (string) $table_tok->get_value();
		$cols  = array();
		$extra = array();
		$types = array();
		$key_n = 0;
		$on_update = array();
		foreach ( $stmt->get_descendant_nodes( 'table_element' ) as $element ) {
			$first = $element->get_first_child_node();
			$name  = null !== $first ? $first->rule_name : '';
			if ( 'column_def' === $name ) {
				$cols[] = self::column_def( $mysql, $element, $on_update, $ci_mode, $bare );
				$types[] = self::column_type( $mysql, $element );
				continue;
			}
			if ( 'table_constraint_def' === $name ) {
				$head = self::first_word( $element );
				if ( 'PRIMARY' === $head ) {
					$cols[] = 'PRIMARY KEY (' . self::key_columns( $element ) . ')';
					continue;
				}
				if ( 'UNIQUE' === $head ) {
					// Keep the MySQL key name: PostgreSQL names the index
					// after the constraint, and dbDelta compares names
					// (audit D-45).
					$key_name = self::constraint_name( $element );
					if ( '' !== $key_name ) {
						$cols[] = 'CONSTRAINT "' . str_replace( '"', '""', $key_name ) . '" UNIQUE (' . self::key_columns( $element ) . ')';
						continue;
					}
					$cols[] = 'UNIQUE (' . self::key_columns( $element ) . ')';
					continue;
				}
				if ( 'FOREIGN' === $head ) {
					throw new TranslationException( 'FOREIGN KEY is out of scope for M2a.' );
				}
				if ( 'CONSTRAINT' === $head || 'CHECK' === $head ) {
					throw new TranslationException( $head . ' constraint prefix is out of scope for M2a.' );
				}
				if ( 'FULLTEXT' === $head || 'SPATIAL' === $head ) {
					throw new TranslationException( $head . ' index is not supported (NG-8).' );
				}
				++$key_n;
				$key_name = self::constraint_name( $element );
				if ( '' === $key_name ) {
					$key_name = 'key_' . $key_n;
				}
				$extra[] = 'CREATE INDEX IF NOT EXISTS "' . self::index_name( self::bare( $table ) . '__' . $key_name ) . '" ON ' . $table . ' (' . self::key_columns( $element ) . ')';
				continue;
			}
			throw new TranslationException( 'Unknown table element.' );
		}
		return array_merge(
			array( $header . $table . ' (' . implode( ', ', $cols ) . ')' ),
			$extra,
			self::column_comments( $table, $cols, $types ),
			self::on_update_triggers( $table, $on_update )
		);
	}

	/**
	 * Trigger statements for columns declared ON UPDATE CURRENT_TIMESTAMP.
	 *
	 * WordPress core keeps `post_modified`/`comment_date` in sync with
	 * ON UPDATE; PostgreSQL needs a trigger for that (K-43). The column
	 * clause itself is dropped (the trigger does the work).
	 *
	 * @param string $table      Quoted table name.
	 * @param array  $on_update  Column names (plain) with ON UPDATE.
	 * @return array Statements.
	 */
	private static function on_update_triggers( $table, array $on_update ) {
		$out = array();
		foreach ( (array) $on_update as $column ) {
			$column = (string) $column;
			$base   = self::bare( $table ) . '_' . $column;
			$fn     = 'wpslonik_touch_' . preg_replace( '/[^A-Za-z0-9_]/', '_', $base );
			$trg    = self::index_name( self::bare( $table ) . '__' . $column . '_update' );
			$out[]  = 'CREATE OR REPLACE FUNCTION "' . $fn . '"() RETURNS trigger LANGUAGE plpgsql AS $fn$ BEGIN NEW."' . str_replace( '"', '""', $column ) . '" := NOW(); RETURN NEW; END $fn$';
			$out[]  = 'CREATE OR REPLACE TRIGGER "' . $trg . '" BEFORE UPDATE ON ' . $table . ' FOR EACH ROW EXECUTE FUNCTION "' . $fn . '"()';
		}
		return $out;
	}

	/**
	 * Index/constraint name within PostgreSQL's 63 byte limit.
	 *
	 * @param string $name Proposed name.
	 * @return string
	 */
	private static function index_name( $name ) {
		$name = (string) $name;
		if ( strlen( $name ) <= 63 ) {
			return $name;
		}
		return substr( $name, 0, 54 ) . '_' . substr( sha1( $name ), 0, 8 );
	}

	private static function column_comments( $table, $cols, $types ) {
		$out = array();
		foreach ( $cols as $i => $col ) {
			if ( ! isset( $types[ $i ] ) || '' === $types[ $i ][1] ) {
				continue;
			}
			$out[] = 'COMMENT ON COLUMN ' . $table . '.' . $types[ $i ][0] . ' IS ' . PgQuote::comment_json( array( 'mysql' => $types[ $i ][1] ) );
		}
		return $out;
	}

	private static function bare( $quoted ) {
		return str_replace( '""', '"', substr( $quoted, 1, -1 ) );
	}

	private static function column_type( $mysql, $element ) {
		$name  = null;
		$first = null;
		$last  = null;
		$stop  = array(
			'NOT' => 1, 'NULL' => 1, 'DEFAULT' => 1, 'AUTO_INCREMENT' => 1, 'GENERATED' => 1,
			'UNIQUE' => 1, 'PRIMARY' => 1, 'KEY' => 1, 'CHECK' => 1, 'CONSTRAINT' => 1,
			'COMMENT' => 1, 'COLLATE' => 1, 'CHARACTER' => 1, 'REFERENCES' => 1, 'ON' => 1,
			'VISIBLE' => 1, 'INVISIBLE' => 1, 'ALWAYS' => 1, 'AS' => 1, 'STORED' => 1, 'VIRTUAL' => 1,
		);
		foreach ( $element->get_descendant_tokens() as $token ) {
			if ( ! $token instanceof \WP_MySQL_Token ) {
				continue;
			}
			$raw = self::raw( $token );
			if ( null === $name && ( WP_MySQL_Lexer::BACK_TICK_QUOTED_ID === $token->id || self::starts_letter( $raw ) ) ) {
				$name = '"' . str_replace( '"', '""', $token->get_value() ) . '"';
				continue;
			}
			if ( null === $name ) {
				continue;
			}
			if ( isset( $stop[ strtoupper( $token->get_value() ) ] ) ) {
				break;
			}
			if ( null === $first ) {
				$first = $token;
			}
			$last = $token;
		}
		if ( null === $name || null === $first ) {
			return array( '"?"', '' );
		}
		return array( $name, substr( $mysql, $first->start, $last->start + $last->length - $first->start ) );
	}

	private static function starts_letter( $raw ) {
		return '' !== $raw && ( ( $raw[0] >= 'A' && $raw[0] <= 'Z' ) || ( $raw[0] >= 'a' && $raw[0] <= 'z' ) || '_' === $raw[0] );
	}

	private static function first_word( $element ) {		foreach ( $element->get_descendant_tokens() as $token ) {
			if ( ! $token instanceof \WP_MySQL_Token ) {
				continue;
			}
			$raw = self::raw( $token );
			if ( '' !== $raw && self::starts_letter( $raw ) ) {
				return strtoupper( $token->get_value() );
			}
		}
		return '';
	}

	//
	// Public surface for MinAlter (D-79): the ALTER ADD/KEY/DROP/DEFAULT
	// paths reuse the CREATE TABLE machinery instead of a second emitter.
	//

	/**
	 * PostgreSQL fragment for one column definition (CREATE TABLE body).
	 *
	 * The ADD COLUMN path runs it on a mini-parsed statement whose element
	 * carries the column name and the type, so the overrides and the
	 * attribute rewriting stay identical to the migration's.
	 *
	 * @param string $mysql     The statement text the element came from.
	 * @param object $element   The column element node.
	 * @param array  $on_update Collector for ON UPDATE column names (plain).
	 * @param string $ci_mode   Case-insensitivity mode.
	 * @param string $table     Bare table name (per-table overrides).
	 * @return string
	 */
	public static function column_sql( $mysql, $element, array &$on_update = array(), $ci_mode = '', $table = '' ) {
		return self::column_def( $mysql, $element, $on_update, $ci_mode, $table );
	}

	/**
	 * Array of the quoted column name and the MySQL type span.
	 *
	 * Exposed for MinAlter (D-79) comment steps.
	 *
	 * @return array{0:string,1:string}
	 */
	public static function column_type_label( $mysql, $element ) {
		return self::column_type( $mysql, $element );
	}

	/**
	 * COMMENT ON COLUMN statements (create-table convention).
	 *
	 * @return array<string>
	 */
	public static function comment_steps( $table, array $cols, array $types ) {
		return self::column_comments( $table, $cols, $types );
	}

	/**
	 * ON UPDATE CURRENT_TIMESTAMP trigger statements (K-43).
	 *
	 * @return array<string>
	 */
	public static function touch_steps( $table, array $on_update ) {
		return self::on_update_triggers( $table, $on_update );
	}

	/**
	 * The named key of a table constraint (or '' when anonymous).
	 */
	public static function key_name( $element ) {
		return self::constraint_name( $element );
	}

	/**
	 * The quoted key column list of a table constraint.
	 */
	public static function key_cols( $element ) {
		return self::key_columns( $element );
	}

	/**
	 * The first keyword of a grammar element, uppercased.
	 */
	public static function head_word( $element ) {
		return self::first_word( $element );
	}

	/**
	 * One CREATE [UNIQUE] INDEX IF NOT EXISTS statement with the
	 * CREATE TABLE naming convention ("table__name", capped at 63 bytes).
	 *
	 * @param string $quoted_table Quoted table name.
	 * @param string $key_name     The index name as MySQL wrote it.
	 * @param string $cols_sql     Quoted, comma-separated key columns.
	 * @param bool   $unique       Unique index (ADD UNIQUE KEY).
	 * @return string
	 */
	public static function index_step( $quoted_table, $key_name, $cols_sql, $unique = false ) {
		$name = self::index_name( self::bare( $quoted_table ) . '__' . (string) $key_name );
		$head = ( $unique ? 'CREATE UNIQUE INDEX ' : 'CREATE INDEX ' ) . 'IF NOT EXISTS ';
		return $head . '"' . str_replace( '"', '""', $name ) . '" ON ' . (string) $quoted_table . ' (' . (string) $cols_sql . ')';
	}

	private static function raw( $token ) {
		return $token->get_bytes();
	}

	private static function constraint_name( $element ) {
		$toks = $element->get_descendant_tokens();
		foreach ( $toks as $i => $token ) {
			if ( ! $token instanceof \WP_MySQL_Token ) {
				continue;
			}
			$upper = strtoupper( $token->get_value() );
			if ( in_array( $upper, array( 'KEY', 'INDEX', 'UNIQUE' ), true ) ) {
				$next = isset( $toks[ $i + 1 ] ) ? $toks[ $i + 1 ] : null;
				// "UNIQUE KEY name" / "UNIQUE INDEX name": the name follows
				// the second word.
				if ( null !== $next && $next instanceof \WP_MySQL_Token && in_array( strtoupper( $next->get_value() ), array( 'KEY', 'INDEX' ), true ) ) {
					$next = isset( $toks[ $i + 2 ] ) ? $toks[ $i + 2 ] : null;
				}
				if ( $next instanceof \WP_MySQL_Token && WP_MySQL_Lexer::BACK_TICK_QUOTED_ID === $next->id ) {
					return $next->get_value();
				}
				if ( $next instanceof \WP_MySQL_Token && self::looks_like_bare_ident( $next ) ) {
					return $next->get_value();
				}
				return '';
			}
		}
		return '';
	}

	private static function looks_like_bare_ident( $token ) {
		$upper = strtoupper( $token->get_value() );
		return ! in_array( $upper, array( 'PRIMARY', 'FOREIGN', 'FULLTEXT', 'SPATIAL', 'KEY', 'INDEX', 'UNIQUE', 'CHECK', 'CONSTRAINT' ), true );
	}

	private static function key_columns( $element ) {
		$cols       = array();
		$skip_depth = 0;
		foreach ( $element->get_descendant_tokens() as $token ) {
			if ( ! $token instanceof \WP_MySQL_Token ) {
				continue;
			}
			$raw = self::raw( $token );
			if ( '(' === $raw ) {
				++$skip_depth;
				continue;
			}
			if ( ')' === $raw ) {
				$skip_depth = $skip_depth > 0 ? $skip_depth - 1 : 0;
				continue;
			}
			if ( $skip_depth > 1 ) {
				continue;
			}
			if ( 1 === $skip_depth && ctype_digit( $token->get_value() ) ) {
				continue;
			}
			if ( 0 === $skip_depth ) {
				continue;
			}
			if ( WP_MySQL_Lexer::BACK_TICK_QUOTED_ID === $token->id ) {
				$cols[] = '"' . str_replace( '"', '""', $token->get_value() ) . '"';
				continue;
			}
			$upper = strtoupper( $token->get_value() );
			if ( in_array( $upper, array( 'PRIMARY', 'KEY', 'INDEX', 'UNIQUE', 'FULLTEXT', 'SPATIAL', 'FOREIGN', 'ASC', 'DESC' ), true ) ) {
				continue;
			}
			if ( preg_match( '/^[A-Za-z_][A-Za-z0-9_$]*$/', $token->get_value() ) ) {
				$cols[] = '"' . $token->get_value() . '"';
			}
		}
		return implode( ', ', $cols );
	}

	private static function column_def( $mysql, $element, array &$on_update = array(), $ci_mode = '', $table = '' ) {
		$citext = ( CiMode::CITEXT === $ci_mode );
		$out        = '';
		$pos        = $element->get_start();
		$skip_paren = 0;
		$skip_enum  = false;
		$drop_width = false;
		$skip_next  = 0;
		$seen_name  = false;
		$col_name   = '';
		$int_type   = '';
		$int_type_at = -1;
		$unsigned   = false;
		$pg_type    = '';
		foreach ( $element->get_descendant_tokens() as $token ) {
			if ( ! $token instanceof \WP_MySQL_Token ) {
				continue;
			}
			$raw = self::raw( $token );
			$gap = substr( $mysql, $pos, $token->start - $pos );
			$pos = $token->start + $token->length;
			if ( 0 < $skip_next ) {
				--$skip_next;
				continue;
			}
			if ( '(' === $raw || ')' === $raw ) {
				if ( $skip_enum || $drop_width ) {
					if ( '(' === $raw ) {
						++$skip_paren;
					} else {
						--$skip_paren;
						if ( $skip_paren <= 0 ) {
							$skip_paren = 0;
							$skip_enum  = false;
							$drop_width = false;
						}
					}
					continue;
				}
				$out .= $gap . $raw;
				continue;
			}
			if ( $skip_paren > 0 ) {
				continue;
			}
			$upper = strtoupper( $token->get_value() );
			if ( ! $seen_name && ( WP_MySQL_Lexer::BACK_TICK_QUOTED_ID === $token->id || self::starts_letter( $raw ) ) ) {
				$out      .= $gap . '"' . str_replace( '"', '""', $token->get_value() ) . '"';
				$col_name  = (string) $token->get_value();
				$seen_name = true;
				continue;
			}
			if ( $seen_name && '' === $pg_type ) {
				// A column-level override wins over the generic type
				// mapping: the column's payload needs a type the MySQL
				// type does not describe (audit D-74).
				$override = ColumnOverrides::pgType( $table, $col_name );
				if ( null !== $override ) {
					$out        .= $gap . $override;
					$pg_type     = $override;
					$drop_width  = true;
					continue;
				}
			}
			if ( WP_MySQL_Lexer::BACK_TICK_QUOTED_ID === $token->id ) {
				$out .= $gap . '"' . str_replace( '"', '""', $token->get_value() ) . '"';
				continue;
			}
			if ( self::is_string_token( $token ) ) {
				$value = $token->get_value();
				if ( 0 === strpos( $value, '0000-00-00' ) ) {
					$value = '0001-01-01' . substr( $value, 10 );
				}
				if ( false !== strpos( $value, "\0" ) ) {
					// The MySQL escape "\0" is decoded by the parser, so a
					// binary default carries its bytes. PostgreSQL's bytea
					// input expects the hex format ("\x…"); the raw bytes
					// would end the literal ("unterminated quoted string",
					// audit D-68). A text column cannot store a NUL byte.
					if ( 'bytea' !== $pg_type ) {
						throw TranslationException::unsupported(
							'NUL byte in a column default',
							'A NUL byte in a text column default cannot be stored by PostgreSQL.'
						);
					}
					$value = '\x' . bin2hex( $value );
				}
				$out .= $gap . PgQuote::string( $value );
				continue;
			}
			if ( $citext && in_array( $upper, array( 'VARCHAR', 'CHAR', 'CHARACTER', 'NVARCHAR', 'NCHAR' ), true ) ) {
				// citext has no length: the parentheses are dropped.
				$out .= $gap . 'citext';
				$drop_width = true;
				continue;
			}
			if ( isset( self::$int_types[ $upper ] ) ) {
				$out         .= $gap . self::$int_types[ $upper ];
				$int_type_at  = strlen( $out ) - strlen( self::$int_types[ $upper ] );
				$int_type     = self::$int_types[ $upper ];
				$drop_width   = true;
				continue;
			}
			if ( isset( self::$word_types[ $upper ] ) ) {
				$out     .= $gap . self::$word_types[ $upper ];
				$pg_type  = self::$word_types[ $upper ];
				// The PostgreSQL type carries its own width ("timestamp(0)
				// without time zone", "bytea"): the MySQL width is dropped
				// ("bytea(124)" is a syntax error, audit D-66).
				$drop_width = true;
				continue;
			}
			if ( 'ZEROFILL' === $upper ) {
				// Display attribute only; PostgreSQL has no equivalent.
				continue;
			}
			if ( 'ENUM' === $upper || 'SET' === $upper ) {
				$out      .= $gap . 'text';
				$skip_enum = true;
				continue;
			}
			if ( 'UNSIGNED' === $upper ) {
				// K-48: unsigned integers widen to bigint; a bigint
				// unsigned column gets a range CHECK (audit D-46).
				if ( 'integer' === $int_type && $int_type_at >= 0 ) {
					$out = substr( $out, 0, $int_type_at ) . 'bigint' . substr( $out, $int_type_at + strlen( $int_type ) );
					$int_type = 'bigint';
				}
				$unsigned = true;
				continue;
			}
			if ( 'COLLATE' === $upper ) {
				// MySQL collation name has no PG equivalent; content stays
				// citext/case-insensitive per S-xx rules (docs/05).
				$skip_next = 1;
				continue;
			}
			if ( 'CHARACTER' === $upper ) {
				// CHARACTER SET <name>: the connection charset is fixed to
				// utf8mb4 on the MySQL side; PG text is UTF-8 by encoding.
				$skip_next = 2;
				continue;
			}
			if ( 'COMMENT' === $upper ) {
				// Column comment text is out of the min-DDL span; WordPress
				// core schema carries none (third-party tables: skipped).
				$skip_next = 1;
				continue;
			}
			if ( 'AUTO_INCREMENT' === $upper ) {
				$out .= $gap . 'GENERATED BY DEFAULT AS IDENTITY';
				continue;
			}
			if ( 'ON' === $upper ) {
				// ON UPDATE CURRENT_TIMESTAMP becomes a trigger (K-43); the
				// clause itself is dropped from the column definition.
				if ( '' !== $col_name ) {
					$on_update[] = $col_name;
				}
				break;
			}
			$out .= $gap . $raw;
		}
		if ( $unsigned && 'bigint' === $int_type && '' !== $col_name ) {
			// unsigned bigint: the range is not implied by the type.
			$out .= ' CHECK ("' . str_replace( '"', '""', $col_name ) . '" >= 0)';
		}
		$enum_values = self::enum_values( $element );
		if ( array() !== $enum_values && '' !== $col_name ) {
			// docs/06: enum becomes text plus a value check.
			$quoted = array();
			foreach ( $enum_values as $value ) {
				$quoted[] = "'" . str_replace( "'", "''", (string) $value ) . "'";
			}
			$out .= ' CHECK ("' . str_replace( '"', '""', $col_name ) . '" IN (' . implode( ', ', $quoted ) . '))';
		}
		return $out;
	}

	/**
	 * Values of an ENUM/SET column definition.
	 *
	 * @param \WP_Parser_Node $element Column definition node.
	 * @return string[]
	 */
	private static function enum_values( $element ) {
		$values = array();
		$in     = false;
		$depth  = 0;
		foreach ( $element->get_descendant_tokens() as $token ) {
			if ( ! $token instanceof \WP_MySQL_Token ) {
				continue;
			}
			$upper = strtoupper( (string) $token->get_value() );
			if ( 'ENUM' === $upper || 'SET' === $upper ) {
				$in = true;
				continue;
			}
			if ( ! $in ) {
				continue;
			}
			$raw = self::raw( $token );
			if ( '(' === $raw ) {
				++$depth;
				continue;
			}
			if ( ')' === $raw ) {
				--$depth;
				if ( $depth <= 0 ) {
					break;
				}
				continue;
			}
			if ( self::is_string_token( $token ) ) {
				$values[] = (string) $token->get_value();
			}
		}
		return $values;
	}

	private static function is_string_token( $token ) {
		return WP_MySQL_Lexer::SINGLE_QUOTED_TEXT === $token->id
			|| WP_MySQL_Lexer::DOUBLE_QUOTED_TEXT === $token->id;
	}
}
