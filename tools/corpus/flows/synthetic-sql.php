<?php
/**
 * Synthetic SQL for corpus capture: patterns real plugins/themes emit through
 * wpdb that core flows alone do not reach (known hard translation cases).
 * Labelled "synthetic" so metrics can separate them from organic traffic.
 *
 * Run with: wp eval-file flows/synthetic-sql.php
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

global $wpdb;

if ( property_exists( $wpdb, 'corpus_label' ) ) {
	$wpdb->corpus_label = 'synthetic';
}

$t = $wpdb->prefix . 'slonik_demo';

// DDL through dbDelta, as plugins do (needs wp-admin/includes/upgrade.php).
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
$charset = $wpdb->get_charset_collate();
dbDelta(
	"CREATE TABLE $t (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  slug varchar(191) NOT NULL DEFAULT '',
  title text NOT NULL,
  hits int(11) NOT NULL DEFAULT 0,
  score decimal(10,2) DEFAULT NULL,
  flags tinyint(1) NOT NULL DEFAULT 0,
  payload longtext,
  created datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  updated timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY slug (slug),
  KEY hits (hits),
  KEY created_slug (created, slug(20))
) $charset;"
);

$statements = array(
	// Introspection.
	"SHOW TABLES LIKE '" . $wpdb->esc_like( $t ) . "'",
	"SHOW COLUMNS FROM `$t`",
	"SHOW FULL COLUMNS FROM `$t`",
	"SHOW INDEX FROM `$t`",
	"SHOW CREATE TABLE `$t`",
	"DESCRIBE `$t`",
	"SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$t'",
	"SHOW VARIABLES LIKE 'max_allowed_packet'",
	'SHOW TABLE STATUS',
	// Inserts / upserts.
	"INSERT INTO `$t` (slug, title, hits, created) VALUES ('a', 'A', 1, NOW()), ('b', 'B', 2, NOW())",
	"INSERT INTO `$t` (slug, title, hits, created) VALUES ('a', 'A2', 1, NOW()) ON DUPLICATE KEY UPDATE hits = hits + 1, title = VALUES(title)",
	"INSERT IGNORE INTO `$t` (slug, title, created) VALUES ('a', 'dup', NOW())",
	"REPLACE INTO `$t` (id, slug, title, created) VALUES (1, 'a', 'replaced', NOW())",
	"UPDATE `$t` SET hits = hits + 1, score = IFNULL(score, 0) + 1.5 WHERE id IN (1, 2) ORDER BY id LIMIT 5",
	"UPDATE `$t` t1 INNER JOIN `{$wpdb->posts}` p ON p.ID = t1.id SET t1.hits = 0",
	"DELETE t1 FROM `$t` t1 LEFT JOIN `{$wpdb->posts}` p ON p.ID = t1.id WHERE p.ID IS NULL",
	"DELETE FROM `$t` WHERE hits > 100 LIMIT 10",
	// Selects with MySQL-only features.
	"SELECT SQL_CALC_FOUND_ROWS id, slug FROM `$t` WHERE hits >= 0 ORDER BY hits DESC LIMIT 0, 10",
	'SELECT FOUND_ROWS()',
	"SELECT GROUP_CONCAT(slug ORDER BY slug SEPARATOR ',') FROM `$t`",
	"SELECT slug, COUNT(*) c FROM `$t` GROUP BY slug HAVING c > 0 ORDER BY c DESC",
	"SELECT id, slug, hits FROM `$t` GROUP BY slug",
	"SELECT UNIX_TIMESTAMP(created), FROM_UNIXTIME(1700000000), DATE_FORMAT(created, '%Y-%m-%d %H:%i'), DATE_ADD(NOW(), INTERVAL 1 DAY), DATEDIFF(NOW(), created) FROM `$t`",
	"SELECT IF(hits > 1, 'many', 'few'), IFNULL(score, 0), COALESCE(score, 0), CONCAT(slug, '-', id), CAST(hits AS SIGNED), CAST(hits AS CHAR) FROM `$t`",
	"SELECT * FROM `$t` WHERE slug LIKE '%a%' OR title LIKE 'A\\_%' OR slug = 'x\\'y'",
	"SELECT * FROM `$t` WHERE MATCH(title) AGAINST ('word' IN BOOLEAN MODE)",
	"SELECT * FROM `$t` ORDER BY RAND() LIMIT 3",
	"SELECT * FROM `$t` WHERE created = '0000-00-00 00:00:00'",
	"SELECT * FROM `$t` WHERE id = '1'",
	"SELECT * FROM `$t` WHERE slug = 1",
	"SELECT id FROM `$t` WHERE id IN (SELECT ID FROM `{$wpdb->posts}`)",
	"SELECT DISTINCT p.ID FROM `{$wpdb->posts}` p ORDER BY p.post_date DESC LIMIT 5",
	"SELECT LAST_INSERT_ID(), ROW_COUNT(), DATABASE(), CONNECTION_ID(), @@sql_mode, @@session.time_zone",
	"SELECT GET_LOCK('slonik_lock', 1), RELEASE_LOCK('slonik_lock')",
	"SELECT /* comment */ 1 -- trailing\n+ 1",
	"SELECT /*!40101 SQL_NO_CACHE */ 1",
	"SELECT BINARY slug, slug COLLATE utf8mb4_bin FROM `$t` WHERE slug = 'a' COLLATE utf8mb4_unicode_ci",
	"SELECT 0x41 AS hex_lit, b'101' AS bit_lit, 1e3 AS exp_lit",
	// Transactions, locks, maintenance.
	'START TRANSACTION',
	"UPDATE `$t` SET hits = 0",
	'ROLLBACK',
	"LOCK TABLES `$t` WRITE",
	'UNLOCK TABLES',
	"OPTIMIZE TABLE `$t`",
	"ANALYZE TABLE `$t`",
	"ALTER TABLE `$t` ADD COLUMN extra varchar(20) NOT NULL DEFAULT '' AFTER title",
	"ALTER TABLE `$t` ADD INDEX extra_idx (extra)",
	"ALTER TABLE `$t` DROP INDEX extra_idx",
	"ALTER TABLE `$t` CHANGE COLUMN extra extra2 varchar(40) NULL",
	"ALTER TABLE `$t` DROP COLUMN extra2",
	"TRUNCATE TABLE `$t`",
	"DROP TABLE IF EXISTS `$t`",
);

$suppress = $wpdb->suppress_errors( true );
foreach ( $statements as $sql ) {
	$wpdb->query( $sql );
}
$wpdb->suppress_errors( $suppress );

// wpdb helper APIs that build SQL internally.
$wpdb->insert( $wpdb->options, array( 'option_name' => 'slonik_ins', 'option_value' => 'x', 'autoload' => 'no' ) );
$wpdb->update( $wpdb->options, array( 'option_value' => 'y' ), array( 'option_name' => 'slonik_ins' ) );
$wpdb->replace( $wpdb->options, array( 'option_name' => 'slonik_ins', 'option_value' => 'z', 'autoload' => 'no' ) );
$wpdb->delete( $wpdb->options, array( 'option_name' => 'slonik_ins' ) );
$wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->options} WHERE option_name LIKE %s AND autoload = %s", $wpdb->esc_like( 'siteurl' ) . '%', 'yes' ) );
$wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_title = %s AND ID > %d AND post_date > %s", "it's", 0, '2000-01-01' ) );
fwrite( STDERR, "[flow] synthetic done\n" );
