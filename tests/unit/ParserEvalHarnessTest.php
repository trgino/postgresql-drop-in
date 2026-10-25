<?php
declare( strict_types=1 );

namespace Slonik\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ParserEvalHarnessTest extends TestCase {
	public function test_harness_script_exists(): void {
		$this->assertFileExists( dirname( __DIR__, 2 ) . '/tools/parser-eval/run.php' );
	}

	public function test_harness_feeds_all_rows_and_reports_contract(): void {
		$src = getenv( 'M1_PARSER_SRC' );
		if ( ! is_string( $src ) || '' === $src || ! is_dir( $src ) ) {
			$src = dirname( __DIR__, 2 ) . '/third-party/mysql-parser/src';
		}
		if ( ! is_dir( $src ) ) {
			$this->markTestSkipped( 'no parser runtime available' );
		}
		$fixture = tempnam( sys_get_temp_dir(), 'm1fx' ) . '.jsonl';
		$rows    = array(
			array( 'sql' => "SELECT * FROM t WHERE note = 'LIMIT 5, 10'" ),
			array( 'sql' => 'SELECT `we``ird` FROM `t`' ),
			array( 'sql' => 'SELECT /*!40101 1 */ FROM t' ),
			array( 'sql' => "INSERT INTO t (a) VALUES (1) ON DUPLICATE KEY UPDATE a = VALUES(a)" ),
			array( 'sql' => "SELECT * FROM t WHERE MATCH(a) AGAINST('x')" ),
			array( 'sql' => "SELECT '\xff\xfe broken'" ),
		);
		$fh      = fopen( $fixture, 'wb' );
		foreach ( $rows as $r ) {
			fwrite( $fh, json_encode( $r, JSON_INVALID_UTF8_SUBSTITUTE ) . "\n" );
		}
		fclose( $fh );
		$cmd = sprintf(
			'%s %s %s',
			PHP_BINARY,
			escapeshellarg( dirname( __DIR__, 2 ) . '/tools/parser-eval/run.php' ),
			escapeshellarg( $fixture )
		);
		$out = array();
		putenv( 'M1_PARSER_SRC=' . $src );
		exec( $cmd, $out, $code );
		unlink( $fixture );
		$this->assertSame( 0, $code, implode( "\n", $out ) );
		// Some interpreters print startup notices (e.g. "PHP Startup: ...")
		// to stdout before the script runs; those lines are environmental,
		// anything else polluting stdout must still fail this test.
		$json_lines = array();
		foreach ( $out as $line ) {
			if ( false !== strpos( $line, 'PHP Startup:' ) ) {
				continue;
			}
			$json_lines[] = $line;
		}
		$summary = json_decode( trim( implode( "\n", $json_lines ) ), true );
		$this->assertIsArray( $summary, 'harness must print valid JSON even with broken UTF-8 input' );
		$this->assertSame( 6, $summary['total'] );
		foreach ( array( 'parse_ok', 'parse_fail', 'error_classes', 'by_verb_fail', 'p50_ms', 'p95_ms', 'snapshot_sha' ) as $key ) {
			$this->assertArrayHasKey( $key, $summary );
		}
		$this->assertSame( 6, $summary['parse_ok'] + $summary['parse_fail'] );
	}
}
