<?php
/**
 * Nav — navigation menu translation.
 *
 * Build ONE menu in the default language. Per request each item resolves to the
 * current language:
 *   - Page/post items → the sibling post (link + translated title). No
 *     translation yet → falls back to the default page (never a gap).
 *   - Custom links / taxonomies / custom labels → label via the theme-string
 *     translator; internal paths get the language prefix.
 *
 * Also provides the menu-item list the admin "Menu" tab edits.
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

class Nav {

	/** Register the menu-objects filter. Called from Boot when live. */
	public static function register(): void {
		add_filter( 'wp_nav_menu_objects', [ self::class, 'filterObjects' ] );
	}

	/**
	 * Resolve a nav item to its URL + label for the current language.
	 * @return array{url:string,title:string}
	 */
	public static function item( $item ): array {
		$lang = LocaleManager::current();

		// Page/post item → its translation sibling.
		if ( ( $item->type ?? '' ) === 'post_type' && (int) ( $item->object_id ?? 0 ) ) {
			$object_id = (int) $item->object_id;
			$sibling   = TranslationGroup::translation( $object_id, $lang );
			$target    = $sibling ?: $object_id;

			// Custom label (menu title ≠ page title) → theme string; else the
			// sibling's own (translated) title.
			$is_custom = trim( (string) $item->title ) !== trim( (string) get_the_title( $object_id ) );

			return [
				'url'   => get_permalink( $target ),
				'title' => $is_custom ? Translator::translate( $item->title ) : get_the_title( $target ),
			];
		}

		// Custom link / taxonomy → translate label; prefix internal paths.
		$path = $item->url ? wp_parse_url( $item->url, PHP_URL_PATH ) : '';
		return [
			'url'   => $path ? UrlGenerator::url( $path ) : ( $item->url ?? '#' ),
			'title' => Translator::translate( $item->title ),
		];
	}

	/** Apply item() to every item of menus rendered via wp_nav_menu(). */
	public static function filterObjects( $items ) {
		foreach ( $items as $item ) {
			$resolved    = self::item( $item );
			$item->url   = $resolved['url'];
			$item->title = $resolved['title'];
		}
		return $items;
	}

	/**
	 * The menu items + their per-language translations, for the admin Menu tab.
	 * Translations come from the theme-string store (Translator::grouped()).
	 */
	public static function menuItems(): array {
		$grouped = Translator::grouped();
		$flat    = [];
		foreach ( $grouped as $strings ) {
			foreach ( $strings as $key => $langs ) {
				$flat[ $key ] = $langs;
			}
		}

		$langs     = array_diff( LocaleManager::supported(), [ LocaleManager::default() ] );
		$locations = get_nav_menu_locations();
		$items     = [];

		foreach ( $locations as $location => $menu_id ) {
			if ( ! $menu_id ) {
				continue;
			}
			$menu_items = wp_get_nav_menu_items( $menu_id );
			if ( ! $menu_items ) {
				continue;
			}
			$menu_obj  = wp_get_nav_menu_object( $menu_id );
			$menu_name = $menu_obj ? $menu_obj->name : $location;

			foreach ( $menu_items as $menu_item ) {
				$title        = $menu_item->title;
				$translations = [];
				foreach ( $langs as $lang ) {
					$translations[ $lang ] = $flat[ $title ][ $lang ] ?? '';
				}

				$items[] = [
					'id'           => $menu_item->ID,
					'title'        => $title,
					'translations' => $translations,
					'menu'         => $location,
					'menuName'     => $menu_name,
					'parent'       => (int) $menu_item->menu_item_parent,
				];
			}
		}

		return $items;
	}
}
