<?php
/**
 * AdminColumns — Language / Languages columns + language filter on post-list
 * tables. Shows each post's language, a coverage overview on source rows, and a
 * dropdown to filter the list by language (defaulting to the source language).
 *
 * @package Snel\Translations
 */

namespace Snel\Translations;

use Snel\Translations\Core\TranslationGroup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AdminColumns {

	/** Register hooks. Called from Boot when live. */
	public static function register(): void {
		add_action( 'admin_init', [ self::class, 'registerColumns' ] );
		add_action( 'admin_head', [ self::class, 'widths' ] );
		add_action( 'restrict_manage_posts', [ self::class, 'filterDropdown' ] );
		add_action( 'pre_get_posts', [ self::class, 'filterQuery' ] );
		// Priority 20: core's own _wp_nav_menu_meta_box_object (added later, at
		// priority 10, from wp-admin/includes/admin-filters.php) *assigns*
		// _default_query rather than merging, so at 10 our meta_query is wiped.
		add_filter( 'nav_menu_meta_box_object', [ self::class, 'filterNavMenuPicker' ], 20 );
	}

	/**
	 * Limit the Appearance → Menus post-type pickers to source-language posts.
	 *
	 * A menu is built once, in the default language; Nav::item() swaps each item
	 * to the visitor's language per request. Listing every sibling there invites
	 * adding, say, the German page — which would pin that one language for all
	 * visitors. Taxonomy boxes run through this same filter, so bail on those:
	 * term translations live in term meta, not post meta.
	 */
	public static function filterNavMenuPicker( $object ) {
		if ( ! $object instanceof \WP_Post_Type ) {
			return $object;
		}

		$default = snel_get_default_lang();
		$query   = isset( $object->_default_query ) ? (array) $object->_default_query : [];
		$meta    = ( isset( $query['meta_query'] ) && is_array( $query['meta_query'] ) ) ? $query['meta_query'] : [];

		// Posts predating the plugin carry no language meta — they're sources too.
		$meta[] = [
			'relation' => 'OR',
			[ 'key' => TranslationGroup::META_LANG, 'value' => $default ],
			[ 'key' => TranslationGroup::META_LANG, 'compare' => 'NOT EXISTS' ],
		];

		$query['meta_query']    = $meta;
		$object->_default_query = $query;

		return $object;
	}

	/** Add the columns to every public post type. */
	public static function registerColumns(): void {
		foreach ( get_post_types( [ 'public' => true ] ) as $pt ) {
			if ( $pt === 'attachment' ) {
				continue;
			}
			add_filter( "manage_{$pt}_posts_columns", [ self::class, 'columns' ] );
			add_action( "manage_{$pt}_posts_custom_column", [ self::class, 'renderColumn' ], 10, 2 );
		}
	}

	/** One narrow Languages column + compact chip styles. */
	public static function widths(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || $screen->base !== 'edit' ) {
			return;
		}
		echo '<style>
			.column-snel_langs { width: 150px; }
			.snel-chips { display:flex; flex-wrap:wrap; gap:3px; align-items:center; }
			.snel-chip { display:inline-flex; align-items:center; justify-content:center; min-width:24px; height:20px; padding:0 6px; border-radius:6px; font-size:10px; font-weight:700; line-height:1; text-decoration:none; }
			.snel-chip--src { background:#dbeafe; color:#1d4ed8; box-shadow:inset 0 0 0 1px #93c5fd; }
			.snel-chip--done { background:#dcfce7; color:#15803d; }
			.snel-chip--draft { background:#fef3c7; color:#b45309; box-shadow:inset 0 0 0 1px #fcd34d; }
			.snel-chip--miss { background:#f1f5f9; color:#94a3b8; }
			.snel-chip--stale { background:#ffedd5; color:#c2410c; box-shadow:inset 0 0 0 1px #fdba74; }
			.snel-chip__dot { width:5px; height:5px; border-radius:50%; margin-right:2px; }
			.snel-chip--draft .snel-chip__dot { background:#d97706; }
			.snel-chip--stale .snel-chip__dot { background:#ea580c; }
			a.snel-chip:hover { filter:brightness(.95); }
			.snel-src { color:#64748b; font-size:12px; text-decoration:none; }
			.snel-src:hover { text-decoration:underline; }
		</style>';
	}

	/**
	 * Insert one Languages column before Date. On crowded tables (many columns,
	 * i.e. posts with Author/Categories/Tags/Comments) drop Comments to free up
	 * width for the title; lean CPTs keep all their columns.
	 */
	public static function columns( array $columns ): array {
		if ( isset( $columns['comments'] ) && count( $columns ) >= 6 ) {
			unset( $columns['comments'] );
		}

		$new = [];
		foreach ( $columns as $key => $label ) {
			if ( $key === 'date' ) {
				$new['snel_langs'] = __( 'Languages', 'snel' );
			}
			$new[ $key ] = $label;
		}
		if ( ! isset( $new['snel_langs'] ) ) {
			$new['snel_langs'] = __( 'Languages', 'snel' );
		}
		return $new;
	}

	/** Render the Languages cell: compact per-language chips with tooltips. */
	public static function renderColumn( $column, $post_id ): void {
		if ( $column !== 'snel_langs' ) {
			return;
		}

		$config  = snel_get_languages_config();
		$default = snel_get_default_lang();
		$lang    = snel_post_lang( $post_id );
		$label   = function ( $code ) use ( $config ) {
			return $config[ $code ]['label'] ?? strtoupper( $code );
		};

		echo '<div class="snel-chips">';

		// A translation row: its own chip + a link back to the source.
		if ( $lang !== $default ) {
			$is_draft = get_post_status( $post_id ) !== 'publish';
			printf(
				'<span class="snel-chip snel-chip--%s" title="%s">%s%s</span>',
				$is_draft ? 'draft' : 'done',
				esc_attr( $label( $lang ) . ' · ' . ( $is_draft ? __( 'draft — not live', 'snel' ) : __( 'published', 'snel' ) ) ),
				$is_draft ? '<span class="snel-chip__dot"></span>' : '',
				esc_html( strtoupper( $lang ) )
			);
			$source_id = (int) snel_get_translation( $post_id, $default );
			if ( $source_id ) {
				printf(
					'<a class="snel-src" href="%s" title="%s">%s</a>',
					esc_url( (string) get_edit_post_link( $source_id ) ),
					esc_attr( get_the_title( $source_id ) ),
					esc_html__( '← source', 'snel' )
				);
			}
			echo '</div>';
			return;
		}

		// Source row: a chip per language — src / translated (link) / missing.
		// One signature per row lets each sibling chip show out-of-sync state.
		$siblings   = snel_get_translations( $post_id );
		$source_sig = Create::source_signature( $post_id );
		foreach ( snel_get_supported_langs() as $code ) {
			$up = esc_html( strtoupper( $code ) );

			if ( $code === $default ) {
				printf(
					'<span class="snel-chip snel-chip--src" title="%s">%s</span>',
					esc_attr( $label( $code ) . ' · ' . __( 'source', 'snel' ) ),
					$up
				);
			} elseif ( ! empty( $siblings[ $code ] ) ) {
				$sib_id   = (int) $siblings[ $code ];
				$is_draft = get_post_status( $sib_id ) !== 'publish';
				$is_stale = ! $is_draft && Create::is_outdated( (string) get_post_meta( $sib_id, Create::HASH_META, true ), $source_sig );
				if ( $is_draft ) {
					$class = 'draft';
					$tip   = __( 'draft — not live · edit', 'snel' );
				} elseif ( $is_stale ) {
					$class = 'stale';
					$tip   = __( 'out of sync — source changed · edit', 'snel' );
				} else {
					$class = 'done';
					$tip   = __( 'published · edit', 'snel' );
				}
				printf(
					'<a class="snel-chip snel-chip--%s" href="%s" title="%s">%s%s</a>',
					$class,
					esc_url( (string) get_edit_post_link( $sib_id ) ),
					esc_attr( $label( $code ) . ' · ' . $tip ),
					( $is_draft || $is_stale ) ? '<span class="snel-chip__dot"></span>' : '',
					$up
				);
			} else {
				printf(
					'<span class="snel-chip snel-chip--miss" title="%s">%s</span>',
					esc_attr( $label( $code ) . ' · ' . __( 'not translated', 'snel' ) ),
					$up
				);
			}
		}
		echo '</div>';
	}

	/**
	 * The language the list defaults to when no filter is chosen: the source
	 * language on the main list (keeps it clean — translations show as chips on
	 * the source row), but "all" on status views like Trash or Drafts, so
	 * trashed/other-language posts aren't hidden behind a manual filter step.
	 */
	private static function defaultFilter(): string {
		$status = isset( $_GET['post_status'] )
			? sanitize_key( wp_unslash( $_GET['post_status'] ) )
			: '';
		if ( $status !== '' && $status !== 'all' ) {
			return 'all';
		}
		return snel_get_default_lang();
	}

	/** The language filter dropdown above the list. */
	public static function filterDropdown(): void {
		global $typenow;
		if ( ! in_array( $typenow, get_post_types( [ 'public' => true ] ), true ) ) {
			return;
		}

		$config  = snel_get_languages_config();
		$default = snel_get_default_lang();
		$current = isset( $_GET['snel_lang_filter'] )
			? sanitize_text_field( wp_unslash( $_GET['snel_lang_filter'] ) )
			: self::defaultFilter();

		echo '<select name="snel_lang_filter">';
		echo '<option value="all"' . selected( $current, 'all', false ) . '>' . esc_html__( 'All languages', 'snel' ) . '</option>';
		foreach ( snel_get_supported_langs() as $lang ) {
			$label = $config[ $lang ]['label'] ?? strtoupper( $lang );
			if ( $lang === $default ) {
				$label .= ' · ' . __( 'src', 'snel' );
			}
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $lang ),
				selected( $current, $lang, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
	}

	/** Apply the language filter to the list query. */
	public static function filterQuery( $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || $screen->base !== 'edit' ) {
			return;
		}

		$default = snel_get_default_lang();
		$lang    = isset( $_GET['snel_lang_filter'] )
			? sanitize_text_field( wp_unslash( $_GET['snel_lang_filter'] ) )
			: self::defaultFilter();
		// 'all' (or anything not a real language) → no filter, show every language.
		if ( ! $lang || ! in_array( $lang, snel_get_supported_langs(), true ) ) {
			return;
		}

		$meta = $query->get( 'meta_query' );
		if ( ! is_array( $meta ) ) {
			$meta = [];
		}

		if ( $lang === $default ) {
			$meta[] = [
				'relation' => 'OR',
				[ 'key' => TranslationGroup::META_LANG, 'value' => $default ],
				[ 'key' => TranslationGroup::META_LANG, 'compare' => 'NOT EXISTS' ],
			];
		} else {
			$meta[] = [ 'key' => TranslationGroup::META_LANG, 'value' => $lang ];
		}

		$query->set( 'meta_query', $meta );
	}
}
