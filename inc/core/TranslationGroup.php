<?php
/**
 * TranslationGroup — the sibling link (the heart of the model).
 *
 * Model: one WordPress post per language. Siblings share a group id.
 * Stored in post meta:
 *   _snel_lang   — the language this post is written in (e.g. 'en')
 *   _snel_group  — the shared group id (the root/NL post's id, by convention)
 *
 * No _snel_lang  → treated as the default language.
 * No _snel_group → a group of one (itself).
 *
 * Also filters the front end: injects the /en/ prefix into permalinks and
 * constrains listings to the current language, plus cross-language slug reuse.
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

	/** Per-request cache: group id => [lang => post_id]. */
	private static array $cache = [];

	/** The language a post is written in (falls back to default). */
	public static function langOf( int $post_id ): string {
		$lang = get_post_meta( $post_id, self::META_LANG, true );
		if ( $lang && in_array( $lang, LocaleManager::supported(), true ) ) {
			return $lang;
		}
		return LocaleManager::default();
	}

	/** The group id for a post (falls back to the post's own id). */
	public static function groupOf( int $post_id ): int {
		$group = (int) get_post_meta( $post_id, self::META_GROUP, true );
		return $group > 0 ? $group : $post_id;
	}

	/**
	 * All posts in a group, keyed by language: ['nl' => 12, 'en' => 45].
	 * Includes drafts/pending so the editor can link unpublished translations.
	 */
	public static function siblings( int $group_id ): array {
		if ( isset( self::$cache[ $group_id ] ) ) {
			return self::$cache[ $group_id ];
		}

		$query = new \WP_Query( [
			'post_type'              => 'any',
			'post_status'            => [ 'publish', 'draft', 'pending', 'private', 'future' ],
			'posts_per_page'         => -1,
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_term_cache' => false,
			'meta_query'             => [
				[
					'key'   => self::META_GROUP,
					'value' => $group_id,
				],
			],
		] );

		$map = [];
		foreach ( $query->posts as $post ) {
			$map[ self::langOf( $post->ID ) ] = $post->ID;
		}

		// The root post may predate having group meta; make sure it's included.
		if ( ! in_array( $group_id, $map, true ) && get_post( $group_id ) ) {
			$map[ self::langOf( $group_id ) ] = $group_id;
		}

		self::$cache[ $group_id ] = $map;
		return $map;
	}

	/**
	 * The translation of a post in a language: post id, or 0 if none. Returns
	 * the post itself if it's already in the requested language.
	 */
	public static function translation( int $post_id, string $lang ): int {
		if ( self::langOf( $post_id ) === $lang ) {
			return $post_id;
		}
		$siblings = self::siblings( self::groupOf( $post_id ) );
		return $siblings[ $lang ] ?? 0;
	}

	/**
	 * Attach a post to a group as a given language. $group_id = 0 starts a new
	 * group rooted at this post.
	 */
	public static function link( int $post_id, int $group_id, string $lang ): void {
		if ( $group_id <= 0 ) {
			$group_id = $post_id;
		}
		update_post_meta( $post_id, self::META_GROUP, $group_id );
		update_post_meta( $post_id, self::META_LANG, $lang );
		unset( self::$cache[ $group_id ] );
	}

	/**
	 * On save, ensure every public post has a language + group. Posts created
	 * normally become a group of one in the default language.
	 */
	public static function ensureDefaults( int $post_id, \WP_Post $post ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( in_array( $post->post_status, [ 'auto-draft', 'trash' ], true ) ) {
			return;
		}
		$public = get_post_types( [ 'public' => true ] );
		if ( ! in_array( $post->post_type, $public, true ) ) {
			return;
		}

		if ( ! get_post_meta( $post_id, self::META_LANG, true ) ) {
			update_post_meta( $post_id, self::META_LANG, LocaleManager::default() );
		}
		if ( ! get_post_meta( $post_id, self::META_GROUP, true ) ) {
			update_post_meta( $post_id, self::META_GROUP, $post_id );
		}
	}

	/**
	 * Let two posts in different languages share a slug. WP forces globally
	 * unique slugs; we only want uniqueness within the same language (the Router
	 * disambiguates by language prefix).
	 */
	public static function uniqueSlug( $slug, $post_ID, $post_status, $post_type, $post_parent, $original_slug ) {
		if ( $slug === $original_slug ) {
			return $slug; // no collision
		}

		global $wpdb;
		$rows = $wpdb->get_col( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_name = %s AND post_type = %s AND post_parent = %d
			 AND ID <> %d AND post_status NOT IN ('trash', 'auto-draft')",
			$original_slug,
			$post_type,
			(int) $post_parent,
			(int) $post_ID
		) );

		if ( empty( $rows ) ) {
			return $slug;
		}

		$my_lang = self::langOf( (int) $post_ID );
		foreach ( $rows as $other_id ) {
			if ( self::langOf( (int) $other_id ) === $my_lang ) {
				return $slug; // genuine same-language collision — keep WP's slug
			}
		}

		return $original_slug; // all collisions are cross-language — keep clean slug
	}

	/**
	 * Inject the language prefix into a non-default post's permalink so menus,
	 * links, and SEO canonicals point at /en/about-us/ not /about-us/. Also
	 * translates a leading CPT archive segment.
	 */
	public static function filterPermalink( $url, $post ) {
		$post = get_post( $post );
		if ( ! $post instanceof \WP_Post ) {
			return $url;
		}

		$default = LocaleManager::default();
		$lang    = self::langOf( $post->ID );
		if ( $lang === $default ) {
			return $url;
		}

		// The static front page lives at the site root. Its translation should
		// live at the language root (/en/), not /en/{slug}/.
		$front_id = (int) get_option( 'page_on_front' );
		if ( $front_id && self::groupOf( $post->ID ) === self::groupOf( $front_id ) ) {
			return home_url( '/' . $lang . '/' );
		}

		$parts = wp_parse_url( $url );
		if ( empty( $parts['path'] ) ) {
			return $url;
		}

		$home_path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$home_path = rtrim( $home_path, '/' );
		$rel       = $parts['path'];
		if ( $home_path !== '' && strpos( $rel, $home_path ) === 0 ) {
			$rel = substr( $rel, strlen( $home_path ) );
		}
		$rel  = trim( $rel, '/' );
		$segs = $rel === '' ? [] : explode( '/', $rel );

		// Already prefixed — don't double up.
		if ( ! empty( $segs ) && in_array( $segs[0], LocaleManager::supported(), true ) ) {
			return $url;
		}

		// Translate a leading CPT archive segment (e.g. diensten → services).
		if ( ! empty( $segs ) ) {
			$cpt = UrlGenerator::cptSlugsConfig();
			if ( ! empty( $cpt[ $segs[0] ][ $lang ] ) ) {
				$segs[0] = $cpt[ $segs[0] ][ $lang ];
			}
		}

		$rel      = implode( '/', $segs );
		$new_path = '/' . $lang . ( $rel !== '' ? '/' . $rel : '' ) . '/';

		$rebuilt = home_url( $new_path );
		if ( ! empty( $parts['query'] ) ) {
			$rebuilt .= '?' . $parts['query'];
		}
		if ( ! empty( $parts['fragment'] ) ) {
			$rebuilt .= '#' . $parts['fragment'];
		}

		return $rebuilt;
	}

	/**
	 * Constrain listings (blog, archives, search) to the current language.
	 * Default-language posts may lack _snel_lang, so for default we also match
	 * posts where the meta is absent.
	 */
	public static function filterArchives( $query ): void {
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( $query->is_singular() || $query->is_404() ) {
			return;
		}
		if ( ! (
			$query->is_home() || $query->is_archive() || $query->is_search()
			|| $query->is_post_type_archive() || $query->is_tax()
			|| $query->is_category() || $query->is_tag()
		) ) {
			return;
		}

		$lang    = $query->get( 'lang' ) ?: LocaleManager::current();
		$default = LocaleManager::default();

		$meta = $query->get( 'meta_query' );
		if ( ! is_array( $meta ) ) {
			$meta = [];
		}

		if ( $lang === $default ) {
			$meta[] = [
				'relation' => 'OR',
				[ 'key' => self::META_LANG, 'value' => $default ],
				[ 'key' => self::META_LANG, 'compare' => 'NOT EXISTS' ],
			];
		} else {
			$meta[] = [ 'key' => self::META_LANG, 'value' => $lang ];
		}

		$query->set( 'meta_query', $meta );
	}

	/**
	 * Stamp every public post that has no language with $lang. Run before
	 * swapping the default language so existing posts keep their real language.
	 *
	 * @return int Number of posts stamped.
	 */
	public static function backfillMissingLang( string $lang ): int {
		global $wpdb;

		$types = array_values( get_post_types( [ 'public' => true ] ) );
		if ( empty( $types ) ) {
			return 0;
		}

		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$args         = array_merge( $types, [ self::META_LANG ] );

		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			 WHERE p.post_type IN ($placeholders)
			 AND p.post_status NOT IN ('auto-draft', 'trash')
			 AND NOT EXISTS (
				 SELECT 1 FROM {$wpdb->postmeta} m
				 WHERE m.post_id = p.ID AND m.meta_key = %s
			 )",
			$args
		) );

		foreach ( $ids as $id ) {
			update_post_meta( (int) $id, self::META_LANG, $lang );
		}

		return count( $ids );
	}

	/** Whether any post exists in a non-default language. */
	public static function translationsExist(): bool {
		global $wpdb;

		$count = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> %s",
			self::META_LANG,
			LocaleManager::default()
		) );

		return (int) $count > 0;
	}

	/** Register the front-end + save hooks. Called from Boot once, when live. */
	public static function register(): void {
		add_action( 'save_post', [ self::class, 'ensureDefaults' ], 5, 2 );
		add_filter( 'wp_unique_post_slug', [ self::class, 'uniqueSlug' ], 10, 6 );

		add_filter( 'post_link', [ self::class, 'filterPermalink' ], 10, 2 );
		add_filter( 'page_link', [ self::class, 'filterPermalink' ], 10, 2 );
		add_filter( 'post_type_link', [ self::class, 'filterPermalink' ], 10, 2 );

		add_action( 'pre_get_posts', [ self::class, 'filterArchives' ] );
		add_action( 'pre_get_posts', [ self::class, 'filterSecondaryQueries' ] );
	}

	/**
	 * Language-filter *secondary* front-end queries (a block/widget running its
	 * own WP_Query/get_posts). The main query is handled by filterArchives; this
	 * catches everything else so posts from other languages can't leak into a
	 * custom listing.
	 *
	 * Deliberately conservative — it only touches "listing" queries for
	 * translatable post types, and skips:
	 *   • admin + the main query (filterArchives owns that)
	 *   • queries opting out with ->set( 'snel_lang', false )
	 *   • queries targeting specific posts (p / name / post__in / pagename)
	 *   • post_type 'any' and non-translatable types (attachment, menu items…)
	 */
	public static function filterSecondaryQueries( $query ): void {
		if ( is_admin() || $query->is_main_query() ) {
			return;
		}
		if ( false === $query->get( 'snel_lang', null ) ) {
			return; // explicit opt-out — this query wants every language
		}
		if ( $query->get( 'p' ) || $query->get( 'name' ) || $query->get( 'pagename' ) || $query->get( 'post__in' ) ) {
			return; // targeting specific posts — don't language-filter
		}

		$types = (array) ( $query->get( 'post_type' ) ?: 'post' );
		if ( in_array( 'any', $types, true ) ) {
			return;
		}

		// Only language-filter post types that are actually translated. Shared
		// CPTs (partners/logos, etc.) must show in every language, and CPTs with
		// their own fallback helpers (services/cases) shouldn't be scoped here.
		// Sites add their translatable CPTs via the filter.
		$translatable = (array) apply_filters( 'snel_translatable_post_types', [ 'post', 'page' ] );
		foreach ( $types as $type ) {
			if ( ! in_array( $type, $translatable, true ) ) {
				return;
			}
		}

		$lang    = LocaleManager::current();
		$default = LocaleManager::default();

		$meta = $query->get( 'meta_query' );
		if ( ! is_array( $meta ) ) {
			$meta = [];
		}
		if ( $lang === $default ) {
			$meta[] = [
				'relation' => 'OR',
				[ 'key' => self::META_LANG, 'value' => $default ],
				[ 'key' => self::META_LANG, 'compare' => 'NOT EXISTS' ],
			];
		} else {
			$meta[] = [ 'key' => self::META_LANG, 'value' => $lang ];
		}
		$query->set( 'meta_query', $meta );
	}
}
