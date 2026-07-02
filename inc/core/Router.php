<?php
/**
 * Router — URL ↔ language wiring.
 *
 * Adds rewrite rules so a /en/… prefix becomes a `lang` query var, then resolves
 * the request to the sibling post written in that language (pinning a concrete
 * post id). This is what makes /en/cancel-subscription/ load post 964.
 *
 * @package Snel\Translations
 */

namespace Snel\Translations\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Router {
	// register() · addRewriteRules() · resolveLanguagePost()
	// TODO: port from theme (skeleton for now).
	public static function register(): void {}
}
