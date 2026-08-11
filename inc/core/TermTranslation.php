<?php
/**
 * TermTranslation — translated taxonomy labels (shared term).
 *
 * A term is NOT duplicated per language. It keeps its native name/slug/desc;
 * the translated name + description live in term meta:
 *   _snel_name_{lang}  · _snel_desc_{lang}
 * The default language uses the native columns (no meta). A missing translation
 * falls back to the native value — a term never renders blank.
 *
 * On the front end a get_term filter swaps name/description into the current
 * language. In wp-admin the current language is always default, so it's a no-op
 * there and edit screens show the native term.
 *
 * @package Snel\Translations
 */

namespace Snel\Translations\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TermTranslation {

	/** A term's name in a language (default: current). Falls back to native. */
	public static function name( $term, ?string $lang = null ): string {
		$term = get_term( $term );
		if ( ! $term instanceof \WP_Term ) {
			return '';
		}

		$lang = $lang ?: LocaleManager::current();
		if ( $lang === LocaleManager::default() ) {
			return $term->name;
		}

		$value = get_term_meta( $term->term_id, self::nameKey( $lang ), true );
		return $value !== '' ? $value : $term->name;
	}

	/** A term's description in a language (default: current). Falls back to native. */
	public static function description( $term, ?string $lang = null ): string {
		$term = get_term( $term );
		if ( ! $term instanceof \WP_Term ) {
			return '';
		}

		$lang = $lang ?: LocaleManager::current();
		if ( $lang === LocaleManager::default() ) {
			return $term->description;
		}

		$value = get_term_meta( $term->term_id, self::descKey( $lang ), true );
		return $value !== '' ? $value : $term->description;
	}

	/** Meta key for a term name in a language. */
	public static function nameKey( string $lang ): string {
		return '_snel_name_' . $lang;
	}

	/** Meta key for a term description in a language. */
	public static function descKey( string $lang ): string {
		return '_snel_desc_' . $lang;
	}

	/** Meta key for a term SEO title in a language. */
	public static function seoTitleKey( string $lang ): string {
		return '_snel_seo_title_' . $lang;
	}

	/** Meta key for a term SEO meta description in a language. */
	public static function seoDescKey( string $lang ): string {
		return '_snel_seo_desc_' . $lang;
	}

	/** Register the front-end display filter (no-op in admin). */
	public static function register(): void {
		if ( is_admin() ) {
			// Per-language name/description fields on every public term's edit
			// screen. Taxonomies register on init (before admin_init).
			add_action( 'admin_init', function () {
				foreach ( get_taxonomies( [ 'public' => true ], 'names' ) as $tax ) {
					add_action( "{$tax}_edit_form_fields", [ self::class, 'editFields' ], 10, 2 );
					add_action( "edited_{$tax}", [ self::class, 'saveFields' ] );
					add_action( "created_{$tax}", [ self::class, 'saveFields' ] );
				}
			} );
			add_action( 'wp_ajax_snel_translate_term', [ self::class, 'ajaxTranslateTerm' ] );
			return;
		}
		add_filter( 'get_term', [ self::class, 'filterTerm' ] );
		add_filter( 'term_link', [ self::class, 'filterTermLink' ], 10, 3 );
		add_filter( 'request', [ self::class, 'resolveTermSlug' ] );
	}

	public static function slugKey( string $lang ): string {
		return '_snel_slug_' . $lang;
	}

	/**
	 * Output the translated slug + language prefix in term links for the current
	 * language. e.g. /blog/ai-automatisering/ → /en/blog/ai-automation/
	 */
	public static function filterTermLink( $url, $term, $taxonomy ) {
		$lang = LocaleManager::current();
		if ( $lang === LocaleManager::default() || ! $term instanceof \WP_Term ) {
			return $url;
		}

		return UrlGenerator::url( self::swapSlugs( (string) $url, $term, $taxonomy, $lang ) );
	}

