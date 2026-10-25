<?php
/**
 * Core flows for corpus capture. Run with: wp eval-file flows/core-flows.php
 *
 * Exercises the SQL paths WordPress core uses most. Each section sets the
 * capture label so statements can be traced back to a flow.
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

global $wpdb;

/**
 * Sets the corpus label when the capture drop-in is active.
 *
 * @param string $label Section name.
 */
function slonik_flow( $label ) {
	global $wpdb;
	if ( property_exists( $wpdb, 'corpus_label' ) ) {
		$wpdb->corpus_label = 'flow:' . $label;
	}
	fwrite( STDERR, "[flow] $label\n" );
}

// ---------------------------------------------------------------- fixtures.
slonik_flow( 'fixtures' );
$cats = array();
foreach ( array( 'News', 'Tech', 'Life', 'Travel' ) as $name ) {
	$cats[ $name ] = wp_insert_category( array( 'cat_name' => $name ) );
}
$child = wp_insert_term( 'Gadgets', 'category', array( 'parent' => $cats['Tech'] ) );
$tags  = array( 'alpha', 'beta', 'gamma', 'delta' );

$users = array();
foreach ( array( 'editor1' => 'editor', 'author1' => 'author', 'sub1' => 'subscriber' ) as $login => $role ) {
	$users[ $login ] = wp_insert_user(
		array(
			'user_login' => $login,
			'user_pass'  => 'pw-' . $login,
			'user_email' => $login . '@example.com',
			'role'       => $role,
		)
	);
	update_user_meta( $users[ $login ], 'nickname', ucfirst( $login ) );
	update_user_meta( $users[ $login ], 'favorite_color', 'blue' );
}

$post_ids = array();
for ( $i = 1; $i <= 30; $i++ ) {
	$post_ids[] = wp_insert_post(
		array(
			'post_title'    => "Post $i with 'quotes' and \"doubles\" — ünïcödé",
			'post_content'  => "Content $i: 100% pure, back\\slash, under_score, emoji 😀",
			'post_status'   => ( 0 === $i % 7 ) ? 'draft' : 'publish',
			'post_author'   => array_values( $users )[ $i % 3 ],
			'post_category' => array( array_values( $cats )[ $i % 4 ] ),
			'tags_input'    => array( $tags[ $i % 4 ], $tags[ ( $i + 1 ) % 4 ] ),
			'post_date'     => gmdate( 'Y-m-d H:i:s', strtotime( "-$i days" ) ),
			'meta_input'    => array(
				'views'    => $i * 10,
				'featured' => ( 0 === $i % 5 ) ? '1' : '0',
				'color'    => ( 0 === $i % 2 ) ? 'red' : 'green',
			),
		)
	);
}
foreach ( array_slice( $post_ids, 0, 10 ) as $pid ) {
	wp_insert_comment(
		array(
			'comment_post_ID'  => $pid,
			'comment_content'  => 'Nice post! 50% off',
			'comment_author'   => 'Visitor',
			'comment_approved' => 1,
		)
	);
}
wp_insert_post( array( 'post_title' => 'About', 'post_type' => 'page', 'post_status' => 'publish' ) );

// ---------------------------------------------------------------- WP_Query.
slonik_flow( 'wp_query' );
$queries = array(
	array( 'posts_per_page' => 5 ),
	array( 'posts_per_page' => -1, 'fields' => 'ids' ),
	array( 'posts_per_page' => 5, 'paged' => 2 ),
	array( 's' => 'Post 1', 'posts_per_page' => 10 ),
	array( 's' => '100% "pure" back\\slash' ),
	array( 'category_name' => 'tech' ),
	array( 'tag__in' => array( get_term_by( 'slug', 'alpha', 'post_tag' )->term_id ) ),
	array( 'author' => $users['editor1'], 'orderby' => 'title', 'order' => 'ASC' ),
	array( 'post__in' => array_slice( $post_ids, 0, 5 ), 'orderby' => 'post__in' ),
	array( 'post__not_in' => array_slice( $post_ids, 0, 5 ) ),
	array( 'orderby' => 'rand', 'posts_per_page' => 3 ),
	array( 'orderby' => 'meta_value_num', 'meta_key' => 'views', 'order' => 'DESC' ),
	array( 'orderby' => array( 'meta_value' => 'ASC', 'date' => 'DESC' ), 'meta_key' => 'color' ),
	array(
		'meta_query' => array(
			'relation' => 'OR',
			array( 'key' => 'featured', 'value' => '1' ),
			array( 'key' => 'views', 'value' => array( 50, 150 ), 'type' => 'NUMERIC', 'compare' => 'BETWEEN' ),
			array( 'key' => 'color', 'compare' => 'NOT EXISTS' ),
		),
	),
	array(
		'meta_query' => array(
			'relation' => 'AND',
			array( 'key' => 'color', 'value' => 're', 'compare' => 'LIKE' ),
			array( 'key' => 'views', 'value' => 100, 'compare' => '>=', 'type' => 'NUMERIC' ),
		),
	),
	array(
		'tax_query' => array(
			'relation' => 'AND',
			array( 'taxonomy' => 'category', 'field' => 'slug', 'terms' => array( 'news', 'tech' ) ),
			array( 'taxonomy' => 'post_tag', 'field' => 'name', 'terms' => 'beta', 'operator' => 'NOT IN' ),
		),
	),
	array(
		'date_query' => array(
			array( 'after' => '10 days ago', 'inclusive' => true ),
			array( 'dayofweek' => array( 2, 6 ), 'compare' => 'BETWEEN' ),
		),
	),
	array( 'year' => (int) gmdate( 'Y' ), 'monthnum' => (int) gmdate( 'n' ) ),
	array( 'post_status' => array( 'publish', 'draft' ), 'post_type' => array( 'post', 'page' ) ),
	array( 'no_found_rows' => true, 'posts_per_page' => 5 ),
	array( 'update_post_meta_cache' => false, 'update_post_term_cache' => false ),
);
foreach ( $queries as $args ) {
	$q = new WP_Query( $args );
	$q->get_posts();
}
get_adjacent_post();
wp_count_posts();
wp_get_archives( array( 'echo' => false, 'type' => 'monthly' ) );
wp_get_archives( array( 'echo' => false, 'type' => 'postbypost', 'limit' => 5 ) );
get_pages();

