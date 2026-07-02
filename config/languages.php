<?php
/**
 * Default languages (the seed).
 *
 * Used only when the snel_languages option is empty. Admins override this list
 * from the Languages tab (stored in the option). Default language has no URL
 * prefix; every other language gets one (e.g. /en/).
 *
 * @package Snel\Translations
 */

return [
	'nl' => [
		'label'   => 'NL',
		'locale'  => 'nl_NL',
		'default' => true,
	],
	'en' => [
		'label'  => 'EN',
		'locale' => 'en_US',
	],
];
