<?php
/**
 * Translation-plan cache (M7.1).
 *
 * The planner (MinTranslator::translate()) runs on every wpdb::query()
 * call. Caching the resulting ExecutionPlan by (SQL, ci_mode, catalog
 * version) removes the parse + emit cost for repeated statements — the
 * common case in a WordPress request.
 *
 * Implementations must be silent on failure: a cache that cannot read or
 * write degrades to a plain translate, never to a fatal.
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Cache;

interface CacheInterface {

	/** Default TTL in seconds (used when a caller does not supply one). */
	const DEFAULT_TTL = 86400;

	/**
	 * Fetch a cached plan.
	 *
	 * @param string $key Cache key (already hashed by the caller).
	 * @return array|null Serialized ExecutionPlan (see ExecutionPlan::toArray()),
	 *                  or null on a miss / unreadable cache.
	 */
	public function get( string $key ): ?array;

	/**
	 * Store a plan.
	 *
	 * @param string $key   Cache key.
	 * @param array  $value Serialized ExecutionPlan.
	 * @param int    $ttl   TTL in seconds (0 = no store / ephemeral).
	 * @return bool true on success, false on any failure (silent).
	 */
	public function set( string $key, array $value, int $ttl = self::DEFAULT_TTL ): bool;

	/**
	 * Drop everything (mainly for tests).
	 *
	 * @return bool
	 */
	public function clear(): bool;
}
