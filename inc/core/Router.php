<?php
/**
 * Router — URL ↔ language wiring.
 *
 * Registers rewrite rules so a /en/ prefix becomes a `lang` query var, then
 * resolves each request to the sibling post written in that language. Each post
 * keeps its native slug (the English "About us" lives at /en/about-us/ with the
 * real slug "about-us") — no slug-translation meta needed.
 *
 * @package Snel\Translations
 */

namespace Snel\Translations\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Router {

	/** Register all routing hooks. Called from Boot once, when live. */
	public static function register(): void {
		add_filter( 'query_vars', [ self::class, 'registerQueryVars' ] );
		add_action( 'init', [ self::class, 'registerRewriteRules' ], 20 ); // after CPTs/taxes register
		add_action( 'after_switch_theme', 'flush_rewrite_rules' );
		add_filter( 'request', [ self::class, 'interceptLanguageUrl' ], 1 );
		add_filter( 'request', [ self::class, 'fixFrontPage' ] );
		add_filter( 'request', [ self::class, 'resolveLanguagePost' ], 20 );
		add_filter( 'redirect_canonical', [ self::class, 'preventCanonicalRedirect' ], 10, 2 );

		// Force one flush after deploy if the rules are stale.
		$rules_version = 'snel_rewrite_v7';
		if ( get_option( $rules_version ) !== '1' ) {
			add_action( 'init', function () use ( $rules_version ) {
				flush_rewrite_rules();
				update_option( $rules_version, '1' );
			}, 99 );
		}
	}

	/** Make WordPress recognise the `lang` query var. */
	public static function registerQueryVars( array $vars ): array {
		$vars[] = 'lang';
		return $vars;
	}

	/**
	 * Rewrite rules per non-default language:
	 *   /en/                    → homepage (lang=en)
	 *   /en/page/2/             → blog pagination
	 *   /en/cpt-slug/           → CPT archive (from config)
	 *   /en/cpt-slug/some-post/ → CPT single
	 *   /en/any-slug/           → page/post
	 */
	public static function registerRewriteRules(): void {
		$default = LocaleManager::default();
		$langs   = LocaleManager::supported();

		// Every public custom post type + taxonomy, routed under each language
		// using its own rewrite slug (same slug across languages). Generic — no
		// per-project config, no per-link work.
		$cpts     = get_post_types( [ 'public' => true, '_builtin' => false ], 'objects' );
		$taxes    = get_taxonomies( [ 'public' => true ], 'objects' );
		$cpt_slug = UrlGenerator::cptSlugsConfig(); // [ default_slug => [ lang => slug ] ]

		$slug_of = function ( $obj ) {
			return ( is_array( $obj->rewrite ) && ! empty( $obj->rewrite['slug'] ) ) ? $obj->rewrite['slug'] : $obj->name;
		};

		foreach ( $langs as $lang ) {
			if ( $lang === $default ) {
				continue;
			}

			// With 'top', the FIRST call is highest priority — so add the most
			// specific rules first and the catch-all last.

			// Language home + blog pagination.
			add_rewrite_rule( "^{$lang}/?$", "index.php?lang={$lang}", 'top' );
			add_rewrite_rule( "^{$lang}/page/([0-9]+)/?$", "index.php?lang={$lang}&paged=\$matches[1]", 'top' );

			// CPT archives + singles. URL uses the translated slug (if set); the
			// post_type query var stays the real CPT name.
			foreach ( $cpts as $cpt ) {
				$default_slug = $slug_of( $cpt );
				$slug         = ! empty( $cpt_slug[ $default_slug ][ $lang ] ) ? $cpt_slug[ $default_slug ][ $lang ] : $default_slug;
				if ( $cpt->has_archive ) {
					add_rewrite_rule( "^{$lang}/{$slug}/?$", "index.php?lang={$lang}&post_type={$cpt->name}", 'top' );
				}
				add_rewrite_rule( "^{$lang}/{$slug}/page/([0-9]+)/?$", "index.php?lang={$lang}&post_type={$cpt->name}&paged=\$matches[1]", 'top' );
				add_rewrite_rule( "^{$lang}/{$slug}/([^/]+)/?$", "index.php?lang={$lang}&post_type={$cpt->name}&name=\$matches[1]", 'top' );
			}

			// Taxonomy term archives (categories, tags, custom public taxonomies).
			foreach ( $taxes as $tax ) {
				if ( $tax->name === 'post_format' ) {
					continue;
				}
				$base = $slug_of( $tax );
				$qv   = $tax->name === 'category' ? 'category_name' : ( $tax->name === 'post_tag' ? 'tag' : $tax->name );
				add_rewrite_rule( "^{$lang}/{$base}/([^/]+)/?$", "index.php?lang={$lang}&{$qv}=\$matches[1]", 'top' );
			}

			// Catch-all for pages/posts (lowest priority — added last).
			add_rewrite_rule( "^{$lang}/(.+?)/?$", "index.php?lang={$lang}&pagename=\$matches[1]", 'top' );
		}
	}

	/**
	 * Pin the query vars when a language prefix is in the URI but WordPress
	 * matched a different rule (e.g. the attachment rule). Fires early.
	 */
	public static function interceptLanguageUrl( array $query_vars ): array {
		$default     = LocaleManager::default();
		$langs       = LocaleManager::supported();
		$non_default = array_diff( $langs, [ $default ] );

		if ( empty( $non_default ) ) {
			return $query_vars;
		}

		$request = trim( $_SERVER['REQUEST_URI'] ?? '', '/' );
		$request = strtok( $request, '?' );

		$pattern = '#^(' . implode( '|', $non_default ) . ')(/(.*))?$#';
		if ( ! preg_match( $pattern, $request, $matches ) ) {
			return $query_vars;
		}

		$lang = $matches[1];
		$path = isset( $matches[3] ) ? trim( $matches[3], '/' ) : '';

		if ( ! empty( $query_vars['lang'] ) && $query_vars['lang'] === $lang ) {
			return $query_vars;
		}

		$new_vars = [ 'lang' => $lang ];

		if ( empty( $path ) ) {
			return $new_vars;
		}

		if ( preg_match( '#^page/(\d+)$#', $path, $page_match ) ) {
			$new_vars['paged'] = (int) $page_match[1];
			return $new_vars;
		}

		$new_vars['pagename'] = $path;
		return $new_vars;
	}

