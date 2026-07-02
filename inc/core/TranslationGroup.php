<?php
/**
 * TranslationGroup — the sibling link (the heart of the model).
 *
 * Reads/writes the _snel_lang and _snel_group post meta, finds a post's siblings
 * and its translation in a given language, and filters the front end:
 *   - filterPermalink: injects the /en/ prefix into get_permalink()
 *   - filterArchives:  limits listings to the current language
 * Also enforces slug uniqueness across siblings.
 *
 * @package Snel\Translations
 */

namespace Snel\Translations\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TranslationGroup {
	const META_LANG  = '_snel_lang';
	const META_GROUP = '_snel_group';

	// register() · langOf() · groupOf() · translation() · siblings() · link()
	// filterPermalink() · filterArchives()
	// TODO: port from theme (skeleton for now).
	public static function register(): void {}
}
