CREATE TABLE diff_items (
  id bigint(20) unsigned NOT NULL auto_increment,
  name varchar(200) NOT NULL default '',
  score int(11) DEFAULT NULL,
  ratio decimal(10,2) DEFAULT NULL,
  created datetime NOT NULL default '0000-00-00 00:00:00',
  payload text,
  PRIMARY KEY (id),
  KEY name (name(191))
);
INSERT INTO diff_items (name, score, ratio, created, payload) VALUES
  ('alpha', 10, 1.50, '2026-01-01 10:00:00', 'hello'),
  ('Beta', 3, NULL, '2026-01-02 11:00:00', NULL),
  ('ALPHA', 7, 2.25, '0000-00-00 00:00:00', 'MiXeD'),
  ('beta', NULL, 0.50, '2026-01-03 12:00:00', ''),
  ('gamma', 10, 1.50, '2026-01-04 13:00:00', 'café'),
  ('slash', 5, 0.75, '2026-01-05 14:00:00', 'a\\b');
