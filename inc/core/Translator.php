<?php
/**
 * Translator — static UI-string lookup (snel__).
 *
 * Lookup order: snel_theme_translations option (admin overrides) →
 * config/strings.php defaults → the original text. Also builds the grouped
 * structure the admin grid renders, and saves edits back to the option.
 *
 * @package Snel\Translations
 */

namespace Snel\Translations\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Translator {
	// translate() · grouped() · save()
	// TODO: port from theme (skeleton for now).
}
