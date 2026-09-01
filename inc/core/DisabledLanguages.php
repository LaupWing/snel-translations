<?php
/**
 * DisabledLanguages — keeps disabled-language posts out of public indexes.
 *
 * Deactivating a language leaves its posts published in the database (nothing
 * is destroyed — re-enabling the language brings them straight back). The
 * Router already refuses to *render* them (301 to a live sibling, else 404);
 * this class covers the machine-readable resolution points that never pass
 * through the Router:
 *
 *   - Yoast XML sitemaps    (wpseo_exclude_from_sitemap_by_post_ids)
 *   - WP core sitemaps      (wp_sitemaps_posts_query_args)
 *   - Public REST listings  (rest_{post_type}_query on wp/v2 collections)
 *   - REST search           (rest_post_search_query on wp/v2/search)
 *
 * Feeds and archives need nothing here: TranslationGroup::filterArchives
 * already constrains every front-end listing (feeds included — a feed query
 * is is_home/is_archive) to the CURRENT language, and the current language is
 * by definition an enabled one.
 *
 * Invariants (ARCHITECTURE.md §3) respected:
 *   - Posts with NO meta count as default-language and are never excluded.
 *   - Shared CPTs carry default/no language meta, so they pass untouched.
 *   - Admin is never language-filtered: REST requests with context=edit
 *     (block editor, admin tooling — capability-gated by core) see everything.
 *   - No options written, no cron — deactivation leaves no debris.
 *
 * @package Snel\Translations
 */

namespace Snel\Translations\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DisabledLanguages {

	/** Register the sitemap + REST guards. Called from Boot once, when live. */
	public static function register(): void {
		add_filter( 'wpseo_exclude_from_sitemap_by_post_ids', [ self::class, 'excludeFromYoastSitemap' ] );
		add_filter( 'wp_sitemaps_posts_query_args', [ self::class, 'filterCoreSitemap' ] );
		add_action( 'rest_api_init', [ self::class, 'registerRestFilters' ] );
		// wp/v2/search runs its own query handler, not rest_{type}_query. Search
		// never allows context=edit, so the guard in filterRestQuery is inert.
		add_filter( 'rest_post_search_query', [ self::class, 'filterRestQuery' ], 10, 2 );
	}

	/** @var int[]|null Per-request cache of disabled-language post ids. */
	private static ?array $disabledIds = null;

	/**
	 * SOT: which posts belong to a disabled language. Every id whose raw
	 * `_snel_lang` is not an enabled language — the exact set that must never
	 * appear on a public surface. Missing meta = default language = enabled,
	 * so those posts are never in this list (invariant 4).
	 */
	public static function disabledPostIds(): array {
		if ( self::$disabledIds !== null ) {
			return self::$disabledIds;
		}

		global $wpdb;
		$supported    = LocaleManager::supported();
		$placeholders = implode( ',', array_fill( 0, count( $supported ), '%s' ) );

		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta}
			 WHERE meta_key = %s AND meta_value NOT IN ($placeholders)",
			array_merge( [ TranslationGroup::META_LANG ], $supported )
		) );

		self::$disabledIds = array_map( 'intval', $ids );
		return self::$disabledIds;
	}

	/** meta_query clause matching only enabled-language posts (or no meta at all). */
	private static function enabledClause(): array {
		return [
			'relation' => 'OR',
			[
				'key'     => TranslationGroup::META_LANG,
				'value'   => LocaleManager::supported(),
				'compare' => 'IN',
			],
			[
				'key'     => TranslationGroup::META_LANG,
				'compare' => 'NOT EXISTS',
			],
		];
	}

	/**
	 * AND the enabled-languages clause onto an existing meta_query. Same
	 * nesting rule as TranslationGroup::addLangClause: the existing query may
	 * carry its own top-level relation, so wrap both under an AND.
	 */
	private static function withEnabledClause( $meta ): array {
		$clause = self::enabledClause();
		if ( ! is_array( $meta ) || empty( $meta ) ) {
			return [ $clause ];
		}
		return [
			'relation' => 'AND',
			$clause,
			$meta,
		];
	}

	/** Yoast XML sitemaps: append every disabled-language post id. */
	public static function excludeFromYoastSitemap( $excluded ): array {
		$excluded = is_array( $excluded ) ? $excluded : [];
		return array_merge( $excluded, self::disabledPostIds() );
	}

	/** WP core sitemaps (wp-sitemap.xml): only enabled-language posts. */
	public static function filterCoreSitemap( $args ): array {
		$args = is_array( $args ) ? $args : [];
		if ( empty( self::disabledPostIds() ) ) {
			return $args; // nothing to hide — zero filter output (invariant 8)
		}
		$args['meta_query'] = self::withEnabledClause( $args['meta_query'] ?? null );
		return $args;
	}

	/** Hook every REST-exposed post type's collection query. */
	public static function registerRestFilters(): void {
		foreach ( get_post_types( [ 'show_in_rest' => true ], 'names' ) as $type ) {
			add_filter( "rest_{$type}_query", [ self::class, 'filterRestQuery' ], 10, 2 );
		}
	}

	/**
	 * Public wp/v2 listings: only enabled-language posts. context=edit is
	 * capability-gated by core (block editor, admin tooling) and stays
	 * unfiltered — the admin must always see everything (invariant 3).
	 */
	public static function filterRestQuery( $args, $request ) {
		if ( $request instanceof \WP_REST_Request && $request->get_param( 'context' ) === 'edit' ) {
			return $args;
		}
		$args = is_array( $args ) ? $args : [];
		if ( empty( self::disabledPostIds() ) ) {
			return $args; // nothing to hide — zero filter output (invariant 8)
		}
		$args['meta_query'] = self::withEnabledClause( $args['meta_query'] ?? null );
		return $args;
	}
}
