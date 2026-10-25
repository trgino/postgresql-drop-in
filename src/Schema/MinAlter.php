<?php
/**
 * ALTER TABLE translation (docs/05 R-078, D-79 v1).
 *
 * Scope: ADD [COLUMN], ADD {UNIQUE} KEY|INDEX, DROP/ADD PRIMARY KEY and
 * ALTER COLUMN SET/DROP DEFAULT - the shapes the WooCommerce and
 * Wordfence schema-upgrade phases emit on the live sites. Everything
 * else fails loud with the reason it is out of scope.
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

final class MinAlter {

	/**
	 * Translate one ALTER TABLE statement into PostgreSQL statements.
	 *
	 * @param string $mysql   MySQL SQL text.
	 * @param array  $options ci_mode ('' or CiMode::CITEXT).
	 * @return array<string> PostgreSQL statements, in source order.
	 * @throws TranslationException When unparseable or out of scope.
	 */
	public static function translate( $mysql, array $options = array() ) {
		MysqlParser::load();
		$ast = MysqlParser::parse( (string) $mysql );
		if ( null === $ast ) {
			throw new TranslationException( 'Unparseable ALTER TABLE statement.' );
		}
		$stmt = $ast->get_first_descendant_node( 'alter_table_stmt' );
		if ( null === $stmt ) {
			throw new TranslationException( 'Not an ALTER TABLE statement.' );
		}
		$ci_mode = isset( $options['ci_mode'] ) ? (string) $options['ci_mode'] : '';
		$bare    = self::table_name( $stmt );
		if ( '' === $bare ) {
			throw new TranslationException( 'ALTER TABLE without a table name.' );
		}
		$quoted = '"' . str_replace( '"', '""', $bare ) . '"';

		$items = $stmt->get_descendant_nodes( 'alter_list_item' );
		if ( empty( $items ) ) {
			throw new TranslationException( 'ALTER TABLE without any action.' );
		}
		$out = array();
		foreach ( $items as $item ) {
			self::item( (string) $mysql, $item, $bare, $quoted, $ci_mode, $out );
		}
		return $out;
	}

	/**
	 * The bare table name as written (single ident; WordPress runs in
	 * one schema, so a qualified name has its tail taken).
	 */
	private static function table_name( $stmt ) {
		$table = $stmt->get_first_descendant_node( 'table_ident' );
		if ( null === $table ) {
			return '';
		}
		$bare = '';
		foreach ( $table->get_descendant_tokens() as $token ) {
			if ( $token instanceof \WP_MySQL_Token && '' !== trim( (string) $token->get_value() ) ) {
				$bare = (string) $token->get_value();
			}
		}
		return $bare;
	}

	private static function item( $mysql, $item, $bare, $quoted, $ci_mode, array &$out ) {
		$head = MinDdl::head_word( $item );
		if ( 'ADD' === $head ) {
			self::add_item( $mysql, $item, $bare, $quoted, $ci_mode, $out );
			return;
		}
		if ( 'DROP' === $head ) {
			self::drop_item( $item, $bare, $quoted, $out );
			return;
		}
		if ( 'ALTER' === $head ) {
			self::default_item( $item, $quoted, $out );
			return;
		}
		throw new TranslationException( 'ALTER TABLE ' . $head . ' is out of the translator scope (R-078 v1).' );
	}

	private static function add_item( $mysql, $item, $bare, $quoted, $ci_mode, array &$out ) {
		$cdef = $item->get_first_child_node( 'table_constraint_def' );
		if ( null !== $cdef ) {
			self::add_constraint( $cdef, $quoted, $out );
			return;
		}
		$ident = null;
		$fdef  = null;
		foreach ( $item->get_child_nodes() as $kid ) {
			if ( 'ident' === $kid->rule_name && null === $ident ) {
				$ident = $kid;
			}
			if ( 'field_def' === $kid->rule_name ) {
				$fdef = $kid;
			}
		}
		if ( null === $ident || null === $fdef ) {
			throw new TranslationException( 'ALTER TABLE ADD shape is outside the translator scope (R-078 v1).' );
		}
		self::assert_no_auto_increment( $item );
		$col = self::first_ident_text( $ident );
		if ( '' === $col ) {
			throw new TranslationException( 'ALTER ADD COLUMN without a column name (report it).' );
		}

		// The fragment comes from the create-table machinery: the field
		// definition is re-parsed as one mini CREATE TABLE and its column
		// element is emitted with the same type/attribute rules the
		// migration built the tables with (D-79). The AFTER/BEFORE marker
		// is part of the item, not of field_def, so it drops here
		// (PostgreSQL has no column order).
		$mini = 'CREATE TABLE `slonik_mini` (`' . str_replace( '`', '``', $col ) . '` '
			. substr( $mysql, $fdef->get_start(), $fdef->get_length() ) . ')';
		$mini_ast = MysqlParser::parse( $mini );
		$elem     = null !== $mini_ast ? $mini_ast->get_first_descendant_node( 'table_element' ) : null;
		if ( null === $elem ) {
			throw new TranslationException( 'ALTER ADD COLUMN definition did not re-parse (report it).' );
		}
		$on_update = array();
		$frag      = MinDdl::column_sql( $mini, $elem, $on_update, $ci_mode, $bare );
		$label     = MinDdl::column_type_label( $mini, $elem );
		$out[]     = 'ALTER TABLE ' . $quoted . ' ADD COLUMN ' . $frag;
		foreach ( MinDdl::comment_steps( $quoted, array( $frag ), array( $label ) ) as $sql ) {
			$out[] = $sql;
		}
		foreach ( MinDdl::touch_steps( $quoted, $on_update ) as $sql ) {
			$out[] = $sql;
		}
	}

	private static function add_constraint( $cdef, $quoted, array &$out ) {
		$head = MinDdl::head_word( $cdef );
		if ( 'PRIMARY' === $head ) {
			$out[] = 'ALTER TABLE ' . $quoted . ' ADD PRIMARY KEY (' . MinDdl::key_cols( $cdef ) . ')';
			return;
		}
		if ( 'KEY' === $head || 'INDEX' === $head || 'UNIQUE' === $head ) {
			$name = MinDdl::key_name( $cdef );
			$cols = MinDdl::key_cols( $cdef );
			if ( ! self::plain_ident( $name ) ) {
				// The anonymous MySQL index is auto-named after its first
				// column; a stray token leaks out of the name scan.
				$name = self::bare_of( $cols );
			}
			if ( '' === $name || '' === trim( (string) $cols ) ) {
				throw new TranslationException( 'ALTER ADD KEY without a usable name or columns (report it).' );
			}
			$out[] = MinDdl::index_step( $quoted, $name, $cols, 'UNIQUE' === $head );
			return;
		}
		$words = self::item_words( $cdef );
		foreach ( array( 'FOREIGN', 'FULLTEXT', 'SPATIAL', 'CHECK' ) as $known ) {
			if ( in_array( $known, $words, true ) ) {
				throw new TranslationException( 'ALTER TABLE ADD ' . $known . ' key/constraint is out of the translator scope (R-078 v1).' );
			}
		}
		throw new TranslationException( 'ALTER TABLE ADD ' . $head . ' is out of the translator scope (R-078 v1).' );
	}

	/**
	 * The migration built every primary key inline, so PostgreSQL's
	 * default constraint name "<table>_pkey" is deterministic.
	 */
	private static function drop_item( $item, $bare, $quoted, array &$out ) {
		$words = self::item_words( $item );
		if ( in_array( 'PRIMARY', $words, true ) && in_array( 'KEY', $words, true ) ) {
			$out[] = 'ALTER TABLE ' . $quoted . ' DROP CONSTRAINT "'
				. str_replace( '"', '""', $bare ) . '_pkey"';
			return;
		}
		if ( in_array( 'COLUMN', $words, true ) ) {
			throw new TranslationException( 'DROP COLUMN is out of the translator scope (R-078 v1).' );
		}
		throw new TranslationException( 'ALTER TABLE DROP form is out of the translator scope (R-078 v1).' );
	}

	private static function default_item( $item, $quoted, array &$out ) {
		$ident = null;
		$lit   = null;
		foreach ( $item->get_child_nodes() as $kid ) {
			if ( 'ident' === $kid->rule_name && null === $ident ) {
				$ident = $kid;
			}
			if ( 'signed_literal_or_null' === $kid->rule_name ) {
				$lit = $kid;
			}
		}
		$words = self::item_words( $item );
		if ( null === $ident || ! in_array( 'DEFAULT', $words, true ) ) {
			throw new TranslationException( 'ALTER COLUMN form is out of the translator scope (R-078 v1).' );
		}
		$colq = '"' . str_replace( '"', '""', self::first_ident_text( $ident ) ) . '"';
		if ( null === $lit || in_array( 'DROP', $words, true ) ) {
			$out[] = 'ALTER TABLE ' . $quoted . ' ALTER COLUMN ' . $colq . ' DROP DEFAULT';
			return;
		}
		$value = self::literal_text( $lit );
		if ( '' === $value ) {
			throw new TranslationException( 'SET DEFAULT with an unsupported literal (R-078 v1).' );
		}
		$out[] = 'ALTER TABLE ' . $quoted . ' ALTER COLUMN ' . $colq . ' SET DEFAULT ' . $value;
	}

	/**
	 * The ADD COLUMN identity column would leave the existing rows
	 * without a key: filling them needs a sequence decision, so it fails
	 * loud (R-078 v1).
	 */
	private static function assert_no_auto_increment( $item ) {
		foreach ( $item->get_descendant_tokens() as $token ) {
			if ( $token instanceof \WP_MySQL_Token && 'AUTO_INCREMENT' === strtoupper( (string) $token->get_value() ) ) {
				throw new TranslationException( 'ADD COLUMN with AUTO_INCREMENT cannot fill the existing rows; it needs a sequence decision (R-078 v1 fails loud).' );
			}
		}
	}

	/**
	 * The uppercased keyword tokens of a grammar element.
	 *
	 * @return array<string>
	 */
	private static function item_words( $element ) {
		$words = array();
		foreach ( $element->get_descendant_tokens() as $token ) {
			if ( $token instanceof \WP_MySQL_Token ) {
				$words[] = strtoupper( (string) $token->get_value() );
			}
		}
		return $words;
	}

	/**
	 * The text of a quoted/bare identifier token set (first piece).
	 */
	private static function first_ident_text( $ident ) {
		foreach ( $ident->get_descendant_tokens() as $token ) {
			if ( $token instanceof \WP_MySQL_Token && '' !== trim( (string) $token->get_value() ) ) {
				return (string) $token->get_value();
			}
		}
		return '';
	}

	/**
	 * The SET DEFAULT literal in PostgreSQL form: quoted text, plain
	 * number or NULL. The zero-date sentinel matches the create-table
	 * path, because PostgreSQL rejects MySQL's zero dates.
	 */
	private static function literal_text( $lit ) {
		foreach ( $lit->get_descendant_tokens() as $token ) {
			if ( ! $token instanceof \WP_MySQL_Token ) {
				continue;
			}
			$value = (string) $token->get_value();
			if ( WP_MySQL_Lexer::SINGLE_QUOTED_TEXT === $token->id ) {
				if ( 0 === strpos( $value, '0000-00-00' ) ) {
					$value = '0001-01-01' . substr( $value, 10 );
				}
				return PgQuote::string( $value );
			}
			if ( '' === trim( $value ) ) {
				continue;
			}
			if ( is_numeric( $value ) ) {
				return $value;
			}
			if ( 'NULL' === strtoupper( $value ) ) {
				return 'NULL';
			}
			return '';
		}
		return '';
	}

	/**
	 * The bare name of one quoted identifier fragment.
	 */
	private static function bare_of( $quoted ) {
		$part = trim( (string) $quoted );
		if ( strlen( $part ) < 2 || '"' !== $part[0] ) {
			return '';
		}
		return str_replace( '""', '"', substr( $part, 1, -1 ) );
	}

	/**
	 * Whether the name is a plain identifier (the same character set the
	 * create-path token scan accepts). Anything else, like a stray
	 * parenthesis from the anonymous-key name scan, counts as anonymous.
	 */
	private static function plain_ident( $name ) {
		return 1 === preg_match( '/^[A-Za-z_][A-Za-z0-9_$]*$/', (string) $name );
	}
}
