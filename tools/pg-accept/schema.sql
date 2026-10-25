-- Minimal schema for the PostgreSQL acceptance gate (tools/pg-accept).
-- The same file is applied to MySQL 8.4 (the oracle) and PostgreSQL 16,
-- so every type is valid in both.
DROP TABLE IF EXISTS wp_posts;
DROP TABLE IF EXISTS wp_users;
DROP TABLE IF EXISTS wp_options;
DROP TABLE IF EXISTS wp_unsigned;
DROP TABLE IF EXISTS wp_named_unique;
DROP TABLE IF EXISTS wp_touch;
DROP TABLE IF EXISTS slonik_distinct_demo;
DROP TABLE IF EXISTS slonik_accept_probe;
DROP TABLE IF EXISTS slonik_accept_widths;
DROP TABLE IF EXISTS slonik_accept_bytes;
DROP TABLE IF EXISTS wp_actionscheduler_actions;
DROP TABLE IF EXISTS wp_wfconfig;
DROP TABLE IF EXISTS wp_wfblocks7;
CREATE TABLE slonik_accept_probe (id int NOT NULL);
CREATE TABLE slonik_distinct_demo (
	a varchar(191) NOT NULL,
	b varchar(191) NOT NULL
);
INSERT INTO slonik_distinct_demo (a, b) VALUES ('x', '1'), ('x', '2'), ('y', '3');
CREATE TABLE wp_options (
	option_id bigint PRIMARY KEY,
	option_name varchar(191) NOT NULL,
	option_value varchar(191) NOT NULL DEFAULT ''
);
CREATE TABLE wp_posts (
	id integer PRIMARY KEY,
	post_author integer NOT NULL DEFAULT 0,
	post_title varchar(191) NOT NULL DEFAULT '',
	post_status varchar(191) NOT NULL DEFAULT 'publish'
);
CREATE TABLE wp_users (
	ID integer PRIMARY KEY,
	user_login varchar(60) NOT NULL DEFAULT ''
);
-- The ActionScheduler schedule column: a serialized PHP object with NUL
-- bytes in the property names, so it targets bytea (audit D-74).
CREATE TABLE wp_actionscheduler_actions (
	action_id bigint NOT NULL PRIMARY KEY,
	hook text,
	schedule bytea
);
-- A binary column written with a MySQL hex literal (X'…'): in
-- PostgreSQL the same spelling is a bit literal (audit D-75).
CREATE TABLE wp_wfconfig (
	name varchar(191) NOT NULL PRIMARY KEY,
	val bytea
);
-- Wordfence's block lookup: an int `type` column with the same name as a
-- text column elsewhere, a bytea `IP` column and the UNIX_TIMESTAMP()
-- rewrite (audit D-72). The single-table catalog resolution must pick
-- these types, not the same-named columns of another table (R-075).
CREATE TABLE wp_wfblocks7 (
	id bigint PRIMARY KEY,
	type integer NOT NULL DEFAULT 0,
	ip bytea NOT NULL,
	blockedTime bigint NOT NULL DEFAULT 0,
	reason varchar(255) NOT NULL DEFAULT '',
	blockedHits integer NOT NULL DEFAULT 0,
	parameters varchar(1020) DEFAULT NULL,
	expiration bigint NOT NULL DEFAULT 0
);
INSERT INTO wp_wfblocks7 (id, type, ip, expiration) VALUES (1, 7, decode('00000000000000000000000000000001', 'hex'), 0);
-- Wordfence's live traffic table: the grouping key of its hit query is a
-- select-list alias (audit D-83).
DROP TABLE IF EXISTS wp_wfhits;
CREATE TABLE wp_wfhits (
	id bigint PRIMARY KEY,
	jsRun integer NOT NULL DEFAULT 0,
	userID bigint NOT NULL DEFAULT 0,
	statusCode integer NOT NULL DEFAULT 0,
	ctime double precision NOT NULL DEFAULT 0
);
