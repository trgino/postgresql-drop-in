<?php
// compat fixture: direct handlers (info signals, docs/10 scope notes)
$c = mysqli_connect( '127.0.0.1', 'u', 'p' );
$x = new PDO( 'mysql:host=127.0.0.1', 'u', 'p' );
$g = new PDO( 'pgsql:host=127.0.0.1' );
$h = $wpdb->dbh;
$y = dbDelta( 'CREATE TABLE wp_x (id INT)' );
$i = mysqli_real_escape_string( $c, 'x' );
$n = $wpdb->query( 'SELECT 1 FROM wp_posts' );
