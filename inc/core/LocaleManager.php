<?php
/**
 * LocaleManager — single source of truth for language config + detection.
 *
 * Answers: what languages exist, which is default, which is the current request
 * in. Reads the languages list from the snel_languages option, falling back to
 * config/languages.php. Everything else asks LocaleManager, never the raw file.
 *
 * @package Snel\Translations
 */

namespace Snel\Translations\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LocaleManager {
	// config() · supported() · default() · current() · is()
	// TODO: port from theme (skeleton for now).
}
