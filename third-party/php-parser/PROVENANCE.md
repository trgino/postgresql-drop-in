# Provenance — php-parser

- Upstream: https://github.com/nikic/php-parser (Nikita Popov)
- Version vendored: v5.9.0 (composer.json in the tree)
- License: BSD-3-Clause (LICENSE file kept verbatim) — GPL-2.0-or-later compatible
- Provenance: copied from the composer-installed dependency tree (vendor/nikic/php-parser, composer platform PHP 7.4.33).
- Modifications: none to upstream sources (lib/ untouched). A small local
  `bootstrap.php` (m5, written here) spl-registers the `PhpParser\` classes
  against `lib/PhpParser/**` when no composer autoloader is present.
- Purpose: static compat scanner (`wp slonik compat`) inside production
  plugin packages where composer is absent.
- Note: the scanner only parses third-party code; these classes do not run
  during normal page requests.
