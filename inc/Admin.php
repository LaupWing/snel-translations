<?php
/**
 * Admin — the wp-admin surface for the plugin.
 *
 * Registers the "Snel Translations" menu page, renders the React mount point,
 * and enqueues the built admin app (build/admin/…) + CodeMirror. Also localizes
 * the data the React app reads on load (restUrl, nonce, languages, themeStrings,
 * menuItems, …) and the AI-translate config.
 *
 * Frontend/runtime hooks (rewrite, permalinks, nav) do NOT live here — that's
 * the core engine. This class is admin-only.
 *
 * @package Snel\Translations
 */

namespace Snel\Translations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin {

	public function __construct() {
		// add_action( 'admin_menu', [ $this, 'menu' ] );
		// add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
	}

	/** Registers the admin menu page. */
	public function menu(): void {
		// TODO
	}

	/** Enqueues the built React app + localizes data. */
	public function assets( $hook ): void {
		// TODO
	}

	/** Echoes the React root div. */
	public function render(): void {
		// echo '<div class="wrap"><div id="snel-translations-root"></div></div>';
	}
}
