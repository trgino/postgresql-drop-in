<?php
// compat fixture: wpdb call sites (docs/10 §2.1)
function slonik_fixture_run( $wpdb, $container, $notwpdb ) {
	$wpdb->get_results( 'SELECT ID FROM wp_posts' );
	$wpdb->get_row( 'SELECT * FROM wp_options LIMIT 1' );
	$wpdb->get_var( 'SELECT COUNT(*) FROM wp_users' );
	$wpdb->get_col( 'SELECT ID FROM wp_posts' );
	$wpdb->query( 'UPDATE wp_options SET option_value = 1' );
	$wpdb->insert( 'wp_posts', array( 'a' => 'b' ) );
	$wpdb->update( 'wp_posts', array( 'a' => 1 ), array( 'ID' => 2 ) );
	$wpdb->delete( 'wp_posts', array( 'ID' => 3 ) );
	$wpdb->replace( 'wp_options', array( 'a' => 'b' ) );
	$wpdb->prepare( 'SELECT * FROM wp_posts WHERE ID = %d', 5 );
	$container->wpdb->query( 'SELECT 1 FROM wp_options' );
	$wpdb->non_compat_method();
	$notwpdb->query( 'SELECT 2' );
	$GLOBALS['wpdb']->query( 'SELECT 3 FROM wp_users' );
	$wpdb->query( 'SELECT 4 FROM wp_terms' );
}
