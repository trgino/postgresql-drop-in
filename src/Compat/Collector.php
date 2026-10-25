<?php
/**
 * Static scan collector: files → $wpdb call sites (docs/10 §2).
 *
 * Unparseable files and scanner-host issues degrade to 'skipped' entries;
 * the scan never dies on bad input.
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Compat;

use PhpParser\NodeTraverser;

final class Collector {

	/** @var FileSource */
	private $source;

	/** @var ParserFactory */
	private $parser_factory;

	public function __construct( FileSource $source, ?ParserFactory $parser_factory = null ) {
		$this->source         = $source;
		$this->parser_factory = $parser_factory ? $parser_factory : new HostParserFactory();
	}

	/**
	 * Collect over pre-fetched (path, contents) pairs — callers that already
	 * hold the file list (Scanner) avoid a second source traversal.
	 *
	 * @param array<int, array{path:string, contents:string}> $files Files.
	 * @return array<int, array{path:string, line:int, kind:string, method:string, sqlNode:?object}>
	 */
	public function collectFiles( $files ) {
		$out = array();
		foreach ( (array) $files as $file ) {
			$out = array_merge( $out, $this->collectFile( (string) $file['path'], (string) $file['contents'] ) );
		}
		return $out;
	}

	/**
	 * @return array<int, array{path:string, line:int, kind:string, method:string, sqlNode:?object}>
	 */
	public function collect() : array {
		return $this->collectFiles( $this->source->files() );
	}

	/**
	 * @param string $path     File path (as reported in findings).
	 * @param string $contents File contents.
	 * @return array<int, array{path:string, line:int, kind:string, method:string, sqlNode:?object}>
	 */
	private function collectFile( $path, $contents ) : array {
		try {
			$parser    = $this->parser_factory->create();
			$statements = $parser->parse( $contents );
		} catch ( \Throwable $e ) {
			return array(
				$this->skip( $path ),
			);
		}
		if ( null === $statements ) {
			return array(
				$this->skip( $path ),
			);
		}
		$visitor = new WpdbVisitor();
		$visitor->reset( (string) $path );
		$traverser = new NodeTraverser();
		$traverser->addVisitor( $visitor );
		$traverser->traverse( $statements );
		return $visitor->entries();
	}

	private function skip( $path ) : array {
		return array(
			'path'    => (string) $path,
			'line'    => 0,
			'kind'    => 'skipped',
			'method'  => '',
			'sqlNode' => null,
		);
	}
}
