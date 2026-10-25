<?php
/**
 * Aborting data-level migration error (docs/15 §5.2): row reported, no
 * silent truncation (K-31).
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Migrate;

class MigrateDataError extends \InvalidArgumentException {
}
