<?php
/**
 * SeoBridge — feeds per-language term meta into Yoast SEO on term archives.
 *
 * Terms are shared across languages (one WP_Term, translations in term meta),
 * so Yoast only knows the default-language SEO fields and permalink. On
 * non-default-language term pages this bridge overrides Yoast's head output:
 *
 *   title / og:title          → _snel_seo_title_{lang}, blank = Yoast's own
 *                               (whose term name is already translated)
 *   metadesc / og:description → _snel_seo_desc_{lang}, blank = the translated
 *                               term description
 *   canonical / og:url        → the term URL in the current language
 *
 * Singular pages need none of this: every language is its own post with its
 * own Yoast fields. All filters no-op when Yoast is inactive (they never fire).
 *
 * @package Snel\Translations
 */

namespace Snel\Translations\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SeoBridge {

	/** Register the Yoast output filters. Front end only. */
	public static function register(): void {
		if ( is_admin() ) {
			return;
		}
		add_filter( 'wpseo_title', [ self::class, 'title' ] );
		add_filter( 'wpseo_opengraph_title', [ self::class, 'title' ] );
		add_filter( 'wpseo_metadesc', [ self::class, 'description' ] );
		add_filter( 'wpseo_opengraph_desc', [ self::class, 'description' ] );
		add_filter( 'wpseo_canonical', [ self::class, 'canonical' ] );
		add_filter( 'wpseo_opengraph_url', [ self::class, 'canonical' ] );
	}

	/**
	 * The current request's term + language, or null when the bridge should
	 * stay out of the way (not a term archive, or default language).
	 */
	private static function context(): ?array {
		if ( ! is_tax() && ! is_category() && ! is_tag() ) {
			return null;
		}

		$lang = LocaleManager::current();
		if ( $lang === LocaleManager::default() ) {
			return null;
		}

		$term = get_queried_object();
		if ( ! $term instanceof \WP_Term ) {
			return null;
		}

		return [ $term, $lang ];
	}

	/** Per-language SEO title, if one is set. */
	public static function title( $title ) {
		$ctx = self::context();
		if ( ! $ctx ) {
			return $title;
		}
		[ $term, $lang ] = $ctx;

		$custom = (string) get_term_meta( $term->term_id, TermTranslation::seoTitleKey( $lang ), true );
		return $custom !== '' ? $custom : $title;
	}

	/** Per-language meta description; falls back to the translated term description. */
	public static function description( $desc ) {
		$ctx = self::context();
		if ( ! $ctx ) {
			return $desc;
		}
		[ $term, $lang ] = $ctx;

		$custom = (string) get_term_meta( $term->term_id, TermTranslation::seoDescKey( $lang ), true );
		if ( $custom !== '' ) {
			return $custom;
		}

		$translated = (string) get_term_meta( $term->term_id, TermTranslation::descKey( $lang ), true );
		if ( $translated === '' ) {
			return $desc; // no translation — keep whatever Yoast built
		}

		$translated = trim( wp_strip_all_tags( $translated ) );
		return wp_html_excerpt( $translated, 156, '…' );
	}

	/** Self-referencing canonical: the term URL in the current language. */
	public static function canonical( $url ) {
		$ctx = self::context();
		if ( ! $ctx ) {
			return $url;
		}
		[ $term, $lang ] = $ctx;

		$link = TermTranslation::linkForLang( $term, $term->taxonomy, $lang );
		return $link !== '' ? $link : $url;
	}
}