// ---------------------------------------------------------------- users/comments/terms.
slonik_flow( 'user_query' );
new WP_User_Query( array( 'search' => '*editor*', 'search_columns' => array( 'user_login' ) ) );
new WP_User_Query( array( 'meta_key' => 'favorite_color', 'meta_value' => 'blue', 'orderby' => 'display_name' ) );
new WP_User_Query( array( 'role__in' => array( 'editor', 'author' ), 'count_total' => true, 'number' => 2 ) );
count_users();

slonik_flow( 'comment_query' );
new WP_Comment_Query( array( 'status' => 'approve', 'orderby' => 'comment_date_gmt', 'number' => 5 ) );
new WP_Comment_Query( array( 'search' => 'Nice', 'count' => true ) );
wp_count_comments();
get_comments( array( 'post_id' => $post_ids[0], 'hierarchical' => 'threaded' ) );

slonik_flow( 'term_query' );
get_terms( array( 'taxonomy' => 'category', 'hide_empty' => false ) );
get_terms( array( 'taxonomy' => 'post_tag', 'search' => 'al', 'orderby' => 'count', 'order' => 'DESC' ) );
get_terms( array( 'taxonomy' => 'category', 'child_of' => $cats['Tech'], 'hide_empty' => false ) );
get_terms( array( 'taxonomy' => 'category', 'name__like' => 'Te', 'fields' => 'ids' ) );
wp_tag_cloud( array( 'echo' => false ) );
wp_list_categories( array( 'echo' => false, 'show_count' => true ) );

// ---------------------------------------------------------------- options/transients/meta.
slonik_flow( 'options' );
add_option( 'slonik_test', array( 'a' => 1 ) );
update_option( 'slonik_test', 'str with \'quote\'' );
get_option( 'slonik_test' );
set_transient( 'slonik_tr', 'v', 3600 );
get_transient( 'slonik_tr' );
delete_transient( 'slonik_tr' );
delete_option( 'slonik_test' );
wp_load_alloptions( true );
wp_cache_flush();
wp_load_alloptions( true );

slonik_flow( 'meta' );
foreach ( array_slice( $post_ids, 0, 5 ) as $pid ) {
	update_post_meta( $pid, 'views', 999 );
	add_post_meta( $pid, 'multi', 'a' );
	add_post_meta( $pid, 'multi', 'b' );
	delete_post_meta( $pid, 'multi', 'a' );
	get_post_meta( $pid );
}
update_metadata( 'user', $users['editor1'], 'favorite_color', 'green' );
delete_metadata( 'post', 0, 'featured', '', true );

// ---------------------------------------------------------------- mutate.
slonik_flow( 'mutate' );
wp_update_post( array( 'ID' => $post_ids[0], 'post_title' => 'Updated' ) );
wp_trash_post( $post_ids[1] );
wp_untrash_post( $post_ids[1] );
wp_delete_post( $post_ids[2], true );
wp_delete_term( $child['term_id'], 'category' );
wp_delete_user( $users['sub1'] );

slonik_flow( 'cron' );
wp_schedule_single_event( time() + 60, 'slonik_hook', array( 1 ) );
wp_schedule_event( time() + 120, 'hourly', 'slonik_recur' );
_get_cron_array();
wp_unschedule_hook( 'slonik_hook' );

slonik_flow( 'nav_menu' );
$menu = wp_create_nav_menu( 'Main' );
wp_update_nav_menu_item( $menu, 0, array( 'menu-item-title' => 'Home', 'menu-item-url' => home_url( '/' ), 'menu-item-status' => 'publish' ) );
wp_get_nav_menu_items( $menu );

slonik_flow( 'site_health_misc' );
get_option( 'siteurl' );
$wpdb->get_var( 'SELECT VERSION()' );
$wpdb->db_version();
fwrite( STDERR, "[flow] done\n" );
