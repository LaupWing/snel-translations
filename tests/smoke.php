<?php
/**
 * Routing smoke test — run before every release.
 *
 * Boots WordPress, creates its own fixture pages (an NL/EN sibling pair),
 * asserts the routing invariants over real HTTP, then deletes the fixtures.
 * No PHPUnit, no mocks — it tests the site the way a browser and Googlebot
 * see it. See ARCHITECTURE.md §3 for the invariants covered.
 *
 * Usage:   tests/smoke.sh            (wrapper — finds PHP + WP automatically)
 * Direct:  php -c <site php.ini> tests/smoke.php /path/to/wp/root
 *
 * Refuses to run outside a local/dev environment (it creates content).
 *
 * @package Snel\Translations
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

$wp_root = $argv[1] ?? dirname( __DIR__, 4 ); // plugin lives in wp-content/plugins/snel-translations
if ( ! file_exists( $wp_root . '/wp-load.php' ) ) {
	fwrite( STDERR, "wp-load.php not found under: {$wp_root}\n" );
	exit( 1 );
}

require $wp_root . '/wp-load.php';

// ─── Safety: local environments only ────────────────────────────────────────
$home = home_url();
$env  = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
if ( ! in_array( $env, [ 'local', 'development' ], true )
	&& ! preg_match( '#://(localhost|127\.0\.0\.1|.+\.local)([:/]|$)#', $home ) ) {
	fwrite( STDERR, "Refusing to run: {$home} does not look like a local site.\n" );
	exit( 1 );
}

use Snel\Translations\Core\TranslationGroup;
use Snel\Translations\Core\LocaleManager;

$default_lang = LocaleManager::default();
$other        = array_values( array_diff( LocaleManager::supported(), [ $default_lang ] ) );
if ( empty( $other ) ) {
	fwrite( STDERR, "Need at least two languages configured to smoke-test routing.\n" );
	exit( 1 );
}
$lang = $other[0]; // first non-default language, e.g. 'en'

// ─── Tiny test harness ───────────────────────────────────────────────────────
$pass = 0;
$fail = 0;

function check( string $label, bool $ok, string $detail = '' ): void {
	global $pass, $fail;
	if ( $ok ) {
		$pass++;
		echo "  ok   {$label}\n";
	} else {
		$fail++;
		echo "  FAIL {$label}" . ( $detail !== '' ? "  ({$detail})" : '' ) . "\n";
	}
}

/** GET without following redirects: [ status, location, body ]. */
function fetch( string $path ): array {
	$res = wp_remote_get( home_url( $path ), [ 'redirection' => 0, 'timeout' => 15 ] );
	if ( is_wp_error( $res ) ) {
		return [ 0, '', $res->get_error_message() ];
	}
	return [
		(int) wp_remote_retrieve_response_code( $res ),
		(string) wp_remote_retrieve_header( $res, 'location' ),
		(string) wp_remote_retrieve_body( $res ),
	];
}

// ─── Fixtures: an NL page + linked EN sibling ────────────────────────────────
echo "Fixtures: creating sibling pair ({$default_lang} + {$lang})…\n";

$src_id = wp_insert_post( [
	'post_title'  => 'Snel Smoke Source',
	'post_name'   => 'snel-smoke-source',
	'post_status' => 'publish',
	'post_type'   => 'page',
	'post_content'=> '<p>snel-smoke-source-body</p>',
], true );
$tr_id = wp_insert_post( [
	'post_title'  => 'Snel Smoke Translated',
	'post_name'   => 'snel-smoke-translated',
	'post_status' => 'publish',
	'post_type'   => 'page',
	'post_content'=> '<p>snel-smoke-translated-body</p>',
], true );

$post_id = wp_insert_post( [
	'post_title'  => 'Snel Smoke Post',
	'post_name'   => 'snel-smoke-post',
	'post_status' => 'publish',
	'post_type'   => 'post',
	'post_content'=> '<p>snel-smoke-post-body</p>',
], true );

if ( is_wp_error( $src_id ) || is_wp_error( $tr_id ) || is_wp_error( $post_id ) ) {
	fwrite( STDERR, "Could not create fixtures.\n" );
	exit( 1 );
}
TranslationGroup::link( (int) $src_id, (int) $src_id, $default_lang );
TranslationGroup::link( (int) $tr_id, (int) $src_id, $lang );
TranslationGroup::link( (int) $post_id, (int) $post_id, $default_lang ); // deliberately untranslated

$cleanup = function () use ( $src_id, $tr_id, $post_id ) {
	wp_delete_post( (int) $src_id, true );
	wp_delete_post( (int) $tr_id, true );
	wp_delete_post( (int) $post_id, true );
	echo "Fixtures: deleted.\n";
};

// From here on, always clean up — even on fatals.
register_shutdown_function( $cleanup );

$src_locale = str_replace( '_', '-', LocaleManager::config()[ $default_lang ]['locale'] ?? $default_lang );
$tr_locale  = str_replace( '_', '-', LocaleManager::config()[ $lang ]['locale'] ?? $lang );