	/**
	 * Term URL in an arbitrary language, independent of the language the current
	 * request is in. The language switcher and the hreflang tags need this: on a
	 * term archive there is no sibling post to look up, and swapping only the
	 * URL prefix would keep the source language's slug.
	 */
	public static function linkForLang( $term, string $taxonomy, string $lang ): string {
		if ( ! $term instanceof \WP_Term ) {
			return '';
		}

		// get_term_link() runs filterTermLink for the CURRENT language — unhook
		// it so we start from the plain, unprefixed, default-language URL.
		remove_filter( 'term_link', [ self::class, 'filterTermLink' ], 10 );
		$url = get_term_link( $term, $taxonomy );
		add_filter( 'term_link', [ self::class, 'filterTermLink' ], 10, 3 );

		if ( is_wp_error( $url ) ) {
			return '';
		}

		$default = LocaleManager::default();
		$url     = ( $lang === $default ) ? (string) $url : self::swapSlugs( (string) $url, $term, $taxonomy, $lang );

		if ( $lang === $default ) {
			return $url;
		}

		$parsed = wp_parse_url( $url );
		$path   = $parsed['path'] ?? '/';
		$base   = isset( $parsed['scheme'], $parsed['host'] )
			? $parsed['scheme'] . '://' . $parsed['host'] . ( isset( $parsed['port'] ) ? ':' . $parsed['port'] : '' )
			: '';

		return $base . '/' . $lang . $path;
	}

	/**
	 * Replace the term's own slug and every ancestor's slug with their
	 * translations for $lang. Hierarchical taxonomies build nested paths like
	 * /producten/parent/child/, so each segment has to be swapped separately.
	 */
	private static function swapSlugs( string $url, \WP_Term $term, string $taxonomy, string $lang ): string {
		// The taxonomy's own base segment (e.g. /producten/) is shared by every
		// term, so it comes from the base-slug config rather than term meta.
		$tax_obj = get_taxonomy( $taxonomy );
		if ( $tax_obj ) {
			$base = ( is_array( $tax_obj->rewrite ) && ! empty( $tax_obj->rewrite['slug'] ) ) ? $tax_obj->rewrite['slug'] : $tax_obj->name;
			$cfg  = UrlGenerator::cptSlugsConfig();
			if ( ! empty( $cfg[ $base ][ $lang ] ) && $cfg[ $base ][ $lang ] !== $base ) {
				$url = preg_replace( '#/' . preg_quote( $base, '#' ) . '(/|$)#', '/' . $cfg[ $base ][ $lang ] . '$1', $url, 1 );
			}
		}

		$chain = array_merge( [ $term->term_id ], get_ancestors( $term->term_id, $taxonomy, 'taxonomy' ) );
		foreach ( $chain as $tid ) {
			$t = get_term( $tid, $taxonomy );
			if ( ! $t instanceof \WP_Term ) {
				continue;
			}
			$tslug = get_term_meta( $t->term_id, self::slugKey( $lang ), true );
			if ( $tslug !== '' && $tslug !== $t->slug ) {
				$url = preg_replace( '#/' . preg_quote( $t->slug, '#' ) . '(/|$)#', '/' . $tslug . '$1', $url, 1 );
			}
		}

		return $url;
	}

	/**
	 * Reverse of filterTermLink: a request carrying a translated slug is mapped
	 * back to the term's real slug so WordPress can resolve it.
	 */
	public static function resolveTermSlug( array $query_vars ): array {
		$lang = $query_vars['lang'] ?? LocaleManager::default();
		if ( $lang === LocaleManager::default() ) {
			return $query_vars;
		}

		foreach ( get_taxonomies( [ 'public' => true ], 'objects' ) as $tax ) {
			$var = $tax->name === 'category' ? 'category_name' : ( $tax->name === 'post_tag' ? 'tag' : $tax->name );
			if ( empty( $query_vars[ $var ] ) || ! is_string( $query_vars[ $var ] ) ) {
				continue;
			}
			// Hierarchical requests carry a nested path (parent/child) where every
			// segment may be a translated slug — map each one back individually.
			$segments = explode( '/', $query_vars[ $var ] );
			foreach ( $segments as $i => $segment ) {
				$real = self::realSlug( $tax->name, $lang, $segment );
				if ( $real ) {
					$segments[ $i ] = $real;
				}
			}
			$query_vars[ $var ] = implode( '/', $segments );
		}

		return $query_vars;
	}

	/** Real term slug whose per-language slug meta matches $translated, if any. */
	private static function realSlug( string $taxonomy, string $lang, string $translated ): ?string {
		$slugs = get_terms( [
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'number'     => 1,
			'fields'     => 'slugs',
			'meta_key'   => self::slugKey( $lang ),
			'meta_value' => $translated,
		] );
		if ( is_array( $slugs ) && ! empty( $slugs ) ) {
			return $slugs[0];
		}
		return null;
	}

	/** Non-default languages that need a translation field. */
	private static function targetLangs(): array {
		return array_values( array_diff( LocaleManager::supported(), [ LocaleManager::default() ] ) );
	}

