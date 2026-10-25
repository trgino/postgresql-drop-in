<?php
/**
 * Dev-repo shim for the `wp db *` guard (K-40/K-51).
 *
 * The shipped wiring lives at the plugin root as wp-cli-guard.php — the
 * packaged path the install hint references. This file only re-requires
 * it so dev-tree wp-cli.yml keeps working unchanged.
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

require_once dirname( __DIR__, 2 ) . '/wp-cli-guard.php';
