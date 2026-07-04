<?php
/**
 * Hreflang — <link rel="alternate" hreflang> tags in <head>.
 *
 * Tells search engines which URLs are language siblings. Singular pages map to
 * their published siblings via TranslationGroup; archives/home swap the URL
 * prefix (same logic as the language switcher, so both always agree). A page
 * with no published translation gets no tag for that language — pointing
 * hreflang at the home page would be a wrong signal, not a fallback.
 *
 * Output rules (per Google's spec):
 *   - Always include a self-reference.
 *   - x-default points at the default-language URL.
 *   - Nothing is printed unless at least two language versions exist.
 *
 * @package Snel\Translations
 */

namespace Snel\Translations\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hreflang {

	/** Register the head output. Called from Boot once, when live. */
	public static function register(): void {
		add_action( 'wp_head', [ self::class, 'output' ], 2 );
	}

	/** Print the hreflang link tags for the current request. */
	public static function output(): void {
		if ( is_404() || is_search() || is_feed() || is_robots() ) {
			return;
		}

		$urls = self::alternates();
		if ( count( $urls ) < 2 ) {
			return; // nothing to cross-link
		}

		foreach ( $urls as $lang => $url ) {
			printf(
				'<link rel="alternate" hreflang="%s" href="%s" />' . "\n",
				esc_attr( self::hreflangCode( $lang ) ),
				esc_url( $url )
			);
		}

		$default = LocaleManager::default();
		if ( isset( $urls[ $default ] ) ) {
			printf(
				'<link rel="alternate" hreflang="x-default" href="%s" />' . "\n",
				esc_url( $urls[ $default ] )
			);
		}
	}

	/**
	 * The current page's URL per language: [ 'nl' => url, 'en' => url ].
	 * Languages without an equivalent page are absent from the map.
	 */
	public static function alternates(): array {
		$langs = LocaleManager::supported();

		if ( is_singular() ) {
			$post_id = get_queried_object_id();
			if ( ! $post_id ) {
				return [];
			}

			$urls = [];
			foreach ( $langs as $lang ) {
				$sibling = TranslationGroup::translation( (int) $post_id, $lang );
				if ( ! $sibling || get_post_status( $sibling ) !== 'publish' ) {
					continue;
				}
				$urls[ $lang ] = get_permalink( $sibling );
			}
			return $urls;
		}

		// Home / archives / taxonomy terms: the same listing exists in every
		// language, only the prefix differs — mirror the switcher's URLs.
		$urls = [];
		foreach ( $langs as $lang ) {
			$url = UrlGenerator::langUrl( $lang );
			if ( $url ) {
				$urls[ $lang ] = $url;
			}
		}
		return $urls;
	}

	/**
	 * The hreflang attribute value for a language. Defaults to the bare language
	 * code (broadest targeting: 'nl' covers NL + BE). Filterable per language,
	 * e.g. return 'nl-NL' for strict regional targeting.
	 */
	public static function hreflangCode( string $lang ): string {
		return (string) apply_filters( 'snel_hreflang_code', $lang, $lang );
	}
}
