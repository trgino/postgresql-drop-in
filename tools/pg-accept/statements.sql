-- Statements that must translate AND be accepted by PostgreSQL (PREPARE).
-- One statement per line; the gate fails when a line cannot be prepared.
SELECT ABS(id) FROM wp_posts
SELECT CONCAT(post_title, '-', post_status) FROM wp_posts
SELECT IFNULL(post_title, '') FROM wp_posts
SELECT RAND() FROM wp_posts
SELECT MD5(post_title) FROM wp_posts
SELECT LENGTH(post_title) FROM wp_posts
SELECT LOWER(post_title) FROM wp_posts
SELECT UPPER(post_title) FROM wp_posts
SELECT REPLACE(post_title, 'a', 'b') FROM wp_posts
SELECT SUBSTRING(post_title, 1, 3) FROM wp_posts
SELECT TRIM(post_title) FROM wp_posts
SELECT COALESCE(post_title, 'x') FROM wp_posts
SELECT NULLIF(post_title, 'x') FROM wp_posts
SELECT CAST(id AS CHAR) FROM wp_posts
SELECT id FROM wp_posts WHERE id = 1 LIMIT 5
SELECT p.id, u.user_login FROM wp_posts p JOIN wp_users u ON p.post_author = u.ID
SELECT COUNT(*) FROM wp_posts
SELECT NOW()
SELECT CURRENT_DATE
INSERT INTO wp_options (option_name, option_value) VALUES ('a', 'b')
UPDATE wp_posts SET post_title = 'x' WHERE id = 1
DELETE FROM wp_posts WHERE id = 1
CREATE TABLE wp_unsigned (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, hits int(10) unsigned NOT NULL DEFAULT 0, PRIMARY KEY (id))
CREATE TABLE wp_named_unique (id int NOT NULL, slug varchar(200) NOT NULL, UNIQUE KEY slug (slug), PRIMARY KEY (id))
CREATE TABLE wp_touch (id int NOT NULL, post_modified datetime NOT NULL DEFAULT '0000-00-00 00:00:00' ON UPDATE CURRENT_TIMESTAMP, status enum('draft','publish') NOT NULL DEFAULT 'draft', PRIMARY KEY (id))
CREATE TABLE IF NOT EXISTS wp_wfls_2fa_secrets (`id` int(11) unsigned NOT NULL AUTO_INCREMENT, `user_id` bigint(20) unsigned NOT NULL, `secret` tinyblob NOT NULL, `recovery` blob NOT NULL, `ctime` int(10) unsigned NOT NULL, `vtime` int(10) unsigned NOT NULL, `mode` enum('authenticator') NOT NULL DEFAULT 'authenticator', PRIMARY KEY (`id`), KEY `user_id` (`user_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8
-- D-79: the ALTER TABLE shapes the schema-upgrade phases emit.
ALTER TABLE wp_unsigned ADD COLUMN hits3 int(10) unsigned NOT NULL DEFAULT 0 AFTER id
ALTER TABLE wp_unsigned ADD COLUMN payload varbinary(16) DEFAULT NULL AFTER hits3
ALTER TABLE wp_touch ADD COLUMN state enum('draft','publish') NOT NULL DEFAULT 'draft'
ALTER TABLE wp_named_unique ADD INDEX slug_idx (slug)
ALTER TABLE wp_named_unique ADD UNIQUE KEY slug2 (slug)
ALTER TABLE wp_unsigned DROP PRIMARY KEY, ADD PRIMARY KEY (id, hits)
ALTER TABLE wp_touch ALTER COLUMN status SET DEFAULT 'draft'
DELETE a, b FROM wp_options a, wp_options b WHERE a.option_name LIKE '\_transient\_%' AND a.option_name NOT LIKE '\_transient\_timeout\_%' AND b.option_name = CONCAT('_transient_timeout_', SUBSTRING(a.option_name, 11)) AND b.option_value < 1000000000
SELECT * FROM wp_options WHERE option_name LIKE 'a\%b' ESCAPE '\\'
SELECT 1 FROM DUAL
INSERT INTO wp_options (option_name, option_value) SELECT 'slonik_from_dual', 'x' FROM DUAL WHERE ( SELECT NULL FROM DUAL ) IS NULL
DROP TABLE IF EXISTS slonik_accept_probe_missing
TRUNCATE TABLE slonik_accept_probe
INSERT IGNORE INTO wp_options (option_name, option_value) VALUES ('slonik_ignore_probe', 'x')
SELECT GROUP_CONCAT(option_value) FROM wp_options
SELECT GROUP_CONCAT(DISTINCT option_value) FROM wp_options
CREATE TABLE slonik_accept_widths (a varbinary(124) DEFAULT NULL, b double(17,6) NOT NULL, c binary(16))
CREATE TABLE slonik_accept_bytes (a binary(16) NOT NULL DEFAULT '\0\0', b varbinary(8) DEFAULT NULL)
CREATE TABLE IF NOT EXISTS slonik_accept_idem (id int NOT NULL, col int NOT NULL, KEY col (col))
CREATE TABLE IF NOT EXISTS slonik_accept_idem (id int NOT NULL, col int NOT NULL, KEY col (col))
INSERT INTO wp_actionscheduler_actions (action_id, hook, schedule) VALUES (1, 'x', 'O:30:"S":1:{s:4:"\0*\0a";i:1;}')
INSERT INTO wp_actionscheduler_actions (action_id, hook, schedule) SELECT 2, 'y', 'O:30:"S":1:{s:4:"\0*\0b";i:2;}' FROM DUAL WHERE ( SELECT NULL FROM DUAL ) IS NULL
INSERT INTO wp_wfconfig (name, val) VALUES ('x', X'616263')
UPDATE wp_wfconfig SET val = X'613a323a7b' WHERE name = 'x'
-- D-72: the comparison classification resolves against the statement's
-- table: the int column stays numeric (no lower(), no literal quote),
-- the bytea column takes the hex input format, and the CASE + alias
-- ORDER BY part reaches PostgreSQL intact.
SELECT * FROM wp_wfblocks7 WHERE IP = '\\x00000000000000000000000000000001' AND `type` = 7 AND (`expiration` = 0 OR `expiration` > UNIX_TIMESTAMP())
SELECT *, CASE WHEN `type` = 3 THEN 0 WHEN `type` = 4 THEN 1 ELSE 99 END AS `typeSort` FROM `wp_wfblocks7` WHERE `type` = 7 AND (`expiration` = 0 OR `expiration` > UNIX_TIMESTAMP()) ORDER BY `typeSort` ASC, `id` DESC
-- Wordfence's block listing CASE: the result arms mix a text column and
-- the bytea IP; the bytea arm goes out as encode(...,'hex') so the arms
-- unify as text (audit D-77, R-077).
SELECT id, CASE WHEN `type` = 3 THEN `parameters` WHEN `type` = 7 THEN `ip` ELSE '' END AS `detailSort` FROM `wp_wfblocks7`
SELECT *, CASE WHEN `type` = 3 THEN 0 WHEN `type` = 7 THEN 7 ELSE 9999 END AS `typeSort`, CASE WHEN `type` = 3 THEN `parameters` WHEN `type` = 7 THEN `ip` ELSE 9999 END AS `detailSort` FROM `wp_wfblocks7` WHERE `type` IN (3, 7) AND (`expiration` = 0 OR `expiration` > UNIX_TIMESTAMP()) ORDER BY `typeSort` ASC, `id` DESC
-- D-55: the information_schema probe shapes (R-079).
SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?
-- D-82: the wordfence table-case probe (the compare is case-sensitive, MySQL utf8mb3_bin).
SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='wp_wfConfig'
SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME=? AND TABLE_NAME=?
SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
SELECT COLUMN_KEY FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME=? AND TABLE_NAME=?
SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wp_wc_order_product_lookup' AND INDEX_NAME = 'PRIMARY'
-- D-55: the SHOW VARIABLES probe: the emulated row carries the MySQL
-- column names, so the consumer reads the value unchanged.
SHOW VARIABLES LIKE 'max_allowed_packet'
SHOW VARIABLES LIKE 'max_long_data_size'
-- D-84: the ON UPDATE trigger must survive a re-run of the same DDL.
CREATE TABLE IF NOT EXISTS wp_accept_touch (id int NOT NULL, touched datetime NOT NULL DEFAULT '0000-00-00 00:00:00' ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (id))
CREATE TABLE IF NOT EXISTS wp_accept_touch (id int NOT NULL, touched datetime NOT NULL DEFAULT '0000-00-00 00:00:00' ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (id))
-- D-83: the grouping key is a select-list alias.
SELECT COUNT(*) AS cnt, CASE WHEN (jsRun = ? OR userID > ?) THEN ? ELSE ? END AS isHuman, statusCode FROM wp_wfhits WHERE ctime > ? GROUP BY isHuman, statusCode
-- D-83 review: a bare GROUP BY alias that collides with an input column
-- keeps the any_value() wrapper (MySQL resolves the input column first).
SELECT option_value AS option_name, COUNT(*) FROM wp_options GROUP BY option_name
SELECT userID AS x, COUNT(*) FROM wp_wfhits h GROUP BY h.statusCode
-- D-83 review: a keyword-spelled alias (AS date) is still the grouping
-- key when no input column shadows it.
SELECT jsRun AS date, COUNT(*) FROM wp_wfhits GROUP BY date
