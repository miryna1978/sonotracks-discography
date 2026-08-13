<?php
/**
 * 削除時の後始末。
 *
 * ★ WordPress は「削除」を選んだときだけこのファイルを読む（無効化では読まない）。
 *   設定と、取得結果のキャッシュ（transient・last-good の option）を消す。
 *
 * ★ transient は option テーブルに _transient_ 接頭辞で入るため、
 *   接頭辞での一括削除で拾えるようにキー名を揃えてある
 *   （sonotracks_discography.php の CACHE_PREFIX と同じ 'sonotracks_dg_'）。
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'sonotracks_discography_settings' );
delete_option( 'sonotracks_discography_cache_version' );

global $wpdb;

// last-good の option と、その transient（_transient_ / _transient_timeout_）
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( 'sonotracks_dg_' ) . '%',
		$wpdb->esc_like( '_transient_sonotracks_dg_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_sonotracks_dg_' ) . '%'
	)
);
