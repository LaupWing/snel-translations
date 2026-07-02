<?php
/**
 * Rest — REST route definitions ONLY.
 *
 * Maps each URL to a Controller method. No business logic, no SQL here.
 * All routes live under the `snel-translations/v1` namespace (the React app
 * already calls these paths).
 *
 * Routes we'll register (ported from the theme):
 *   GET/POST  /theme-strings        read / save UI-string translations
 *   GET       /pages                page block-translation overview
 *   GET/POST  /languages-config     read / save the languages JSON
 *   GET       /debug                read-only DB dump
 *   GET       /orphans              posts in a removed language
 *   POST      /orphan-action        re-add language / trash / delete
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

	public function __construct() {
		$this->controller = new Controller();
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/** register_rest_route() calls go here, each pointing at a Controller method. */
	public function register_routes(): void {
		// TODO: register routes (skeleton for now).
	}

	/** Shared permission gate for the admin endpoints. */
	public function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}
}