	/**
	 * Load the sibling front page for a non-default language, so /en/ shows the
	 * English front page rather than the Dutch one.
	 */
	public static function fixFrontPage( array $query_vars ): array {
		$lang = $query_vars['lang'] ?? '';
		if ( ! $lang || $lang === LocaleManager::default() ) {
			return $query_vars;
		}

		// Any of these query vars means the URL points at real content (a page,
		// post, category/tag/taxonomy archive, author, date, search) — NOT the
		// language home. Bail so we don't hijack it with the front page.
		$content_vars = [
			'pagename', 'page_id', 'p', 'name', 'post_type', 's',
			'category_name', 'cat', 'tag', 'tag_id', 'author', 'author_name',
			'year', 'monthnum', 'day', 'w', 'm', 'feed',
		];
		foreach ( get_taxonomies( [ 'public' => true ], 'objects' ) as $tax ) {
			if ( ! empty( $tax->query_var ) ) {
				$content_vars[] = $tax->query_var;
			}
			$content_vars[] = $tax->name;
		}
		foreach ( $content_vars as $var ) {
			if ( ! empty( $query_vars[ $var ] ) ) {
				return $query_vars;
			}
		}

		$front_page_id = (int) get_option( 'page_on_front' );
		if ( $front_page_id ) {
			$sibling = TranslationGroup::translation( $front_page_id, $lang );
			// Only use the sibling if it's actually published — otherwise a
			// draft/trashed translation would 404 the language home.
			$use_sibling           = $sibling && get_post_status( $sibling ) === 'publish';
			$query_vars['page_id'] = $use_sibling ? $sibling : $front_page_id;
		}

		return $query_vars;
	}

	/**
	 * Resolve a request to the post written in the current language. WP resolves
	 * a slug to *a* post, but with one post per language (and repeatable slugs)
	 * it can land on the wrong one — so we find the candidate and swap in the
	 * sibling by pinning a concrete post id.
	 */
	public static function resolveLanguagePost( array $query_vars ): array {
		if ( ! empty( $query_vars['s'] ) || is_admin() ) {
			return $query_vars;
		}

		$lang = $query_vars['lang'] ?? LocaleManager::default();

		$post = null;
		if ( ! empty( $query_vars['pagename'] ) ) {
			$post = get_page_by_path( $query_vars['pagename'] );
			if ( ! $post ) {
				$post = get_page_by_path( $query_vars['pagename'], OBJECT, 'post' );
			}
		} elseif ( ! empty( $query_vars['name'] ) ) {
			$type = $query_vars['post_type'] ?? 'post';
			$post = get_page_by_path( $query_vars['name'], OBJECT, $type );
		} elseif ( ! empty( $query_vars['page_id'] ) ) {
			$post = get_post( (int) $query_vars['page_id'] );
		} elseif ( ! empty( $query_vars['p'] ) ) {
			$post = get_post( (int) $query_vars['p'] );
		}

		if ( ! $post instanceof \WP_Post ) {
			return $query_vars;
		}

		// Posts page (Settings → Reading) in another language: WordPress only
		// treats the one designated page_for_posts as the blog index. Pin that
		// real page so the request shows the post loop (filterArchives then
		// restricts it to $lang) instead of rendering the sibling as a page.
		$posts_page = (int) get_option( 'page_for_posts' );
		if (
			$posts_page && $lang !== LocaleManager::default() &&
			TranslationGroup::groupOf( $post->ID ) === TranslationGroup::groupOf( $posts_page )
		) {
			unset( $query_vars['pagename'], $query_vars['name'], $query_vars['p'], $query_vars['attachment'] );
			$query_vars['page_id'] = $posts_page;
			return $query_vars;
		}

		$target = $post;
		if ( TranslationGroup::langOf( $post->ID ) !== $lang ) {
			$sibling_id = TranslationGroup::translation( $post->ID, $lang );
			if ( ! $sibling_id ) {
				return $query_vars; // no translation — let WP render what it found
			}
			$target = get_post( $sibling_id );
			// Ignore unpublished translations — fall back to the found post so the
			// URL still resolves instead of 404ing on a draft.
			if ( ! $target instanceof \WP_Post || $target->post_status !== 'publish' ) {
				return $query_vars;
			}
		}

		return self::pinPost( $query_vars, $target );
	}

	/** Replace slug-based query vars with a concrete post id. */
	private static function pinPost( array $query_vars, \WP_Post $post ): array {
		unset(
			$query_vars['pagename'],
			$query_vars['name'],
			$query_vars['p'],
			$query_vars['page_id'],
			$query_vars['attachment']
		);

		if ( $post->post_type === 'page' ) {
			$query_vars['page_id'] = $post->ID;
		} else {
			$query_vars['p']         = $post->ID;
			$query_vars['post_type'] = $post->post_type;
		}

		return $query_vars;
	}

	/** Stop WP redirecting a translated URL to the default-language canonical. */
	public static function preventCanonicalRedirect( $redirect_url, $requested_url ) {
		$lang = get_query_var( 'lang', '' );

		if ( $lang && $lang !== LocaleManager::default() ) {
			return false;
		}

		return $redirect_url;
	}
}
