<?php
/**
 * Plugin Name:       Mendbird Helper
 * Plugin URI:        https://mendbird.com/helper
 * Description:       Lets Mendbird read the exact version of WordPress, your plugins, your theme, PHP and your database, so it can check each one against the lists of known security flaws every morning. It only reads, sends no content or personal details, and answers only Mendbird. See what it sends under Tools, Mendbird helper.
 * Version:           1.1.0
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
 * 4. Connecting. A copy downloaded from your Mendbird account has its key written in below. A copy from wordpress.org
 *    has none, so under Tools, Mendbird helper you press Connect to Mendbird: it takes you to mendbird.com to sign in
 *    or start a free trial and confirm the site, then brings you back with a one time code. This plugin swaps that
 *    code for the key with one request to https://mendbird.com/api/connect, sending only the code, and keeps the key
 *    in this site's options. You can paste a key from your account instead.
 *
 * It never reads posts, pages, comments, orders, form entries or user details, never reads wp-config.php, contacts
 * nobody but Mendbird and only when you connect it, and never changes anything else. Delete it and it stops. The code
 * is public at https://github.com/moonlathe-technologies/mendbird-helper
 */

defined( 'ABSPATH' ) || exit;

// Written in by Mendbird when you downloaded this copy from your account; left as they are in the copy from
// wordpress.org, which is connected from Tools, Mendbird helper instead. "Make a new key" in your account retires it.
define( 'MENDBIRD_HELPER_KEY', '__MENDBIRD_KEY__' );
define( 'MENDBIRD_HELPER_HOST', '__MENDBIRD_HOST__' );
define( 'MENDBIRD_HELPER_VERSION', '1.1.0' );
if ( ! defined( 'MENDBIRD_HELPER_URL' ) ) {
	define( 'MENDBIRD_HELPER_URL', 'https://mendbird.com' );
}

/** The key and the site name it signs for: written into this copy, or saved when it was connected. Null if neither. */
function mendbird_helper_key() {
	// Split in two, so writing the key in leaves this comparison alone.
	if ( '__MENDBIRD' . '_KEY__' !== MENDBIRD_HELPER_KEY ) {
		return array( 'key' => MENDBIRD_HELPER_KEY, 'host' => MENDBIRD_HELPER_HOST, 'written' => true );
	}
	$saved = get_option( 'mendbird_helper_connection' );
	return is_array( $saved ) && ! empty( $saved['key'] ) && ! empty( $saved['host'] ) ? $saved + array( 'written' => false ) : null;
}

/** This site's name without www, to check a key is for this site and not another. */
function mendbird_helper_bare( $host ) {
	return preg_replace( '/^www\./', '', strtolower( (string) $host ) );
}
function mendbird_helper_site() {
	return (string) wp_parse_url( home_url(), PHP_URL_HOST );
}

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
	$server = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '';
	if ( preg_match( '/^(apache|nginx|litespeed|openresty)(?:\/([\d.]+))?/i', $server, $m ) ) {
		$software[] = array( 'kind' => 'server', 'name' => strtolower( $m[1] ), 'version' => isset( $m[2] ) ? $m[2] : '' );
	}

	// Only read: whether a setting or another plugin makes every plugin update itself.
	$forced = function_exists( 'wp_is_auto_update_forced_for_item' ) && wp_is_auto_update_forced_for_item( 'plugin', null, (object) array( 'plugin' => '' ) );
	$users = count_users();
	return array(
		'helper'   => array( 'kind' => 'wordpress', 'version' => MENDBIRD_HELPER_VERSION ),
		'software' => $software,
		'settings' => array(
			'debug_on'              => defined( 'WP_DEBUG' ) && WP_DEBUG && ( ! defined( 'WP_DEBUG_DISPLAY' ) || WP_DEBUG_DISPLAY ),
			'dashboard_code_editor' => ! ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) && ! ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ),
			'plugin_auto_updates'   => ! ( defined( 'AUTOMATIC_UPDATER_DISABLED' ) && AUTOMATIC_UPDATER_DISABLED ) && ( $forced || $all_auto ),
			'php_version_announced' => (bool) ini_get( 'expose_php' ),
			'admins'                => isset( $users['avail_roles']['administrator'] ) ? (int) $users['avail_roles']['administrator'] : 0,
		),
	);
}

