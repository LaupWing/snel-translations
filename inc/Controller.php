<?php
/**
 * Controller — business logic.
 *
 * Receives WP_REST_Request, validates + sanitizes input, orchestrates the work
 * (calls Model for data, the AI translator for text), returns WP_REST_Response
 * or WP_Error. This is where the "thinking" happens. It never writes SQL — that
 * is Model's job.
 *
 * Methods we'll add (ported from the theme's AJAX/REST handlers):
 *   create_translation() · sync_translation() · translation_state()
 *   get/save theme_strings() · get_pages()
 *   get/save languages_config() · debug() · orphans() · orphan_action()
 *
 * @package Snel\Translations
 */

namespace Snel\Translations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Controller {
	// TODO: controller methods (skeleton for now).
}