	/** Tabbed per-language name/description editor on the term edit screen. */
	public static function editFields( $term, $taxonomy ): void {
		$langs = self::targetLangs();
		if ( empty( $langs ) ) {
			return;
		}
		$cfg   = LocaleManager::config();
		$nonce = wp_create_nonce( 'snel_term_tr_ajax' );
		wp_nonce_field( 'snel_term_tr', 'snel_term_tr_nonce' );
		?>
		<tr class="form-field">
			<th scope="row"><label><?php esc_html_e( 'Translations', 'snel' ); ?></label></th>
			<td>
				<div class="snel-tr" data-term="<?php echo esc_attr( $term->term_id ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">
					<div class="snel-tr-bar">
						<div class="snel-tr-tabs">
							<?php foreach ( $langs as $i => $lang ) : ?>
								<button type="button" class="snel-tr-tab<?php echo 0 === $i ? ' is-active' : ''; ?>" data-lang="<?php echo esc_attr( $lang ); ?>">
									<?php echo esc_html( $cfg[ $lang ]['label'] ?? strtoupper( $lang ) ); ?>
								</button>
							<?php endforeach; ?>
						</div>
						<button type="button" class="button snel-tr-ai">
							<span class="dashicons dashicons-translation"></span>
							<?php esc_html_e( 'Translate all', 'snel' ); ?>
						</button>
					</div>

					<?php foreach ( $langs as $i => $lang ) :
						$name      = get_term_meta( $term->term_id, self::nameKey( $lang ), true );
						$desc      = get_term_meta( $term->term_id, self::descKey( $lang ), true );
						$slug      = get_term_meta( $term->term_id, self::slugKey( $lang ), true );
						$seo_title = get_term_meta( $term->term_id, self::seoTitleKey( $lang ), true );
						$seo_desc  = get_term_meta( $term->term_id, self::seoDescKey( $lang ), true );
						?>
						<div class="snel-tr-panel<?php echo 0 === $i ? ' is-active' : ''; ?>" data-lang="<?php echo esc_attr( $lang ); ?>">
							<p>
								<label><strong><?php esc_html_e( 'Name', 'snel' ); ?></strong></label><br>
								<input type="text" class="regular-text snel-tr-name"
									name="snel_term_name[<?php echo esc_attr( $lang ); ?>]"
									value="<?php echo esc_attr( $name ); ?>"
									placeholder="<?php echo esc_attr( $term->name ); ?>" />
							</p>
							<p>
								<label><strong><?php esc_html_e( 'Slug', 'snel' ); ?></strong></label><br>
								<input type="text" class="regular-text snel-tr-slug"
									name="snel_term_slug[<?php echo esc_attr( $lang ); ?>]"
									value="<?php echo esc_attr( $slug ); ?>"
									placeholder="<?php echo esc_attr( $term->slug ); ?>" />
								<span class="description"><?php esc_html_e( 'URL for this language, e.g. /en/blog/your-slug/. Blank = the default slug.', 'snel' ); ?></span>
							</p>
							<p>
								<label><strong><?php esc_html_e( 'Description', 'snel' ); ?></strong></label><br>
								<textarea rows="3" class="large-text snel-tr-desc"
									name="snel_term_desc[<?php echo esc_attr( $lang ); ?>]"><?php echo esc_textarea( $desc ); ?></textarea>
							</p>
							<p>
								<label><strong><?php esc_html_e( 'SEO title', 'snel' ); ?></strong></label><br>
								<input type="text" class="regular-text snel-tr-seo-title"
									name="snel_term_seo_title[<?php echo esc_attr( $lang ); ?>]"
									value="<?php echo esc_attr( $seo_title ); ?>"
									placeholder="<?php esc_attr_e( 'Blank = automatic (translated name)', 'snel' ); ?>" />
							</p>
							<p>
								<label><strong><?php esc_html_e( 'Meta description', 'snel' ); ?></strong></label><br>
								<textarea rows="2" class="large-text snel-tr-seo-desc"
									name="snel_term_seo_desc[<?php echo esc_attr( $lang ); ?>]"
									placeholder="<?php esc_attr_e( 'Blank = the translated description', 'snel' ); ?>"><?php echo esc_textarea( $seo_desc ); ?></textarea>
							</p>
						</div>
					<?php endforeach; ?>
					<p class="description"><?php esc_html_e( 'Shown on the front end for each language. Blank = the default name. Save the term to keep changes.', 'snel' ); ?></p>
				</div>
			</td>
		</tr>
		<?php
		self::printAssets();
	}

