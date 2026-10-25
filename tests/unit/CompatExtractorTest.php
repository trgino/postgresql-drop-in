<?php
declare( strict_types=1 );

namespace Slonik\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Slonik\Compat\Collector;
use Slonik\Compat\SqlExtractor;
use Slonik\Compat\ArraySource;
use Slonik\Compat\FileSource;

final class CompatExtractorTest extends TestCase {

	/**
	 * @param string $code PHP source snippet.
	 * @return array<int, array{method:string, path:string, line:int, kind:string, sqlNode:?object}>
	 */
	private function collect_one( $code ) {
		$source = new ArraySource( array( 'snippet.php' => '<?php ' . $code ) );
		$col    = new Collector( $source );
		return $col->collect();
	}

	/**
	 * @return array<string, array{string, string, bool}>
	 */
	public function template_provider() {
		return array(
			'plain literal'      => array( '$wpdb->query( "SELECT ID FROM wp_posts" );', 'SELECT ID FROM wp_posts', false ),
			'sprintf string'     => array( '$wpdb->get_results( sprintf( "SELECT %s FROM wp_posts WHERE c = %s" ) );', "SELECT 'x' FROM wp_posts WHERE c = 'x'", false ),
			'sprintf numbers'    => array( '$wpdb->query( sprintf( "SELECT %d LIMIT %f" ) );', 'SELECT 1 LIMIT 1.0', false ),
			'sprintf positional' => array( '$wpdb->get_var( sprintf( "SELECT %1\$d" ) );', 'SELECT 1', false ),
			'percent escape'     => array( '$wpdb->get_var( sprintf( "SELECT 50%%" ) );', 'SELECT 50%', false ),
			'prepare fill'       => array( '$wpdb->prepare( "SELECT * FROM wp_posts WHERE ID = %d" );', 'SELECT * FROM wp_posts WHERE ID = 1', false ),
			'prepare ident'      => array( '$wpdb->prepare( "SELECT %i FROM wp_t" );', 'SELECT `id` FROM wp_t', false ),
			'concat literals'    => array( '$wpdb->get_results( "SELECT " . "x" );', 'SELECT x', false ),
			'heredoc literal'    => array( '$wpdb->get_results( <<<SQL
SELECT 6
SQL );', 'SELECT 6', false ),
			'prefix concat'      => array( '$wpdb->get_results( $wpdb->prefix . "users" );', 'wp_users', false ),
			'posts property'     => array( '$wpdb->query( "SELECT * FROM " . $wpdb->posts );', 'SELECT * FROM wp_posts', false ),
			'sitemeta property'  => array( '$wpdb->get_results( "SELECT * FROM " . $wpdb->sitemeta );', 'SELECT * FROM wp_sitemeta', false ),
			'blogs property'     => array( '$wpdb->get_results( $wpdb->prefix . "blogs" );', 'wp_blogs', false ),
			'unknown variable'   => array( '$wpdb->get_results( $sql );', '‹DYN›', true ),
			'interpolated cast'  => array( '$wpdb->get_results( "SELECT {$col} FROM wp_t" );', 'SELECT ‹DYN› FROM wp_t', true ),
			'func return sql'    => array( '$wpdb->get_results( "SELECT " . \Slonik\Compat\SlonikProxy::part() );', 'SELECT ‹DYN›', true ),
			'insert table arg'   => array( '$wpdb->insert( "wp_shops", array( "x" => 1 ) );', 'wp_shops', false ),
			'insert dyn table'   => array( '$wpdb->insert( $table, array( "x" => 1 ) );', '‹DYN›', true ),
		);
	}

	/**
	 * @dataProvider template_provider
	 *
	 * @param string $code     Snippet.
	 * @param string $template Expected template.
	 * @param bool   $dynamic  Expected dynamic flag.
	 */
	public function test_templates( $code, $template, $dynamic ) {
		$entries   = $this->collect_one( $code );
		$extractor = new SqlExtractor();
		$this->assertSame( 'query', $entries[0]['kind'] );
		$result = $extractor->extract( $entries[0]['sqlNode'], $entries[0]['method'] );
		$this->assertSame( $template, $result['template'], var_export( $result, true ) );
		$this->assertSame( $dynamic, $result['dynamic'] );
	}

	public function test_missing_sql_arg_gives_dynamic(): void {
		$entries   = $this->collect_one( '$wpdb->query();' );
		$extractor = new SqlExtractor();
		$result    = $extractor->extract( null, 'query' );
		$this->assertSame( '‹DYN›', $result['template'] );
		$this->assertTrue( $result['dynamic'] );
	}

	public function test_insert_table_args_not_sql(): void {
		$entries   = $this->collect_one( '$wpdb->insert( "wp_shops", array( "x" => 1 ), array( "%s" ) );' );
		$extractor = new SqlExtractor();
		$result    = $extractor->extract( $entries[0]['sqlNode'], 'insert' );
		$this->assertSame( 'wp_shops', $result['template'] );
		$this->assertFalse( $result['dynamic'] );
	}
}
