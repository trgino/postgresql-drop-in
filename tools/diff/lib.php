<?php
/**
 * Differential helpers (dev only). Pure functions; DB access lives in run.php.
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

function slonik_diff_normalize_row( $row ) {
	$norm = array();
	foreach ( (array) $row as $k => $v ) {
		if ( null === $v ) {
			$norm[ $k ] = null;
			continue;
		}
		if ( true === $v ) {
			$norm[ $k ] = '1';
			continue;
		}
		if ( false === $v ) {
			$norm[ $k ] = '0';
			continue;
		}
		$norm[ $k ] = (string) $v;
	}
	ksort( $norm );
	return $norm;
}

function slonik_diff_normalize( $rows ) {
	$out = array();
	foreach ( (array) $rows as $row ) {
		$out[] = slonik_diff_normalize_row( $row );
	}
	usort(
		$out,
		function ( $a, $b ) {
			return strcmp( json_encode( $a ), json_encode( $b ) );
		}
	);
	return $out;
}

function slonik_diff_shapes_equal( $a, $b, $ordered ) {
	$na = array();
	foreach ( (array) $a as $row ) {
		$na[] = slonik_diff_normalize_row( $row );
	}
	$nb = array();
	foreach ( (array) $b as $row ) {
		$nb[] = slonik_diff_normalize_row( $row );
	}
	if ( $ordered ) {
		return $na === $nb;
	}
	$sa = $na;
	$sb = $nb;
	sort( $sa );
	sort( $sb );
	return $sa == $sb;
}
