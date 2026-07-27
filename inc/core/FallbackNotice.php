<?php
/**
 * FallbackNotice — toast after an untranslated-content redirect.
 *
 * Router 302s an untranslated single to its own-language URL with
 * ?snel_notrans={lang} appended. This renders a small dismissible toast on
 * that landing page ("not available in {lang} yet") in the language the
 * visitor asked for, and strips the param from the address bar so the clean
 * URL is what gets copied or shared.
 *
 * @package Snel\Translations
 */

namespace Snel\Translations\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FallbackNotice {

	public static function register(): void {
		add_action( 'wp_footer', [ self::class, 'render' ] );
	}

	/** Toast copy per requested language; unknown languages fall back to English. */
	private static function message( string $lang ): string {
		$messages = [
			'nl' => 'Deze pagina is nog niet beschikbaar in het Nederlands. Je ziet de originele versie.',
			'en' => 'This page isn’t available in English yet. Showing the original version.',
			'fr' => 'Cette page n’est pas encore disponible en français. Version originale affichée.',
			'es' => 'Esta página aún no está disponible en español. Se muestra la versión original.',
			'de' => 'Diese Seite ist noch nicht auf Deutsch verfügbar. Originalversion wird angezeigt.',
		];
		return $messages[ $lang ] ?? $messages['en'];
	}

	public static function render(): void {
		$lang = isset( $_GET['snel_notrans'] ) ? sanitize_key( wp_unslash( $_GET['snel_notrans'] ) ) : '';
		if ( $lang === '' || ! in_array( $lang, LocaleManager::supported(), true ) ) {
			return;
		}

		$text = apply_filters( 'snel_fallback_notice_text', self::message( $lang ), $lang );
		?>
		<div class="snel-notrans-toast" role="status" hidden>
			<span><?php echo esc_html( $text ); ?></span>
			<button type="button" aria-label="<?php echo esc_attr( $lang === 'nl' ? 'Sluiten' : 'Dismiss' ); ?>">&#215;</button>
		</div>
		<style>
			.snel-notrans-toast{position:fixed;left:1rem;bottom:1rem;z-index:99999;display:flex;align-items:center;gap:.75rem;max-width:min(92vw,26rem);padding:.75rem 1rem;background:#18181b;color:#fafafa;border-radius:.625rem;box-shadow:0 8px 24px rgba(0,0,0,.25);font-size:.875rem;line-height:1.45;opacity:0;transform:translateY(.5rem);transition:opacity .3s ease,transform .3s ease}
			.snel-notrans-toast.is-visible{opacity:1;transform:none}
			.snel-notrans-toast button{flex:none;background:none;border:0;padding:0;margin:0;color:inherit;opacity:.6;font-size:1.125rem;line-height:1;cursor:pointer}
			.snel-notrans-toast button:hover{opacity:1}
			@media (prefers-reduced-motion:reduce){.snel-notrans-toast{transition:none}}
		</style>
		<script>
		(function(){
			var url = new URL( window.location.href );
			url.searchParams.delete( 'snel_notrans' );
			window.history.replaceState( null, '', url );

			var toast = document.querySelector( '.snel-notrans-toast' );
			if ( ! toast ) return;
			toast.hidden = false;
			requestAnimationFrame( function(){ toast.classList.add( 'is-visible' ); } );
			function hide(){
				toast.classList.remove( 'is-visible' );
				setTimeout( function(){ toast.remove(); }, 350 );
			}
			toast.querySelector( 'button' ).addEventListener( 'click', hide );
			setTimeout( hide, 7000 );
		})();
		</script>
		<?php
	}
}
