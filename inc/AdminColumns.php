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
			.snel-chip--miss { background:#f1f5f9; color:#94a3b8; }
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
			printf(
				'<span class="snel-chip snel-chip--done" title="%s">%s</span>',
				esc_attr( $label( $lang ) . ' · ' . __( 'translation', 'snel' ) ),
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
		$siblings = snel_get_translations( $post_id );
		foreach ( snel_get_supported_langs() as $code ) {
			$up = esc_html( strtoupper( $code ) );

			if ( $code === $default ) {
				printf(
					'<span class="snel-chip snel-chip--src" title="%s">%s</span>',
					esc_attr( $label( $code ) . ' · ' . __( 'source', 'snel' ) ),
					$up
				);
			} elseif ( ! empty( $siblings[ $code ] ) ) {
				printf(
					'<a class="snel-chip snel-chip--done" href="%s" title="%s">%s</a>',
					esc_url( (string) get_edit_post_link( (int) $siblings[ $code ] ) ),
					esc_attr( $label( $code ) . ' · ' . __( 'translated — edit', 'snel' ) ),
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
			: $default;

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
			: $default;
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
