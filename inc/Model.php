<?php
/**
 * Model — database access ONLY.
 *
 * Static methods, raw data in / out. No request handling, no validation, no
 * hooks. The Controller calls these; nothing here knows a REST request exists.
 *
 * Storage:
 *   post meta  _snel_lang / _snel_group        (the sibling links)
 *   options    snel_languages, snel_default_lang, snel_enabled_langs,
 *              snel_theme_translations
 *
 * @package Snel\Translations
 */

namespace Snel\Translations;

use Snel\Translations\Core\TranslationGroup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Model {

	// ─── Translation data (raw reads) ────────────────────────────────────────

	/**
	 * One row per language-tagged post (wp_posts joined with its meta).
	 * @return array<int,array{id:int,lang:string,group:int,title:string,type:string,status:string}>
	 */
	public static function translationRows(): array {
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID, p.post_title, p.post_type, p.post_status,
			        ml.meta_value AS lang, mg.meta_value AS grp
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} ml ON ml.post_id = p.ID AND ml.meta_key = %s
			 LEFT JOIN {$wpdb->postmeta} mg ON mg.post_id = p.ID AND mg.meta_key = %s
			 WHERE p.post_status NOT IN ('auto-draft', 'trash', 'inherit')
			 ORDER BY mg.meta_value, ml.meta_value",
			TranslationGroup::META_LANG,
			TranslationGroup::META_GROUP
		), ARRAY_A );

		return array_map( function ( $r ) {
			return [
				'id'     => (int) $r['ID'],
				'lang'   => $r['lang'],
				'group'  => (int) ( $r['grp'] ?: $r['ID'] ),
				'title'  => $r['post_title'],
				'type'   => $r['post_type'],
				'status' => $r['post_status'],
			];
		}, $rows );
	}

	/**
	 * The literal wp_postmeta rows for the two link keys — exactly as stored.
	 * @return array<int,array{meta_id:int,post_id:int,meta_key:string,meta_value:string}>
	 */
	public static function metaRows(): array {
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT meta_id, post_id, meta_key, meta_value
			 FROM {$wpdb->postmeta}
			 WHERE meta_key IN (%s, %s)
			 ORDER BY post_id, meta_key",
			TranslationGroup::META_LANG,
			TranslationGroup::META_GROUP
		), ARRAY_A );

		return array_map( function ( $r ) {
			return [
				'meta_id'    => (int) $r['meta_id'],
				'post_id'    => (int) $r['post_id'],
				'meta_key'   => $r['meta_key'],
				'meta_value' => $r['meta_value'],
			];
		}, $rows );
	}

	/**
	 * Posts whose _snel_lang is NOT one of $configured (orphaned by a config
	 * change). Raw rows — the Controller adds edit URLs.
	 * @return array<int,array{id:int,lang:string,title:string,type:string,status:string}>
	 */
	public static function orphanPosts( array $configured ): array {
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID, p.post_title, p.post_type, p.post_status, ml.meta_value AS lang
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} ml ON ml.post_id = p.ID AND ml.meta_key = %s
			 WHERE p.post_status NOT IN ('auto-draft', 'trash', 'inherit')
			 ORDER BY ml.meta_value, p.post_title",
			TranslationGroup::META_LANG
		), ARRAY_A );

		$out = [];
		foreach ( $rows as $r ) {
			if ( in_array( $r['lang'], $configured, true ) ) {
				continue;
			}
			$out[] = [
				'id'     => (int) $r['ID'],
				'lang'   => $r['lang'],
				'title'  => $r['post_title'],
				'type'   => $r['post_type'],
				'status' => $r['post_status'],
			];
		}
		return $out;
	}

	// ─── Options ─────────────────────────────────────────────────────────────

	public static function languagesJson(): string {
		return (string) get_option( 'snel_languages', '' );
	}

	public static function saveLanguagesJson( string $json ): void {
		update_option( 'snel_languages', $json );
	}

	public static function clearLanguages(): void {
		delete_option( 'snel_languages' );
	}

	public static function saveDefaultLang( string $lang ): void {
		update_option( 'snel_default_lang', $lang );
	}

	public static function saveEnabledLangs( array $langs ): void {
		update_option( 'snel_enabled_langs', array_values( $langs ) );
	}

	public static function themeStrings(): array {
		$val = get_option( 'snel_theme_translations', [] );
		return is_array( $val ) ? $val : [];
	}

	// ─── Post actions ────────────────────────────────────────────────────────

	public static function trashPost( int $post_id ): void {
		wp_trash_post( $post_id );
	}

	public static function deletePost( int $post_id ): void {
		wp_delete_post( $post_id, true );
	}

	// ─── Custom fields (translatable meta) ───────────────────────────────────

	/**
	 * Distinct meta keys actually used by a post type, minus obvious internal
	 * keys (WP core, Yoast, oEmbed, our own). Hand-rolled + ACF fields remain.
	 * @return string[]
	 */
	public static function metaKeysForType( string $post_type ): array {
		global $wpdb;

		$keys = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT m.meta_key
			 FROM {$wpdb->postmeta} m
			 INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
			 WHERE p.post_type = %s
			 AND m.meta_key NOT LIKE %s
			 AND m.meta_key NOT LIKE %s
			 AND m.meta_key NOT LIKE %s
			 AND m.meta_key NOT LIKE %s
			 ORDER BY m.meta_key",
			$post_type, '\_edit%', '\_wp\_%', '\_oembed%', '\_yoast%'
		) );

		$skip = [ '_thumbnail_id', '_pingme', '_encloseme', '_snel_lang', '_snel_group', '_snel_src_hash' ];
		return array_values( array_filter( $keys, function ( $k ) use ( $skip ) {
			if ( in_array( $k, $skip, true ) ) {
				return false;
			}
			return strpos( $k, '_yoast' ) !== 0 && strpos( $k, '_snel' ) !== 0;
		} ) );
	}

	/** Admin-chosen translatable meta: [ post_type => [ meta_key, … ] ]. */
	public static function getTranslatableMeta(): array {
		$val = get_option( 'snel_translatable_meta', [] );
		return is_array( $val ) ? $val : [];
	}

	public static function saveTranslatableMeta( array $map ): void {
		update_option( 'snel_translatable_meta', $map );
	}

	// ─── CPT slug translations ───────────────────────────────────────────────

	/** Per-language CPT archive slugs: [ default_slug => [ lang => slug ] ]. */
	public static function getCptSlugs(): array {
		$v = get_option( 'snel_cpt_slugs', [] );
		return is_array( $v ) ? $v : [];
	}

	public static function saveCptSlugs( array $map ): void {
		update_option( 'snel_cpt_slugs', $map );
	}

	// ─── Media alt text ──────────────────────────────────────────────────────

	/**
	 * Image counts per parent post type, plus totals.
	 *
	 * The core media route can't do this — it has no filter for "attachments
	 * whose parent is of type X" — and CPTs registered with show_in_rest=false
	 * aren't in /wp/v2/types at all. Hence a direct query.
	 *
	 * @return array{types:array<string,int>,total:int,unattached:int}
	 */
	public static function imageCountsByParentType(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			"SELECT parent.post_type AS post_type, COUNT(*) AS n
			 FROM {$wpdb->posts} a
			 INNER JOIN {$wpdb->posts} parent ON parent.ID = a.post_parent
			 WHERE a.post_type = 'attachment'
			 AND a.post_mime_type LIKE 'image/%'
			 GROUP BY parent.post_type",
			ARRAY_A
		);

		$types = [];
		foreach ( $rows as $row ) {
			$types[ $row['post_type'] ] = (int) $row['n'];
		}

		$total = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts}
			 WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'"
		);

		$unattached = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts}
			 WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'
			 AND ( post_parent = 0 OR post_parent IS NULL )"
		);

		return [ 'types' => $types, 'total' => $total, 'unattached' => $unattached ];
	}

	/**
	 * Backlog per parent post type: how many images still need work.
	 *
	 * Two different numbers, because the two jobs have different backlogs:
	 *   noAlt        — no source alt at all, so vision has to look at the image
	 *   missingTrans — has a source alt but at least one language is empty
	 *
	 * @param string[] $langs Non-default language codes.
	 * @return array{noAlt:array<string,int>,missingTrans:array<string,int>,noAltTotal:int,missingTransTotal:int}
	 */
	public static function imageBacklogByParentType( array $langs ): array {
		global $wpdb;

		// Images whose alt meta is absent or empty.
		$no_alt_rows = $wpdb->get_results(
			"SELECT COALESCE(parent.post_type, 'unattached') AS post_type, COUNT(*) AS n
			 FROM {$wpdb->posts} a
			 LEFT JOIN {$wpdb->posts} parent ON parent.ID = a.post_parent
			 LEFT JOIN {$wpdb->postmeta} alt
			        ON alt.post_id = a.ID AND alt.meta_key = '_wp_attachment_image_alt'
			 WHERE a.post_type = 'attachment'
			 AND a.post_mime_type LIKE 'image/%'
			 AND ( alt.meta_value IS NULL OR alt.meta_value = '' )
			 GROUP BY COALESCE(parent.post_type, 'unattached')",
			ARRAY_A
		);

		$no_alt = [];
		foreach ( $no_alt_rows as $row ) {
			$no_alt[ $row['post_type'] ] = (int) $row['n'];
		}

		// Images with a source alt but fewer than one translation per language.
		$missing = [];
		if ( $langs ) {
			$keys         = array_map( fn( $l ) => '_snel_alt_' . $l, $langs );
			$placeholders = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );

			$missing_rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT COALESCE(parent.post_type, 'unattached') AS post_type, COUNT(*) AS n
				 FROM (
				   SELECT a.ID, a.post_parent,
				          ( SELECT COUNT(*) FROM {$wpdb->postmeta} tm
				            WHERE tm.post_id = a.ID
				            AND tm.meta_key IN ( {$placeholders} )
				            AND tm.meta_value <> '' ) AS done
				   FROM {$wpdb->posts} a
				   INNER JOIN {$wpdb->postmeta} alt
				           ON alt.post_id = a.ID
				          AND alt.meta_key = '_wp_attachment_image_alt'
				          AND alt.meta_value <> ''
				   WHERE a.post_type = 'attachment'
				   AND a.post_mime_type LIKE 'image/%'
				 ) x
				 LEFT JOIN {$wpdb->posts} parent ON parent.ID = x.post_parent
				 WHERE x.done < %d
				 GROUP BY COALESCE(parent.post_type, 'unattached')",
				...array_merge( $keys, [ count( $langs ) ] )
			), ARRAY_A );

			foreach ( $missing_rows as $row ) {
				$missing[ $row['post_type'] ] = (int) $row['n'];
			}
		}

		return [
			'noAlt'             => $no_alt,
			'missingTrans'      => $missing,
			'noAltTotal'        => array_sum( $no_alt ),
			'missingTransTotal' => array_sum( $missing ),
		];
	}

	/**
	 * Paginated image rows, optionally scoped to a parent post type.
	 *
	 * @param string $parent_type Post type slug, 'all', or 'unattached'.
	 * @param int    $page        1-based.
	 * @param int    $per_page    Rows per page.
	 * @param string $search      Matches alt text, title or filename.
	 * @return array{rows:array<int,array>,total:int}
	 */
	public static function imageRows( string $parent_type, int $page, int $per_page, string $search = '' ): array {
		global $wpdb;

		$join  = '';
		$where = [ "a.post_type = 'attachment'", "a.post_mime_type LIKE 'image/%'" ];
		$args  = [];

		if ( 'unattached' === $parent_type ) {
			$where[] = '( a.post_parent = 0 OR a.post_parent IS NULL )';
		} elseif ( 'all' !== $parent_type ) {
			$join    = "INNER JOIN {$wpdb->posts} parent ON parent.ID = a.post_parent";
			$where[] = 'parent.post_type = %s';
			$args[]  = $parent_type;
		}

		if ( '' !== $search ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$join   .= " LEFT JOIN {$wpdb->postmeta} altm ON altm.post_id = a.ID AND altm.meta_key = '_wp_attachment_image_alt'";
			$where[] = '( a.post_title LIKE %s OR altm.meta_value LIKE %s )';
			$args[]  = $like;
			$args[]  = $like;
		}

		$where_sql = implode( ' AND ', $where );

		$count_sql = "SELECT COUNT(DISTINCT a.ID) FROM {$wpdb->posts} a {$join} WHERE {$where_sql}";
		$total     = (int) ( $args
			? $wpdb->get_var( $wpdb->prepare( $count_sql, ...$args ) )
			: $wpdb->get_var( $count_sql ) );

		$offset    = max( 0, ( $page - 1 ) * $per_page );
		$page_args = array_merge( $args, [ $per_page, $offset ] );

		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT a.ID FROM {$wpdb->posts} a {$join}
			 WHERE {$where_sql}
			 ORDER BY a.ID DESC
			 LIMIT %d OFFSET %d",
			...$page_args
		) );

		$rows = [];
		foreach ( $ids as $id ) {
			$id     = (int) $id;
			$parent = (int) get_post_field( 'post_parent', $id );

			$rows[] = [
				'id'         => $id,
				'title'      => get_the_title( $id ),
				'thumb'      => wp_get_attachment_image_url( $id, 'thumbnail' ) ?: wp_get_attachment_url( $id ),
				'alt'        => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
				'parentId'   => $parent,
				'parentTitle'=> $parent ? get_the_title( $parent ) : '',
				'parentType' => $parent ? (string) get_post_field( 'post_type', $parent ) : '',
			];
		}

		return [ 'rows' => $rows, 'total' => $total ];
	}
}