// ─── 1. Sibling model (in-process) ──────────────────────────────────────────
echo "Sibling model:\n";
check( 'translation() resolves source → sibling', TranslationGroup::translation( (int) $src_id, $lang ) === (int) $tr_id );
check( 'translation() resolves sibling → source', TranslationGroup::translation( (int) $tr_id, $default_lang ) === (int) $src_id );
check( 'permalink of translated page carries /' . $lang . '/ prefix', strpos( get_permalink( (int) $tr_id ), "/{$lang}/snel-smoke-translated/" ) !== false, get_permalink( (int) $tr_id ) );
check( 'permalink of source page has no prefix', strpos( get_permalink( (int) $src_id ), "/{$lang}/" ) === false, get_permalink( (int) $src_id ) );

// ─── 2. Routing over HTTP ────────────────────────────────────────────────────
echo "Routing:\n";
[ $code, , $body ] = fetch( '/snel-smoke-source/' );
check( 'source URL → 200', $code === 200, "got {$code}" );
check( 'source URL renders source content', strpos( $body, 'snel-smoke-source-body' ) !== false );
check( 'source URL has lang="' . $src_locale . '"', strpos( $body, 'lang="' . $src_locale . '"' ) !== false );

[ $code, , $body ] = fetch( "/{$lang}/snel-smoke-translated/" );
check( 'translated URL → 200', $code === 200, "got {$code}" );
check( 'translated URL renders translated content', strpos( $body, 'snel-smoke-translated-body' ) !== false );
check( 'translated URL has lang="' . $tr_locale . '"', strpos( $body, 'lang="' . $tr_locale . '"' ) !== false );

// ─── 3. hreflang ─────────────────────────────────────────────────────────────
echo "hreflang:\n";
[ , , $body ] = fetch( '/snel-smoke-source/' );
check( 'source head links its ' . $lang . ' sibling', (bool) preg_match( '#hreflang="' . $lang . '" href="[^"]*/' . $lang . '/snel-smoke-translated/#', $body ) );
check( 'source head has self-reference', (bool) preg_match( '#hreflang="' . $default_lang . '" href="[^"]*/snel-smoke-source/#', $body ) );
check( 'source head has x-default → default lang', (bool) preg_match( '#hreflang="x-default" href="[^"]*/snel-smoke-source/#', $body ) );

// ─── 4. Canonical redirects ──────────────────────────────────────────────────
echo "Canonical redirects:\n";
[ $code, $loc ] = fetch( "/{$lang}/snel-smoke-translated" ); // no trailing slash
check( 'missing trailing slash → 301', $code === 301, "got {$code}" );
check( '…to the slashed URL', substr( $loc, -strlen( "/{$lang}/snel-smoke-translated/" ) ) === "/{$lang}/snel-smoke-translated/", $loc );

[ $code, $loc ] = fetch( "/{$lang}/snel-smoke-source/" ); // wrong-language slug
check( 'source slug under /' . $lang . '/ → 301', $code === 301, "got {$code}" );
check( '…to the real translated URL', strpos( $loc, "/{$lang}/snel-smoke-translated/" ) !== false, $loc );

[ $code, $loc ] = fetch( '/snel-smoke-translated/' ); // translated slug at the root
check( 'translated slug at root → 301', $code === 301, "got {$code}" );
check( '…back to the source URL', strpos( $loc, '/snel-smoke-source/' ) !== false, $loc );

[ $code ] = fetch( "/{$lang}/" );
check( 'language home → 200 (no redirect loop)', $code === 200, "got {$code}" );
[ $code, $loc ] = fetch( "/{$lang}" );
check( 'language home without slash → 301 → /' . $lang . '/', $code === 301 && substr( $loc, -strlen( "/{$lang}/" ) ) === "/{$lang}/", "got {$code} → {$loc}" );

// ─── 5. Untranslated fallback (invariant 1) ──────────────────────────────────
echo "Untranslated fallback:\n";
[ $code, $loc ] = fetch( "/{$lang}/snel-smoke-post/" ); // post with no sibling in $lang
check( 'untranslated post under /' . $lang . '/ → 302 (no 404)', $code === 302, "got {$code}" );
check( '…to its own-language URL', strpos( $loc, '/snel-smoke-post/' ) !== false && strpos( $loc, "/{$lang}/" ) === false, $loc );
check( '…carrying snel_notrans=' . $lang, strpos( $loc, 'snel_notrans=' . $lang ) !== false, $loc );

[ , , $body ] = fetch( '/snel-smoke-post/?snel_notrans=' . $lang );
check( 'landing page renders fallback toast', strpos( $body, 'snel-notrans-toast' ) !== false );

wp_update_post( [ 'ID' => (int) $tr_id, 'post_status' => 'draft' ] );

[ $code, $loc ] = fetch( "/{$lang}/snel-smoke-source/" );
check( 'draft sibling: 302 back to source (no 404)', $code === 302, "got {$code}" );
check( '…to the source URL', strpos( $loc, '/snel-smoke-source/' ) !== false, $loc );

[ , , $body ] = fetch( '/snel-smoke-source/' );
check( 'draft sibling: no hreflang advertised', strpos( $body, 'hreflang="' . $lang . '"' ) === false );

wp_update_post( [ 'ID' => (int) $tr_id, 'post_status' => 'publish' ] );

// ─── Summary ─────────────────────────────────────────────────────────────────
echo "\n{$pass} passed, {$fail} failed.\n";
exit( $fail === 0 ? 0 : 1 );
