<?php
/**
 * Controller — business logic for the admin-page REST endpoints.
 *
 * Validates + sanitizes input, orchestrates (Model for data, core classes for
 * language logic), returns WP_REST_Response or WP_Error. Never writes SQL.
 *
 * Covers: theme strings, languages config, settings, debug, orphans.
 * The create/sync/AI flow lives in its own service (added next).
 *
 * @package Snel\Translations
 */

namespace Snel\Translations;

use Snel\Translations\Core\LocaleManager;
use Snel\Translations\Core\TranslationGroup;
use Snel\Translations\Core\Translator;
use Snel\Translations\Core\UrlGenerator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Controller {

	private const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

	// ─── Theme strings ───────────────────────────────────────────────────────

	public function theme_strings_get() {
		return rest_ensure_response( Translator::grouped() );
	}

	public function theme_strings_save( \WP_REST_Request $request ) {
		$translations = $request->get_json_params();
		if ( ! is_array( $translations ) ) {
			return new \WP_Error( 'invalid_data', 'Expected an object of translations.', [ 'status' => 400 ] );
		}

		foreach ( $translations as $dutch_key => $langs ) {
			if ( ! is_array( $langs ) ) {
				continue;
			}
			foreach ( $langs as $lang => $text ) {
				Translator::save(
					sanitize_text_field( $dutch_key ),
					sanitize_key( $lang ),
					sanitize_text_field( $text )
				);
			}
		}

		return rest_ensure_response( [ 'success' => true ] );
	}

	// ─── Languages config (JSON) ─────────────────────────────────────────────

	public function languages_config_get() {
		$file   = require SNEL_TR_DIR . 'config/languages.php';
		$stored = Model::languagesJson();

		return rest_ensure_response( [
			'json'        => wp_json_encode( LocaleManager::config(), self::JSON_FLAGS ),
			'defaultJson' => wp_json_encode( $file, self::JSON_FLAGS ),
			'overridden'  => trim( $stored ) !== '',
		] );
	}

	public function languages_config_save( \WP_REST_Request $request ) {
		$raw = (string) $request->get_param( 'json' );

		if ( trim( $raw ) === '' ) {
			Model::clearLanguages();
			flush_rewrite_rules();
			return rest_ensure_response( [ 'success' => true, 'reverted' => true ] );
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) || empty( $decoded ) ) {
			return new \WP_Error( 'invalid_json', 'Not valid JSON, or empty object.', [ 'status' => 400 ] );
		}

		$defaults = 0;
		foreach ( $decoded as $code => $lang ) {
			if ( ! is_string( $code ) || ! preg_match( '/^[a-z]{2}(-[a-z]{2})?$/i', $code ) ) {
				return new \WP_Error( 'invalid_code', "Invalid language code: '" . esc_html( (string) $code ) . "'.", [ 'status' => 400 ] );
			}
			if ( ! is_array( $lang ) || empty( $lang['label'] ) ) {
				return new \WP_Error( 'invalid_lang', "Language '" . esc_html( $code ) . "' needs at least a \"label\".", [ 'status' => 400 ] );
			}
			if ( ! empty( $lang['default'] ) ) {
				$defaults++;
			}
		}
		if ( $defaults !== 1 ) {
			return new \WP_Error( 'default_count', 'Exactly one language must have "default": true.', [ 'status' => 400 ] );
		}

		// Removing a language whose translations still exist orphans those posts.
		// Surface it and let the UI ask for an explicit confirm (force=true).
		$removed = array_diff( array_keys( LocaleManager::config() ), array_keys( $decoded ) );
		if ( ! empty( $removed ) && ! (bool) $request->get_param( 'force' ) ) {
			$in_use = [];
			foreach ( $removed as $code ) {
				$count = count( Model::postIdsInLang( $code ) );
				if ( $count > 0 ) {
					$in_use[ $code ] = $count;
				}
			}
			if ( ! empty( $in_use ) ) {
				return rest_ensure_response( [
					'success'      => false,
					'needsConfirm' => true,
					'inUse'        => $in_use,
				] );
			}
		}

		Model::saveLanguagesJson( wp_json_encode( $decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		flush_rewrite_rules();

		return rest_ensure_response( [ 'success' => true ] );
	}

	// ─── Settings (default + enabled languages) ──────────────────────────────

	public function settings_save( \WP_REST_Request $request ) {
		$config      = LocaleManager::config();
		$all_codes   = array_keys( $config );
		$new_default = sanitize_text_field( (string) $request->get_param( 'defaultLang' ) );

		if ( ! array_key_exists( $new_default, $config ) ) {
			return new \WP_Error( 'invalid_lang', 'Unknown language.', [ 'status' => 400 ] );
		}

		$enabled_in = $request->get_param( 'enabledLangs' );
		$enabled    = is_array( $enabled_in )
			? array_values( array_intersect( $all_codes, array_map( 'sanitize_text_field', $enabled_in ) ) )
			: $all_codes;
		if ( ! in_array( $new_default, $enabled, true ) ) {
			$enabled[] = $new_default;
		}

		// Stamp unstamped posts with the OLD default before switching, so they
		// keep their real language instead of inheriting the new default.
		$old_default = LocaleManager::default();
		if ( $old_default !== $new_default ) {
			TranslationGroup::backfillMissingLang( $old_default );
		}

		Model::saveDefaultLang( $new_default );
		Model::saveEnabledLangs( $enabled );

		// Optional per-disabled-language redirect target ({ es: 'en' }). Only
		// entries pointing at an enabled language are kept; everything else
		// falls back to the default language at request time.
		$redirects_in = $request->get_param( 'disabledRedirects' );
		if ( is_array( $redirects_in ) ) {
			$map = [];
			foreach ( $redirects_in as $lang => $target ) {
				$lang   = sanitize_text_field( (string) $lang );
				$target = sanitize_text_field( (string) $target );
				if (
					array_key_exists( $lang, $config ) && ! in_array( $lang, $enabled, true )
					&& $target !== '' && $target !== $lang && in_array( $target, $enabled, true )
				) {
					$map[ $lang ] = $target;
				}
			}
			Model::saveDisabledRedirects( $map );
		}

		flush_rewrite_rules();

		return rest_ensure_response( [ 'success' => true ] );
	}

	// ─── Debug ───────────────────────────────────────────────────────────────

	public function debug_get() {
		$rows   = Model::translationRows();
		$groups = [];
		foreach ( $rows as $r ) {
			$groups[ $r['group'] ][] = $r;
		}

		return rest_ensure_response( [
			'languagesConfig'   => LocaleManager::config(),
			'defaultLang'       => LocaleManager::default(),
			'enabledLangs'      => LocaleManager::supported(),
			'themeStrings'      => Model::themeStrings(),
			'translationGroups' => array_values( $groups ),
			'translationRows'   => $rows,
			'metaRows'          => Model::metaRows(),
		] );
	}

	// ─── Orphans ─────────────────────────────────────────────────────────────

	public function orphans_get() {
		$configured = array_keys( LocaleManager::config() );
		$posts      = Model::orphanPosts( $configured );

		$langs = [];
		foreach ( $posts as &$p ) {
			$langs[ $p['lang'] ] = true;
			$p['editUrl']        = get_edit_post_link( $p['id'], 'raw' );
		}
		unset( $p );

		return rest_ensure_response( [
			'languages' => array_keys( $langs ),
			'posts'     => $posts,
		] );
	}

	public function orphan_action( \WP_REST_Request $request ) {
		$action = sanitize_text_field( (string) $request->get_param( 'action' ) );

		if ( $action === 'add_language' ) {
			$lang = sanitize_text_field( (string) $request->get_param( 'lang' ) );
			if ( ! preg_match( '/^[a-z]{2}(-[a-z]{2})?$/i', $lang ) ) {
				return new \WP_Error( 'bad_lang', 'Invalid language code.', [ 'status' => 400 ] );
			}
			$config = LocaleManager::config();
			if ( ! isset( $config[ $lang ] ) ) {
				$config[ $lang ] = [ 'label' => strtoupper( $lang ), 'locale' => $lang ];
				Model::saveLanguagesJson( wp_json_encode( $config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
				flush_rewrite_rules();
			}
			return rest_ensure_response( [ 'success' => true ] );
		}

		$post_id = (int) $request->get_param( 'postId' );
		if ( ! $post_id || ! current_user_can( 'delete_post', $post_id ) ) {
			return new \WP_Error( 'cap', 'Not allowed.', [ 'status' => 403 ] );
		}

		if ( $action === 'trash' ) {
			Model::trashPost( $post_id );
			return rest_ensure_response( [ 'success' => true ] );
		}
		if ( $action === 'delete' ) {
			Model::deletePost( $post_id );
			return rest_ensure_response( [ 'success' => true ] );
		}

		return new \WP_Error( 'bad_action', 'Unknown action.', [ 'status' => 400 ] );
	}

	// ─── Custom fields (translatable meta) ───────────────────────────────────

	/** Public post types + their detected meta keys + current selection. */
	public function fields_get() {
		$selected = Model::getTranslatableMeta();
		$out      = [];

		foreach ( get_post_types( [ 'public' => true ], 'objects' ) as $pt ) {
			if ( $pt->name === 'attachment' ) {
				continue;
			}
			$keys = Model::metaKeysForType( $pt->name );
			if ( empty( $keys ) ) {
				continue;
			}
			$sel = $selected[ $pt->name ] ?? [];
			$out[] = [
				'postType' => $pt->name,
				'label'    => $pt->labels->singular_name ?? $pt->name,
				'fields'   => array_map( function ( $k ) use ( $sel ) {
					return [ 'key' => $k, 'translate' => in_array( $k, $sel, true ) ];
				}, $keys ),
			];
		}

		return rest_ensure_response( $out );
	}

	/** Save the per-CPT translatable-meta selection. Body: { postType: [keys] }. */
	public function fields_save( \WP_REST_Request $request ) {
		$data = $request->get_json_params();
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'invalid_data', 'Expected an object of post types.', [ 'status' => 400 ] );
		}

		$clean = [];
		foreach ( $data as $pt => $keys ) {
			if ( ! is_array( $keys ) ) {
				continue;
			}
			$clean[ sanitize_key( $pt ) ] = array_values( array_map( 'sanitize_text_field', $keys ) );
		}

		Model::saveTranslatableMeta( $clean );
		return rest_ensure_response( [ 'success' => true ] );
	}

	// ─── CPT slug translations ───────────────────────────────────────────────

	/**
	 * Every translatable URL base segment: custom post types and public
	 * taxonomies. Both occupy the same position in a URL (/producten/…,
	 * /merken/…), so they share one config keyed by the default slug.
	 *
	 * @return array<int, array{name:string,label:string,slug:string,kind:string}>
	 */
	private static function urlBases(): array {
		$bases = [];

		foreach ( get_post_types( [ 'public' => true, '_builtin' => false ], 'objects' ) as $pt ) {
			$bases[] = [
				'name'  => $pt->name,
				'label' => $pt->labels->singular_name ?? $pt->name,
				'slug'  => ( is_array( $pt->rewrite ) && ! empty( $pt->rewrite['slug'] ) ) ? $pt->rewrite['slug'] : $pt->name,
				'kind'  => 'post_type',
			];
		}

		foreach ( get_taxonomies( [ 'public' => true ], 'objects' ) as $tax ) {
			if ( 'post_format' === $tax->name ) {
				continue;
			}
			$bases[] = [
				'name'  => $tax->name,
				'label' => $tax->labels->singular_name ?? $tax->name,
				'slug'  => ( is_array( $tax->rewrite ) && ! empty( $tax->rewrite['slug'] ) ) ? $tax->rewrite['slug'] : $tax->name,
				'kind'  => 'taxonomy',
			];
		}

		return $bases;
	}

	/** Public CPT + taxonomy bases with their per-language slug translations. */
	// ─── Media alt text ──────────────────────────────────────────────────────

	/**
	 * Scopes for the media batch wizard: every post type that actually has
	 * images attached, with its count.
	 *
	 * Deliberately not using /wp/v2/types — post types registered with
	 * show_in_rest=false (products here) are missing from it entirely.
	 */
	public function media_scopes_get() {
		$langs   = array_values( array_diff( LocaleManager::supported(), [ LocaleManager::default() ] ) );
		$counts  = Model::imageCountsByParentType();
		$backlog = Model::imageBacklogByParentType( $langs );
		$scopes  = [];

		foreach ( $counts['types'] as $slug => $count ) {
			// 'attachment' turns up when an image's parent is another image —
			// not a scope anyone wants to caption.
			if ( 'attachment' === $slug ) {
				continue;
			}
			$obj = get_post_type_object( $slug );
			if ( ! $obj || ! $obj->public ) {
				continue;
			}
			$scopes[] = [
				'id'           => $slug,
				'label'        => $obj->labels->name ?? $slug,
				'count'        => $count,
				'noAlt'        => $backlog['noAlt'][ $slug ] ?? 0,
				'missingTrans' => $backlog['missingTrans'][ $slug ] ?? 0,
			];
		}

		// Biggest first — that's the one people want.
		usort( $scopes, fn( $a, $b ) => $b['count'] <=> $a['count'] );

		if ( $counts['unattached'] > 0 ) {
			$scopes[] = [
				'id'           => 'unattached',
				'label'        => __( 'Unattached', 'snel' ),
				'count'        => $counts['unattached'],
				'noAlt'        => $backlog['noAlt']['unattached'] ?? 0,
				'missingTrans' => $backlog['missingTrans']['unattached'] ?? 0,
			];
		}

		return rest_ensure_response( [
			'scopes'       => $scopes,
			'total'        => $counts['total'],
			'noAlt'        => $backlog['noAltTotal'],
			'missingTrans' => $backlog['missingTransTotal'],
			'langs'        => $langs,
		] );
	}

	/** Paginated image list with the per-language alt text for each row. */
	public function media_list_get( \WP_REST_Request $request ) {
		$scope    = (string) ( $request->get_param( 'scope' ) ?: 'all' );
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = min( 100, max( 1, (int) ( $request->get_param( 'per_page' ) ?: 20 ) ) );
		$search   = trim( (string) $request->get_param( 'search' ) );

		$result = Model::imageRows( $scope, $page, $per_page, $search );
		$langs  = array_values( array_diff( LocaleManager::supported(), [ LocaleManager::default() ] ) );

		$rows = array_map( function ( $row ) use ( $langs ) {
			$translations = [];
			foreach ( $langs as $lang ) {
				$translations[ $lang ] = (string) get_post_meta( $row['id'], '_snel_alt_' . $lang, true );
			}
			$row['translations'] = $translations;
			return $row;
		}, $result['rows'] );

		return rest_ensure_response( [
			'rows'       => $rows,
			'total'      => $result['total'],
			'totalPages' => (int) ceil( $result['total'] / $per_page ),
			'page'       => $page,
			'langs'      => $langs,
		] );
	}

	public function cptslugs_get() {
		$cfg   = UrlGenerator::cptSlugsConfig();
		$langs = array_values( array_diff( LocaleManager::supported(), [ LocaleManager::default() ] ) );
		$items = [];

		$seen = [];
		foreach ( self::urlBases() as $base ) {
			if ( isset( $seen[ $base['slug'] ] ) ) {
				continue;
			}
			$seen[ $base['slug'] ] = true;

			$tr = [];
			foreach ( $langs as $l ) {
				$tr[ $l ] = $cfg[ $base['slug'] ][ $l ] ?? '';
			}
			$items[] = [
				'postType'     => $base['name'],
				'label'        => $base['label'],
				'kind'         => $base['kind'],
				'defaultSlug'  => $base['slug'],
				'translations' => $tr,
			];
		}

		return rest_ensure_response( [ 'langs' => $langs, 'items' => $items ] );
	}

	/** Save slug translations. Body: { default_slug: { en: slug, … } }. */
	public function cptslugs_save( \WP_REST_Request $request ) {
		$data = $request->get_json_params();
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'invalid_data', 'Expected an object keyed by slug.', [ 'status' => 400 ] );
		}

		$clean = [];
		foreach ( $data as $slug => $langs ) {
			if ( ! is_array( $langs ) ) {
				continue;
			}
			$slug = sanitize_title( $slug );
			foreach ( $langs as $lang => $value ) {
				$value = sanitize_title( $value );
				if ( $value !== '' ) {
					$clean[ $slug ][ sanitize_key( $lang ) ] = $value;
				}
			}
		}

		Model::saveCptSlugs( $clean );
		flush_rewrite_rules(); // slugs changed → rules must rebuild
		return rest_ensure_response( [ 'success' => true ] );
	}

	// ─── Bulk translate ──────────────────────────────────────────────────────

	/** Work list: every source post × language that is missing or outdated. */
	public function bulk_plan() {
		$default = LocaleManager::default();
		$langs   = array_values( array_diff( LocaleManager::supported(), [ $default ] ) );
		$cfg     = LocaleManager::config();
		// Every public type, so custom post types (cases, services…) are covered
		// without per-theme wiring. Filter to narrow it, e.g. to exclude a CPT
		// that is deliberately shared across languages.
		$public = get_post_types( [ 'public' => true ], 'names' );
		unset( $public['attachment'] ); // media isn't translated content
		$types = (array) apply_filters( 'snel_bulk_post_types', array_values( $public ) );

		$sources = get_posts( [
			'post_type'   => $types,
			'post_status' => [ 'publish', 'draft', 'pending', 'private' ],
			'numberposts' => -1,
			'orderby'     => 'title',
			'order'       => 'ASC',
			'meta_query'  => [
				'relation' => 'OR',
				[ 'key' => TranslationGroup::META_LANG, 'value' => $default ],
				[ 'key' => TranslationGroup::META_LANG, 'compare' => 'NOT EXISTS' ],
			],
		] );

		$items = [];
		foreach ( $sources as $src ) {
			$sig = Create::source_signature( $src->ID );
			foreach ( $langs as $lang ) {
				$sib    = (int) TranslationGroup::translation( $src->ID, $lang );
				$action = '';
				if ( ! $sib ) {
					$action = 'create';
				} else {
					$stored = get_post_meta( $sib, Create::HASH_META, true );
					if ( Create::is_outdated( (string) $stored, $sig ) ) {
						$action = 'sync';
					}
				}
				if ( $action ) {
					$items[] = [
						'postId'    => $src->ID,
						'lang'      => $lang,
						'langLabel' => $cfg[ $lang ]['label'] ?? strtoupper( $lang ),
						'action'    => $action,
						'title'     => get_the_title( $src ) ?: '(no title)',
					];
				}
			}
		}

		return rest_ensure_response( [ 'items' => $items, 'total' => count( $items ) ] );
	}

	/** Translate one work item (create or sync). */
	public function bulk_run( \WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'postId' );
		$lang    = sanitize_text_field( (string) $request->get_param( 'lang' ) );
		$publish = (bool) $request->get_param( 'publish' );

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'forbidden', 'Unauthorized', [ 'status' => 403 ] );
		}

		$res = Create::translate_one( $post_id, $lang, $publish );
		if ( empty( $res['ok'] ) ) {
			return new \WP_Error(
				$res['code'] ?? 'translate_failed',
				$res['message'] ?? 'Translation failed',
				[ 'status' => 500 ]
			);
		}
		return rest_ensure_response( $res );
	}

	/** AI-suggest a translated slug for every URL base × language. Does not save. */
	public function cptslugs_translate() {
		$default = LocaleManager::default();
		$langs   = array_values( array_diff( LocaleManager::supported(), [ $default ] ) );
		if ( empty( $langs ) ) {
			return rest_ensure_response( [] );
		}

		$slugs = array_column( self::urlBases(), 'slug' );
		$slugs = array_values( array_unique( $slugs ) );
		if ( empty( $slugs ) ) {
			return rest_ensure_response( [] );
		}

		// Translate the human form of each slug (hyphens → spaces), then slugify.
		$sources = array_map( function ( $s ) { return str_replace( '-', ' ', $s ); }, $slugs );

		$out = [];
		foreach ( $langs as $lang ) {
			$tr = Ai::translate( $sources, $default, $lang );
			if ( is_wp_error( $tr ) ) {
				return $tr;
			}
			foreach ( $slugs as $i => $slug ) {
				$suggest = sanitize_title( $tr[ $i ] ?? '' );
				if ( $suggest !== '' ) {
					$out[ $slug ][ $lang ] = $suggest;
				}
			}
		}

		return rest_ensure_response( $out );
	}
}
