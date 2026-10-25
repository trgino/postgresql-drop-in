<?php
/**
 * File-backed ring buffer for runtime compat entries (K-18/K-33).
 *
 * One JSON line per query shape: {ts,kind,rule,shape,sql,count,first,last}.
 * Re-appends of a known shape bump count/last instead of growing the
 * file; the buffer is capped (oldest shapes evicted). Every failure
 * mode returns false / empty — logging must never break a request.
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Audit;

use Throwable;

final class CompatLog {

	/** @var string */
	private $file;

	/** @var int */
	private $cap;

	public function __construct( $file, $cap = 500 ) {
		$this->file = (string) $file;
		$this->cap  = max( 1, (int) $cap );
	}

	/**
	 * Format a timestamp for humans, in the site's timezone.
	 *
	 * wp_date() needs WordPress; outside WordPress (the unit suite) the
	 * server timezone is the only answer available.
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return string
	 */
	public static function formatStamp( $timestamp ): string {
		$format = 'Y-m-d H:i:s';
		$ts     = (int) $timestamp;
		if ( function_exists( 'wp_date' ) ) {
			return (string) wp_date( $format, $ts );
		}
		return date( $format, $ts );
	}

	/**
	 * @param array $entry Entry with kind, rule, shape, sql keys.
	 * @return bool
	 */
	public function append( $entry ) {
		try {
			$entry = LogEntry::build( $entry );
			$now   = time();
			// The read-modify-write runs under an exclusive lock: two
			// concurrent requests must not overwrite each other's entry.
			$lock = $this->lock();
			if ( null === $lock ) {
				return false;
			}
			try {
				$rows = $this->readRows();
				if ( null === $rows ) {
					return false;
				}
				$shape = $entry['shape'];
				$hit   = false;
				foreach ( $rows as &$row ) {
					if ( $row['shape'] === $shape ) {
						$row['count'] = (int) $row['count'] + 1;
						$row['last']  = $now;
						$hit          = true;
						break;
					}
				}
				unset( $row );
				if ( ! $hit ) {
					$entry['first'] = $now;
					$entry['last']  = $now;
					$rows[]         = $entry;
				}
				if ( count( $rows ) > $this->cap ) {
					$rows = array_slice( $rows, count( $rows ) - $this->cap );
				}
				return $this->writeRows( $rows );
			} finally {
				$this->unlock( $lock );
			}
		} catch ( Throwable $e ) {
			return false;
		}
	}

	/**
	 * @param int $limit Max entries (newest last).
	 * @return array
	 */
	public function read( $limit ) {
		try {
			$rows = $this->readRows();
			if ( null === $rows ) {
				return array();
			}
			$limit = max( 1, (int) $limit );
			if ( count( $rows ) > $limit ) {
				$rows = array_slice( $rows, count( $rows ) - $limit );
			}
			return $rows;
		} catch ( Throwable $e ) {
			return array();
		}
	}

	/**
	 * @return array Array with kinds key (kind => shape count).
	 */
	public function stats() {
		$kinds = array();
		foreach ( $this->read( $this->cap ) as $row ) {
			$kind = $row['kind'];
			if ( ! isset( $kinds[ $kind ] ) ) {
				$kinds[ $kind ] = 0;
			}
			++$kinds[ $kind ];
		}
		return array( 'kinds' => $kinds );
	}

	/**
	 * @return bool
	 */
	public function clear() {
		try {
			if ( '' === $this->file ) {
				return false;
			}
			if ( ! is_file( $this->file ) ) {
				return true;
			}
			return unlink( $this->file );
		} catch ( Throwable $e ) {
			return false;
		}
	}

	/**
	 * Take the exclusive lock of the log file.
	 *
	 * @return resource|null The lock handle, or null when the file
	 *                        cannot be locked.
	 */
	private function lock() {
		if ( '' === $this->file ) {
			return null;
		}
		$handle = @fopen( $this->file . '.lock', 'c' );
		if ( ! is_resource( $handle ) ) {
			return null;
		}
		if ( ! @flock( $handle, LOCK_EX ) ) {
			@fclose( $handle );
			return null;
		}
		return $handle;
	}

	/**
	 * @param resource $handle Lock handle.
	 * @return void
	 */
	private function unlock( $handle ) {
		if ( is_resource( $handle ) ) {
			@flock( $handle, LOCK_UN );
			@fclose( $handle );
		}
	}

	/**
	 * @return array|null Rows, or null when the file cannot be read.
	 */
	private function readRows() {
		if ( '' === $this->file || ! is_file( $this->file ) ) {
			return array();
		}
		$text = file_get_contents( $this->file );
		if ( ! is_string( $text ) ) {
			return null;
		}
		$rows = array();
		foreach ( explode( "\n", $text ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$row = json_decode( $line, true );
			if ( ! is_array( $row ) || ! isset( $row['shape'], $row['count'] ) ) {
				continue;
			}
			$rows[] = array(
				'ts'     => isset( $row['ts'] ) ? (int) $row['ts'] : 0,
				'kind'   => isset( $row['kind'] ) ? (string) $row['kind'] : 'error',
				'rule'   => isset( $row['rule'] ) ? (string) $row['rule'] : '',
				'shape'  => (string) $row['shape'],
				'sql'    => isset( $row['sql'] ) ? (string) $row['sql'] : '?',
				'source' => isset( $row['source'] ) ? (string) $row['source'] : '',
				'count'  => (int) $row['count'],
				'first'  => isset( $row['first'] ) ? (int) $row['first'] : 0,
				'last'   => isset( $row['last'] ) ? (int) $row['last'] : 0,
			);
		}
		return $rows;
	}

	/**
	 * @param array $rows
	 * @return bool
	 */
	private function writeRows( $rows ) {
		if ( '' === $this->file ) {
			return false;
		}
		$text = '';
		foreach ( (array) $rows as $row ) {
			$json = json_encode( $row );
			if ( ! is_string( $json ) ) {
				continue;
			}
			$text .= $json . "\n";
		}
		$tmp = $this->file . '.tmp';
		if ( false === @file_put_contents( $tmp, $text ) ) {
			return false;
		}
		if ( ! @rename( $tmp, $this->file ) ) {
			@unlink( $tmp );
			return false;
		}
		return true;
	}
}