/** Answers Mendbird's signed request, and nobody else's. */
function mendbird_helper_answer() {
	// No nonce: Mendbird's request is not a form, and its signature below does that job.
	if ( ! isset( $_GET['mendbird-helper'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}
	$time = isset( $_SERVER['HTTP_MENDBIRD_TIME'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_MENDBIRD_TIME'] ) ) : '';
	$sign = isset( $_SERVER['HTTP_MENDBIRD_SIGN'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_MENDBIRD_SIGN'] ) ) ) : '';
	$creds = mendbird_helper_key();
	if ( ! $creds || ! ctype_digit( $time ) || abs( time() - (int) $time ) > 300 || 64 !== strlen( $sign ) ) {
		return;
	}
	if ( ! hash_equals( hash_hmac( 'sha256', 'pull:' . $creds['host'] . ':' . $time, $creds['key'] ), $sign ) ) {
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
	header( 'Mendbird-Sign: ' . hash_hmac( 'sha256', 'reply:' . $time . ':' . $body, $creds['key'] ) );
	echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON, sent only to Mendbird.
	exit;
}
add_action( 'init', 'mendbird_helper_answer', 0 );

/**
 * Connecting, step one: a random state for this visit, kept for half an hour, and the address on mendbird.com to send
 * the admin to. Mendbird sends them back to this site's Tools page with the same state and a one time code.
 */
function mendbird_helper_connect_url() {
	$state = wp_generate_password( 32, false );
	set_transient( 'mendbird_helper_state', $state, 30 * MINUTE_IN_SECONDS );
	return MENDBIRD_HELPER_URL . '/connect?' . http_build_query(
		array(
			'site'  => mendbird_helper_site(),
			'back'  => admin_url( 'tools.php?page=mendbird-helper' ),
			'state' => $state,
		)
	);
}

/**
 * Step two: the state must be the one this site made, then the code is swapped for the key with one request to
 * Mendbird. The key comes back over that request, never through the browser. Returns true, or a message.
 */
function mendbird_helper_finish( $code, $state ) {
	$expected = get_transient( 'mendbird_helper_state' );
	if ( ! is_string( $expected ) || ! hash_equals( $expected, (string) $state ) || ! preg_match( '/^[\w-]{20,100}$/', (string) $code ) ) {
		return 'That link has run out or was not started from this site. Press Connect to Mendbird again.';
	}
	// Used up only by the right way back, so a wrong link cannot cancel a connection that is under way.
	delete_transient( 'mendbird_helper_state' );
	$res = wp_remote_post(
		MENDBIRD_HELPER_URL . '/api/connect',
		array(
			'timeout' => 15,
			'headers' => array( 'content-type' => 'application/json' ),
			'body'    => wp_json_encode( array( 'code' => (string) $code ) ),
		)
	);
	$reply = is_wp_error( $res ) ? null : json_decode( wp_remote_retrieve_body( $res ), true );
	if ( 200 !== wp_remote_retrieve_response_code( $res ) || ! is_array( $reply ) ) {
		return 'Mendbird could not be reached just now, or the code had run out. Press Connect to Mendbird again.';
	}
	return mendbird_helper_save( isset( $reply['key'] ) ? $reply['key'] : '', isset( $reply['host'] ) ? $reply['host'] : '', isset( $reply['site'] ) ? $reply['site'] : 0 );
}

/** A key pasted from the Mendbird account instead: "mb1:" then the site's name, then the key. */
function mendbird_helper_paste( $text ) {
	$parts = explode( ':', trim( (string) $text ) );
	if ( 3 !== count( $parts ) || 'mb1' !== $parts[0] ) {
		return 'That does not look like a Mendbird helper key. Copy the whole line from your account, starting mb1.';
	}
	return mendbird_helper_save( $parts[2], $parts[1], 0 );
}

/** Keeps a key, if it is for this site. */
function mendbird_helper_save( $key, $host, $site ) {
	if ( ! preg_match( '/^[\w-]{40,64}$/', (string) $key ) || ! preg_match( '/^[a-z0-9.-]{1,253}$/i', (string) $host ) ) {
		return 'Mendbird sent back something unexpected. Press Connect to Mendbird again.';
	}
	if ( mendbird_helper_bare( $host ) !== mendbird_helper_bare( mendbird_helper_site() ) ) {
		return sprintf( 'That key is for %s, but this site is %s. Use the key for this site.', $host, mendbird_helper_site() );
	}
	update_option( 'mendbird_helper_connection', array( 'key' => (string) $key, 'host' => strtolower( (string) $host ), 'site' => (int) $site ), false );
	return true;
}

/** The buttons on the Tools page post here. Coming back from Mendbird with a code lands on the page itself. */
function mendbird_helper_actions() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$back = admin_url( 'tools.php?page=mendbird-helper' );
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the state made for this visit does a nonce's job.
	if ( isset( $_GET['page'], $_GET['mendbird_code'], $_GET['mendbird_state'] ) && 'mendbird-helper' === $_GET['page'] ) {
		$done = mendbird_helper_finish( sanitize_text_field( wp_unslash( $_GET['mendbird_code'] ) ), sanitize_text_field( wp_unslash( $_GET['mendbird_state'] ) ) );
		mendbird_helper_tell( $done, 'Connected. Mendbird reads this list every morning from now on.' );
		wp_safe_redirect( $back );
		exit;
	}
	// phpcs:enable
	if ( ! isset( $_POST['mendbird_helper'] ) ) {
		return;
	}
	check_admin_referer( 'mendbird_helper' );
	$what = sanitize_key( wp_unslash( $_POST['mendbird_helper'] ) );
	if ( 'connect' === $what ) {
		// Off to mendbird.com, which sends the admin back here.
		wp_redirect( mendbird_helper_connect_url() ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Mendbird's own address.
		exit;
	}
	if ( 'paste' === $what && isset( $_POST['mendbird_key'] ) ) {
		mendbird_helper_tell( mendbird_helper_paste( sanitize_text_field( wp_unslash( $_POST['mendbird_key'] ) ) ), 'Connected with the key you pasted.' );
	}
	if ( 'disconnect' === $what ) {
		delete_option( 'mendbird_helper_connection' );
		mendbird_helper_tell( true, 'Disconnected. Mendbird can no longer read this list.' );
	}
	wp_safe_redirect( $back );
	exit;
}
add_action( 'admin_init', 'mendbird_helper_actions' );

/** One message for the next view of the Tools page. */
function mendbird_helper_tell( $done, $ok ) {
	set_transient( 'mendbird_helper_said_' . get_current_user_id(), true === $done ? array( 'success', $ok ) : array( 'error', (string) $done ), 60 );
}

/** The top of the Tools page: connected or not, and how. */
function mendbird_helper_connection_box() {
	$said = get_transient( 'mendbird_helper_said_' . get_current_user_id() );
	delete_transient( 'mendbird_helper_said_' . get_current_user_id() );
	if ( is_array( $said ) ) {
		echo '<div class="notice notice-' . esc_attr( $said[0] ) . '"><p>' . esc_html( $said[1] ) . '</p></div>';
	}
	$creds = mendbird_helper_key();
	$form  = function ( $what, $inner, $button, $class ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'tools.php?page=mendbird-helper' ) ) . '" style="margin:.6em 0">';
		wp_nonce_field( 'mendbird_helper' );
		echo '<input type="hidden" name="mendbird_helper" value="' . esc_attr( $what ) . '">' . $inner . ' '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup.
		submit_button( $button, $class, 'mendbird_' . $what, false );
		echo '</form>';
	};
	echo '<div class="card" style="max-width:none">';
	if ( $creds && $creds['written'] ) {
		echo '<h2>Connected</h2><p>' . esc_html( sprintf( 'This copy was downloaded from your Mendbird account for %s, with its key written in. To stop it, deactivate or delete the plugin.', $creds['host'] ) ) . '</p>';
	} elseif ( $creds ) {
		echo '<h2>Connected</h2><p>' . esc_html( sprintf( 'Mendbird reads this list every morning for %s.', $creds['host'] ) ) . '</p>';
		$form( 'disconnect', '', 'Disconnect', 'secondary' );
	} else {
		echo '<h2>Connect to Mendbird</h2><p>Mendbird checks everything below against the lists of known security flaws every morning, and tells you what needs fixing in plain English. Connecting takes you to mendbird.com to sign in, or to start a free trial, and brings you back here.</p>';
		$form( 'connect', '', 'Connect to Mendbird', 'primary' );
		echo '<details><summary>Have a key from your Mendbird account? Paste it instead</summary>';
		$form( 'paste', '<label for="mendbird-key" class="screen-reader-text">Helper key</label><input id="mendbird-key" name="mendbird_key" type="text" class="regular-text code" placeholder="mb1:" autocomplete="off" required>', 'Save the key', 'secondary' );
		echo '</details>';
	}
	echo '</div>';
}

/** The Mendbird bird, drawn inline so the page loads nothing from anywhere else. The same drawing as on mendbird.com. */
function mendbird_helper_bird() {
	return '<svg class="mendbird-bird" viewBox="0 0 300 300" aria-hidden="true"><g transform="translate(9 8)" fill="#ff48b0" fill-opacity=".9" style="mix-blend-mode:multiply"><path fill-rule="evenodd" d="M150 54c66 0 110 48 110 112 0 60-46 100-110 100S40 226 40 166C40 102 84 54 150 54zM150 150c40 0 66 22 66 52s-30 50-66 50-66-20-66-50 26-52 66-52z"/><path d="M44 160c-22 6-34 28-26 46 18-2 34-14 40-30zM130 52c6-18 22-26 34-20-4 10-12 18-26 22zM138 150l12 22 12-22z"/></g><g fill="none" stroke="#0078bf" stroke-width="7" stroke-linecap="round" stroke-linejoin="round"><path d="M150 54c66 0 110 48 110 112 0 60-46 100-110 100S40 226 40 166C40 102 84 54 150 54z"/><path d="M150 150c40 0 66 22 66 52s-30 50-66 50-66-20-66-50 26-52 66-52z"/><path d="M118 196c20 6 44 6 64 0M126 214c16 4 32 4 48 0"/><path d="M44 160c-22 6-34 28-26 46 18-2 34-14 40-30z"/><path d="M130 52c6-18 22-26 34-20-4 10-12 18-26 22"/><circle cx="116" cy="124" r="22"/><circle cx="184" cy="124" r="22"/><path d="M138 150l12 22 12-22z"/><path d="M134 266v16m-10 0h20M168 266v16m-10 0h20"/><g transform="translate(240 170) rotate(30)"><path d="M-7 0h14v62h-14zM-18-2a18 18 0 1 0 36 0v12h-11v-16h-14v16h-11z"/></g></g><circle class="eye" cx="122" cy="128" r="9" fill="#0078bf"/><circle class="eye" cx="190" cy="128" r="9" fill="#0078bf"/></svg>';
}

/** The top of the Tools page: the bird, the name, and the pages on mendbird.com that explain what it finds. */
function mendbird_helper_masthead() {
	$links = array(
		'https://mendbird.com/account' => 'Your Mendbird account',
		'https://mendbird.com/helper'  => 'How the helper works',
		'https://mendbird.com/checks'  => 'Every check explained',
		'https://mendbird.com/flaws'   => 'Kinds of security flaw',
	);
	$items = array();
	foreach ( $links as $url => $label ) {
		$items[] = '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $label ) . '<span class="screen-reader-text"> (opens in a new tab)</span></a>';
	}
	echo '<style>.mendbird-head{display:flex;align-items:center;gap:14px;margin:14px 0 6px}.mendbird-head h1{margin:0;padding:0}.mendbird-bird{width:60px;height:60px;flex:none;background:#fff;border:2px solid #23262d;border-radius:50%;padding:4px;box-sizing:border-box}.mendbird-links{margin:0 0 14px}</style>';
	echo '<div class="mendbird-head">' . mendbird_helper_bird() . '<h1>Mendbird helper</h1></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed drawing.
	echo '<p class="mendbird-links">' . implode( ' | ', $items ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
}

/** Tools, Mendbird helper: exactly what it sends, for anyone who can manage the site. */
function mendbird_helper_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	// The tables are drawn from the same list Mendbird receives, so they can never show less than is sent.
	$list      = mendbird_helper_list();
	$kinds     = array( 'cms' => 'WordPress', 'plugin' => 'Plugin', 'theme' => 'Theme', 'php' => 'PHP', 'db' => 'Database', 'server' => 'Web server' );
	$rows      = '';
	$exts      = '';
	$ext_count = 0;
	foreach ( $list['software'] as $item ) {
		if ( 'ext' === $item['kind'] ) {
			$exts .= '<tr><td>' . esc_html( $item['name'] ) . '</td><td>' . esc_html( $item['version'] ) . '</td></tr>';
			++$ext_count;
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

	echo '<div class="wrap">';
	mendbird_helper_masthead();
	mendbird_helper_connection_box();
	echo '<p>Once connected, Mendbird asks this site for the list below once a day, and checks every version against the lists of known security flaws. Nothing else is sent, and the helper never changes anything.</p>';
	echo '<h2>Software</h2><table class="widefat striped"><thead><tr><th>Kind</th><th>Name</th><th>Version</th><th>In use</th></tr></thead><tbody>' . $rows . '</tbody></table>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
	echo '<h2>Settings</h2><table class="widefat striped"><tbody>' . $settings . '</tbody></table>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
	echo '<h2>' . esc_html( sprintf( 'PHP add-ons (%d)', $ext_count ) ) . '</h2><table class="widefat striped"><thead><tr><th>Name</th><th>Version</th></tr></thead><tbody>' . $exts . '</tbody></table>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
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
	delete_option( 'mendbird_helper_connection' );
}
