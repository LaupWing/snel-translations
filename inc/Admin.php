<?php
/**
 * Admin — the wp-admin surface for the plugin.
 *
 * Registers the "Snel Translations" top-level menu page, renders the React mount
 * point, enqueues the built admin app (+ CodeMirror), and localizes the data the
 * React app reads (restUrl, nonce, languages, themeStrings, menuItems, …) plus
 * the AI-translate config. Also enqueues the editor sidebar bundle.
 *
 * @package Snel\Translations
 */

namespace Snel\Translations;

use Snel\Translations\Core\LocaleManager;
use Snel\Translations\Core\TranslationGroup;
use Snel\Translations\Core\Translator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin {

	const PAGE = 'snel-translations';

	/** Register admin hooks. Called from Boot when live. */
	public function register(): void {
		// Priority 99 so a parent menu (e.g. the theme's "Snel Stack") exists first.
		add_action( 'admin_menu', [ $this, 'menu' ], 99 );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
		add_action( 'enqueue_block_editor_assets', [ $this, 'editor_assets' ] );
	}

	/**
	 * Register the admin page. Sits under the "Snel Stack" menu when it exists;
	 * falls back to a top-level page otherwise (so it's never orphaned). The
	 * parent slug is filterable via `snel_translations_parent_menu`.
	 */
	public function menu(): void {
		$parent = apply_filters( 'snel_translations_parent_menu', 'snelstack' );

		if ( $parent && isset( $GLOBALS['admin_page_hooks'][ $parent ] ) ) {
			add_submenu_page(
				$parent,
				__( 'Snel Translations', 'snel' ),
				__( 'Translations', 'snel' ),
				'manage_options',
				self::PAGE,
				[ $this, 'render' ]
			);
			return;
		}

		add_menu_page(
			__( 'Snel Translations', 'snel' ),
			__( 'Translations', 'snel' ),
			'manage_options',
			self::PAGE,
			[ $this, 'render' ],
			'dashicons-translation',
			58
		);
	}

	/** The React mount point. */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="wrap"><div id="snel-translations-root"></div></div>';
	}

	/** Enqueue the admin app + CodeMirror + localize data (our page only). */
	public function assets( $hook ): void {
		if ( strpos( (string) $hook, self::PAGE ) === false ) {
			return;
		}

		$dir = SNEL_TR_DIR . 'build/admin/translations/';
		$url = SNEL_TR_URL . 'build/admin/translations/';
		if ( ! file_exists( $dir . 'index.asset.php' ) ) {
			return; // not built yet
		}
		$asset = require $dir . 'index.asset.php';

		wp_enqueue_script( 'snel-translations-admin', $url . 'index.js', $asset['dependencies'], $asset['version'], true );
		wp_enqueue_style( 'snel-translations-admin', $url . 'index.css', [ 'wp-components' ], $asset['version'] );

		wp_enqueue_code_editor( [ 'type' => 'application/json' ] );

		$default = LocaleManager::default();
		$enabled = LocaleManager::supported();
		$config  = LocaleManager::config();

		wp_localize_script( 'snel-translations-admin', 'snelTranslations', [
			'restUrl'           => rest_url( 'snel-translations/v1' ),
			'nonce'             => wp_create_nonce( 'wp_rest' ),
			'languages'         => array_map( function ( $code ) use ( $default, $enabled, $config ) {
				return [
					'code'    => $code,
					'label'   => $config[ $code ]['label'] ?? strtoupper( $code ),
					'default' => $code === $default,
					'enabled' => in_array( $code, $enabled, true ),
				];
			}, array_keys( $config ) ),
			'defaultLang'       => $default,
			'translationsExist' => TranslationGroup::translationsExist(),
			'themeStrings'      => Translator::grouped(),
			'menuItems'         => Nav::menuItems(),
			'menuEditUrl'       => admin_url( 'nav-menus.php' ),
		] );

		wp_localize_script( 'snel-translations-admin', 'snelTranslate', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'snel_translate_nonce' ),
			'langs'   => $enabled,
			'default' => $default,
		] );
	}

	/** Enqueue the editor sidebar bundle (Create::editor_data localizes onto it). */
	public function editor_assets(): void {
		$dir = SNEL_TR_DIR . 'build/editor/snelstack/';
		$url = SNEL_TR_URL . 'build/editor/snelstack/';
		if ( ! file_exists( $dir . 'index.asset.php' ) ) {
			return; // not built yet
		}
		$asset = require $dir . 'index.asset.php';

		wp_enqueue_script( 'snel-editor-snelstack', $url . 'index.js', $asset['dependencies'], $asset['version'], true );

		wp_localize_script( 'snel-editor-snelstack', 'snelTranslate', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'snel_translate_nonce' ),
			'langs'   => LocaleManager::supported(),
			'default' => LocaleManager::default(),
		] );
	}
}
