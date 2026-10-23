<?php
/**
 * Plugin Name:       Mendbird Helper
 * Plugin URI:        https://mendbird.com/helper
 * Description:       Lets Mendbird read the exact version of WordPress, your plugins, your theme, PHP and your database, so it can check each one against the lists of known security flaws every morning. It only reads, sends no content or personal details, and answers only Mendbird. See what it sends under Tools, Mendbird helper.
 * Version:           1.0.0
 * Requires at least: 5.5
 * Requires PHP:      7.4
 * Author:            Mendbird
 * Author URI:        https://mendbird.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * How it works, in full, because you should be able to check:
 *
 * 1. Once a day Mendbird asks for the list at https://your-site/?mendbird-helper, with the time and a signature made
 *    from the secret key below. The request carries nothing else: no commands, no settings, no code.
 * 2. If the signature is right and the time is within five minutes, this plugin replies with the list, signed with
 *    the same key so Mendbird knows it was not changed on the way. Otherwise it does nothing at all, and your site
 *    shows its normal page, so nobody else can tell it is here.
 * 3. The list is names and version numbers, and five settings: whether debug mode shows errors to visitors, whether
 *    admins can edit code from the dashboard, whether plugins update themselves, whether PHP announces its version,
 *    and how many admin accounts there are (a number, never names).
 *
 * It never reads posts, pages, comments, orders, form entries or user details, never reads wp-config.php, never
 * contacts anyone, and never changes anything. Delete it and it stops. The code is public at
 * https://github.com/moonlathe-technologies/mendbird-helper
 */

defined( 'ABSPATH' ) || exit;

// Written in by Mendbird when you downloaded this copy. "Make a new key" in your Mendbird account retires it.
define( 'MENDBIRD_HELPER_KEY', '__MENDBIRD_KEY__' );
define( 'MENDBIRD_HELPER_HOST', '__MENDBIRD_HOST__' );
define( 'MENDBIRD_HELPER_VERSION', '1.0.0' );

/** The list: names, versions and five settings. Nothing else is ever read. */
function mendbird_helper_list() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	$software = array( array( 'kind' => 'cms', 'name' => 'wordpress', 'version' => get_bloginfo( 'version' ) ) );

	$auto = (array) get_site_option( 'auto_update_plugins', array() );
	$all_auto = true;
	foreach ( get_plugins() as $file => $data ) {
		$active = is_plugin_active( $file );
		$slug = false === strpos( $file, '/' ) ? basename( $file, '.php' ) : dirname( $file );
		$software[] = array( 'kind' => 'plugin', 'name' => $slug, 'version' => (string) $data['Version'], 'active' => $active );
		if ( $active && ! in_array( $file, $auto, true ) ) {
			$all_auto = false;
		}
	}
	foreach ( wp_get_themes() as $slug => $theme ) {
		$software[] = array( 'kind' => 'theme', 'name' => (string) $slug, 'version' => (string) $theme->get( 'Version' ), 'active' => in_array( $slug, array( get_stylesheet(), get_template() ), true ) );
	}

	$software[] = array( 'kind' => 'php', 'name' => 'php', 'version' => PHP_VERSION );
	foreach ( get_loaded_extensions() as $ext ) {
		$software[] = array( 'kind' => 'ext', 'name' => strtolower( $ext ), 'version' => (string) phpversion( $ext ) );
	}
	// For example "10.11.6-MariaDB-0+deb12u1" or "8.0.36".
	$db = method_exists( $wpdb, 'db_server_info' ) ? (string) $wpdb->db_server_info() : '';
	if ( preg_match( '/^([\d.]+)/', $db, $m ) ) {
		$software[] = array( 'kind' => 'db', 'name' => false !== stripos( $db, 'mariadb' ) ? 'mariadb' : 'mysql', 'version' => $m[1] );
	}
	// For example "Apache/2.4.58 (Ubuntu)" or "nginx/1.24.0".
	$server = isset( $_SERVER['SERVER_SOFTWARE'] ) ? (string) $_SERVER['SERVER_SOFTWARE'] : '';
	if ( preg_match( '/^(apache|nginx|litespeed|openresty)(?:\/([\d.]+))?/i', $server, $m ) ) {
		$software[] = array( 'kind' => 'server', 'name' => strtolower( $m[1] ), 'version' => isset( $m[2] ) ? $m[2] : '' );
	}

	$forced = apply_filters( 'auto_update_plugin', null, (object) array( 'plugin' => '' ) );
	$users = count_users();
	return array(
		'helper'   => array( 'kind' => 'wordpress', 'version' => MENDBIRD_HELPER_VERSION ),
		'software' => $software,
		'settings' => array(
			'debug_on'              => defined( 'WP_DEBUG' ) && WP_DEBUG && ( ! defined( 'WP_DEBUG_DISPLAY' ) || WP_DEBUG_DISPLAY ),
			'dashboard_code_editor' => ! ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) && ! ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ),
			'plugin_auto_updates'   => ! ( defined( 'AUTOMATIC_UPDATER_DISABLED' ) && AUTOMATIC_UPDATER_DISABLED ) && ( true === $forced || $all_auto ),
			'php_version_announced' => (bool) ini_get( 'expose_php' ),
			'admins'                => isset( $users['avail_roles']['administrator'] ) ? (int) $users['avail_roles']['administrator'] : 0,
		),
	);
}

