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
}
