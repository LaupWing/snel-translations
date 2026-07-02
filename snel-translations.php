<?php
/**
 * Plugin Name:       Snel Translations
 * Description:       Lightweight multilingual for WordPress — one post per language, no page bloat.
 * Version:           0.1.0
 * Author:            Snelstack
 * License:           GPL-2.0-or-later
 * Text Domain:       snel
 *
 * @package Snel\Translations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Constants ───────────────────────────────────────────────────────────────
define( 'SNEL_TR_VERSION', '0.1.0' );
define( 'SNEL_TR_FILE', __FILE__ );
define( 'SNEL_TR_DIR', plugin_dir_path( __FILE__ ) );  // .../plugins/snel-translations/
define( 'SNEL_TR_URL', plugin_dir_url( __FILE__ ) );   // https://site/.../snel-translations/

// ─── Boot ────────────────────────────────────────────────────────────────────
// The main file does almost nothing itself: it hands off to Boot, which is the
// single place that loads files and wires the layers together.
require_once SNEL_TR_DIR . 'inc/Boot.php';

add_action( 'plugins_loaded', [ '\Snel\Translations\Boot', 'init' ] );

// Activation writes rewrite rules (so /en/ URLs resolve); deactivation clears them.
register_activation_hook( __FILE__, [ '\Snel\Translations\Boot', 'activate' ] );
register_deactivation_hook( __FILE__, function () { flush_rewrite_rules(); } );
