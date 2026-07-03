<?php
/**
 * Create — duplicate + AI-translate a post into another language, sync an
 * existing translation, and report translation state to the editor sidebar.
 *
 * Duplicates a post into a new draft, links it to the same translation group,
 * and runs the title + block text + declared meta through Ai. Stores a signature
 * (_snel_src_hash) of the source's translatable content so a later source edit
 * flags the translation as "needs update".
 *
 * Theme integration points (filters, empty by default):
 *   snel_block_text_attrs        text attribute keys per Snel block
 *   snel_translatable_meta_keys  ACF/custom text meta keys to translate
 *
 * @package Snel\Translations
 */

namespace Snel\Translations;

use Snel\Translations\Core\LocaleManager;
use Snel\Translations\Core\TranslationGroup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Create {

	/** Meta key on a translation storing the source signature it was built from. */
	const HASH_META = '_snel_src_hash';

	/** Meta key: translation memory { source text => translated text } for reuse. */
	const TM_META = '_snel_tm';

	/** Register AJAX handlers + editor data. Called from Boot when live. */
	public static function register(): void {
		add_action( 'wp_ajax_snel_create_translation', [ self::class, 'ajax_create' ] );
		add_action( 'wp_ajax_snel_sync_translation', [ self::class, 'ajax_sync' ] );
		add_action( 'wp_ajax_snel_translation_state', [ self::class, 'ajax_state' ] );
		add_action( 'enqueue_block_editor_assets', [ self::class, 'editor_data' ], 20 );
	}

	// ─── Editor sidebar data ─────────────────────────────────────────────────

	/** Localize the current post's translation state for the editor sidebar. */
	public static function editor_data(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || $screen->base !== 'post' ) {
			return;
		}

		$post = get_post();
		if ( ! $post ) {
			return;
		}

		wp_localize_script( 'snel-editor-snelstack', 'snelCreateTranslation', [
			'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
			'nonce'       => wp_create_nonce( 'snel_create_translation' ),
			'postId'      => $post->ID,
			'currentLang' => TranslationGroup::langOf( $post->ID ),
			'defaultLang' => LocaleManager::default(),
			'languages'   => self::languages_state( $post->ID ),
		] );
	}

	/**
	 * Per-language state for a post: which exist, edit/view URLs, status, and
	 * whether each is out of date.
	 */
	public static function languages_state( int $post_id ): array {
		$config       = LocaleManager::config();
		$this_lang    = TranslationGroup::langOf( $post_id );
		$siblings     = TranslationGroup::siblings( TranslationGroup::groupOf( $post_id ) );
		$default_lang = LocaleManager::default();

		$source_id  = self::source_post_id( $post_id );
		$source_sig = $source_id ? self::source_signature( $source_id ) : '';

		$languages = [];
		foreach ( LocaleManager::supported() as $code ) {
			$sib = $siblings[ $code ] ?? 0;

			$outdated = false;
			if ( $sib && $code !== $default_lang && $source_sig ) {
				$stored   = get_post_meta( $sib, self::HASH_META, true );
				$outdated = ( $stored !== '' && $stored !== $source_sig );
			}

			$languages[] = [
				'code'      => $code,
				'label'     => $config[ $code ]['label'] ?? strtoupper( $code ),
				'isCurrent' => $code === $this_lang,
				'postId'    => $sib ?: null,
				'editUrl'   => $sib ? get_edit_post_link( $sib, 'raw' ) : null,
				'viewUrl'   => $sib ? get_permalink( $sib ) : null,
				'status'    => $sib ? get_post_status( $sib ) : null,
				'outdated'  => $outdated,
			];
		}

		return $languages;
	}

	/** AJAX: return the current translation state (post-save refresh). */
	public static function ajax_state(): void {
		check_ajax_referer( 'snel_create_translation', 'nonce' );

		$post_id = (int) ( $_POST['post_id'] ?? 0 );
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		wp_send_json_success( [
			'currentLang' => TranslationGroup::langOf( $post_id ),
			'defaultLang' => LocaleManager::default(),
			'languages'   => self::languages_state( $post_id ),
		] );
	}

	// ─── Create ──────────────────────────────────────────────────────────────

	/** AJAX: duplicate a post into a target language and AI-translate it. */
	public static function ajax_create(): void {
		check_ajax_referer( 'snel_create_translation', 'nonce' );

		$source_id = (int) ( $_POST['post_id'] ?? 0 );
		$target    = sanitize_text_field( wp_unslash( $_POST['target'] ?? '' ) );

		if ( ! $source_id || ! current_user_can( 'edit_post', $source_id ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}
		if ( ! in_array( $target, LocaleManager::supported(), true ) ) {
			wp_send_json_error( [ 'message' => 'Invalid language' ] );
		}

		$source = get_post( $source_id );
		if ( ! $source ) {
			wp_send_json_error( [ 'message' => 'Source post not found' ] );
		}

		$source_lang = TranslationGroup::langOf( $source_id );
		$group       = TranslationGroup::groupOf( $source_id );

		$existing = TranslationGroup::translation( $source_id, $target );
		if ( $existing ) {
			wp_send_json_success( [ 'edit_url' => get_edit_post_link( $existing, 'raw' ), 'post_id' => $existing, 'existed' => true ] );
		}

		$tr = self::translate_source_fields( $source, $source_lang, $target );
		if ( is_wp_error( $tr ) ) {
			wp_send_json_error( [ 'message' => $tr->get_error_message() ] );
		}

		$new_parent = $source->post_parent;
		if ( $source->post_parent ) {
			$parent_sibling = TranslationGroup::translation( $source->post_parent, $target );
			if ( $parent_sibling ) {
				$new_parent = $parent_sibling;
			}
		}

		$new_id = wp_insert_post( [
			'post_type'      => $source->post_type,
			'post_status'    => 'draft',
			// wp_insert_post expects slashed data (it unslashes internally); without
			// wp_slash the \uXXXX escapes serialize_blocks emits get corrupted.
			'post_title'     => wp_slash( $tr['title'] ),
			'post_content'   => wp_slash( $tr['content'] ),
			'post_excerpt'   => wp_slash( $tr['excerpt'] ),
			'post_parent'    => $new_parent,
			'menu_order'     => $source->menu_order,
			'comment_status' => $source->comment_status,
			'ping_status'    => $source->ping_status,
		], true );

		if ( is_wp_error( $new_id ) ) {
			wp_send_json_error( [ 'message' => $new_id->get_error_message() ] );
		}

		TranslationGroup::link( $source_id, $group, $source_lang );
		TranslationGroup::link( $new_id, $group, $target );

		self::copy_meta( $source_id, $new_id );
		self::copy_terms( $source_id, $new_id );

		$memory = $tr['memory'] ?? [];
		self::translate_meta( $source_id, $new_id, $source_lang, $target, [], $memory );
		self::store_memory( $new_id, $memory );

		update_post_meta( $new_id, self::HASH_META, self::source_signature( $source_id ) );

		wp_send_json_success( [
			'edit_url' => get_edit_post_link( $new_id, 'raw' ),
			'post_id'  => $new_id,
		] );
	}

	// ─── Sync ────────────────────────────────────────────────────────────────

	/** AJAX: re-translate the source into an existing translation, overwriting it. */
	public static function ajax_sync(): void {
		check_ajax_referer( 'snel_create_translation', 'nonce' );

		$post_id = (int) ( $_POST['post_id'] ?? 0 );
		$target  = sanitize_text_field( wp_unslash( $_POST['target'] ?? '' ) );

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}
		if ( ! in_array( $target, LocaleManager::supported(), true ) ) {
			wp_send_json_error( [ 'message' => 'Invalid language' ] );
		}

		$source_id = self::source_post_id( $post_id );
		$source    = $source_id ? get_post( $source_id ) : null;
		if ( ! $source ) {
			wp_send_json_error( [ 'message' => 'Source post not found' ] );
		}

		$target_id = (int) TranslationGroup::translation( $source_id, $target );
		if ( ! $target_id ) {
			wp_send_json_error( [ 'message' => 'Translation does not exist' ] );
		}
		if ( ! current_user_can( 'edit_post', $target_id ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		$source_lang = TranslationGroup::langOf( $source_id );
		$memory      = self::load_memory( $target_id );

		$tr = self::translate_source_fields( $source, $source_lang, $target, $memory );
		if ( is_wp_error( $tr ) ) {
			wp_send_json_error( [ 'message' => $tr->get_error_message() ] );
		}

		$updated = wp_update_post( [
			'ID'           => $target_id,
			// Slashed — wp_update_post unslashes internally (see ajax_create).
			'post_title'   => wp_slash( $tr['title'] ),
			'post_content' => wp_slash( $tr['content'] ),
			'post_excerpt' => wp_slash( $tr['excerpt'] ),
		], true );
		if ( is_wp_error( $updated ) ) {
			wp_send_json_error( [ 'message' => $updated->get_error_message() ] );
		}

		self::copy_terms( $source_id, $target_id );

		$new_memory = $tr['memory'] ?? [];
		self::translate_meta( $source_id, $target_id, $source_lang, $target, $memory, $new_memory );
		self::store_memory( $target_id, $new_memory );
		update_post_meta( $target_id, self::HASH_META, self::source_signature( $source_id ) );

		wp_send_json_success( [
			'edit_url' => get_edit_post_link( $target_id, 'raw' ),
			'post_id'  => $target_id,
		] );
	}

	// ─── Translation of a source post ────────────────────────────────────────

	/**
	 * Translate a source's title, excerpt and block content into a target lang.
	 * @return array|\WP_Error ['title'=>…, 'excerpt'=>…, 'content'=>…]
	 */
	public static function translate_source_fields( \WP_Post $source, string $source_lang, string $target, array $memory = [] ) {
		$new_memory = [];

		$meta_texts = [ $source->post_title ];
		if ( $source->post_excerpt !== '' ) {
			$meta_texts[] = $source->post_excerpt;
		}
		$meta_tr = self::translate_batch( $meta_texts, $source_lang, $target, $memory, $new_memory );
		if ( is_wp_error( $meta_tr ) ) {
			return $meta_tr;
		}

		$content = self::translate_block_content( $source->post_content, $source_lang, $target, $memory, $new_memory );
		if ( is_wp_error( $content ) ) {
			return $content;
		}

		return [
			'title'   => $meta_tr[0] ?? $source->post_title,
			'excerpt' => ( $source->post_excerpt !== '' && isset( $meta_tr[1] ) ) ? $meta_tr[1] : '',
			'content' => $content,
			'memory'  => $new_memory,
		];
	}

	/**
	 * Translate a list of strings, reusing $memory for ones already translated
	 * (translation memory) so only new/changed text hits the AI. Records every
	 * result into $new_memory (source text => translated text) for next time.
	 * Preserves input order; returns WP_Error only if the AI call fails.
	 */
	private static function translate_batch( array $texts, string $source, string $target, array $memory, array &$new_memory ) {
		$misses = [];
		foreach ( $texts as $t ) {
			if ( $t !== '' && ! array_key_exists( $t, $memory ) && ! in_array( $t, $misses, true ) ) {
				$misses[] = $t;
			}
		}

		$fresh = [];
		if ( ! empty( $misses ) ) {
			$tr = Ai::translate( array_values( $misses ), $source, $target );
			if ( is_wp_error( $tr ) ) {
				return $tr;
			}
			foreach ( array_values( $misses ) as $i => $m ) {
				$fresh[ $m ] = $tr[ $i ] ?? $m;
			}
		}

		$out = [];
		foreach ( $texts as $t ) {
			if ( $t === '' ) {
				$out[] = '';
				continue;
			}
			$val               = $memory[ $t ] ?? $fresh[ $t ] ?? $t;
			$out[]             = $val;
			$new_memory[ $t ]  = $val;
		}
		return $out;
	}

	/** Load a translation's stored memory map. */
	public static function load_memory( int $post_id ): array {
		$raw = get_post_meta( $post_id, self::TM_META, true );
		if ( ! is_string( $raw ) || $raw === '' ) {
			return [];
		}
		$data = json_decode( $raw, true );
		return is_array( $data ) ? $data : [];
	}

	/** Persist a translation's memory map. */
	public static function store_memory( int $post_id, array $memory ): void {
		if ( empty( $memory ) ) {
			return;
		}
		update_post_meta( $post_id, self::TM_META, wp_slash( (string) wp_json_encode( $memory ) ) );
	}

	/** md5 of everything that gets translated on a source (title/excerpt/blocks/meta). */
	public static function source_signature( int $source_id ): string {
		$post = get_post( $source_id );
		if ( ! $post ) {
			return '';
		}

		// Hash the FULL block markup (structure + settings + text), not just the
		// translatable strings — so a settings/layout change also marks
		// translations outdated. Re-syncing is cheap: the translation memory
		// reuses unchanged text, so only genuinely new text hits the AI.
		$parts = [ $post->post_title, $post->post_excerpt, $post->post_content ];

		foreach ( self::translatable_meta_keys( $post->post_type ) as $key ) {
			$val = get_post_meta( $source_id, $key, true );
			if ( is_string( $val ) ) {
				$parts[] = $val;
			}
		}

		return md5( implode( "\x1f", $parts ) );
	}

	/** The default-language (source) post id for any post. */
	public static function source_post_id( int $post_id ): int {
		if ( TranslationGroup::langOf( $post_id ) === LocaleManager::default() ) {
			return $post_id;
		}
		return (int) TranslationGroup::translation( $post_id, LocaleManager::default() );
	}

	// ─── Meta ────────────────────────────────────────────────────────────────

	/** Copy source meta to a translation, skipping internal/language keys. */
	public static function copy_meta( int $from, int $to ): void {
		$skip = [
			TranslationGroup::META_LANG,
			TranslationGroup::META_GROUP,
			'_edit_lock',
			'_edit_last',
			'_wp_old_slug',
			'_wp_old_date',
		];

		foreach ( get_post_meta( $from ) as $key => $values ) {
			if ( in_array( $key, $skip, true ) ) {
				continue;
			}
			delete_post_meta( $to, $key );
			foreach ( $values as $value ) {
				add_post_meta( $to, $key, maybe_unserialize( $value ) );
			}
		}
	}

	/**
	 * Copy taxonomy term assignments (categories, tags, custom taxes) from the
	 * source to its translation. Terms are shared across languages — the label
	 * translates on the front end — so the sibling gets the same term IDs.
	 */
	public static function copy_terms( int $from, int $to ): void {
		$post = get_post( $from );
		if ( ! $post ) {
			return;
		}
		foreach ( get_object_taxonomies( $post->post_type ) as $tax ) {
			$ids = wp_get_object_terms( $from, $tax, [ 'fields' => 'ids' ] );
			if ( ! is_wp_error( $ids ) ) {
				wp_set_object_terms( $to, $ids, $tax );
			}
		}
	}

	/** AI-translate declared text meta keys onto a translation, in place. */
	public static function translate_meta( int $from, int $to, string $source, string $target, array $memory = [], array &$new_memory = [] ): void {
		$keys = self::translatable_meta_keys( get_post_type( $from ) );
		if ( empty( $keys ) ) {
			return;
		}

		$values = [];
		foreach ( $keys as $key ) {
			$value = get_post_meta( $from, $key, true );
			if ( is_string( $value ) && trim( $value ) !== '' ) {
				$values[ $key ] = $value;
			}
		}
		if ( empty( $values ) ) {
			return;
		}

		// Reuse the memory so a settings-only sync doesn't re-translate meta.
		$translated = self::translate_batch( array_values( $values ), $source, $target, $memory, $new_memory );
		if ( is_wp_error( $translated ) ) {
			return; // leave the copied source values in place
		}

		$i = 0;
		foreach ( array_keys( $values ) as $key ) {
			if ( isset( $translated[ $i ] ) ) {
				update_post_meta( $to, $key, wp_slash( $translated[ $i ] ) );
			}
			$i++;
		}
	}

	/**
	 * Meta keys to AI-translate: the admin-selected fields (Custom Fields tab)
	 * merged with anything declared via the filter (code).
	 */
	public static function translatable_meta_keys( string $post_type ): array {
		$selected = Model::getTranslatableMeta();
		$keys     = $selected[ $post_type ] ?? [];
		return array_values( array_unique( apply_filters( 'snel_translatable_meta_keys', $keys, $post_type ) ) );
	}

	// ─── Block content translation ───────────────────────────────────────────

	/** Translate the text inside block content, returning new block markup. */
	public static function translate_block_content( string $content, string $source, string $target, array $memory = [], array &$new_memory = [] ) {
		if ( trim( $content ) === '' ) {
			return $content;
		}

		$blocks = parse_blocks( $content );
		// Materialize block.json defaults for the translatable attributes, so a
		// block using its defaults (nothing saved in content, e.g. a bare
		// <!-- wp:snel/process /-->) still gets its text translated + persisted.
		self::merge_translatable_defaults( $blocks );

		$strings = [];
		self::collect_strings( $blocks, $strings );

		if ( empty( $strings ) ) {
			return $content;
		}

		// Reuse the memory for unchanged strings; only new/changed text is sent
		// to the AI. Structure/settings come from the (current) source blocks, so
		// a settings-only change re-applies for free.
		$translated = self::translate_batch( $strings, $source, $target, $memory, $new_memory );
		if ( is_wp_error( $translated ) ) {
			return $translated;
		}

		$idx = 0;
		self::apply_strings( $blocks, $translated, $idx );

		return serialize_blocks( $blocks );
	}

	/**
	 * Fill in block.json defaults for the DECLARED translatable attributes only,
	 * pulled from the registered block type (theme-agnostic). parse_blocks() does
	 * not include default attribute values, so without this a block using its
	 * defaults would translate nothing.
	 */
	public static function merge_translatable_defaults( array &$blocks ): void {
		$registry = \WP_Block_Type_Registry::get_instance();

		foreach ( $blocks as &$block ) {
			$name = $block['blockName'] ?? '';
			if ( $name ) {
				$type     = $registry->get_registered( $name );
				$defaults = ( $type && $type->attributes ) ? $type->attributes : [];

				foreach ( self::block_text_attrs( $name ) as $key ) {
					if ( ! isset( $block['attrs'][ $key ] ) && isset( $defaults[ $key ]['default'] ) ) {
						$block['attrs'][ $key ] = $defaults[ $key ]['default'];
					}
				}
				foreach ( array_keys( self::block_repeater_attrs( $name ) ) as $attr ) {
					if ( ! isset( $block['attrs'][ $attr ] ) && isset( $defaults[ $attr ]['default'] ) ) {
						$block['attrs'][ $attr ] = $defaults[ $attr ]['default'];
					}
				}
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				self::merge_translatable_defaults( $block['innerBlocks'] );
			}
		}
		unset( $block );
	}

	/** Text attribute keys per Snel block (filterable). */
	public static function block_text_attrs( string $name ): array {
		$map = apply_filters( 'snel_block_text_attrs', [], $name );
		return $map[ $name ] ?? [];
	}

	/**
	 * Repeater attributes per block: an array attribute whose items each hold
	 * text sub-fields. Filterable, e.g.
	 *   ['snel/process' => ['steps' => ['title', 'heading', 'body']]]
	 * @return array<string,array<string>>  attr => [text sub-keys]
	 */
	public static function block_repeater_attrs( string $name ): array {
		$map = apply_filters( 'snel_block_repeater_attrs', [], $name );
		return $map[ $name ] ?? [];
	}

	/** Pass 1 — collect translatable strings in deterministic order. */
	public static function collect_strings( array $blocks, array &$out ): void {
		foreach ( $blocks as $block ) {
			$name = $block['blockName'] ?? '';

			foreach ( self::block_text_attrs( $name ) as $key ) {
				$val = $block['attrs'][ $key ] ?? '';
				if ( is_string( $val ) && trim( $val ) !== '' ) {
					$out[] = $val;
				}
			}

			// Repeater attributes: each array item's declared text sub-fields.
			foreach ( self::block_repeater_attrs( $name ) as $attr => $subkeys ) {
				$items = $block['attrs'][ $attr ] ?? [];
				if ( ! is_array( $items ) ) {
					continue;
				}
				foreach ( $items as $item ) {
					foreach ( $subkeys as $sk ) {
						$val = is_array( $item ) ? ( $item[ $sk ] ?? '' ) : '';
						if ( is_string( $val ) && trim( $val ) !== '' ) {
							$out[] = $val;
						}
					}
				}
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				self::collect_strings( $block['innerBlocks'], $out );
			} else {
				foreach ( ( $block['innerContent'] ?? [] ) as $chunk ) {
					if ( is_string( $chunk ) && trim( wp_strip_all_tags( $chunk ) ) !== '' ) {
						$out[] = $chunk;
					}
				}
			}
		}
	}

	/** Pass 2 — write translations back in the same order. */
	public static function apply_strings( array &$blocks, array $translations, int &$idx ): void {
		foreach ( $blocks as &$block ) {
			$name = $block['blockName'] ?? '';

			foreach ( self::block_text_attrs( $name ) as $key ) {
				$val = $block['attrs'][ $key ] ?? '';
				if ( is_string( $val ) && trim( $val ) !== '' ) {
					$block['attrs'][ $key ] = $translations[ $idx ] ?? $val;
					$idx++;
				}
			}

			// Repeater attributes — same order as collect_strings.
			foreach ( self::block_repeater_attrs( $name ) as $attr => $subkeys ) {
				if ( empty( $block['attrs'][ $attr ] ) || ! is_array( $block['attrs'][ $attr ] ) ) {
					continue;
				}
				foreach ( $block['attrs'][ $attr ] as $ii => $item ) {
					if ( ! is_array( $item ) ) {
						continue;
					}
					foreach ( $subkeys as $sk ) {
						$val = $item[ $sk ] ?? '';
						if ( is_string( $val ) && trim( $val ) !== '' ) {
							$block['attrs'][ $attr ][ $ii ][ $sk ] = $translations[ $idx ] ?? $val;
							$idx++;
						}
					}
				}
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				self::apply_strings( $block['innerBlocks'], $translations, $idx );
			} else {
				$has_text = false;
				foreach ( ( $block['innerContent'] ?? [] ) as $ci => $chunk ) {
					if ( is_string( $chunk ) && trim( wp_strip_all_tags( $chunk ) ) !== '' ) {
						$block['innerContent'][ $ci ] = $translations[ $idx ] ?? $chunk;
						$idx++;
						$has_text = true;
					}
				}
				if ( $has_text ) {
					$block['innerHTML'] = implode( '', array_filter( $block['innerContent'], 'is_string' ) );
				}
			}
		}
		unset( $block );
	}
}
