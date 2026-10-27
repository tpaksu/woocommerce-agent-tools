<?php
/**
 * Stubs for the PHP 8 string functions WordPress polyfills, for static analysis only.
 *
 * WordPress declares both in `wp-includes/compat.php` when the PHP version lacks them, so
 * they are available on every install this plugin supports. `php-stubs/wordpress-stubs`
 * ships the core API but not the polyfills, so without these PHPStan reports the calls as
 * unresolved.
 *
 * Two details are deliberate. Return types are `@return` rather than native, matching
 * `wordpress-stubs`, because a native return type on an empty body makes editors report
 * that not all paths return a value. And the declarations are guarded, because on PHP 8
 * these functions already exist and an unguarded declaration cannot even be linted.
 *
 * Keep this to the surface this plugin actually calls.
 *
 * @package woocommerce-agent-tools
 */

if ( ! function_exists( 'str_starts_with' ) ) {
	/**
	 * @param string $haystack The string to search in.
	 * @param string $needle   The substring to search for.
	 * @return bool
	 */
	function str_starts_with( string $haystack, string $needle )
	{
	}
}

if ( ! function_exists( 'str_ends_with' ) ) {
	/**
	 * @param string $haystack The string to search in.
	 * @param string $needle   The substring to search for.
	 * @return bool
	 */
	function str_ends_with( string $haystack, string $needle )
	{
	}
}
