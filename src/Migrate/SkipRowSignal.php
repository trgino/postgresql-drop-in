<?php
/**
 * WPSLONIK_MIGRATE_ON_INVALID_UTF8=skip-row signal (docs/15 §5.2): the
 * whole row is skipped and counted, never silently dropped.
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Migrate;

class SkipRowSignal extends \RuntimeException {
}