/** Answers Mendbird's signed request, and nobody else's. */
function mendbird_helper_answer() {
	if ( ! isset( $_GET['mendbird-helper'] ) ) {
		return;
	}
	$time = isset( $_SERVER['HTTP_MENDBIRD_TIME'] ) ? (string) $_SERVER['HTTP_MENDBIRD_TIME'] : '';
	$sign = isset( $_SERVER['HTTP_MENDBIRD_SIGN'] ) ? strtolower( (string) $_SERVER['HTTP_MENDBIRD_SIGN'] ) : '';
	if ( ! ctype_digit( $time ) || abs( time() - (int) $time ) > 300 || 64 !== strlen( $sign ) ) {
		return;
	}
	if ( ! hash_equals( hash_hmac( 'sha256', 'pull:' . MENDBIRD_HELPER_HOST . ':' . $time, MENDBIRD_HELPER_KEY ), $sign ) ) {
		return;
	}
	// Each signed request works once.
	if ( (int) get_option( 'mendbird_helper_last', 0 ) >= (int) $time ) {
		return;
	}
	update_option( 'mendbird_helper_last', (int) $time, false );

	$body = wp_json_encode( mendbird_helper_list() );
	nocache_headers();
	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'X-Robots-Tag: noindex' );
	header( 'Mendbird-Sign: ' . hash_hmac( 'sha256', 'reply:' . $time . ':' . $body, MENDBIRD_HELPER_KEY ) );
	echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON, sent only to Mendbird.
	exit;
}
add_action( 'init', 'mendbird_helper_answer', 0 );

/** Tools, Mendbird helper: exactly what it sends, for anyone who can manage the site. */
function mendbird_helper_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	// The tables are drawn from the same list Mendbird receives, so they can never show less than is sent.
	$list  = mendbird_helper_list();
	$kinds = array( 'cms' => 'WordPress', 'plugin' => 'Plugin', 'theme' => 'Theme', 'php' => 'PHP', 'db' => 'Database', 'server' => 'Web server' );
	$rows  = '';
	$exts  = array();
	foreach ( $list['software'] as $item ) {
		if ( 'ext' === $item['kind'] ) {
			$exts[] = $item['name'] . ( '' !== $item['version'] ? ' ' . $item['version'] : '' );
			continue;
		}
		$use   = isset( $item['active'] ) ? ( $item['active'] ? 'Switched on' : 'Switched off' ) : '';
		$rows .= '<tr><td>' . esc_html( $kinds[ $item['kind'] ] ) . '</td><td>' . esc_html( $item['name'] ) . '</td><td>' . esc_html( $item['version'] ) . '</td><td>' . esc_html( $use ) . '</td></tr>';
	}
	$labels = array(
		'debug_on'              => 'Debug mode shows errors to visitors',
		'dashboard_code_editor' => 'Admins can edit code from the dashboard',
		'plugin_auto_updates'   => 'Plugins update themselves',
		'php_version_announced' => 'PHP tells visitors its version',
		'admins'                => 'Admin accounts (a count, never names)',
	);
	$settings = '';
	foreach ( $list['settings'] as $key => $value ) {
		$settings .= '<tr><td>' . esc_html( $labels[ $key ] ) . '</td><td>' . esc_html( is_bool( $value ) ? ( $value ? 'Yes' : 'No' ) : (string) $value ) . '</td></tr>';
	}

	echo '<div class="wrap"><h1>Mendbird helper</h1>';
	echo '<p>Once a day Mendbird asks this site for the list below, and checks every version against the lists of known security flaws. Nothing else is sent, and the helper never changes anything. To stop it, deactivate or delete the plugin.</p>';
	echo '<h2>Software</h2><table class="widefat striped"><thead><tr><th>Kind</th><th>Name</th><th>Version</th><th>In use</th></tr></thead><tbody>' . $rows . '</tbody></table>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
	echo '<p>' . esc_html( sprintf( 'PHP add-ons (%d): %s.', count( $exts ), implode( ', ', $exts ) ) ) . '</p>';
	echo '<h2>Settings</h2><table class="widefat striped"><tbody>' . $settings . '</tbody></table>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
	echo '<details style="margin-top:1.5em"><summary>Show the exact data, as Mendbird receives it</summary>';
	echo '<pre style="background:#fff;border:1px solid #ccd0d4;padding:12px;max-height:60vh;overflow:auto;white-space:pre-wrap">' . esc_html( wp_json_encode( $list, JSON_PRETTY_PRINT ) ) . '</pre></details></div>';
}
add_action(
	'admin_menu',
	function () {
		add_management_page( 'Mendbird helper', 'Mendbird helper', 'manage_options', 'mendbird-helper', 'mendbird_helper_page' );
	}
);

register_uninstall_hook( __FILE__, 'mendbird_helper_uninstall' );
function mendbird_helper_uninstall() {
	delete_option( 'mendbird_helper_last' );
}