	/** Inline CSS + JS for the term-translation tabs (printed once). */
	private static function printAssets(): void {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		?>
		<style>
			.snel-tr{max-width:640px}
			.snel-tr-bar{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px}
			.snel-tr-tabs{display:inline-flex;gap:4px;background:#f0f0f1;padding:3px;border-radius:8px}
			.snel-tr-tab{border:0;background:transparent;padding:5px 12px;font-size:13px;font-weight:600;color:#50575e;border-radius:6px;cursor:pointer}
			.snel-tr-tab.is-active{background:#fff;color:#1d2327;box-shadow:0 1px 2px rgba(0,0,0,.08)}
			.snel-tr-ai{display:inline-flex;align-items:center;gap:6px}
			.snel-tr-ai .dashicons{font-size:16px;width:16px;height:16px}
			.snel-tr-ai.is-busy{opacity:.6;pointer-events:none}
			.snel-tr-panel{display:none;position:relative}
			.snel-tr-panel.is-active{display:block}
			.snel-tr-panel p{margin:0 0 12px}
			.snel-tr.is-translating .snel-tr-panel.is-active::after{content:"";position:absolute;inset:-4px;background:rgba(255,255,255,.65);border-radius:6px;z-index:1}
			.snel-tr.is-translating .snel-tr-panel.is-active::before{content:"";position:absolute;top:40px;left:50%;width:24px;height:24px;margin-left:-12px;border:2px solid #dcdcde;border-top-color:#2271b1;border-radius:50%;animation:snel-spin .7s linear infinite;z-index:2}
			@keyframes snel-spin{to{transform:rotate(360deg)}}
			.snel-tr-log{margin-top:12px;padding:10px 12px;background:#1d2327;color:#9ec2e6;font-size:12px;line-height:1.5;border-radius:6px;max-height:280px;overflow:auto;white-space:pre-wrap;word-break:break-word;user-select:all}
			.snel-tr-log.is-error{color:#f0b849}
		</style>
		<script>
		( function () {
			// Debug log box: printed under the fields so it can be copy-pasted.
			function showLog( root, lines, ok ) {
				var pre = root.querySelector( '.snel-tr-log' );
				if ( ! lines || ! lines.length ) {
					if ( pre ) pre.remove();
					return;
				}
				if ( ! pre ) {
					pre = document.createElement( 'pre' );
					pre.className = 'snel-tr-log';
					root.appendChild( pre );
				}
				pre.classList.toggle( 'is-error', ! ok );
				pre.textContent = lines.join( '\n' );
			}

			document.querySelectorAll( '.snel-tr' ).forEach( function ( root ) {
				root.querySelectorAll( '.snel-tr-tab' ).forEach( function ( tab ) {
					tab.addEventListener( 'click', function () {
						var lang = tab.dataset.lang;
						root.querySelectorAll( '.snel-tr-tab' ).forEach( function ( t ) { t.classList.toggle( 'is-active', t === tab ); } );
						root.querySelectorAll( '.snel-tr-panel' ).forEach( function ( p ) { p.classList.toggle( 'is-active', p.dataset.lang === lang ); } );
					} );
				} );

				var ai = root.querySelector( '.snel-tr-ai' );
				ai && ai.addEventListener( 'click', function () {
					ai.classList.add( 'is-busy' );
					root.classList.add( 'is-translating' );
					var body = new FormData();
					body.append( 'action', 'snel_translate_term' );
					body.append( 'nonce', root.dataset.nonce );
					body.append( 'term_id', root.dataset.term );
					fetch( ajaxurl, { method: 'POST', body: body, credentials: 'same-origin' } )
						.then( function ( r ) { return r.json(); } )
						.then( function ( res ) {
							var data = ( res && res.data ) || {};
							showLog( root, data.debug, res && res.success );
							if ( res && res.success ) {
								var tr = data.translations || {};
								Object.keys( tr ).forEach( function ( lang ) {
									var panel = root.querySelector( '.snel-tr-panel[data-lang="' + lang + '"]' );
									if ( ! panel ) return;
									var n = panel.querySelector( '.snel-tr-name' );
									var d = panel.querySelector( '.snel-tr-desc' );
									var s = panel.querySelector( '.snel-tr-slug' );
									if ( n && ! n.value ) n.value = tr[ lang ].name || '';
									if ( d && ! d.value ) d.value = tr[ lang ].desc || '';
									if ( s && ! s.value ) s.value = tr[ lang ].slug || '';
								} );
							} else {
								alert( ( data && data.message ) || 'Translation failed.' );
							}
						} )
						.catch( function () { alert( 'Request failed.' ); } )
						.finally( function () { ai.classList.remove( 'is-busy' ); root.classList.remove( 'is-translating' ); } );
				} );
			} );
		} )();
		</script>
		<?php
	}

	/** AJAX: translate the term's default name + description into every language. */
	public static function ajaxTranslateTerm(): void {
		check_ajax_referer( 'snel_term_tr_ajax', 'nonce' );

		$term_id = (int) ( $_POST['term_id'] ?? 0 );
		if ( ! $term_id || ! current_user_can( 'manage_categories' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}
		$term = get_term( $term_id );
		if ( ! $term instanceof \WP_Term ) {
			wp_send_json_error( [ 'message' => 'Term not found' ] );
		}

		$source = LocaleManager::default();
		$out    = [];
		\Snel\Translations\Ai::log( sprintf( 'term translate: #%d "%s" (desc %d chars)', $term->term_id, $term->name, strlen( (string) $term->description ) ) );
		foreach ( self::targetLangs() as $lang ) {
			$tr = \Snel\Translations\Ai::translate( [ $term->name, (string) $term->description ], $source, $lang );
			if ( is_wp_error( $tr ) ) {
				wp_send_json_error( [ 'message' => $tr->get_error_message(), 'debug' => \Snel\Translations\Ai::logs() ] );
			}
			// AI sometimes returns HTML-encoded text (e.g. "&amp;"). Decode to a
			// clean "&" so it isn't stored double-encoded.
			$tname = html_entity_decode( (string) ( $tr[0] ?? '' ), ENT_QUOTES, 'UTF-8' );
			$out[ $lang ] = [
				'name' => $tname,
				'desc' => html_entity_decode( (string) ( $tr[1] ?? '' ), ENT_QUOTES, 'UTF-8' ),
				'slug' => sanitize_title( $tname ),
			];
		}

		wp_send_json_success( [ 'translations' => $out, 'debug' => \Snel\Translations\Ai::logs() ] );
	}

	/** Save the per-language name/description meta. */
	public static function saveFields( int $term_id ): void {
		if (
			! isset( $_POST['snel_term_tr_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['snel_term_tr_nonce'] ) ), 'snel_term_tr' )
		) {
			return;
		}

		$names      = isset( $_POST['snel_term_name'] ) ? (array) $_POST['snel_term_name'] : [];
		$descs      = isset( $_POST['snel_term_desc'] ) ? (array) $_POST['snel_term_desc'] : [];
		$slugs      = isset( $_POST['snel_term_slug'] ) ? (array) $_POST['snel_term_slug'] : [];
		$seo_titles = isset( $_POST['snel_term_seo_title'] ) ? (array) $_POST['snel_term_seo_title'] : [];
		$seo_descs  = isset( $_POST['snel_term_seo_desc'] ) ? (array) $_POST['snel_term_seo_desc'] : [];

		foreach ( self::targetLangs() as $lang ) {
			if ( isset( $names[ $lang ] ) ) {
				update_term_meta( $term_id, self::nameKey( $lang ), sanitize_text_field( wp_unslash( $names[ $lang ] ) ) );
			}
			if ( isset( $descs[ $lang ] ) ) {
				update_term_meta( $term_id, self::descKey( $lang ), sanitize_textarea_field( wp_unslash( $descs[ $lang ] ) ) );
			}
			if ( isset( $slugs[ $lang ] ) ) {
				update_term_meta( $term_id, self::slugKey( $lang ), sanitize_title( wp_unslash( $slugs[ $lang ] ) ) );
			}
			if ( isset( $seo_titles[ $lang ] ) ) {
				update_term_meta( $term_id, self::seoTitleKey( $lang ), sanitize_text_field( wp_unslash( $seo_titles[ $lang ] ) ) );
			}
			if ( isset( $seo_descs[ $lang ] ) ) {
				update_term_meta( $term_id, self::seoDescKey( $lang ), sanitize_textarea_field( wp_unslash( $seo_descs[ $lang ] ) ) );
			}
		}
	}

	/** Swap a term's name/description into the current language for display. */
	public static function filterTerm( $term ) {
		if ( ! $term instanceof \WP_Term ) {
			return $term;
		}

		$lang = LocaleManager::current();
		if ( $lang === LocaleManager::default() ) {
			return $term;
		}

		$name = get_term_meta( $term->term_id, self::nameKey( $lang ), true );
		if ( $name !== '' ) {
			$term->name = $name;
		}

		$desc = get_term_meta( $term->term_id, self::descKey( $lang ), true );
		if ( $desc !== '' ) {
			$term->description = $desc;
		}

		return $term;
	}
}
