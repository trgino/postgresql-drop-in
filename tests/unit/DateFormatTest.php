<?php
declare( strict_types=1 );

namespace Slonik\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Slonik\Dialect\Pg\DateFormat;

/**
 * Every MySQL DATE_FORMAT() specifier must have a PostgreSQL counterpart
 * (the maintainer's requirement: the converter covers all of them and
 * each one is tested).
 *
 * The reference is MySQL 8.4 itself: the expected values live in
 * tools/pg-accept/results.sql and the acceptance gate compares them
 * against PostgreSQL.
 */
final class DateFormatTest extends TestCase {

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public function specifiers(): array {
		return array(
			'%a'  => array( 'a', "to_char(d, 'Dy')" ),
			'%b'  => array( 'b', "to_char(d, 'Mon')" ),
			'%c'  => array( 'c', 'EXTRACT(MONTH FROM d)::int::text' ),
			'%d'  => array( 'd', "to_char(d, 'DD')" ),
			'%D'  => array( 'D', "to_char(d, 'FMDD') || CASE" ),
			'%e'  => array( 'e', "to_char(d, 'FMDD')" ),
			'%f'  => array( 'f', "to_char(d, 'US')" ),
			'%H'  => array( 'H', "to_char(d, 'HH24')" ),
			'%h'  => array( 'h', "to_char(d, 'HH12')" ),
			'%I'  => array( 'I', "to_char(d, 'HH12')" ),
			'%i'  => array( 'i', "to_char(d, 'MI')" ),
			'%j'  => array( 'j', "to_char(d, 'DDD')" ),
			'%k'  => array( 'k', "to_char(d, 'FMHH24')" ),
			'%l'  => array( 'l', "to_char(d, 'FMHH12')" ),
			'%M'  => array( 'M', "to_char(d, 'FMMonth')" ),
			'%m'  => array( 'm', "to_char(d, 'MM')" ),
			'%p'  => array( 'p', "to_char(d, 'AM')" ),
			'%r'  => array( 'r', "to_char(d, 'hh12:MI:SS AM')" ),
			'%S'  => array( 'S', "to_char(d, 'SS')" ),
			'%s'  => array( 's', "to_char(d, 'SS')" ),
			'%T'  => array( 'T', "to_char(d, 'HH24:MI:SS')" ),
			'%t'  => array( 't', 'chr(9)' ),
			'%U'  => array( 'U', "trunc((EXTRACT(DOY FROM d)::int + EXTRACT(DOW FROM date_trunc('year', d))::int - 1) / 7)::int::text" ),
			'%u'  => array( 'u', "(trunc((EXTRACT(DOY FROM d)::int + ((EXTRACT(DOW FROM date_trunc('year', d))::int + 6) % 7) - 1) / 7)::int + 1)::text" ),
			'%V'  => array( 'V', "trunc((EXTRACT(DOY FROM d)::int + EXTRACT(DOW FROM date_trunc('year', d))::int - 1) / 7)::int::text" ),
			'%v'  => array( 'v', "(trunc((EXTRACT(DOY FROM d)::int + ((EXTRACT(DOW FROM date_trunc('year', d))::int + 6) % 7) - 1) / 7)::int + 1)::text" ),
			'%W'  => array( 'W', "to_char(d, 'FMDay')" ),
			'%w'  => array( 'w', 'EXTRACT(DOW FROM d)::int::text' ),
			'%X'  => array( 'X', "EXTRACT(YEAR FROM (d - (EXTRACT(DOW FROM d)::int * INTERVAL '1 day')))::int::text" ),
			'%x'  => array( 'x', "EXTRACT(YEAR FROM (d - (((EXTRACT(DOW FROM d)::int + 6) % 7) * INTERVAL '1 day')))::int::text" ),
			'%Y'  => array( 'Y', "to_char(d, 'YYYY')" ),
			'%y'  => array( 'y', "to_char(d, 'YY')" ),
			'%%'  => array( '%', "'%'" ),
		);
	}

	/**
	 * @dataProvider specifiers
	 */
	public function test_every_mysql_specifier_has_a_postgresql_counterpart( string $spec, string $expected ): void {
		$out = DateFormat::specifier( $spec, 'd' );
		$this->assertNotNull( $out, 'no mapping for %' . $spec );
		$this->assertStringContainsString( $expected, (string) $out );
	}

	/**
	 * The specifiers MySQL 8.4 does not implement stay literal, exactly
	 * like MySQL prints them.
	 */
	public function test_mysql_8_4_does_not_implement_the_century_specifier(): void {
		$this->assertNull( DateFormat::specifier( 'C', 'd' ) );
		$this->assertSame( "'C'", DateFormat::to_pg( '%C', 'd' ) );
	}

	public function test_all_mysql_specifiers_are_known(): void {
		$mysql = array( 'a', 'b', 'c', 'C', 'd', 'D', 'e', 'f', 'H', 'h', 'I', 'i', 'j', 'k', 'l', 'M', 'm', 'p', 'r', 'S', 's', 'T', 't', 'U', 'u', 'V', 'v', 'W', 'w', 'X', 'x', 'Y', 'y', '%' );
		$known = 0;
		foreach ( $mysql as $spec ) {
			if ( null !== DateFormat::specifier( $spec, 'd' ) ) {
				++$known;
			}
		}
		// Every MySQL specifier is either mapped or deliberately literal.
		$this->assertSame( count( $mysql ) - 1, $known );
		$this->assertCount( count( $mysql ) - 1, $this->specifiers() );
	}

	public function test_a_plain_format_becomes_one_to_char_call(): void {
		$this->assertSame( "to_char((d)::timestamp, 'YYYY-MM-DD')", DateFormat::to_pg( '%Y-%m-%d', 'd' ) );
	}

	public function test_mixed_formats_are_concatenated(): void {
		// %U is an expression (the week number), the rest is a pattern.
		$out = DateFormat::to_pg( '%Y-%m-%d %U', 'd' );
		$this->assertStringContainsString( "to_char((d)::timestamp, 'YYYY-MM-DD ')", $out );
		$this->assertStringContainsString( 'EXTRACT(DOY FROM', $out );
		$this->assertStringContainsString( ' || ', $out );
	}

	public function test_unknown_specifiers_stay_literal_without_the_percent_sign(): void {
		$this->assertSame( "'Q'", DateFormat::to_pg( '%Q', 'd' ) );
	}

	public function test_literals_are_quoted_safely(): void {
		$this->assertSame( "'it''s'", DateFormat::to_pg( "it's", 'd' ) );
	}
}
