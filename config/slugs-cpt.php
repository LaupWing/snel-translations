<?php
/**
 * URL base-slug translations — custom post types AND public taxonomies.
 *
 * Maps a default-language base segment to its translated form, so that
 * /en/services/ resolves to the same archive as /diensten/, and a taxonomy
 * base translates the same way: /en/products/bookcases/ ↔ /producten/boekenkasten/.
 *   'diensten'  => ['en' => 'services']
 *   'producten' => ['en' => 'products', 'es' => 'productos']
 *
 * Empty by default (generic plugin). Projects add their CPTs here, or via the
 * `snel_cpt_slugs` filter. CPTs with the same slug in every language can be
 * skipped.
 *
 * @package Snel\Translations
 */

return [];
