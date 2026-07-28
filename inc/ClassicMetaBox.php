<?php
/**
 * ClassicMetaBox — Translations box for post types on the CLASSIC editor.
 *
 * The block editor gets the Snelstack sidebar (Create::editor_data); a CPT
 * without `show_in_rest` never loads that. This server-rendered meta box
 * mirrors that sidebar's design: current-language card with badges, a card
 * per translation with Edit/View/Sync links, and create/sync-all buttons —
 * wired to the existing snel_create_translation / snel_sync_translation AJAX.
 *
 * @package Snel\Translations
 */

namespace Snel\Translations;

use Snel\Translations\Core\LocaleManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ClassicMetaBox {

	/** Hook the meta box in for classic-editor public post types. */
	public static function register(): void {
		add_action( 'add_meta_boxes', [ self::class, 'add' ], 10, 2 );
	}

	public static function add( string $post_type, $post ): void {
		if ( ! is_object( $post ) || empty( $post->ID ) ) {
			return;
		}
		$public = get_post_types( [ 'public' => true ] );
		if ( ! in_array( $post_type, $public, true ) || $post_type === 'attachment' ) {
			return;
		}
		// The block editor already has the sidebar — classic screens only.
		if ( function_exists( 'use_block_editor_for_post_type' ) && use_block_editor_for_post_type( $post_type ) ) {
			return;
		}
		if ( count( LocaleManager::supported() ) < 2 ) {
			return;
		}

		add_meta_box(
			'snel_translations_box',
			__( 'Translations', 'snel' ),
			[ self::class, 'render' ],
			$post_type,
			'side',
			'high'
		);
	}

	/** Small pill badge matching the editor sidebar's Badge component. */
	private static function badge( string $color, string $text ): string {
		$colors = [
			'gray'  => [ '#f1f3f5', '#5f6b7a' ],
			'amber' => [ '#fdf0d5', '#9a6700' ],
			'green' => [ '#e7f6ec', '#15803d' ],
			'blue'  => [ '#e8f0fe', '#1d4ed8' ],
		];
		list( $bg, $fg ) = $colors[ $color ] ?? $colors['gray'];
		return '<span style="display:inline-flex;align-items:center;gap:4px;padding:1px 7px;border-radius:999px;font-size:9px;font-weight:700;letter-spacing:.03em;text-transform:uppercase;line-height:1.7;background:' . esc_attr( $bg ) . ';color:' . esc_attr( $fg ) . ';white-space:nowrap;">'
			. '<span style="width:5px;height:5px;border-radius:999px;background:' . esc_attr( $fg ) . ';opacity:.85;"></span>'
			. esc_html( $text ) . '</span>';
	}

	/** Badges for one language row: source / needs update / non-publish status. */
	private static function langBadges( array $lang, bool $is_source_lang ): string {
		$out = '';
		if ( $is_source_lang ) {
			$out .= self::badge( 'blue', __( 'source', 'snel' ) );
		}
		if ( ! empty( $lang['outdated'] ) ) {
			$out .= ' ' . self::badge( 'amber', __( 'needs update', 'snel' ) );
		}
		if ( ! empty( $lang['status'] ) && 'publish' !== $lang['status'] ) {
			$out .= ' ' . self::badge( 'gray', $lang['status'] );
		}
		return $out;
	}

	public static function render( \WP_Post $post ): void {
		if ( 'auto-draft' === $post->post_status ) {
			echo '<p class="description">' . esc_html__( 'Save the post first, then manage translations here.', 'snel' ) . '</p>';
			return;
		}

		$languages = Create::languages_state( $post->ID );
		$default   = LocaleManager::default();
		$current   = null;
		$others    = [];
		foreach ( $languages as $lang ) {
			if ( ! empty( $lang['isCurrent'] ) ) {
				$current = $lang;
			} else {
				$others[] = $lang;
			}
		}
		$existing  = array_filter( $others, static fn( $l ) => ! empty( $l['postId'] ) );
		$missing   = array_filter( $others, static fn( $l ) => empty( $l['postId'] ) );
		$outdated  = array_filter( $existing, static fn( $l ) => ! empty( $l['outdated'] ) );
		$is_source = $current && $current['code'] === $default;
		$source_id = Create::source_post_id( $post->ID ) ?: $post->ID;
		$nonce     = wp_create_nonce( 'snel_create_translation' );
		$label     = static fn( $l ) => $l['label'] ?? strtoupper( $l['code'] );
		$src_lang  = null;
		foreach ( $languages as $lang ) {
			if ( $lang['code'] === $default ) {
				$src_lang = $lang;
			}
		}
		?>
		<div class="snel-clbox" data-nonce="<?php echo esc_attr( $nonce ); ?>" data-source="<?php echo esc_attr( $source_id ); ?>" style="font-size:13px;">
			<style>
				.snel-clbox.is-busy { opacity: .6; pointer-events: none; }
				.snel-clbox .snel-clbox-kicker { font-size: 10px; font-weight: 700; color: #9aa4b0; text-transform: uppercase; letter-spacing: .04em; }
				.snel-clbox .snel-clbox-btn { width: 100%; justify-content: center; margin-top: 8px; display: inline-flex; align-items: center; text-align: center; }
			</style>

			<!-- Current language card -->
			<div style="position:relative;margin-bottom:16px;padding:10px 12px;background:#f6f7f9;border-radius:8px;">
				<span class="snel-clbox-kicker"><?php esc_html_e( 'This page', 'snel' ); ?></span>
				<div style="margin-top:3px;color:#1e2733;font-weight:700;"><?php echo esc_html( $current ? $label( $current ) : '—' ); ?></div>
				<?php if ( $current ) : ?>
					<div style="position:absolute;top:8px;right:8px;display:flex;gap:4px;">
						<?php echo self::langBadges( $current, $is_source ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					</div>
				<?php endif; ?>
			</div>

			<?php if ( ! empty( $existing ) ) : ?>
				<div style="margin-bottom:16px;">
					<span class="snel-clbox-kicker" style="display:block;margin-bottom:8px;"><?php esc_html_e( 'Translations', 'snel' ); ?></span>
					<?php foreach ( $existing as $l ) : ?>
						<div style="padding:8px 10px;margin-bottom:6px;border-radius:8px;border:1px solid #eef0f2;" data-lang="<?php echo esc_attr( $l['code'] ); ?>" class="snel-clbox-row<?php echo ! empty( $l['outdated'] ) ? ' is-outdated' : ''; ?>">
							<?php $badges = self::langBadges( $l, $l['code'] === $default ); ?>
							<?php if ( $badges ) : ?>
								<div style="display:flex;flex-wrap:wrap;gap:4px;margin-bottom:10px;"><?php echo $badges; // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
							<?php endif; ?>
							<div style="display:flex;justify-content:space-between;align-items:center;gap:8px;">
								<strong style="font-weight:700;"><?php echo esc_html( $label( $l ) ); ?></strong>
								<span style="font-size:12px;white-space:nowrap;">
									<a href="<?php echo esc_url( $l['editUrl'] ); ?>"><?php esc_html_e( 'Edit', 'snel' ); ?></a>
									<?php if ( ! empty( $l['viewUrl'] ) ) : ?>
										· <a href="<?php echo esc_url( $l['viewUrl'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View', 'snel' ); ?></a>
									<?php endif; ?>
									<?php if ( $is_source && ! empty( $l['outdated'] ) ) : ?>
										· <a href="#" class="snel-clbox-sync" data-target="<?php echo esc_attr( $l['code'] ); ?>"><?php esc_html_e( 'Sync', 'snel' ); ?></a>
									<?php endif; ?>
								</span>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<?php if ( ! $is_source ) : ?>
				<p style="font-size:13px;color:#666;">
					<?php esc_html_e( 'Translations are created from the source language.', 'snel' ); ?>
					<?php if ( $src_lang && ! empty( $src_lang['editUrl'] ) ) : ?>
						<a href="<?php echo esc_url( $src_lang['editUrl'] ); ?>"><?php esc_html_e( 'Open the', 'snel' ); ?> <?php echo esc_html( $label( $src_lang ) ); ?> <?php esc_html_e( 'source →', 'snel' ); ?></a>
					<?php endif; ?>
				</p>
			<?php else : ?>
				<?php if ( ! empty( $missing ) ) : ?>
					<button type="button" class="button button-primary snel-clbox-btn snel-clbox-create-all" title="<?php esc_attr_e( 'Create every missing translation', 'snel' ); ?>">
						✦ <?php esc_html_e( 'Create all missing', 'snel' ); ?> (<?php echo count( $missing ); ?>)
					</button>
				<?php endif; ?>
				<?php if ( ! empty( $outdated ) ) : ?>
					<button type="button" class="button snel-clbox-btn snel-clbox-sync-outdated" title="<?php esc_attr_e( 'Re-translate the outdated translations from the source', 'snel' ); ?>">
						↻ <?php esc_html_e( 'Sync outdated', 'snel' ); ?> (<?php echo count( $outdated ); ?>)
					</button>
				<?php endif; ?>
				<?php if ( ! empty( $existing ) ) : ?>
					<button type="button" class="button snel-clbox-btn snel-clbox-sync-all" title="<?php esc_attr_e( 'Re-translate every existing translation from the source (overwrites manual edits)', 'snel' ); ?>">
						↻ <?php esc_html_e( 'Sync all', 'snel' ); ?> (<?php echo count( $existing ); ?>)
					</button>
				<?php endif; ?>
				<p class="snel-clbox-status description" style="margin:8px 0 0;"></p>
			<?php endif; ?>
		</div>

		<script>
		(function(){
			var box = document.querySelector('.snel-clbox');
			if (!box) return;
			var nonce  = box.dataset.nonce;
			var source = box.dataset.source;
			var status = box.querySelector('.snel-clbox-status');

			var missingTargets  = <?php echo wp_json_encode( array_values( array_map( static fn( $l ) => $l['code'], $missing ) ) ); ?>;
			var existingTargets = <?php echo wp_json_encode( array_values( array_map( static fn( $l ) => $l['code'], $existing ) ) ); ?>;
			var outdatedTargets = <?php echo wp_json_encode( array_values( array_map( static fn( $l ) => $l['code'], $outdated ) ) ); ?>;

			function call(action, target) {
				var fd = new FormData();
				fd.append('action', action);
				fd.append('nonce', nonce);
				fd.append('post_id', source);
				fd.append('target', target);
				return fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: fd })
					.then(function(r){ return r.json(); });
			}

			function run(actions) {
				box.classList.add('is-busy');
				var chain = Promise.resolve();
				var errors = 0;
				actions.forEach(function(a){
					chain = chain.then(function(){
						if (status) status.textContent = a.target.toUpperCase() + '…';
						return call(a.action, a.target).then(function(res){
							if (!res || !res.success) { errors++; console.error('snel translations', a, res); }
						}).catch(function(e){ errors++; console.error(e); });
					});
				});
				chain.then(function(){
					if (errors) {
						if (status) status.textContent = errors + ' <?php echo esc_js( __( 'error(s) — see console', 'snel' ) ); ?>';
						box.classList.remove('is-busy');
					} else {
						location.reload();
					}
				});
			}

			box.addEventListener('click', function(e){
				var sync = e.target.closest('.snel-clbox-sync');
				if (sync) {
					e.preventDefault();
					if (window.confirm('<?php echo esc_js( __( 'Re-translate and overwrite this translation? Manual edits will be lost.', 'snel' ) ); ?>')) {
						run([{ action: 'snel_sync_translation', target: sync.dataset.target }]);
					}
					return;
				}
				if (e.target.closest('.snel-clbox-create-all')) {
					run(missingTargets.map(function(t){ return { action: 'snel_create_translation', target: t }; }));
				}
				if (e.target.closest('.snel-clbox-sync-outdated')) {
					run(outdatedTargets.map(function(t){ return { action: 'snel_sync_translation', target: t }; }));
				}
				if (e.target.closest('.snel-clbox-sync-all')) {
					if (window.confirm('<?php echo esc_js( __( 'Re-translate ALL translations from the source? Manual edits will be lost.', 'snel' ) ); ?>')) {
						run(existingTargets.map(function(t){ return { action: 'snel_sync_translation', target: t }; }));
					}
				}
			});
		})();
		</script>
		<?php
	}
}
