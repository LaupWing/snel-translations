<?php
/**
 * SlugCleanup — TEMPORARY admin tool.
 *
 * Repairs slugs from before the uniqueSlug timing fix: translation siblings
 * that got WP's auto-suffixed slug (blog-2, blog-4, contact-3, …) because the
 * language meta didn't exist yet while the slug was generated.
 *
 * Adds Tools → Snel slug cleanup with a dry-run preview and a Fix button.
 * Only renames a sibling whose slug is exactly "{source-slug}-{number}" — a
 * deliberately translated slug (kontakt, fallstudien) is never touched.
 *
 * Delete this file (and its require in Boot) once production sites are clean.
 *
 * @package Snel\Translations
 */

namespace Snel\Translations;

use Snel\Translations\Core\TranslationGroup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SlugCleanup {

	/** Register the tools page + form handler. Called from Boot when live. */
	public static function register(): void {
		add_action( 'admin_menu', function () {
			add_management_page(
				__( 'Snel slug cleanup', 'snel' ),
				__( 'Snel slug cleanup', 'snel' ),
				'manage_options',
				'snel-slug-cleanup',
				[ self::class, 'renderPage' ]
			);
		} );
		add_action( 'admin_post_snel_slug_cleanup', [ self::class, 'handleFix' ] );
	}

	/**
	 * Every sibling whose slug is the source slug + "-N".
	 * @return array<int,array{id:int,title:string,lang:string,slug:string,target:string}>
	 */
	private static function findSuffixed(): array {
		global $wpdb;

		// All posts that belong to a group but are not its root (= translations).
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID, p.post_title, p.post_name, m.meta_value AS group_id
			 FROM {$wpdb->posts} p
			 JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
			 WHERE p.ID <> m.meta_value
			 AND p.post_status NOT IN ('trash', 'auto-draft')",
			TranslationGroup::META_GROUP
		) );

		$bad = [];
		foreach ( $rows as $row ) {
			$source_slug = get_post_field( 'post_name', (int) $row->group_id );
			if ( ! $source_slug ) {
				continue;
			}
			if ( ! preg_match( '/^' . preg_quote( $source_slug, '/' ) . '-\d+$/', $row->post_name ) ) {
				continue; // translated or unrelated slug — leave it alone
			}
			$bad[] = [
				'id'     => (int) $row->ID,
				'title'  => $row->post_title,
				'lang'   => TranslationGroup::langOf( (int) $row->ID ),
				'slug'   => $row->post_name,
				'target' => $source_slug,
			];
		}

		return $bad;
	}

	/** Rename every suffixed sibling back to the source slug. */
	public static function handleFix(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'snel' ) );
		}
		check_admin_referer( 'snel_slug_cleanup' );

		$fixed = 0;
		foreach ( self::findSuffixed() as $item ) {
			// uniqueSlug allows the cross-language share; if a genuine
			// same-language collision exists WP re-suffixes and we skip it.
			$res = wp_update_post( [ 'ID' => $item['id'], 'post_name' => $item['target'] ], true );
			if ( ! is_wp_error( $res ) && get_post_field( 'post_name', $item['id'] ) === $item['target'] ) {
				$fixed++;
			}
		}

		flush_rewrite_rules();

		wp_safe_redirect( add_query_arg(
			[ 'page' => 'snel-slug-cleanup', 'fixed' => $fixed ],
			admin_url( 'tools.php' )
		) );
		exit;
	}

	/** The tools page: dry-run table + Fix button. */
	public static function renderPage(): void {
		$bad   = self::findSuffixed();
		$fixed = isset( $_GET['fixed'] ) ? (int) $_GET['fixed'] : null;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Snel slug cleanup', 'snel' ); ?></h1>
			<p><?php esc_html_e( 'Translation siblings stuck with an auto-suffixed slug (blog-2, blog-4, …) from before the slug fix. Fixing renames them to the source slug; old URLs 301 via the plugin router.', 'snel' ); ?></p>

			<?php if ( $fixed !== null ) : ?>
				<div class="notice notice-success"><p>
					<?php printf( esc_html__( '%d slug(s) fixed.', 'snel' ), $fixed ); ?>
				</p></div>
			<?php endif; ?>

			<?php if ( empty( $bad ) ) : ?>
				<p><strong><?php esc_html_e( 'Nothing to fix — all sibling slugs are clean. You can remove this tool.', 'snel' ); ?></strong></p>
			<?php else : ?>
				<table class="widefat striped" style="max-width:700px">
					<thead><tr>
						<th><?php esc_html_e( 'Post', 'snel' ); ?></th>
						<th><?php esc_html_e( 'Lang', 'snel' ); ?></th>
						<th><?php esc_html_e( 'Current slug', 'snel' ); ?></th>
						<th><?php esc_html_e( 'Becomes', 'snel' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $bad as $item ) : ?>
						<tr>
							<td><a href="<?php echo esc_url( (string) get_edit_post_link( $item['id'] ) ); ?>"><?php echo esc_html( $item['title'] ); ?></a></td>
							<td><?php echo esc_html( strtoupper( $item['lang'] ) ); ?></td>
							<td><code><?php echo esc_html( $item['slug'] ); ?></code></td>
							<td><code><?php echo esc_html( $item['target'] ); ?></code></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:16px">
					<?php wp_nonce_field( 'snel_slug_cleanup' ); ?>
					<input type="hidden" name="action" value="snel_slug_cleanup" />
					<?php submit_button( sprintf( __( 'Fix %d slug(s)', 'snel' ), count( $bad ) ), 'primary', 'submit', false ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}
}
