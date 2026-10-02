<?php
/**
 * PHPUnit bootstrap.
 *
 * The Phase 0 suite covers the pure-PHP support layer, which has no WordPress
 * dependency beyond a handful of text helpers. Those are stubbed here so the suite
 * runs on a bare checkout — the WordPress integration suite arrives with M2, where
 * the first classes that genuinely need a database appear.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

// Time constants, referenced in class constant expressions and therefore needed at
// class-load time rather than at call time.
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );
define( 'SCSTUDIO_VERSION', '0.1.0-alpha' );
define( 'SCSTUDIO_DIR', dirname( __DIR__ ) . '/' );
define( 'SCSTUDIO_FILE', dirname( __DIR__ ) . '/social-card-studio.php' );

if ( ! function_exists( 'strip_shortcodes' ) ) {
	/**
	 * Minimal stand-in for the WordPress function.
	 *
	 * @param string $content Input content.
	 *
	 * @return string Content without shortcodes.
	 */
	function strip_shortcodes( string $content ): string {
		return (string) preg_replace( '/\[\/?[^\]]*\]/', '', $content );
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	/**
	 * Minimal stand-in for the WordPress function.
	 *
	 * @param string $text    Input text.
	 * @param bool   $remove_breaks Whether to collapse line breaks.
	 *
	 * @return string Text without tags.
	 */
	function wp_strip_all_tags( string $text, bool $remove_breaks = false ): string {
		$text = (string) preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $text );
		$text = wp_kses_strip( $text );

		if ( $remove_breaks ) {
			$text = (string) preg_replace( '/[\r\n\t ]+/', ' ', $text );
		}

		return trim( $text );
	}
}

if ( ! function_exists( 'wp_kses_strip' ) ) {
	/**
	 * Strips tags.
	 *
	 * @param string $text Input text.
	 *
	 * @return string Text without tags.
	 */
	function wp_kses_strip( string $text ): string {
		return strip_tags( $text );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Stand-in that returns the value unfiltered.
	 *
	 * The unit suite has no hook system; filters are exercised against the real
	 * WordPress in the integration harness.
	 *
	 * @param string $hook_name Filter name.
	 * @param mixed  $value     Value to filter.
	 *
	 * @return mixed The unmodified value.
	 */
	function apply_filters( string $hook_name, $value ) {
		return $value;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * Minimal stand-in for the WordPress function.
	 *
	 * @param mixed $data Data to encode.
	 *
	 * @return string Encoded JSON.
	 */
	function wp_json_encode( $data ): string {
		return (string) json_encode( $data );
	}
}

if ( ! function_exists( 'is_multisite' ) ) {
	/**
	 * The unit suite is single-site.
	 *
	 * @return bool Always false.
	 */
	function is_multisite(): bool {
		return false;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Reads from an in-memory option store that tests seed directly.
	 *
	 * @param string $option        Option name.
	 * @param mixed  $default_value Returned when unset.
	 *
	 * @return mixed Stored value.
	 */
	function get_option( string $option, $default_value = false ) {
		return $GLOBALS['scstudio_test_options'][ $option ] ?? $default_value;
	}
}

if ( ! function_exists( '__' ) ) {
	/**
	 * Returns the text untranslated.
	 *
	 * @param string $text   Text.
	 * @param string $domain Text domain.
	 *
	 * @return string The text.
	 */
	function __( string $text, string $domain = 'default' ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Matches the WordPress signature.
		return $text;
	}
}

require_once dirname( __DIR__ ) . '/src/autoload.php';
