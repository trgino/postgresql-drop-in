-- Statements whose ROW COUNT is compared (MySQL 8.4 is the oracle).
-- Format: <MySQL statement> ||| <expected row count>
SELECT DISTINCT a FROM slonik_distinct_demo ORDER BY b ||| 2
SELECT DISTINCT a FROM slonik_distinct_demo ORDER BY b DESC ||| 2
SELECT DISTINCT a FROM slonik_distinct_demo ||| 2
SELECT a FROM slonik_distinct_demo ORDER BY b ||| 3
