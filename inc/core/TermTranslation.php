<?php
/**
 * TermTranslation — translated taxonomy labels (shared term).
 *
 * A term is NOT duplicated per language. One term keeps its native name/slug;
 * the translated name + description live in term meta (_snel_name_{lang},
 * _snel_desc_{lang}). A get_term filter swaps them in on the front end.
 *
 * @package Snel\Translations
 */

namespace Snel\Translations\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TermTranslation {
	// register() · name() · description() · filterTerm()
	// TODO: port from theme (skeleton for now).
	public static function register(): void {}
}
