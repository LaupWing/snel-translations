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
		add_action( 'init', [ self::class, 'registerRewriteRules' ] );
		add_action( 'after_switch_theme', 'flush_rewrite_rules' );
		add_filter( 'request', [ self::class, 'interceptLanguageUrl' ], 1 );
		add_filter( 'request', [ self::class, 'fixFrontPage' ] );
		add_filter( 'request', [ self::class, 'resolveLanguagePost' ], 20 );
		add_filter( 'redirect_canonical', [ self::class, 'preventCanonicalRedirect' ], 10, 2 );

		// Force one flush after deploy if the rules are stale.
		$rules_version = 'snel_rewrite_v6';
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
		$default   = LocaleManager::default();
		$langs     = LocaleManager::supported();
		$cpt_slugs = UrlGenerator::cptSlugsConfig();

		foreach ( $langs as $lang ) {
			if ( $lang === $default ) {
				continue;
			}

			add_rewrite_rule( "^{$lang}/?$", 'index.php?lang=' . $lang, 'top' );

			add_rewrite_rule(
				"^{$lang}/page/([0-9]+)/?$",
				'index.php?lang=' . $lang . '&paged=$matches[1]',
				'top'
			);

			foreach ( $cpt_slugs as $dutch_slug => $translations ) {
				if ( ! empty( $translations[ $lang ] ) ) {
					$translated_slug = $translations[ $lang ];

					add_rewrite_rule(
						"^{$lang}/{$translated_slug}/?$",
						'index.php?lang=' . $lang . '&post_type=' . $dutch_slug,
						'top'
					);
					add_rewrite_rule(
						"^{$lang}/{$translated_slug}/([^/]+)/?$",
						'index.php?lang=' . $lang . '&post_type=' . $dutch_slug . '&name=$matches[1]',
						'top'
					);
					add_rewrite_rule(
						"^{$lang}/{$translated_slug}/page/([0-9]+)/?$",
						'index.php?lang=' . $lang . '&post_type=' . $dutch_slug . '&paged=$matches[1]',
						'top'
					);
				}
			}

			// Catch-all for pages and posts.
			add_rewrite_rule(
				"^{$lang}/(.+?)/?$",
				'index.php?lang=' . $lang . '&pagename=$matches[1]',
				'top'
			);
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

		if (
			$lang &&
			$lang !== LocaleManager::default() &&
			empty( $query_vars['pagename'] ) &&
			empty( $query_vars['page_id'] ) &&
			empty( $query_vars['p'] ) &&
			empty( $query_vars['name'] ) &&
			empty( $query_vars['post_type'] ) &&
			empty( $query_vars['s'] )
		) {
			$front_page_id = (int) get_option( 'page_on_front' );
			if ( $front_page_id ) {
				$sibling               = TranslationGroup::translation( $front_page_id, $lang );
				$query_vars['page_id'] = $sibling ?: $front_page_id;
			}
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

		$target = $post;
		if ( TranslationGroup::langOf( $post->ID ) !== $lang ) {
			$sibling_id = TranslationGroup::translation( $post->ID, $lang );
			if ( ! $sibling_id ) {
				return $query_vars; // no translation — let WP render what it found
			}
			$target = get_post( $sibling_id );
			if ( ! $target instanceof \WP_Post ) {
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
