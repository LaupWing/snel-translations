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
		add_filter( 'option_page_for_posts', [ self::class, 'filterPostsPageId' ] );
		add_filter( 'option_page_on_front', [ self::class, 'filterFrontPageId' ] );
		add_filter( 'redirect_canonical', [ self::class, 'fixCanonicalRedirect' ], 10, 2 );
		add_action( 'template_redirect', [ self::class, 'redirectDisabledLanguage' ], 1 ); // before the canonical handlers
		add_action( 'template_redirect', [ self::class, 'canonicalizeSwappedSlug' ], 9 ); // before redirect_canonical (10)

		// Force one flush after deploy if the rules are stale.
		$rules_version = 'snel_rewrite_v10';
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

	/** @var array<string,int> per-language cache for the option filters. */
	private static array $pageOptionCache = [];

	/** Re-entrancy guard: current() may read these same options. */
	private static bool $inPageOption = false;

	/** Swap the posts page (blog index) to the current language's sibling. */
	public static function filterPostsPageId( $value ) {
		return self::translatedPageOption( 'posts', (int) $value );
	}

	/** Swap the static front page to the current language's sibling. */
	public static function filterFrontPageId( $value ) {
		return self::translatedPageOption( 'front', (int) $value );
	}

	private static function translatedPageOption( string $key, int $value ) {
		if ( is_admin() || ! $value || self::$inPageOption ) {
			return $value;
		}
		self::$inPageOption = true;
		$lang               = LocaleManager::current();
		self::$inPageOption = false;

		if ( $lang === LocaleManager::default() ) {
			return $value;
		}
		$cache = $key . ':' . $lang;
		if ( ! isset( self::$pageOptionCache[ $cache ] ) ) {
			self::$inPageOption               = true;
			$sibling                          = (int) TranslationGroup::translation( $value, $lang );
			self::$pageOptionCache[ $cache ] = ( $sibling && get_post_status( $sibling ) === 'publish' ) ? $sibling : $value;
			self::$inPageOption               = false;
		}
		return self::$pageOptionCache[ $cache ];
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
			// A hierarchical rewrite matches the full nested path (parent/child),
			// mirroring core's own hierarchical taxonomy rules.
			foreach ( $taxes as $tax ) {
				if ( $tax->name === 'post_format' ) {
					continue;
				}
				$default_base = $slug_of( $tax );
				$base         = ! empty( $cpt_slug[ $default_base ][ $lang ] ) ? $cpt_slug[ $default_base ][ $lang ] : $default_base;
				$qv           = $tax->name === 'category' ? 'category_name' : ( $tax->name === 'post_tag' ? 'tag' : $tax->name );
				$segment = ( is_array( $tax->rewrite ) && ! empty( $tax->rewrite['hierarchical'] ) ) ? '(.+?)' : '([^/]+)';

				// A taxonomy base can be the slug of a real page — the category
				// base "blog" sitting on the posts page at /blog/ is the common
				// one. Core survives that with verbose page rules; here the term
				// rule below would swallow /en/blog/page/2/ as the term
				// "page/2". Claim base pagination for the page first.
				add_rewrite_rule( "^{$lang}/{$base}/page/([0-9]+)/?$", "index.php?lang={$lang}&pagename={$base}&paged=\$matches[1]", 'top' );

				add_rewrite_rule( "^{$lang}/{$base}/{$segment}/page/([0-9]+)/?$", "index.php?lang={$lang}&{$qv}=\$matches[1]&paged=\$matches[2]", 'top' );
				add_rewrite_rule( "^{$lang}/{$base}/{$segment}/?$", "index.php?lang={$lang}&{$qv}=\$matches[1]", 'top' );
			}

			// Paginated page (e.g. the posts page: /en/blog/page/2/) — must beat
			// the catch-all, which would swallow it as pagename "blog/page/2".
			add_rewrite_rule( "^{$lang}/(.+?)/page/([0-9]+)/?$", "index.php?lang={$lang}&pagename=\$matches[1]&paged=\$matches[2]", 'top' );

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

		// Trailing /page/N on a page path (e.g. blog/page/2) — split it off, or
		// the whole thing becomes an unresolvable pagename.
		if ( preg_match( '#^(.+?)/page/(\d+)$#', $path, $page_match ) ) {
			$new_vars['pagename'] = $page_match[1];
			$new_vars['paged']    = (int) $page_match[2];
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
	 * 301 a disabled language's URLs to a live language. The rewrite rules for a
	 * disabled language are gone, so /es/… would 404 cold — instead send the
	 * visitor to the admin-chosen redirect target (or the default language).
	 * Singles land on the target-language sibling when one is published; anything
	 * else lands on the target language's home.
	 */
	public static function redirectDisabledLanguage(): void {
		if ( is_admin() || is_preview() || is_feed() || is_embed() ) {
			return;
		}

		$req_path  = (string) wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH );
		$home_path = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		$path      = trim( $req_path, '/' );
		if ( $home_path !== '' && strpos( $path, $home_path ) === 0 ) {
			$path = trim( substr( $path, strlen( $home_path ) ), '/' );
		}

		$segs  = explode( '/', $path );
		$first = $segs[0] ?? '';
		if ( $first === '' || ! preg_match( '/^[a-z]{2}(-[a-z]{2})?$/i', $first ) ) {
			return;
		}
		if ( in_array( $first, LocaleManager::supported(), true ) ) {
			return; // live language — normal routing
		}
		// Only claim prefixes that are (or were) actually a language here: still
		// in the config, or carrying a redirect choice. A random /xx/ stays a 404.
		if ( ! isset( LocaleManager::config()[ $first ] ) && ! isset( LocaleManager::redirectTargets()[ $first ] ) ) {
			return;
		}

		$target = LocaleManager::redirectTarget( $first );

		// Single content: try the target-language sibling of the requested post.
		$dest = '';
		$slug = sanitize_title( end( $segs ) );
		if ( count( $segs ) > 1 && $slug !== '' ) {
			$found = get_posts( [
				'name'        => $slug,
				'post_type'   => 'any',
				'post_status' => 'publish',
				'numberposts' => 1,
				'meta_key'    => TranslationGroup::META_LANG,
				'meta_value'  => $first,
			] );
			if ( $found ) {
				$sibling = (int) TranslationGroup::translation( $found[0]->ID, $target );
				if ( $sibling && get_post_status( $sibling ) === 'publish' ) {
					$dest = get_permalink( $sibling );
				}
			}
		}

		if ( ! $dest ) {
			$dest = $target === LocaleManager::default()
				? home_url( '/' )
				: home_url( '/' . $target . '/' );
		}

		wp_safe_redirect( $dest, 301 );
		exit;
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
			$sibling    = $sibling_id ? get_post( $sibling_id ) : null;
			if ( ! $sibling instanceof \WP_Post || $sibling->post_status !== 'publish' ) {
				// Language home with an untranslated front page: render it in
				// place — redirecting /en/ to / would dead-end the switcher.
				if ( $post->ID === (int) get_option( 'page_on_front' ) ) {
					return $query_vars;
				}
				// The found post belongs to a DISABLED language (its slug still
				// resolves — singular queries aren't lang-filtered). Never render
				// it: 301 to its sibling in the redirect target, or plain 404.
				$post_lang = TranslationGroup::langOf( $post->ID );
				if ( $post_lang && ! in_array( $post_lang, LocaleManager::supported(), true ) ) {
					$target     = LocaleManager::redirectTarget( $post_lang );
					$sibling_id = (int) TranslationGroup::translation( $post->ID, $target );
					if ( $sibling_id && get_post_status( $sibling_id ) === 'publish' ) {
						self::$swappedTarget = $sibling_id; // 301 via canonicalizeSwappedSlug
						return self::pinPost( $query_vars, get_post( $sibling_id ) );
					}
					unset( $query_vars['pagename'], $query_vars['name'], $query_vars['p'], $query_vars['page_id'], $query_vars['attachment'] );
					$query_vars['error'] = '404';
					return $query_vars;
				}
				// No published translation. Pin the found post anyway — core only
				// resolves `pagename` against hierarchical types, so leaving the
				// vars untouched 404s a blog post. canonicalizeSwappedSlug then
				// 302s to the post's own-language URL.
				self::$fallbackTarget = $post->ID;
				self::$fallbackLang   = $lang;
				return self::pinPost( $query_vars, $post );
			}
			$target = $sibling;
			// The URL carried the *other* language's slug — remember the swap so
			// canonicalizeSwappedSlug can 301 to the real URL (core can't: only
			// the plugin knows the two slugs are siblings).
			self::$swappedTarget = $target->ID;
		}

		return self::pinPost( $query_vars, $target );
	}

	/** Post id pinned in place of the slug the URL actually carried. */
	private static ?int $swappedTarget = null;

	/** Post pinned under a language prefix it has no translation in. */
	private static ?int $fallbackTarget = null;

	/** Language the visitor asked for when the fallback post was pinned. */
	private static ?string $fallbackLang = null;

	/**
	 * Redirect a pinned post to its own permalink: 301 for a sibling-swapped
	 * URL (/en/{nl-slug}/ → /en/{en-slug}/), 302 for an untranslated post under
	 * a foreign prefix (/en/{nl-slug}/ → /{nl-slug}/) — temporary because the
	 * prefixed URL becomes real the moment the translation is published.
	 * Runs just before redirect_canonical. Skips previews, feeds, embeds and
	 * paginated requests — those must keep the URL they were asked on.
	 */
	public static function canonicalizeSwappedSlug(): void {
		$target = self::$swappedTarget ?? self::$fallbackTarget;
		if ( ! $target || is_preview() || is_feed() || is_embed() ) {
			return;
		}
		if ( get_query_var( 'paged' ) || get_query_var( 'page' ) || get_query_var( 'cpage' ) ) {
			return;
		}

		$canonical = get_permalink( $target );
		if ( ! $canonical ) {
			return;
		}

		$request  = $_SERVER['REQUEST_URI'] ?? '/';
		$req_path = (string) wp_parse_url( $request, PHP_URL_PATH );
		$can_path = (string) wp_parse_url( $canonical, PHP_URL_PATH );
		if ( untrailingslashit( $req_path ) === untrailingslashit( $can_path ) ) {
			return; // same URL apart from the slash — redirect_canonical's job
		}

		$query = (string) wp_parse_url( $request, PHP_URL_QUERY );
		if ( ! self::$swappedTarget && self::$fallbackLang ) {
			// FallbackNotice reads this on the landing page: shows a "not
			// translated yet" toast, then strips the param from the URL.
			$query = ( $query !== '' ? $query . '&' : '' ) . 'snel_notrans=' . rawurlencode( self::$fallbackLang );
		}
		wp_safe_redirect( $canonical . ( $query !== '' ? '?' . $query : '' ), self::$swappedTarget ? 301 : 302 );
		exit;
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

	/**
	 * Keep canonical redirects language-aware. WP's redirect_canonical still
	 * runs (trailing slash, host/scheme normalisation, wrong-slug fixes) but its
	 * target is computed from default-language state, so on a translated URL it
	 * can strip the /en/ prefix. Re-apply the prefix instead of disabling the
	 * whole mechanism; cancel only when the "fixed" URL is the requested one.
	 */
	public static function fixCanonicalRedirect( $redirect_url, $requested_url ) {
		$lang = get_query_var( 'lang', '' );

		if ( ! $redirect_url || ! $lang || $lang === LocaleManager::default() ) {
			return $redirect_url;
		}

		$parts     = wp_parse_url( $redirect_url );
		$home_path = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );

		$rel = $parts['path'] ?? '/';
		if ( $home_path !== '' && strpos( $rel, $home_path ) === 0 ) {
			$rel = substr( $rel, strlen( $home_path ) );
		}

		// Already carries the prefix (permalink filters got it right) — trust it.
		$segs = explode( '/', trim( $rel, '/' ) );
		if ( ! empty( $segs[0] ) && $segs[0] === $lang ) {
			return $redirect_url;
		}

		// Re-apply the stripped prefix.
		$rel      = trim( $rel, '/' );
		$new_path = $home_path . '/' . $lang . '/' . ( $rel !== '' ? $rel . '/' : '' );

		$rebuilt = '';
		if ( isset( $parts['scheme'], $parts['host'] ) ) {
			$rebuilt = $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		}
		$rebuilt .= $new_path;
		if ( ! empty( $parts['query'] ) ) {
			$rebuilt .= '?' . $parts['query'];
		}

		// Redirecting to the URL we're already on would loop — don't.
		if ( $rebuilt === (string) $requested_url ) {
			return false;
		}

		return $rebuilt;
	}
}
