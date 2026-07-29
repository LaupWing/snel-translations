<?php
/**
 * UrlGenerator — builds language-aware URLs.
 *
 * Adds the language prefix for non-default languages (default has none). For
 * singular content, a language URL points at the *sibling* post's own permalink,
 * so /en/ links resolve to the real English page with its translated slug.
 *
 * @package Snel\Translations
 */

namespace Snel\Translations\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UrlGenerator {

	/** Cached CPT slug-translation config. */
	private static ?array $cptSlugs = null;

	/** Load + cache the CPT archive-slug translations (filterable). */
	public static function cptSlugsConfig(): array {
		if ( self::$cptSlugs === null ) {
			$config = require SNEL_TR_DIR . 'config/slugs-cpt.php';
			$config = is_array( $config ) ? $config : [];

			// Admin-entered slug translations (Slugs tab). Keyed by default slug.
			$saved = get_option( 'snel_cpt_slugs', [] );
			if ( is_array( $saved ) ) {
				foreach ( $saved as $slug => $langs ) {
					if ( is_array( $langs ) ) {
						$config[ $slug ] = array_merge( $config[ $slug ] ?? [], $langs );
					}
				}
			}

			/** Projects can also add CPT slug translations in code. */
			self::$cptSlugs = apply_filters( 'snel_cpt_slugs', $config );
		}

		return self::$cptSlugs;
	}

	/**
	 * URL for the current page in another language.
	 *
	 * Singular: link to the sibling translation's permalink. With no sibling —
	 * and for everything else — swap the prefix on the current URL.
	 */
	public static function langUrl( string $target_lang ): string {
		if ( is_singular() || is_page() ) {
			$current_id = get_queried_object_id();
			if ( $current_id ) {
				$sibling_id = TranslationGroup::translation( $current_id, $target_lang );
				// Drafts have no public URL (?p=N would 404) — treat as missing.
				if ( $sibling_id && get_post_status( $sibling_id ) === 'publish' ) {
					return self::prefixedPermalink( $sibling_id, $target_lang );
				}
				// No sibling: keep the path and just swap the prefix. Router pins
				// the untranslated post there and 302s back to its own-language
				// URL, so the link degrades to the language the post exists in.
			}
		}

		// Term archives have no sibling post to look up, and swapPrefix() would
		// keep the current language's term slug. Rebuild the URL from the term.
		if ( is_tax() || is_category() || is_tag() ) {
			$term = get_queried_object();
			if ( $term instanceof \WP_Term ) {
				$url = TermTranslation::linkForLang( $term, $term->taxonomy, $target_lang );
				if ( $url !== '' ) {
					return $url;
				}
			}
		}

		return self::swapPrefix( $target_lang );
	}

	/**
	 * Public URL for a post in its own language. The prefix + CPT segment are
	 * applied centrally by TranslationGroup::filterPermalink, so get_permalink()
	 * already returns the correct language-prefixed URL — thin wrapper.
	 */
	public static function prefixedPermalink( int $post_id, string $lang ): string {
		return get_permalink( $post_id );
	}

	/** Home-rooted URL for a language: homeUrl('en') → https://site/en/ */
	private static function homeUrl( string $lang, string $path = '' ): string {
		$path   = trim( $path, '/' );
		$prefix = ( $lang === LocaleManager::default() ) ? '' : $lang . '/';
		$full   = $prefix . ( $path !== '' ? $path . '/' : '' );
		return home_url( '/' . $full );
	}

	/**
	 * Swap the language prefix on the current request URI (non-singular pages).
	 * Also swaps a translated CPT archive segment, so /en/ai-services/ ↔
	 * /ai-diensten/ — the prefix alone isn't the whole difference.
	 */
	private static function swapPrefix( string $target_lang ): string {
		$default = LocaleManager::default();
		$langs   = LocaleManager::supported();
		$current = LocaleManager::current();

		$request = $_SERVER['REQUEST_URI'] ?? '/';
		$path    = (string) wp_parse_url( $request, PHP_URL_PATH );
		$query   = (string) wp_parse_url( $request, PHP_URL_QUERY );

		$non_default = array_diff( $langs, [ $default ] );
		if ( ! empty( $non_default ) ) {
			$pattern = '#^/(' . implode( '|', $non_default ) . ')(/|$)#';
			$path    = preg_replace( $pattern, '/', $path );
		}
		if ( empty( $path ) ) {
			$path = '/';
		}

		// Translate the leading CPT archive segment from the current language's
		// slug to the target language's.
		$segs = explode( '/', trim( $path, '/' ) );
		if ( ! empty( $segs[0] ) ) {
			foreach ( self::cptSlugsConfig() as $default_slug => $translations ) {
				$current_slug = ( $current === $default ) ? $default_slug : ( $translations[ $current ] ?? $default_slug );
				if ( $segs[0] === $current_slug ) {
					$segs[0] = ( $target_lang === $default ) ? $default_slug : ( $translations[ $target_lang ] ?? $default_slug );
					$path    = '/' . implode( '/', $segs ) . ( substr( $path, -1 ) === '/' ? '/' : '' );
					break;
				}
			}
		}

		$url = ( $target_lang === $default ) ? home_url( $path ) : home_url( '/' . $target_lang . $path );

		return $url . ( $query !== '' ? '?' . $query : '' );
	}

	/**
	 * Add the current language prefix to an internal URL. No-op for the default
	 * language or a URL that already carries a prefix.
	 */
	public static function url( string $url ): string {
		$lang    = LocaleManager::current();
		$default = LocaleManager::default();

		if ( $lang === $default ) {
			return $url;
		}

		$langs       = LocaleManager::supported();
		$non_default = array_diff( $langs, [ $default ] );
		$parsed      = parse_url( $url );
		$path        = $parsed['path'] ?? '/';

		$pattern = '#^/(' . implode( '|', $non_default ) . ')(/|$)#';
		if ( preg_match( $pattern, $path ) ) {
			return $url; // already prefixed
		}

		$new_path = '/' . $lang . $path;

		if ( isset( $parsed['scheme'], $parsed['host'] ) ) {
			$host = $parsed['host'];
			if ( isset( $parsed['port'] ) ) {
				$host .= ':' . $parsed['port'];
			}
			return $parsed['scheme'] . '://' . $host . $new_path;
		}

		return $new_path;
	}
}
