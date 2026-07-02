<?php
/**
 * Model — database access ONLY.
 *
 * Static methods, pure $wpdb / get_post_meta / options. No request handling, no
 * validation, no hooks. Returns raw data (arrays, ids, booleans). The Controller
 * calls these; nothing here knows a REST request exists.
 *
 * This feature stores its links in post meta (not a custom table):
 *   _snel_lang   → the language a post is written in
 *   _snel_group  → the shared group id across siblings
 * and options: snel_languages, snel_default_lang, snel_enabled_langs,
 * snel_theme_translations.
 *
 * Methods we'll add:
 *   siblings() · translation() · lang_of() · group_of()
 *   meta_rows() (debug) · orphan_posts()
 *   get/save_theme_strings() · get/save_languages()
 *
 * @package Snel\Translations
 */

namespace Snel\Translations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Model {
	// TODO: static query methods (skeleton for now).
}
