<?php
/**
 * Rest — REST route definitions ONLY.
 *
 * Maps each URL under `snel-translations/v1` to a Controller method. No business
 * logic, no SQL here. The React admin app calls these paths.
 *
 * @package Snel\Translations
 */

namespace Snel\Translations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Rest {

	/** @var Controller */
	private $controller;

	private const NS = 'snel-translations/v1';

	public function __construct() {
		$this->controller = new Controller();
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		$c    = $this->controller;
		$perm = [ $this, 'can_manage' ];

		$this->route( '/theme-strings', 'GET',  [ $c, 'theme_strings_get' ],  $perm );
		$this->route( '/theme-strings', 'POST', [ $c, 'theme_strings_save' ], $perm );

		$this->route( '/languages-config', 'GET',  [ $c, 'languages_config_get' ],  $perm );
		$this->route( '/languages-config', 'POST', [ $c, 'languages_config_save' ], $perm );

		$this->route( '/settings', 'POST', [ $c, 'settings_save' ], $perm );

		$this->route( '/debug', 'GET', [ $c, 'debug_get' ], $perm );

		$this->route( '/orphans', 'GET',  [ $c, 'orphans_get' ],   $perm );
		$this->route( '/orphan-action', 'POST', [ $c, 'orphan_action' ], $perm );

		$this->route( '/fields', 'GET',  [ $c, 'fields_get' ],  $perm );
		$this->route( '/fields', 'POST', [ $c, 'fields_save' ], $perm );

		$this->route( '/cpt-slugs', 'GET',  [ $c, 'cptslugs_get' ],  $perm );
		$this->route( '/cpt-slugs', 'POST', [ $c, 'cptslugs_save' ], $perm );
		$this->route( '/cpt-slugs/translate', 'POST', [ $c, 'cptslugs_translate' ], $perm );

		$this->route( '/media/scopes', 'GET', [ $c, 'media_scopes_get' ], $perm );
		$this->route( '/media/list',   'GET', [ $c, 'media_list_get' ],   $perm );

		$this->route( '/bulk/plan', 'GET',  [ $c, 'bulk_plan' ], $perm );
		$this->route( '/bulk/run',  'POST', [ $c, 'bulk_run' ],  $perm );
	}

	/** Thin register_rest_route wrapper. */
	private function route( string $path, string $methods, callable $callback, callable $permission ): void {
		register_rest_route( self::NS, $path, [
			'methods'             => $methods,
			'callback'            => $callback,
			'permission_callback' => $permission,
		] );
	}

	/** Shared permission gate for the admin endpoints. */
	public function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}
}
